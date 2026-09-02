<?php

namespace FriendsOfRedaxo\AiChat\Api;

use FriendsOfRedaxo\AiChat\Service\ChatQueryService;
use FriendsOfRedaxo\AiChat\Service\IndexerService;
use FriendsOfRedaxo\AiChat\Service\IndexRunStore;
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
                $this->sendJsonClean(['success' => true] + self::backgroundRunnerDiagnostics());
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
        $curlPath = self::resolveBinary('curl');

        if ($isWindows) {
            // Windows-Zweig bewusst einfach gehalten (nur der öffentliche
            // Aufruf, kein Loopback-Fallback): der dortige Container-Portmapping-
            // Fall ist dort seltener, und der verschachtelte cmd.exe-Quoting
            // (escapeshellarg() quotet unter Windows mit doppelten
            // Anführungszeichen, die dieselben sind wie die des äußeren
            // `cmd /C "..."`-Wrappers) würde bei mehreren verketteten
            // curl-Versuchen unzuverlässig.
            $downloader = $curlPath !== null
                ? escapeshellarg($curlPath) . ' -s -X POST ' . escapeshellarg($workerUrl)
                : escapeshellarg((string) self::resolveBinary('wget')) . ' -q -O NUL ' . escapeshellarg($workerUrl);
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
                : escapeshellarg((string) self::resolveBinary('wget')) . ' -q -O /dev/null ' . escapeshellarg($workerUrl);
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
    private static function buildCurlSelfCallCommand(string $curlPath, string $server, string $workerUrl): string
    {
        $scheme = parse_url($server, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($server, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($server, PHP_URL_PORT) ?: ('https' === $scheme ? 443 : 80);
        $hostPort = $host . ':' . $port;

        $publicAttempt = escapeshellarg($curlPath) . ' -s --max-time 20 -X POST ' . escapeshellarg($workerUrl);
        $loopbackHttps = escapeshellarg($curlPath) . ' -sk --max-time 20 --connect-to '
            . escapeshellarg($hostPort . ':127.0.0.1:443') . ' -X POST ' . escapeshellarg($workerUrl);
        $loopbackHttp = escapeshellarg($curlPath) . ' -s --max-time 20 --connect-to '
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
        return (bool) self::backgroundRunnerDiagnostics()['available'];
    }

    /**
     * Liefert nicht nur ja/nein, sondern auch WARUM - ohne Shell-Zugriff auf
     * den echten Server ist das sonst von außen kaum zu diagnostizieren, wenn
     * die Hintergrundausführung unerwartet nicht greift (z.B. shell_exec()
     * grundsätzlich per disable_functions gesperrt, oder nur curl/wget fehlen).
     *
     * @return array{available: bool, reason: string}
     */
    private static function backgroundRunnerDiagnostics(): array
    {
        $isWindows = str_starts_with(PHP_OS, 'WIN');
        // Unix startet den Hintergrundprozess über shell_exec('... &'), Windows
        // (siehe handleStartBackground()) über popen()/pclose() mit "start /B" -
        // beide Wege brauchen ihre jeweilige Funktion tatsächlich freigeschaltet.
        $requiredFunctions = $isWindows ? ['popen', 'pclose'] : ['shell_exec'];
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach ($requiredFunctions as $function) {
            if (!function_exists($function)) {
                return ['available' => false, 'reason' => $function . '() ist auf diesem Server nicht kompiliert/verfügbar.'];
            }
            if (in_array($function, $disabled, true)) {
                return ['available' => false, 'reason' => $function . '() ist per php.ini disable_functions gesperrt.'];
            }
        }
        // resolveBinary() selbst braucht shell_exec(), auch im Windows-Zweig (für "where").
        if (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
            return ['available' => false, 'reason' => 'shell_exec() ist auf diesem Server nicht verfügbar/gesperrt.'];
        }

        if (self::resolveBinary('curl') !== null || self::resolveBinary('wget') !== null) {
            return ['available' => true, 'reason' => ''];
        }

        return ['available' => false, 'reason' => 'Weder curl noch wget auf dem Server gefunden (auch nicht unter den üblichen Standardpfaden).'];
    }

    /**
     * Liefert einen tatsächlich ausführbaren, absoluten Pfad statt eines
     * bloßen Bool - PHP-FPM-Pools laufen oft mit einer geleerten Umgebung
     * (kein $PATH), dann findet `command -v` ein tatsächlich installiertes
     * Programm gar nicht erst UND ein bloßer Programmname im shell_exec()-
     * Aufruf würde später ebenso fehlschlagen. Deshalb zusätzlich die
     * üblichen Installationspfade direkt per is_executable() prüfen und den
     * gefundenen absoluten Pfad zurückgeben, damit auch der eigentliche
     * curl/wget-Aufruf in handleStartBackground() PATH-unabhängig ist.
     */
    private static function resolveBinary(string $binary): ?string
    {
        $isWindows = str_starts_with(PHP_OS, 'WIN');
        // "command -v" ist ein Bash-Builtin und existiert unter Windows' cmd.exe
        // nicht - dortiges Äquivalent ist "where".
        $lookupCommand = $isWindows ? 'where ' . escapeshellarg($binary) : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
        $viaPath = trim((string) shell_exec($lookupCommand));
        if ($viaPath !== '') {
            // "where" kann bei mehreren Treffern mehrzeilig antworten - der erste reicht.
            // strtok() findet auf dem bereits getrimmten, nicht-leeren $viaPath beim ersten
            // Aufruf garantiert ein Token (es gibt keine \r\n mehr, an denen es scheitern könnte).
            return strtok($viaPath, "\r\n");
        }

        if ($isWindows) {
            // curl.exe liegt seit Windows 10 1803 i.d.R. in System32 und damit im
            // PATH - anders als unter Unix gibt es keinen sinnvollen, festen Satz
            // weiterer Fallback-Pfade, die sich lohnen würde zu raten.
            return null;
        }

        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/', '/bin/'] as $dir) {
            if (is_executable($dir . $binary)) {
                return $dir . $binary;
            }
        }

        return null;
    }
}
