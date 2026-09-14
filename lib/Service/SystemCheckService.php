<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Service;

use FriendsOfRedaxo\AiChat\Db\VectorCapability;
use rex;
use rex_addon;
use Smalot\PdfParser\Parser;

/**
 * Buendelt Umgebungs-/Voraussetzungs-Checks an einer Stelle statt verstreut ueber mehrere
 * Seiten (Vektor-Status bislang nur auf der Indexierung-Seite, Hintergrund-Voraussetzungen
 * nur als Fehlermeldung beim Versuch) - Ziel ist eine einzige Uebersicht, an der ein Nutzer
 * schnell sieht, was auf seinem Server (nicht) verfuegbar ist, statt aus einem Fehlerbild
 * (z.B. https://github.com/FriendsOfREDAXO/ai_chat/issues/1) rueckwaerts zu debuggen.
 *
 * resolveBinary()/backgroundRunnerDiagnostics() lebten vorher nur in Api\ChatIndex - hierher
 * verschoben, damit sowohl die eigentliche Hintergrund-Indexierung als auch dieser Systemcheck
 * dieselbe, einzige Pruef-/Aufloesungslogik nutzen.
 *
 * @phpstan-type CheckResult array{label: string, status: 'ok'|'warning'|'error', message: string}
 */
final class SystemCheckService
{
    /**
     * @return list<CheckResult>
     */
    public static function runChecks(): array
    {
        return [
            self::checkPhpVersion(),
            self::checkRedaxoVersion(),
            self::checkPdfExtraction(),
            self::checkBackgroundIndexing(),
            self::checkNativeVectorRetrieval(),
            self::checkAiProvider(),
        ];
    }

    /**
     * @return CheckResult
     */
    private static function checkPhpVersion(): array
    {
        $required = '8.1.0';
        $current = PHP_VERSION;
        if (version_compare($current, $required, '>=')) {
            return ['label' => 'PHP-Version', 'status' => 'ok', 'message' => $current . ' (mindestens ' . $required . ' erforderlich)'];
        }

        return ['label' => 'PHP-Version', 'status' => 'error', 'message' => $current . ' ist zu alt, mindestens ' . $required . ' erforderlich.'];
    }

    /**
     * @return CheckResult
     */
    private static function checkRedaxoVersion(): array
    {
        $required = '5.20.0';
        $current = rex::getVersion();
        if (version_compare($current, $required, '>=')) {
            return ['label' => 'REDAXO-Version', 'status' => 'ok', 'message' => $current . ' (mindestens ' . $required . ' erforderlich)'];
        }

        return ['label' => 'REDAXO-Version', 'status' => 'error', 'message' => $current . ' ist zu alt, mindestens ' . $required . ' erforderlich.'];
    }

    /**
     * @return CheckResult
     */
    private static function checkPdfExtraction(): array
    {
        $pdftotext = self::resolveBinary('pdftotext');
        if (null !== $pdftotext) {
            return ['label' => 'PDF-Textextraktion', 'status' => 'ok', 'message' => 'pdftotext (poppler-utils) gefunden unter ' . $pdftotext . ' - beste Qualitaet, auch bei komplexem Layout.'];
        }

        if (self::hasPdfParserLibrary()) {
            return ['label' => 'PDF-Textextraktion', 'status' => 'warning', 'message' => 'pdftotext (poppler-utils) nicht gefunden, Fallback auf die reine PHP-Bibliothek smalot/pdfparser aktiv - funktioniert, liefert aber bei komplexem Layout/Tabellen schlechtere Ergebnisse. poppler-utils installieren (z.B. "apt install poppler-utils") fuer bessere Qualitaet.'];
        }

        return ['label' => 'PDF-Textextraktion', 'status' => 'error', 'message' => 'Weder pdftotext noch die vendor/-Bibliothek smalot/pdfparser gefunden - PDF-Indexierung (global wie je Profil) liefert aktuell keinen Text.'];
    }

    /**
     * Ob PDFs ueberhaupt zu Text verarbeitet werden koennen (pdftotext ODER die PHP-
     * Fallback-Bibliothek) - genutzt von pages/profiles.php, um die PDF-Auswahlfelder gar
     * nicht erst anzubieten, wenn PDF-Indexierung auf diesem Server ohnehin keinen Text
     * liefern wuerde (statt Nutzer PDFs waehlen zu lassen, die dann stumm leer indexiert
     * werden).
     */
    public static function isPdfExtractionAvailable(): bool
    {
        return null !== self::resolveBinary('pdftotext') || self::hasPdfParserLibrary();
    }

    private static function hasPdfParserLibrary(): bool
    {
        return class_exists(Parser::class);
    }

    /**
     * @return CheckResult
     */
    private static function checkBackgroundIndexing(): array
    {
        $diagnostics = self::backgroundRunnerDiagnostics();
        if ($diagnostics['available']) {
            return ['label' => 'Hintergrund-Indexierung', 'status' => 'ok', 'message' => 'shell_exec() und curl/wget verfuegbar - "Im Hintergrund indexieren" funktioniert.'];
        }

        return ['label' => 'Hintergrund-Indexierung', 'status' => 'warning', 'message' => $diagnostics['reason'] . ' Reindizierung laeuft dann nur synchron im Browser-Tab (Timeout-Risiko bei groesserem Index).'];
    }

    /**
     * @return CheckResult
     */
    private static function checkNativeVectorRetrieval(): array
    {
        if (VectorCapability::isSupported()) {
            $dimension = VectorCapability::trackedDimension();
            $version = VectorCapability::checkedVersion();

            return ['label' => 'Vektor-Retrieval', 'status' => 'ok', 'message' => 'Natives MariaDB-Vektor-Retrieval aktiv' . ($dimension ? ' (Dimension ' . $dimension . ')' : '') . ('' !== $version ? ', erkannte Datenbank: ' . $version : '') . '.'];
        }

        return ['label' => 'Vektor-Retrieval', 'status' => 'warning', 'message' => 'Kein natives Vektor-Retrieval erkannt (braucht MariaDB 11.7+/11.8+), Fallback auf PHP-Brute-Force-Berechnung - funktioniert immer, ist bei sehr grossem Index aber langsamer.'];
    }

    /**
     * @return CheckResult
     */
    private static function checkAiProvider(): array
    {
        $addon = rex_addon::get('ai_chat');
        $provider = (string) $addon->getConfig('provider', 'gemini');

        $missing = match ($provider) {
            'gemini' => empty($addon->getConfig('gemini_api_key')) ? 'Gemini API-Key fehlt.' : null,
            'cloudflare' => (empty($addon->getConfig('cloudflare_account_id')) || empty($addon->getConfig('cloudflare_api_token'))) ? 'Cloudflare Account-ID oder API-Token fehlt.' : null,
            'ai_platform' => empty($addon->getConfig('ai_platform_text_profile_id')) ? 'Kein Text-Profil im ai_platform-Addon ausgewaehlt.' : null,
            default => null, // openai: Base-URL optional (Default api.openai.com), API-Key je nach Gateway optional.
        };

        if (null !== $missing) {
            return ['label' => 'KI-Provider', 'status' => 'error', 'message' => 'Provider "' . $provider . '" konfiguriert, aber unvollstaendig: ' . $missing . ' Siehe AI Chat → Einstellungen → KI-Provider.'];
        }

        return ['label' => 'KI-Provider', 'status' => 'ok', 'message' => 'Provider "' . $provider . '" konfiguriert. Echte Erreichbarkeit prueft am zuverlaessigsten der "Verbindung testen"-Button unter AI Chat → Einstellungen → KI-Provider.'];
    }

    /**
     * Ob der Server die Voraussetzungen fuer den entkoppelten Hintergrund-Indexierungs-
     * Prozess erfuellt (shell_exec()/popen() fuer den eigentlichen Start, curl/wget fuer den
     * fire-and-forget-Aufruf an sich selbst) - z.B. auf Shared-Hosting grundsaetzlich per
     * disable_functions gesperrt, oder nur curl/wget fehlen.
     *
     * @return array{available: bool, reason: string}
     */
    public static function backgroundRunnerDiagnostics(): array
    {
        $isWindows = str_starts_with(PHP_OS, 'WIN');
        // Unix startet den Hintergrundprozess ueber shell_exec('... &'), Windows
        // ueber popen()/pclose() mit "start /B" - beide Wege brauchen ihre jeweilige
        // Funktion tatsaechlich freigeschaltet.
        $requiredFunctions = $isWindows ? ['popen', 'pclose'] : ['shell_exec'];
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach ($requiredFunctions as $function) {
            if (!function_exists($function)) {
                return ['available' => false, 'reason' => $function . '() ist auf diesem Server nicht kompiliert/verfuegbar.'];
            }
            if (in_array($function, $disabled, true)) {
                return ['available' => false, 'reason' => $function . '() ist per php.ini disable_functions gesperrt.'];
            }
        }
        // resolveBinary() selbst braucht shell_exec(), auch im Windows-Zweig (fuer "where").
        if (!function_exists('shell_exec') || in_array('shell_exec', $disabled, true)) {
            return ['available' => false, 'reason' => 'shell_exec() ist auf diesem Server nicht verfuegbar/gesperrt.'];
        }

        if (self::resolveBinary('curl') !== null || self::resolveBinary('wget') !== null) {
            return ['available' => true, 'reason' => ''];
        }

        return ['available' => false, 'reason' => 'Weder curl noch wget auf dem Server gefunden (auch nicht unter den ueblichen Standardpfaden).'];
    }

    /**
     * Analysiert, ob ein GESTARTETER Hintergrundlauf (Api\ReindexWorker) auch bei laenger
     * dauernden Indexierungen (viele Seiten, langsamer Embedding-Provider) tatsaechlich bis
     * zum Ende durchlaufen kann, statt nur ob er ueberhaupt STARTEN kann (siehe
     * backgroundRunnerDiagnostics() dafuer - komplementaer, kein Ersatz). Der Worker setzt
     * `@set_time_limit(0)`, das schuetzt aber NUR vor PHPs eigenem `max_execution_time` -
     * ein PHP-FPM-Pool mit gesetztem `request_terminate_timeout` (php-fpm "www.conf",
     * typischer Default z.B. 60s je nach Distribution/Hoster) killt den Kindprozess trotzdem
     * nach Ablauf dieser Zeit, UNABHAENGIG von set_time_limit() - dieser Wert ist von PHP aus
     * grundsaetzlich nicht auslesbar (reine FPM-Pool-Konfiguration, kein ini_get()-Wert), die
     * Analyse kann deshalb nur die tatsaechlich sichtbaren Vorbedingungen pruefen und auf das
     * blinde-Fleck-Risiko klar hinweisen, statt einen falschen "sicher"-Eindruck zu erwecken.
     *
     * @return list<array{label: string, status: 'ok'|'warning'|'error', message: string}>
     */
    public static function analyzeBackgroundReliability(): array
    {
        $findings = [];

        $sapi = PHP_SAPI;
        $isFpm = str_contains($sapi, 'fpm');
        if ($isFpm) {
            $findings[] = [
                'label' => 'PHP-SAPI',
                'status' => 'warning',
                'message' => 'PHP läuft als "' . $sapi . '" (PHP-FPM). FPM-Pools haben typischerweise ein eigenes Zeitlimit ("request_terminate_timeout" in der jeweiligen Pool-Konfiguration, z.B. www.conf/Plesk-PHP-Einstellungen), das den Hintergrund-Worker-Prozess nach Ablauf hart beendet - UNABHÄNGIG von set_time_limit(0), das dieser Worker bereits setzt. Dieser Wert ist von PHP aus nicht auslesbar, daher kann dieser Check nur darauf hinweisen: bei häufig abbrechenden Läufen beim Hoster/Admin nach "request_terminate_timeout" (oder gleichbedeutender Einstellung im Hosting-Panel) fragen und ggf. erhöhen, oder stattdessen den inkrementellen Modus mit einem Cronjob nutzen (mehrere kurze Läufe statt eines langen).',
            ];
        } else {
            $findings[] = [
                'label' => 'PHP-SAPI',
                'status' => 'ok',
                'message' => 'PHP läuft als "' . $sapi . '" - kein FPM-Pool-Zeitlimit zu erwarten, das set_time_limit(0) unabhängig aushebeln würde.',
            ];
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('set_time_limit') || in_array('set_time_limit', $disabled, true)) {
            $findings[] = [
                'label' => 'set_time_limit()',
                'status' => 'error',
                'message' => 'set_time_limit() ist auf diesem Server gesperrt (disable_functions) oder nicht kompiliert - der Hintergrund-Worker kann PHPs eigenes max_execution_time dadurch NICHT aufheben. Aktuelles max_execution_time: ' . self::formatExecutionTimeLimit() . '. Läuft die Indexierung länger als dieser Wert, bricht sie an dieser Stelle sicher ab.',
            ];
        } else {
            $findings[] = [
                'label' => 'set_time_limit()',
                'status' => 'ok',
                'message' => 'set_time_limit() ist verfügbar, der Worker kann PHPs eigenes max_execution_time (aktuell konfiguriert: ' . self::formatExecutionTimeLimit() . ') für sich selbst aufheben.',
            ];
        }

        if (in_array('ignore_user_abort', $disabled, true)) {
            $findings[] = [
                'label' => 'ignore_user_abort()',
                'status' => 'warning',
                'message' => 'ignore_user_abort() ist gesperrt (disable_functions) - der Worker kann dadurch nicht garantieren, unabhängig von der (bewusst kurz gehaltenen) curl/wget-Verbindung weiterzulaufen. In der Praxis meist unkritisch, da die meisten SAPIs den Prozess ohnehin nicht wegen einer geschlossenen Verbindung beenden, aber nicht auf allen Setups garantiert.',
            ];
        }

        $backgroundRunner = self::backgroundRunnerDiagnostics();
        if (!$backgroundRunner['available']) {
            $findings[] = [
                'label' => 'Hintergrundlauf-Start',
                'status' => 'error',
                'message' => 'Ein Hintergrundlauf kann auf diesem Server nicht einmal GESTARTET werden: ' . $backgroundRunner['reason'] . ' Die folgenden Laufzeit-Einschätzungen sind damit hinfällig - Indexierung ist hier nur im Browser-Tab (Vordergrund) möglich.',
            ];
        }

        $findings[] = [
            'label' => 'Empfehlung bei Unsicherheit',
            'status' => 'ok',
            'message' => 'Da das FPM-Zeitlimit nicht zuverlässig erkennbar ist: bei einem großen Index (viele hundert/tausend Seiten) oder einem spürbar langsamen KI-Provider ist der inkrementelle Modus (Einstellungen → Quellen, kombiniert mit einem Cronjob) robuster als ein einzelner langer Lauf - mehrere kurze Aufrufe statt eines, der potenziell an einem unsichtbaren Zeitlimit hängen bleibt. Ein abgebrochener Lauf wird zudem spätestens nach 10 Minuten ohne Fortschritts-Update automatisch als fehlgeschlagen erkannt (siehe IndexRunStore), blockiert also keinen künftigen Start dauerhaft.',
        ];

        return $findings;
    }

    private static function formatExecutionTimeLimit(): string
    {
        $value = (int) ini_get('max_execution_time');

        return 0 === $value ? 'unbegrenzt (0)' : $value . 's';
    }

    /**
     * Liefert einen tatsaechlich ausfuehrbaren, absoluten Pfad statt eines bloszen Bool -
     * PHP-FPM-Pools laufen oft mit einer geleerten Umgebung (kein $PATH), dann findet
     * `command -v` ein tatsaechlich installiertes Programm gar nicht erst UND ein blosser
     * Programmname im shell_exec()-Aufruf wuerde spaeter ebenso fehlschlagen. Deshalb
     * zusaetzlich die ueblichen Installationspfade direkt per is_executable() pruefen.
     */
    public static function resolveBinary(string $binary): ?string
    {
        $isWindows = str_starts_with(PHP_OS, 'WIN');
        // "command -v" ist ein Bash-Builtin und existiert unter Windows' cmd.exe
        // nicht - dortiges Aequivalent ist "where".
        $lookupCommand = $isWindows ? 'where ' . escapeshellarg($binary) : 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
        $viaPath = trim((string) shell_exec($lookupCommand));
        if ($viaPath !== '') {
            // "where" kann bei mehreren Treffern mehrzeilig antworten - der erste reicht.
            // strtok() findet auf dem bereits getrimmten, nicht-leeren $viaPath beim ersten
            // Aufruf garantiert ein Token (es gibt keine \r\n mehr, an denen es scheitern koennte).
            return strtok($viaPath, "\r\n");
        }

        if ($isWindows) {
            // curl.exe liegt seit Windows 10 1803 i.d.R. in System32 und damit im
            // PATH - anders als unter Unix gibt es keinen sinnvollen, festen Satz
            // weiterer Fallback-Pfade, der sich zu raten lohnen wuerde.
            return null;
        }

        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/', '/bin/'] as $dir) {
            $path = $dir . $binary;
            // is_executable() auf einem Pfad ausserhalb von open_basedir wirft eine PHP-
            // Warnung (sichtbar in Logs/je nach display_errors auch im Frontend), auf
            // vielen Shared-Hosting-Umgebungen (z.B. open_basedir nur auf das eigene
            // Webroot/tmp beschraenkt) liegen "/usr/bin/"&co. grundsaetzlich ausserhalb -
            // hier vorab prüfen statt die Warnung einfach in Kauf zu nehmen.
            if (!self::isWithinOpenBasedir($path)) {
                continue;
            }
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * true wenn open_basedir nicht gesetzt ist, oder $path innerhalb mindestens eines der
     * konfigurierten open_basedir-Pfade liegt (Praefix-Vergleich auf dem jeweiligen
     * Verzeichnis, kein Dateisystem-Zugriff - reine String-Prüfung, damit sie vor einem
     * riskanten is_executable()-Aufruf ausserhalb der erlaubten Pfade greift).
     */
    private static function isWithinOpenBasedir(string $path): bool
    {
        $openBasedir = trim((string) ini_get('open_basedir'));
        if ('' === $openBasedir) {
            return true;
        }

        $separator = str_starts_with(PHP_OS, 'WIN') ? ';' : ':';
        foreach (explode($separator, $openBasedir) as $allowed) {
            $allowed = trim($allowed);
            if ('' === $allowed) {
                continue;
            }
            if (str_starts_with($path, rtrim($allowed, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}
