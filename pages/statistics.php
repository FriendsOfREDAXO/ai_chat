<?php

use FriendsOfREDAXO\ECharts\ChartRenderer;
use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;
use FriendsOfRedaxo\AiChat\Service\SystemCheckService;

$addon = rex_addon::get('ai_chat');

// Systemcheck und Nutzungsstatistik bewusst auf einer Seite statt verstreut (Vektor-Status
// vorher nur auf der Indexierung-Seite, Hintergrund-Voraussetzungen nur als Fehlermeldung
// beim Versuch) - eine Landing-Page, auf der sofort sichtbar ist, ob der Server die
// Voraussetzungen erfuellt UND wie das Addon tatsaechlich genutzt wird.
$statusBadgeClass = [
    'ok' => 'label-success',
    'warning' => 'label-warning',
    'error' => 'label-danger',
];
$statusLabel = [
    'ok' => 'OK',
    'warning' => 'Hinweis',
    'error' => 'Fehler',
];
$systemCheckHtml = '<div class="klxmchat-statistics-shell" style="margin-bottom: 20px;">';
$systemCheckPanel = new rex_fragment();
$systemCheckPanel->setVar('title', 'Systemcheck');
$systemCheckBody = '<table class="table table-striped klxmchat-stat-table">'
    . '<thead><tr><th style="width:180px;">Prüfung</th><th style="width:90px;">Status</th><th>Details</th></tr></thead><tbody>';
foreach (SystemCheckService::runChecks() as $check) {
    $systemCheckBody .= '<tr>'
        . '<td>' . rex_escape($check['label']) . '</td>'
        . '<td><span class="label ' . $statusBadgeClass[$check['status']] . '">' . $statusLabel[$check['status']] . '</span></td>'
        . '<td>' . rex_escape($check['message']) . '</td>'
        . '</tr>';
}
$systemCheckBody .= '</tbody></table>';
$systemCheckPanel->setVar('body', $systemCheckBody, false);
$systemCheckHtml .= $systemCheckPanel->parse('core/page/section.php');
$systemCheckHtml .= '</div>';
echo $systemCheckHtml;

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

// '' = alle Profile, '0' = explizit "kein Profil" (globaler Fallback ohne aufgeloestes
// Profil, siehe ChatQueryService::process()), sonst eine echte Profil-ID. Zeilen von vor
// der Einfuehrung dieser Spalte haben ebenfalls profile_id NULL und landen damit unter "0".
$profileFilterRaw = rex_request('profile', 'string', '');
$allProfiles = (new ProfileRepository())->getAll();
$profileNamesById = [];
foreach ($allProfiles as $profileEntry) {
    $profileNamesById[$profileEntry->id] = $profileEntry->name;
}

$profileClause = '';
$profileParam = null;
if ($profileFilterRaw === '0') {
    $profileClause = ' AND profile_id IS NULL';
} elseif ($profileFilterRaw !== '') {
    $profileClause = ' AND profile_id = :profile_id';
    $profileParam = (int) $profileFilterRaw;
}

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

$buildScopeQuery = static function (rex_sql $sql, string $scope, int $days, string $mode = '', bool $onlyNoResult = false) use ($profileClause, $profileParam): array {
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
};

$sql = rex_sql::factory();
$topQueries = [];
$noResultQueries = [];
foreach ($scopeLabels as $scopeKey => $scopeName) {
    $modeLabels = $scopeModeLabels[$scopeKey];
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

// Immer ALLE Profile, unabhaengig vom oben gewaehlten Profil-Filter (der schraenkt nur die
// Detail-Tabellen weiter unten ein) - diese Uebersicht soll auf einen Blick zeigen, wie sich
// die Nutzung ueberhaupt auf die Profile verteilt.
$profileSummaryRows = $sql->getArray(
    'SELECT profile_id, COUNT(*) AS total
     FROM ' . rex::getTable('ai_chat_stats') . '
     WHERE status <> :started
       ' . ($days > 0 ? ' AND created_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)' : '') . '
     GROUP BY profile_id
     ORDER BY total DESC',
    ['started' => 'request_started']
);

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
    . '<label for="profile" style="margin: 0 8px 0 0;">Profil</label>'
    . '<select id="profile" name="profile" class="form-control input-sm" onchange="this.form.submit()" style="margin-right: 16px;">'
    . '<option value=""' . ('' === $profileFilterRaw ? ' selected' : '') . '>Alle Profile</option>'
    . '<option value="0"' . ('0' === $profileFilterRaw ? ' selected' : '') . '>Kein Profil (global)</option>';
foreach ($allProfiles as $profileEntry) {
    $selected = $profileFilterRaw === (string) $profileEntry->id ? ' selected' : '';
    $periodHtml .= '<option value="' . $profileEntry->id . '"' . $selected . '>' . rex_escape($profileEntry->name) . '</option>';
}
$periodHtml .= '</select>'
    . '<label for="days" style="margin-right: 8px;">Zeitraum</label>'
    . '<select id="days" name="days" class="form-control input-sm" onchange="this.form.submit()">';
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
        . '<div class="value">' . (int) $scopeSummary[$scopeKey] . '</div>'
        . '</div>';
}
$scopeSummaryHtml .= '</div>';

$body = $scopeSummaryHtml;
if (rex_addon::get('echarts')->isAvailable() && class_exists(ChartRenderer::class)) {
    $body .= '<div class="row" style="margin-bottom: 20px;">'
        . '<div class="col-md-12">' . ChartRenderer::render($buildOverviewChartOptions($scopeSummary, $days > 0 ? 'Gesamt pro Scope (' . $days . ' Tage)' : 'Gesamt pro Scope (alle Daten)'), 260) . '</div>'
        . '</div>';
}

if (count($profileSummaryRows) > 1 || (count($profileSummaryRows) === 1 && null !== $profileSummaryRows[0]['profile_id'])) {
    $body .= '<div style="margin-bottom: 20px;">'
        . '<div class="klxmchat-stat-list-label">Anfragen je Profil' . ($days > 0 ? ' (' . $days . ' Tage)' : ' (alle Daten)') . '</div>'
        . '<table class="table table-striped table-condensed klxmchat-stat-table">'
        . '<thead><tr><th>Profil</th><th>Anzahl</th></tr></thead><tbody>';
    foreach ($profileSummaryRows as $row) {
        $rowProfileId = null !== $row['profile_id'] ? (int) $row['profile_id'] : null;
        $profileName = null === $rowProfileId
            ? 'Kein Profil (global)'
            : ($profileNamesById[$rowProfileId] ?? 'Gelöschtes Profil #' . $rowProfileId);
        $body .= '<tr><td>' . rex_escape($profileName) . '</td><td>' . (int) $row['total'] . '</td></tr>';
    }
    $body .= '</tbody></table></div>';
}

$body .= '<div class="row" style="margin-top: 20px;">';
foreach ($scopeLabels as $scopeKey => $scopeName) {
    $modeLabels = $scopeModeLabels[$scopeKey];
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
    foreach (array_keys($scopeModeLabels[$scopeKey]) as $modeKey) {
        if (($topQueries[$scopeKey][$modeKey] ?? []) !== []) {
            $hasAnyStats = true;
            break 2;
        }
    }
}

if (!$hasAnyStats) {
    echo '<div class="alert alert-info">Es wurden noch keine passenden Such- oder Chat-Daten für den gewählten Zeitraum erfasst.</div>';
}
