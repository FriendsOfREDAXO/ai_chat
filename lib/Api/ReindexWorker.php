<?php

namespace FriendsOfRedaxo\AiChat\Api;

use FriendsOfRedaxo\AiChat\Service\IndexerService;
use FriendsOfRedaxo\AiChat\Service\IndexRunStore;
use rex_api_function;
use rex_logger;
use rex_response;

/**
 * Der eigentliche Hintergrund-Arbeiter fuer die Neuindizierung: wird von
 * ChatIndex::handleStartBackground() per shell_exec()+curl/wget als
 * abgekoppelter Prozess aufgerufen, laeuft also OHNE Backend-Session/Cookies -
 * daher `published = true` (sonst wuerde REDAXOs eigener Dispatcher die
 * Anfrage schon vor execute() mit 401/403 ablehnen, siehe rex_api_function::
 * handleCall()). Einzige Absicherung ist der Einmal-Token aus IndexRunStore,
 * der nur waehrend eines aktiven Laufs gueltig ist (siehe Klassendoc dort).
 */
class ReindexWorker extends rex_api_function
{
    use JsonResponseTrait;

    protected $published = true;

    public function execute()
    {
        // Siehe WidgetTranslations::execute() fuer denselben Reset: ohne ihn bleibt eine
        // erfolgreiche Antwort bei HTTP 404 stehen (vorbelegter Status bei einer rex-api-call-
        // Anfrage auf einer yrewrite-Domain). Hier praktisch folgenlos, da der aufrufende
        // detached curl/wget-Prozess die Antwort ignoriert - der Vollstaendigkeit halber
        // trotzdem korrekt gesetzt.
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::cleanOutputBuffers();
        ob_start();

        $token = rex_request('token', 'string', '');
        $state = IndexRunStore::read();

        $isValidRequest = $token !== ''
            && ($state['status'] ?? '') === 'running'
            && is_string($state['token'] ?? null)
            && hash_equals($state['token'], $token);

        if (!$isValidRequest) {
            rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
            $this->sendJsonClean(['error' => 'Forbidden']);
        }

        // Vom aufrufenden curl/wget entkoppelt weiterlaufen, auch wenn die
        // Verbindung selbst (z.B. durch stop_background) beendet wird -
        // Fortschritt/Abbruch werden ohnehin ausschliesslich ueber die
        // State-Datei gesteuert, nicht ueber die HTTP-Verbindung.
        ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(0);

        // Dem aufrufenden curl/wget-Prozess SOFORT antworten und die Verbindung
        // schliessen, statt ihn auf die komplette (potenziell viele Minuten dauernde)
        // Laufzeit warten zu lassen. ChatIndex::buildCurlSelfCallCommand() setzt je
        // Versuch ein `--max-time 20` UND verkettet drei Versuche per `||`
        // (oeffentliche URL -> Loopback 443 -> Loopback 80) - ohne dieses fruehe
        // Response wuerde JEDER Lauf, der laenger als 20s braucht, den ersten
        // Versuch als "fehlgeschlagen" erscheinen lassen, WAEHREND er dank
        // ignore_user_abort() serverseitig weiterlief - der `||`-Fallback haette
        // dann einen zweiten, ueberlappenden Lauf gegen denselben Token gestartet
        // (zwei Prozesse schreiben dann gleichzeitig/durcheinander in dieselbe
        // IndexRunStore-Datei). Erklaert vermutlich den gemeldeten Unterschied
        // "haengt auf einer Website, laeuft auf der anderen durch": betrifft nur
        // Installationen, deren Gesamtlaufzeit ueber 20s liegt (groesserer Index,
        // langsamerer Embedding-Provider, langsamere Verbindung).
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        rex_response::sendJson(['ok' => true, 'accepted' => true]);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            // Fallback fuer Nicht-FPM-SAPIs (z.B. mod_php) - schliesst die Verbindung
            // nicht ganz so sauber wie fastcgi_finish_request(), reicht aber, damit
            // curl/wget nicht laenger als noetig blockiert.
            if (!headers_sent()) {
                header('Connection: close');
            }
            flush();
        }

        try {
            $service = new IndexerService();
            $onProgress = static function (array $progress): void {
                IndexRunStore::write(array_merge(IndexRunStore::read(), $progress, ['status' => 'running']));
            };
            $shouldStop = static function (): bool {
                return (bool) (IndexRunStore::read()['cancel_requested'] ?? false);
            };

            $mode = (string) ($state['mode'] ?? 'full');
            if ('incremental' === $mode) {
                $maxItems = (int) ($state['max_items'] ?? 0);
                $result = $service->sync($maxItems, $onProgress, $shouldStop);
            } else {
                $result = $service->runFull($onProgress, $shouldStop);
            }

            IndexRunStore::write(array_merge(IndexRunStore::read(), $result, [
                'status' => $result['cancelled'] ? 'cancelled' : 'done',
            ]));
        } catch (\Throwable $e) {
            rex_logger::logException($e);
            IndexRunStore::write(array_merge(IndexRunStore::read(), [
                'status' => 'error',
                'message' => $e->getMessage(),
            ]));
        }

        // Kein weiterer Response noetig - die Antwort ist bereits oben, vor dem
        // eigentlichen Indexierungslauf, an den Client gegangen (siehe Kommentar dort).
        // Ein erneuter header()/echo-Versuch hier wuerde nach fastcgi_finish_request()
        // ohnehin nirgendwo mehr ankommen. exit statt eines regulaeren "return", da
        // execute() laut rex_api_function-Vertrag ein rex_api_result liefern muesste -
        // wie bei jedem anderen sendJsonClean()-Pfad in diesem Addon auch gibt es das
        // hier nie, das Skript endet stattdessen einfach.
        exit;
    }
}
