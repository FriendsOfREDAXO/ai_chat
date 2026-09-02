<?php

use FriendsOfREDAXO\ECharts\ChartRenderer;

$addon = rex_addon::get('ai_chat');

$resetToken = rex_csrf_token::factory('ai_chat_stats_reset');
if (rex_request('reset_stats', 'string', '') !== '') {
    if (!$resetToken->isValid()) {
        echo rex_view::error('Die Sicherheitsprüfung für das Zurücksetzen der Statistik ist fehlgeschlagen. Bitte erneut versuchen.');
    } else {
        $sql = rex_sql::factory();
        $sql->setQuery('TRUNCATE TABLE ' . rex::getTable('ai_chat_stats'));
        echo rex_view::success('Die Statistik wurde zurückgesetzt.');
    }
}

$days = (int) rex_request('days', 'int', 30);
$periodOptions = [
    0 => 'Alle Daten',
    7 => '7 Tage',
    30 => '30 Tage',
    90 => '90 Tage',
];

$scopeLabels = [
    'frontend' => 'Frontend',
    'developer' => 'Developer',
];
$scopeModeLabels = [
    'frontend' => [
        'search' => 'Suchbegriffe',
        'chat' => 'Fragen / Chat',
    ],
    'developer' => [
        'chat' => 'Fragen / Chat',
    ],
];

$buildDateClause = static function (int $days): string {
    return $days > 0 ? ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)' : '';
};

$buildScopeQuery = static function (rex_sql $sql, string $scope, int $days, string $mode = '', bool $onlyNoResult = false): array {
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
         GROUP BY normalized_query
         ORDER BY total DESC',
        array_filter([
            'scope' => $scope,
            'mode' => $mode !== '' ? $mode : null,
        ], static fn ($value) => $value !== null)
    );
};

$sql = rex_sql::factory();
$topQueries = [];
$noResultQueries = [];
foreach ($scopeLabels as $scopeKey => $scopeName) {
    $modeLabels = $scopeModeLabels[$scopeKey] ?? ['chat' => 'Fragen / Chat'];
    $topQueries[$scopeKey] = [];
    $noResultQueries[$scopeKey] = [];

    foreach (array_keys($modeLabels) as $modeKey) {
        $topQueries[$scopeKey][$modeKey] = $buildScopeQuery($sql, $scopeKey, $days, $modeKey, false);
        $noResultQueries[$scopeKey][$modeKey] = $buildScopeQuery($sql, $scopeKey, $days, $modeKey, true);
    }
}

$scopeSummary = [];
foreach ($scopeLabels as $scopeKey => $scopeName) {
    $summaryRows = $sql->getArray(
        'SELECT COUNT(*) AS total
         FROM ' . rex::getTable('ai_chat_stats') . '
         WHERE scope = :scope
           AND status <> :started
           ' . ($days > 0 ? ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)' : ''),
        ['scope' => $scopeKey, 'started' => 'request_started']
    );
    $scopeSummary[$scopeKey] = (int) ($summaryRows[0]['total'] ?? 0);
}

$buildOverviewChartOptions = static function (array $scopeSummary, string $title): array {
    $labels = [];
    $values = [];
    foreach ($scopeSummary as $scopeKey => $value) {
        $labels[] = $scopeKey === 'frontend' ? 'Frontend' : 'Developer';
        $values[] = (int) $value;
    }

    return [
        'title' => ['text' => $title, 'left' => 'center', 'textStyle' => ['fontSize' => 14]],
        'tooltip' => ['trigger' => 'axis'],
        'grid' => ['left' => '10%', 'right' => '8%', 'bottom' => '18%', 'top' => '18%', 'containLabel' => true],
        'xAxis' => ['type' => 'category', 'data' => $labels],
        'yAxis' => ['type' => 'value', 'minInterval' => 1],
        'series' => [[
            'type' => 'bar',
            'data' => $values,
            'barMaxWidth' => 40,
            'itemStyle' => ['color' => '#4a90e2'],
            'label' => ['show' => true, 'position' => 'top'],
        ]],
    ];
};

$currentStatsPage = rex_url::backendPage('ai_chat/statistics');
$periodHtml = '<div class="pull-right" style="margin-bottom: 12px;">'
    . '<form id="klxmchat-stats-period-form" method="get" action="' . rex_escape($currentStatsPage) . '" class="form-inline" style="margin: 0;">'
    . '<label for="days" style="margin-right: 8px;">Zeitraum</label>'
    . '<select id="days" name="days" class="form-control input-sm">';
foreach ($periodOptions as $value => $label) {
    $selected = $days === (int) $value ? ' selected' : '';
    $periodHtml .= '<option value="' . (int) $value . '"' . $selected . '>' . rex_escape($label) . '</option>';
}
$periodHtml .= '</select>'
    . '</form>'
    . '</div>';

$resetHtml = '<div class="pull-right" style="margin: 0 10px 12px 0;">'
    . '<form method="post" style="margin: 0;">'
    . $resetToken->getHiddenField()
    . '<button type="submit" name="reset_stats" value="1" class="btn btn-danger btn-sm" onclick="return confirm(\'Die gesamte Statistik wirklich zurücksetzen?\');">Statistik zurücksetzen</button>'
    . '</form>'
    . '</div>';

echo '<div class="klxmchat-statistics-shell">';
echo '<div class="klxmchat-statistics-toolbar" style="margin-bottom: 12px;">' . $resetHtml . $periodHtml . '<div class="clearfix"></div></div>';

$panel = new rex_fragment();
$panel->setVar('title', 'Such- und Chat-Statistiken');

$scopeSummaryHtml = '<div class="klxmchat-statistics-summary">';
foreach ($scopeLabels as $scopeKey => $scopeName) {
    $scopeSummaryHtml .= '<div class="klxmchat-stat-card ' . rex_escape($scopeKey === 'developer' ? 'developer' : '') . '">'
        . '<div class="label">' . rex_escape($scopeName) . '</div>'
        . '<div class="value">' . (int) ($scopeSummary[$scopeKey] ?? 0) . '</div>'
        . '</div>';
}
$scopeSummaryHtml .= '</div>';

$body = $scopeSummaryHtml;
if (rex_addon::get('echarts')->isAvailable() && class_exists(ChartRenderer::class)) {
    $body .= '<div class="row" style="margin-bottom: 20px;">'
        . '<div class="col-md-12">' . ChartRenderer::render($buildOverviewChartOptions($scopeSummary, $days > 0 ? 'Gesamt pro Scope (' . $days . ' Tage)' : 'Gesamt pro Scope (alle Daten)'), 260) . '</div>'
        . '</div>';
}

$body .= '<div class="row" style="margin-top: 20px;">';
foreach ($scopeLabels as $scopeKey => $scopeName) {
    $modeLabels = $scopeModeLabels[$scopeKey] ?? ['chat' => 'Fragen / Chat'];
    $body .= '<div class="col-md-12" style="margin-bottom: 24px;">'
        . '<div class="klxmchat-stat-section">'
        . '<div class="section-head">' . rex_escape($scopeName) . '</div>'
        . '<div class="panel-body" style="padding: 18px 18px 8px;">'
        . '<div class="row">';

    foreach ($modeLabels as $modeKey => $modeName) {
        $topRows = $topQueries[$scopeKey][$modeKey] ?? [];
        $noResultRows = $noResultQueries[$scopeKey][$modeKey] ?? [];

        $body .= '<div class="col-md-6" style="margin-bottom: 18px;">'
            . '<div class="klxmchat-stat-card-panel">'
            . '<div class="panel-heading">' . rex_escape($modeName) . '</div>'
            . '<div class="panel-body">'
            . '<div style="margin-bottom: 14px;">'
            . '<div class="klxmchat-stat-list-label">Top Begriffe</div>'
            . '<table class="table table-striped table-condensed klxmchat-stat-table">'
            . '<thead><tr><th>Begriff</th><th>Anzahl</th></tr></thead>'
            . '<tbody>';

        if ($topRows === []) {
            $body .= '<tr><td colspan="2">Keine Einträge</td></tr>';
        } else {
            foreach ($topRows as $entry) {
                $query = trim((string) ($entry['query'] ?? ''));
                $count = (int) ($entry['total'] ?? 0);
                if ($query === '') {
                    continue;
                }
                $body .= '<tr><td>' . rex_escape($query) . '</td><td>' . $count . '</td></tr>';
            }
        }

        $body .= '</tbody></table>'
            . '</div>'
            . '<div>'
            . '<div class="klxmchat-stat-list-label">Ohne Ergebnis</div>'
            . '<table class="table table-striped table-condensed klxmchat-stat-table">'
            . '<thead><tr><th>Begriff</th><th>Anzahl</th></tr></thead>'
            . '<tbody>';

        if ($noResultRows === []) {
            $body .= '<tr><td colspan="2">Keine leeren Treffer</td></tr>';
        } else {
            foreach ($noResultRows as $entry) {
                $query = trim((string) ($entry['query'] ?? ''));
                $count = (int) ($entry['total'] ?? 0);
                if ($query === '') {
                    continue;
                }
                $body .= '<tr><td>' . rex_escape($query) . '</td><td>' . $count . '</td></tr>';
            }
        }

        $body .= '</tbody></table>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    $body .= '</div>'
        . '</div>'
        . '</div>'
        . '</div>';
}
$body .= '</div>';

$panel->setVar('body', $body, false);
echo $panel->parse('core/page/section.php');
echo '</div>';

$hasAnyStats = false;
foreach ($scopeLabels as $scopeKey => $scopeName) {
    foreach (array_keys($scopeModeLabels[$scopeKey] ?? ['chat' => 'Fragen / Chat']) as $modeKey) {
        if (($topQueries[$scopeKey][$modeKey] ?? []) !== []) {
            $hasAnyStats = true;
            break 2;
        }
    }
}

if (!$hasAnyStats) {
    echo '<div class="alert alert-info">Es wurden noch keine passenden Such- oder Chat-Daten für den gewählten Zeitraum erfasst.</div>';
}
