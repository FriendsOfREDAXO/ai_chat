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

        $this->sendJsonClean(['ok' => true]);
    }
}
