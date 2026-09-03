<?php

namespace FriendsOfRedaxo\AiChat\Api;

use FriendsOfRedaxo\AiChat\Service\ChatQueryService;
use FriendsOfRedaxo\AiChat\Service\IndexerService;
use FriendsOfRedaxo\AiChat\Service\IndexRunStore;
use FriendsOfRedaxo\AiChat\Service\SystemCheckService;
use rex;
use rex_addon;
use rex_api_exception;
use rex_api_function;
use rex_logger;
use rex_path;
use rex_response;
use rex_socket;
use rex_sql;

class ChatIndex extends rex_api_function
{
    use JsonResponseTrait;

    protected $published = false; // Only for backend users

    public function execute()
    {
        $user = rex::getUser();
        if (!$user || !($user->isAdmin() || $user->hasPerm('ai_chat[chatadmin]'))) {
            throw new rex_api_exception('Unauthorized');
        }

        // Release session lock to prevent blocking other backend windows/requests
        if (session_id()) {
            session_write_close();
        }

        // Increase time limit for long-running index tasks/embeddings
        @set_time_limit(0);

        rex_response::cleanOutputBuffers();
        // Auffangen für alles, was REDAXOs Error-Handler im Debug-Modus direkt in
        // die Ausgabe schreibt (PHP-Warnings/Notices landen dann VOR dem JSON im
        // Response-Body und machen ihn für jeden JSON-Parser ungültig - siehe
        // sendJsonClean()). Ohne diesen Puffer würde eine einzelne Warning z.B.
        // bei "action=process" die komplette Antwort kaputt machen, was im
        // Browser je nach Client als kryptischer Netzwerkfehler statt als
        // klarer JSON-Fehler auftauchen kann.
        ob_start();

        try {
            $action = rex_request('action', 'string');
            $service = new IndexerService();

            if ($action === 'update_sources') {
                $result = $service->updateGithubSources();
                $success = $result['status'] === 'success' || ($result['message'] ?? '') === 'No repos configured';
                $this->sendJsonClean(['success' => $success, 'details' => $result]);
            }

            if ($action === 'clear') {
                $service->clearIndex();
                $this->sendJsonClean(['success' => true, 'message' => 'Index cleared']);
            }

            if ($action === 'clear_cache') {
                $service->clearCache();
                $this->sendJsonClean(['success' => true, 'message' => 'Cache cleared']);
            }

            if ($action === 'warm_cache') {
                $maxItems = rex_request('max_items', 'int', 0);
                if ($maxItems < 0) {
                    $maxItems = 0;
                }

                $chatService = new ChatQueryService();
                $stats = $chatService->warmupFaqCache($maxItems);
                $this->sendJsonClean(['success' => true, 'stats' => $stats]);
            }

            if ($action === 'collect') {
                $tasks = $service->collectTasks();
                $this->sendJsonClean(['success' => true, 'tasks' => $tasks, 'count' => count($tasks)]);
            }

            if ($action === 'count') {
                $this->sendJsonClean(['success' => true, 'total' => $this->currentIndexSize()]);
            }

            if ($action === 'background_available') {
                $this->sendJsonClean(['success' => true] + SystemCheckService::backgroundRunnerDiagnostics());
            }

            if ($action === 'test_background_selfcall') {
                $this->handleTestBackgroundSelfCall();
            }

            if ($action === 'start_background') {
                $this->handleStartBackground();
            }

            if ($action === 'background_status') {
                $this->handleBackgroundStatus();
                exit;
            }

            if ($action === 'stop_background') {
                $this->handleStopBackground();
                exit;
            }

            if ($action === 'refresh') {
                $maxItems = rex_request('max_items', 'int', 0);
                if ($maxItems < 0) {
                    $maxItems = 0;
                }

                $stats = $service->sync($maxItems);
                $this->sendJsonClean(['success' => true, 'stats' => $stats]);
            }

            if ($action === 'test_sitemap') {
                $url = rex_request('url', 'string');
                if (empty($url)) {
                    throw new rex_api_exception('Sitemap-URL fehlt');
                }

                // Wir nutzen die Methode aus dem IndexerService
                // Da fetchSitemapUrls private ist, machen wir sie protected oder rufen collectTasks mit der URL als Temp-Override auf
                // Aber wir können sie auch einfach im IndexerService public machen oder hier direkt kurz testen.

                try {
                    $socket = rex_socket::factoryUrl($url);
                    $response = $socket->doGet();
                    if (!$response->isOk()) {
                        throw new \Exception('Sitemap konnte nicht geladen werden (HTTP ' . $response->getStatusCode() . ')');
                    }

                    $xmlString = $response->getBody();

                    // Haeufigster Fall fuer "String could not be parsed as XML": die URL zeigt auf
                    // eine normale HTML-Seite (z.B. die Domain-Startseite statt .../sitemap.xml) -
                    // dafuer ein konkreter, umsetzbarer Hinweis statt der rohen libxml-Fehlermeldung.
                    if (1 === preg_match('/^\s*<(!doctype\s+html|html\b)/i', $xmlString)) {
                        throw new \Exception('Die Antwort sieht nach einer normalen HTML-Seite aus, nicht nach einer Sitemap-XML-Datei. Zeigt die URL wirklich direkt auf die sitemap.xml (z.B. https://example.com/sitemap.xml), nicht nur auf die Domain/Startseite?');
                    }

                    $xml = new \SimpleXMLElement($xmlString);
                    $count = 0;

                    if (isset($xml->url)) $count += count($xml->url);
                    if (isset($xml->sitemap)) $count += count($xml->sitemap);

                    $this->sendJsonClean(['success' => true, 'message' => 'Erfolgreich! Gefundene Einträge: ' . $count]);
                } catch (\Exception $e) {
                    $this->sendJsonClean(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
                }
            }

            if ($action === 'process') {
                $task = rex_request('task', 'array');
                $result = $service->processTask($task);

                $response = [
                    'success' => $result['error'] === null,
                    'title'   => $result['title'],
                    'chunks'  => $result['chunks'],
                ];

                if ($result['error']) {
                    $response['error'] = $result['error'];
                }

                $this->sendJsonClean($response);
            }

            if ($action === 'optimize_rag_settings') {
                $addon = rex_addon::get('ai_chat');

                $countSql = rex_sql::factory();
                $countSql->setQuery('SELECT COUNT(*) as total FROM ' . rex::getTable('ai_chat_index'));
                $total = (int) $countSql->getValue('total');

                // Dieselben Stufen wie im Settings-Dropdown, damit der vorgeschlagene Wert
                // dort auch als vorhandene Option erscheint.
                $tiers = [300, 800, 1500, 3000, 6000];
                // Sicherheitsmarge, damit auch kurzfristig neu hinzukommende Chunks noch abgedeckt sind.
                $target = (int) ceil($total * 1.2);

                $recommended = $tiers[count($tiers) - 1];
                foreach ($tiers as $tier) {
                    if ($tier >= $target) {
                        $recommended = $tier;
                        break;
                    }
                }

                $current = (int) $addon->getConfig('rag_candidate_limit', 800);
                $changed = false;

                if ($recommended > $current) {
                    $addon->setConfig('rag_candidate_limit', $recommended);
                    $changed = true;
                }

                $this->sendJsonClean([
                    'success' => true,
                    'total_chunks' => $total,
                    'previous_limit' => $current,
                    'recommended_limit' => $recommended,
                    'changed' => $changed,
                ]);
            }
        } catch (\Throwable $e) {
            rex_logger::logException($e);
            $this->sendJsonClean(['success' => false, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }

        exit;
    }

    private function currentIndexSize(): int
    {
        $sql = rex_sql::factory();
        $row = $sql->getArray('SELECT COUNT(*) as total FROM ' . rex::getTable('ai_chat_index'));

        return (int) ($row[0]['total'] ?? 0);
    }

    /**
     * Startet den Hintergrundlauf: schreibt den initialen Zustand samt frischem
     * Einmal-Token, ruft dann per shell_exec() einen abgekoppelten curl/wget-
     * Prozess gegen Api\ReindexWorker auf (gleiches Prinzip wie mediaplace's
     * ThumbWarmup::handleStartBackground() - Aufruf der eigenen, öffentlich
     * erreichbaren URL statt eines PHP-CLI-Binaries, das auf vielen Hosts gar
     * nicht zuverlässig auffindbar wäre). Läuft weiter, auch wenn der
     * Browser-Tab geschlossen wird; Fortschritt kommt ausschließlich aus
     * IndexRunStore.
     */
    private function handleStartBackground(): void
    {
        if (!self::backgroundRunnerAvailable()) {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            $this->sendJsonClean(['success' => false, 'error' => 'Hintergrundausführung auf diesem Server nicht verfügbar (shell_exec/curl/wget fehlen).']);
        }

        if (IndexRunStore::isRunning()) {
            $this->sendJsonClean(['success' => false, 'error' => 'Es läuft bereits eine Hintergrund-Indizierung.']);
        }

        $mode = rex_request('mode', 'string', 'full');
        if (!in_array($mode, ['full', 'incremental'], true)) {
            $mode = 'full';
        }
        $maxItems = rex_request('max_items', 'int', 0);
        if ($maxItems < 0) {
            $maxItems = 0;
        }

        $token = bin2hex(random_bytes(16));
        IndexRunStore::write([
            'status' => 'running',
            'started_at' => time(),
            'processed' => 0,
            'total' => 0,
            'chunks' => 0,
            'errors' => 0,
            'current_label' => null,
            'error_log' => [],
            'token' => $token,
            'cancel_requested' => false,
            'mode' => $mode,
            'max_items' => $maxItems,
        ]);

        $server = rtrim(rex::getServer(), '/');
        $workerUrl = $server . '/index.php?rex-api-call=ai_chat_reindex_worker&token=' . $token;
        $logFile = rex_path::addonData('ai_chat', 'reindex_background.log');
        $pidFile = rex_path::addonData('ai_chat', 'reindex_background.pid');

        $isWindows = str_starts_with(PHP_OS, 'WIN');
        $curlPath = SystemCheckService::resolveBinary('curl');

        if ($isWindows) {
            // Windows-Zweig bewusst einfach gehalten (nur der öffentliche
            // Aufruf, kein Loopback-Fallback): der dortige Container-Portmapping-
            // Fall ist dort seltener, und der verschachtelte cmd.exe-Quoting
            // (escapeshellarg() quotet unter Windows mit doppelten
            // Anführungszeichen, die dieselben sind wie die des äußeren
            // `cmd /C "..."`-Wrappers) würde bei mehreren verketteten
            // curl-Versuchen unzuverlässig.
            $downloader = $curlPath !== null
                ? escapeshellarg($curlPath) . ' -s -A ' . escapeshellarg(self::SELF_CALL_USER_AGENT) . ' -X POST ' . escapeshellarg($workerUrl)
                : escapeshellarg((string) SystemCheckService::resolveBinary('wget')) . ' -q --user-agent=' . escapeshellarg(self::SELF_CALL_USER_AGENT) . ' -O NUL ' . escapeshellarg($workerUrl);
            // Unter Windows gibt es kein shell_exec('... &')-Backgrounding wie unter
            // Unix - "start /B" startet abgekoppelt, cmd /C führt das eigentliche
            // Kommando aus (gleiches Prinzip wie ffmpeg's Api\Converter::handleStart()).
            // Keine PID erfassbar wie unter Unix (siehe dort) - Stop/Cancel läuft
            // hier ausschließlich über das kooperative cancel_requested-Flag.
            $handle = popen('start /B cmd /C "' . $downloader . ' > ' . $logFile . ' 2>&1"', 'r');
            if (false !== $handle) {
                pclose($handle);
            }
        } else {
            $downloader = $curlPath !== null
                ? self::buildCurlSelfCallCommand($curlPath, $server, $workerUrl)
                : escapeshellarg((string) SystemCheckService::resolveBinary('wget')) . ' -q --user-agent=' . escapeshellarg(self::SELF_CALL_USER_AGENT) . ' -O /dev/null ' . escapeshellarg($workerUrl);
            shell_exec('(' . $downloader . ') > ' . escapeshellarg($logFile) . ' 2>&1 & echo $! > ' . escapeshellarg($pidFile));
        }

        $this->sendJsonClean(['success' => true, 'started' => true]);
    }

    /**
     * Der Selbst-Aufruf über die öffentliche rex::getServer()-URL scheitert in
     * containerisierten Setups regelmäßig: Docker mappt einen Host-Port (z.B.
     * 9444) auf einen anderen internen Container-Port (z.B. 443) - von INNEN
     * im Container ist der öffentliche Host:Port dann schlicht nicht erreichbar
     * ("Connection refused"), unabhängig davon, ob curl/wget grundsätzlich
     * funktionieren. Deshalb drei Versuche hintereinander (der erste
     * erfolgreiche gewinnt, `||` = nur bei Verbindungsfehler den nächsten
     * versuchen): (1) die öffentliche URL direkt (Regelfall auf normalem
     * Hosting ohne Portmapping), (2) Loopback auf den Standard-HTTPS-Port 443,
     * (3) Loopback auf Standard-HTTP-Port 80 - jeweils mit `--connect-to`,
     * damit Host-Header/SNI weiterhin zum öffentlichen Hostnamen passen (sonst
     * würde virtuelles Hosting/yrewrite-Domain-Erkennung fehlschlagen).
     * Zertifikatsprüfung wird für die Loopback-Versuche bewusst übersprungen -
     * rein interner Aufruf, kein Browser-Vertrauenskontext nötig.
     */
    /**
     * Viele gemanagte Hosting-/Plesk-Setups blocken Requests mit curls/wgets eigenem
     * Standard-User-Agent per WAF/ModSecurity (OWASP-Core-Rule-Set "Scripting User
     * Agent" - curl/x.y.z, Wget/x.y.z, python-requests/... gelten dort pauschal als
     * Bot/Scraper-Signatur), unabhaengig davon, ob curl/wget grundsaetzlich
     * funktionieren UND der Server ganz normal erreichbar ist - sichtbar als HTTP 403
     * direkt vom Webserver, nicht als Verbindungsfehler. Ein gaengiger, echter
     * Browser-User-Agent umgeht das zuverlaessig, ohne dass hier irgendein
     * Sicherheitsmechanismus umgangen wuerde, der tatsaechlich externe Bots abwehren
     * soll - der Aufruf bleibt ein legitimer Selbstaufruf derselben Seite.
     */
    private const SELF_CALL_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private static function buildCurlSelfCallCommand(string $curlPath, string $server, string $workerUrl): string
    {
        $scheme = parse_url($server, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($server, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($server, PHP_URL_PORT) ?: ('https' === $scheme ? 443 : 80);
        $hostPort = $host . ':' . $port;
        $userAgent = ' -A ' . escapeshellarg(self::SELF_CALL_USER_AGENT);

        $publicAttempt = escapeshellarg($curlPath) . ' -s --max-time 20' . $userAgent . ' -X POST ' . escapeshellarg($workerUrl);
        $loopbackHttps = escapeshellarg($curlPath) . ' -sk --max-time 20' . $userAgent . ' --connect-to '
            . escapeshellarg($hostPort . ':127.0.0.1:443') . ' -X POST ' . escapeshellarg($workerUrl);
        $loopbackHttp = escapeshellarg($curlPath) . ' -s --max-time 20' . $userAgent . ' --connect-to '
            . escapeshellarg($hostPort . ':127.0.0.1:80') . ' -X POST ' . escapeshellarg($workerUrl);

        return '(' . $publicAttempt . ' || ' . $loopbackHttps . ' || ' . $loopbackHttp . ')';
    }

    private function handleBackgroundStatus(): void
    {
        $state = IndexRunStore::readEffective();
        $status = (string) ($state['status'] ?? 'idle');

        $this->sendJsonClean([
            'success' => true,
            'running' => $status === 'running',
            'status' => $status,
            'processed' => (int) ($state['processed'] ?? 0),
            'total' => (int) ($state['total'] ?? 0),
            'chunks' => (int) ($state['chunks'] ?? 0),
            'errors' => (int) ($state['errors'] ?? 0),
            'current_label' => $state['current_label'] ?? null,
            'error_log' => array_slice((array) ($state['error_log'] ?? []), -20),
            'message' => $state['message'] ?? null,
        ]);
    }

    /**
     * Best effort, analog mediaplace's ThumbWarmup::handleStopBackground():
     * Abbruch-Flag setzen (der Worker prüft es zwischen den Aufgaben, siehe
     * IndexerService::runFull()) UND den gemerkten curl/wget-Prozess selbst
     * beenden. Der PHP-Worker-Request läuft dadurch nicht sofort hart ab,
     * bricht aber spätestens nach der aktuell laufenden Aufgabe kooperativ ab.
     */
    private function handleStopBackground(): void
    {
        $state = IndexRunStore::read();
        $state['cancel_requested'] = true;
        // Status sofort auf "cancelled" setzen statt nur das Flag - ein noch
        // lebender Worker prüft cancel_requested zwar selbst zwischen den
        // Aufgaben und würde denselben Status ohnehin gleich schreiben, aber
        // falls der Worker-Prozess bereits tot/unerreichbar ist (siehe
        // IndexRunStore::readEffective()), würde ein reines Flag NIE gesehen
        // und "läuft bereits" bliebe bestehen. So ist Abbrechen immer sofort
        // wirksam, unabhängig vom tatsächlichen Zustand des Worker-Prozesses.
        if ('running' === ($state['status'] ?? 'idle')) {
            $state['status'] = 'cancelled';
        }
        IndexRunStore::write($state);

        $pidFile = rex_path::addonData('ai_chat', 'reindex_background.pid');
        if (is_file($pidFile) && self::backgroundRunnerAvailable()) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0) {
                shell_exec('kill ' . $pid . ' 2>/dev/null');
            }
        }

        $this->sendJsonClean(['success' => true, 'stopped' => true]);
    }

    private static function backgroundRunnerAvailable(): bool
    {
        return (bool) SystemCheckService::backgroundRunnerDiagnostics()['available'];
    }

    /**
     * Diagnose-Werkzeug fuer "Einstellungen -> Systemcheck": spielt denselben
     * Selbstaufruf-Mechanismus wie handleStartBackground()/buildCurlSelfCallCommand()
     * durch, aber SYNCHRON (nicht per shell_exec('... &') abgekoppelt) und gegen den
     * wirkungslosen Api\SelfCallPing statt des echten Arbeiters - liefert dadurch
     * sofort ein vollstaendiges Ergebnis pro Versuch (oeffentliche URL/Loopback 443/
     * Loopback 80: Dauer, HTTP-Status, Rohausgabe) statt nur "hat irgendwas
     * funktioniert". Zusaetzlich der letzte reale Hintergrundlauf-Log sowie ob ein
     * gemerkter PID-Prozess noch lebt - beides wurde bisher nirgends angezeigt,
     * obwohl es bei jedem echten Lauf mitgeschrieben wird.
     */
    private function handleTestBackgroundSelfCall(): void
    {
        $diagnostics = SystemCheckService::backgroundRunnerDiagnostics();
        $curlPath = SystemCheckService::resolveBinary('curl');
        $wgetPath = SystemCheckService::resolveBinary('wget');

        $server = rtrim(rex::getServer(), '/');
        $pingUrl = $server . '/index.php?rex-api-call=ai_chat_selfcall_ping';

        $attempts = [];
        if ($curlPath !== null) {
            $attempts = self::runSelfCallAttempts($curlPath, $server, $pingUrl);
        }

        $logFile = rex_path::addonData('ai_chat', 'reindex_background.log');
        $pidFile = rex_path::addonData('ai_chat', 'reindex_background.pid');
        $lastLog = is_file($logFile) ? trim((string) file_get_contents($logFile)) : '';

        $pidStillRunning = false;
        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0) {
                // posix_kill($pid, 0) ist oft nicht kompiliert (Shared-Hosting) - `ps -p`
                // als portablerer Fallback, funktioniert ueberall wo shell_exec() selbst
                // schon verfuegbar sein muss (sonst waere backgroundRunnerAvailable() eh
                // false und wir kaemen hier nie an).
                $pidStillRunning = '' !== trim((string) shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null'));
            }
        }

        $this->sendJsonClean([
            'success' => true,
            'runner_available' => $diagnostics['available'],
            'runner_reason' => $diagnostics['reason'],
            'curl_path' => $curlPath,
            'wget_path' => $wgetPath,
            'ping_url' => $pingUrl,
            'attempts' => $attempts,
            'last_run_log' => '' !== $lastLog ? mb_substr($lastLog, -4000) : null,
            'pid_file_present' => is_file($pidFile),
            'pid_still_running' => $pidStillRunning,
        ]);
    }

    /**
     * @return list<array{label: string, duration_ms: int, http_status: ?int, headers: string, output: string}>
     */
    private static function runSelfCallAttempts(string $curlPath, string $server, string $pingUrl): array
    {
        $scheme = parse_url($server, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($server, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($server, PHP_URL_PORT) ?: ('https' === $scheme ? 443 : 80);
        $hostPort = $host . ':' . $port;
        // %{http_code} auf einer eigenen Zeile angehaengt, damit wir Nutzlast und
        // HTTP-Status nachtraeglich wieder sauber trennen koennen, ohne curls
        // "-D -"/--dump-header extra parsen zu muessen.
        $statusMarker = '\n__HTTP_STATUS__%{http_code}';
        // Dieselbe User-Agent-Kennung wie der echte Selbstaufruf (siehe
        // SELF_CALL_USER_AGENT), PLUS uebliche Browser-Header (Accept/-Language/
        // -Encoding) - viele WAF/ModSecurity-Regelwerke werten mehrere Signale
        // KUMULATIV (Anomaly-Score), ein einzelner "guter" User-Agent allein reicht
        // dort nicht zwingend. "-D -" gibt zusaetzlich die vollstaendigen
        // Response-Header aus (z.B. ein ModSecurity-Referenz-Header oder ein
        // aufschlussreicher "Server:"-Wert), falls der reine Statuscode/Body nicht
        // genug fuer die Diagnose hergibt.
        $commonFlags = ' -A ' . escapeshellarg(self::SELF_CALL_USER_AGENT)
            . ' -H ' . escapeshellarg('Accept: text/html,application/json;q=0.9,*/*;q=0.8')
            . ' -H ' . escapeshellarg('Accept-Language: de-DE,de;q=0.9,en;q=0.8')
            . ' -D -';

        $variants = [
            'Öffentliche URL' => escapeshellarg($curlPath) . ' -s --max-time 15' . $commonFlags . ' -w ' . escapeshellarg($statusMarker) . ' -X POST ' . escapeshellarg($pingUrl),
            'Loopback 443 (HTTPS)' => escapeshellarg($curlPath) . ' -sk --max-time 15' . $commonFlags . ' --connect-to '
                . escapeshellarg($hostPort . ':127.0.0.1:443') . ' -w ' . escapeshellarg($statusMarker) . ' -X POST ' . escapeshellarg($pingUrl),
            'Loopback 80 (HTTP)' => escapeshellarg($curlPath) . ' -s --max-time 15' . $commonFlags . ' --connect-to '
                . escapeshellarg($hostPort . ':127.0.0.1:80') . ' -w ' . escapeshellarg($statusMarker) . ' -X POST ' . escapeshellarg($pingUrl),
        ];

        $results = [];
        foreach ($variants as $label => $command) {
            $start = microtime(true);
            $raw = trim((string) shell_exec($command . ' 2>&1'));
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $httpStatus = null;
            if (1 === preg_match('/__HTTP_STATUS__(\d{3})$/', $raw, $m)) {
                $httpStatus = (int) $m[1];
                $raw = trim(substr($raw, 0, -strlen($m[0])));
            }

            // "-D -" schreibt die Response-Header VOR den Body in dieselbe Ausgabe -
            // durch die Leerzeile dazwischen (HTTP-Spec) sauber trennbar. Bei
            // mehreren Redirects/Retries koennen mehrere Header-Bloecke vorkommen,
            // der ERSTE Header-Block bis zur ersten Leerzeile reicht fuer die
            // Diagnose (welcher Server/welche Regel hat reagiert).
            $headers = '';
            $body = $raw;
            $splitPos = strpos($raw, "\r\n\r\n");
            if (false === $splitPos) {
                $splitPos = strpos($raw, "\n\n");
            }
            if (false !== $splitPos) {
                $headers = trim(substr($raw, 0, $splitPos));
                $body = trim(substr($raw, $splitPos));
            }

            $results[] = [
                'label' => $label,
                'duration_ms' => $durationMs,
                'http_status' => $httpStatus,
                'headers' => mb_substr($headers, 0, 800),
                'output' => mb_substr($body, 0, 500),
            ];
        }

        return $results;
    }
}
