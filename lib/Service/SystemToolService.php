<?php

namespace FriendsOfRedaxo\AiChat\Service;

use rex;
use rex_addon;
use rex_article;
use rex_category;
use rex_clang;
use rex_dir;
use rex_file;
use rex_path;
use rex_sql;
use rex_version;

class SystemToolService
{
    /**
     * Returns a human-readable string of the current date, day of week and time.
     * Useful for AI context to answer questions about opening hours.
     */
    public static function getDateTimeContext(): string
    {
        $days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $now = time();
        $dayName = $days[date('w', $now)];
        $date = date('d.m.Y', $now);
        $time = date('H:i', $now);
        
        return "Aktuelles Datum: $dayName, der $date. Aktuelle Uhrzeit: $time Uhr.";
    }

    public static function getAddonListContext(): string
    {
        $addons = \rex_addon::getAvailableAddons();
        $list = [];
        foreach ($addons as $addon) {
            $list[] = $addon->getPackageId() . ' (v' . $addon->getVersion() . ')';
        }
        return implode(', ', $list);
    }

    public static function execute(string $command): string
    {
        // Parse command: ACTION_NAME,PARAM1=VAL1,PARAM2=VAL2
        $parts = explode(',', $command);
        $action = strtoupper(trim(array_shift($parts)));
        $params = [];
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $params[trim($kv[0])] = trim($kv[1]);
            }
        }

        switch ($action) {
            case 'CLEAR_CACHE':
                return self::clearCache();
            case 'SYSTEM_INFO':
                return self::getSystemInfo();
            case 'LIST_ADDONS':
                return self::listAddons();
            case 'REINDEX_CHAT':
                return self::reindexChat();
            case 'GET_LOGS':
                return self::getLogs();
            default:
                return "Unbekannte Aktion: " . $action;
        }
    }

    private static function getLogs(): string
    {
        $logFile = rex_path::coreData('error.log');
        if (!file_exists($logFile)) {
            return "Keine Log-Datei gefunden.";
        }

        $content = (string) rex_file::get($logFile);
        if ($content === '') {
            return "Keine Log-Einträge vorhanden.";
        }

        $lines = array_slice(explode("\n", trim($content)), -15);
        $output = "### Letzte 15 Log-Einträge\n```text\n" . implode("\n", $lines) . "\n```";
        return $output;
    }

    private static function clearCache(): string
    {
        if (rex::getUser()) {
            \rex_delete_cache();
            return "Der REDAXO System-Cache wurde erfolgreich gelöscht.";
        }
        return "Cache löschen ist nur für authentifizierte Backend-Nutzer erlaubt.";
    }

    private static function getSystemInfo(): string
    {
         $info = [
            'REDAXO Version' => rex::getVersion(),
            'PHP Version' => PHP_VERSION,
            'Memory Limit' => ini_get('memory_limit'),
            'Server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'Database' => 'MySQL ' . rex_sql::factory()->getArray('SELECT VERSION() as v')[0]['v']
        ];

        $output = "### System-Informationen\n";
        foreach ($info as $key => $value) {
            $output .= "- **$key**: $value\n";
        }
        return $output;
    }

    private static function listAddons(): string
    {
        $addons = rex_addon::getAvailableAddons();
        $output = "### Installierte Addons\n";
        foreach ($addons as $addon) {
            $output .= "- " . $addon->getName() . " (" . $addon->getVersion() . ")\n";
        }
        return $output;
    }

    private static function reindexChat(): string
    {
        $indexer = new IndexerService();
        $indexer->clearIndex();
        return "Der Chat-Index und Cache wurden geleert. Bitte starte eine neue Indizierung über die Addon-Einstellungen, um die Daten neu aufzubauen.";
    }
}
