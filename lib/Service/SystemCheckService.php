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

        if (class_exists(Parser::class)) {
            return ['label' => 'PDF-Textextraktion', 'status' => 'warning', 'message' => 'pdftotext (poppler-utils) nicht gefunden, Fallback auf die reine PHP-Bibliothek smalot/pdfparser aktiv - funktioniert, liefert aber bei komplexem Layout/Tabellen schlechtere Ergebnisse. poppler-utils installieren (z.B. "apt install poppler-utils") fuer bessere Qualitaet.'];
        }

        return ['label' => 'PDF-Textextraktion', 'status' => 'error', 'message' => 'Weder pdftotext noch die vendor/-Bibliothek smalot/pdfparser gefunden - PDF-Indexierung (global wie je Profil) liefert aktuell keinen Text.'];
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
            if (is_executable($dir . $binary)) {
                return $dir . $binary;
            }
        }

        return null;
    }
}
