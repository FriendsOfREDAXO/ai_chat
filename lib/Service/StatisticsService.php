<?php

namespace FriendsOfRedaxo\AiChat\Service;

use rex;
use rex_csrf_token;
use rex_sql;
use rex_url;

class StatisticsService
{
    /**
     * @return array<int, string>
     */
    public function getPeriodOptions(): array
    {
        return [
            0 => 'Alle Daten',
            7 => '7 Tage',
            30 => '30 Tage',
            90 => '90 Tage',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getScopeLabels(): array
    {
        return [
            'frontend' => 'Frontend',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getScopeModeLabels(): array
    {
        return [
            'frontend' => [
                'search' => 'Suchbegriffe',
                'chat' => 'Fragen / Chat',
            ],
        ];
    }

    public function getCurrentStatsPageUrl(): string
    {
        return rex_url::backendPage('ai_chat/statistics');
    }

    /**
     * Setzt die komplette Statistik zurueck (TRUNCATE) - nur nach gueltigem CSRF-Token,
     * damit ein verlinktes/eingebettetes Bild o.ae. nicht unbemerkt einen Reset ausloesen
     * kann (frueher nur in pages/statistics.php selbst geprueft, diese Methode war bislang
     * ungenutzt und haette den Schutz umgangen).
     */
    public function resetStats(rex_csrf_token $token): bool
    {
        if (!$token->isValid()) {
            return false;
        }

        $sql = rex_sql::factory();
        $sql->setQuery('TRUNCATE TABLE ' . rex::getTable('ai_chat_stats'));

        return true;
    }

    /**
     * @return array{
     *     days:int,
     *     periodOptions: array<int, string>,
     *     scopeLabels: array<string, string>,
     *     scopeModeLabels: array<string, array<string, string>>,
     *     topQueries: array<string, array<string, array<int, array<string, mixed>>>>,
     *     noResultQueries: array<string, array<string, array<int, array<string, mixed>>>>,
     *     scopeSummary: array<string, int>,
     *     profileSummaryRows: array<int, array<string, mixed>>,
     *     hasAnyStats: bool,
     *     currentStatsPage: string
     * }
     */
    public function buildDashboard(int $days, ?string $profileFilterRaw = null): array
    {
        $scopeLabels = $this->getScopeLabels();
        $scopeModeLabels = $this->getScopeModeLabels();
        $sql = rex_sql::factory();

        // '' = alle Profile, '0' = explizit "kein Profil" (globaler Fallback ohne
        // aufgeloestes Profil, siehe ChatQueryService::process()), sonst eine echte
        // Profil-ID. Zeilen von vor der Einfuehrung dieser Spalte haben ebenfalls
        // profile_id NULL und landen damit unter "0".
        $profileClause = '';
        $profileParam = null;
        if ('0' === $profileFilterRaw) {
            $profileClause = ' AND profile_id IS NULL';
        } elseif (null !== $profileFilterRaw && '' !== $profileFilterRaw) {
            $profileClause = ' AND profile_id = :profile_id';
            $profileParam = (int) $profileFilterRaw;
        }

        $topQueries = [];
        $noResultQueries = [];
        foreach ($scopeLabels as $scopeKey => $scopeName) {
            $modeLabels = $scopeModeLabels[$scopeKey] ?? ['chat' => 'Fragen / Chat'];
            $topQueries[$scopeKey] = [];
            $noResultQueries[$scopeKey] = [];

            foreach (array_keys($modeLabels) as $modeKey) {
                $topQueries[$scopeKey][$modeKey] = $this->buildScopeQuery($sql, $scopeKey, $days, $modeKey, false, $profileClause, $profileParam);
                $noResultQueries[$scopeKey][$modeKey] = $this->buildScopeQuery($sql, $scopeKey, $days, $modeKey, true, $profileClause, $profileParam);
            }
        }

        $scopeSummary = [];
        foreach ($scopeLabels as $scopeKey => $scopeName) {
            $summaryRows = $sql->getArray(
                'SELECT COUNT(*) AS total
                 FROM ' . rex::getTable('ai_chat_stats') . '
                 WHERE scope = :scope
                   AND status <> :started
                   ' . ($days > 0 ? ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)' : '') . '
                   ' . $profileClause,
                array_filter([
                    'scope' => $scopeKey,
                    'started' => 'request_started',
                    'profile_id' => $profileParam,
                ], static fn ($value) => $value !== null)
            );
            $scopeSummary[$scopeKey] = (int) ($summaryRows[0]['total'] ?? 0);
        }

        // Immer ALLE Profile, unabhaengig vom oben gewaehlten Profil-Filter (der schraenkt
        // nur die Detail-Tabellen ein) - diese Uebersicht soll auf einen Blick zeigen, wie
        // sich die Nutzung ueberhaupt auf die Profile verteilt.
        $profileSummaryRows = $sql->getArray(
            'SELECT profile_id, COUNT(*) AS total
             FROM ' . rex::getTable('ai_chat_stats') . '
             WHERE status <> :started
               ' . ($days > 0 ? ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)' : '') . '
             GROUP BY profile_id
             ORDER BY total DESC',
            ['started' => 'request_started']
        );

        $hasAnyStats = false;
        foreach ($scopeLabels as $scopeKey => $scopeName) {
            foreach (array_keys($scopeModeLabels[$scopeKey] ?? ['chat' => 'Fragen / Chat']) as $modeKey) {
                if (($topQueries[$scopeKey][$modeKey] ?? []) !== []) {
                    $hasAnyStats = true;
                    break 2;
                }
            }
        }

        return [
            'days' => $days,
            'periodOptions' => $this->getPeriodOptions(),
            'scopeLabels' => $scopeLabels,
            'scopeModeLabels' => $scopeModeLabels,
            'topQueries' => $topQueries,
            'noResultQueries' => $noResultQueries,
            'scopeSummary' => $scopeSummary,
            'profileSummaryRows' => $profileSummaryRows,
            'hasAnyStats' => $hasAnyStats,
            'currentStatsPage' => $this->getCurrentStatsPageUrl(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildScopeQuery(rex_sql $sql, string $scope, int $days, string $mode, bool $onlyNoResult, string $profileClause, ?int $profileParam): array
    {
        $statusClause = $onlyNoResult
            ? "AND status IN ('search_no_result', 'chat_no_answer')"
            : "AND status NOT IN ('request_started')";

        $modeClause = $mode !== '' ? "AND mode = :mode" : '';
        $dateClause = $days > 0 ? ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)' : '';

        return $sql->getArray(
            'SELECT normalized_query AS query, COUNT(*) AS total
             FROM ' . rex::getTable('ai_chat_stats') . '
             WHERE scope = :scope
               ' . $dateClause . '
               AND COALESCE(normalized_query, \'\') <> \'\'
               ' . $statusClause . '
               ' . $modeClause . '
               ' . $profileClause . '
             GROUP BY normalized_query
             ORDER BY total DESC',
            array_filter([
                'scope' => $scope,
                'mode' => $mode !== '' ? $mode : null,
                'profile_id' => $profileParam,
            ], static fn ($value) => $value !== null)
        );
    }
}
