<?php

use FriendsOfREDAXO\ECharts\ChartRenderer;
use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;
use FriendsOfRedaxo\AiChat\Service\StatisticsService;

$addon = rex_addon::get('ai_chat');
$statisticsService = new StatisticsService();

// Der Systemcheck (Server-/Voraussetzungs-Diagnose) lebt jetzt unter Einstellungen ->
// Systemcheck statt hier - eine Diagnose-Frage ("laeuft die Umgebung korrekt?"), keine
// Nutzungsauswertung. Diese Seite zeigt seitdem ausschliesslich die Nutzungsstatistik.

$resetToken = rex_csrf_token::factory('ai_chat_stats_reset');
if (rex_request('reset_stats', 'string', '') !== '') {
    if ($statisticsService->resetStats($resetToken)) {
        echo rex_view::success('Die Statistik wurde zurückgesetzt.');
    } else {
        echo rex_view::error('Die Sicherheitsprüfung für das Zurücksetzen der Statistik ist fehlgeschlagen. Bitte erneut versuchen.');
    }
}

$retentionToken = rex_csrf_token::factory('ai_chat_stats_retention');
if (rex_request('save_retention', 'string', '') !== '') {
    if (!$retentionToken->isValid()) {
        echo rex_view::error('Die Sicherheitsprüfung für das Speichern der Aufbewahrungsdauer ist fehlgeschlagen. Bitte erneut versuchen.');
    } else {
        $retentionDays = max(1, rex_request('stats_retention_days', 'int', 90));
        $addon->setConfig('stats_retention_days', $retentionDays);
        echo rex_view::success('Aufbewahrungsdauer gespeichert.');
    }
}

$days = (int) rex_request('days', 'int', 30);
$profileFilterRaw = rex_request('profile', 'string', '');

$allProfiles = (new ProfileRepository())->getAll();
$profileNamesById = [];
foreach ($allProfiles as $profileEntry) {
    $profileNamesById[$profileEntry->id] = $profileEntry->name;
}

$dashboard = $statisticsService->buildDashboard($days, $profileFilterRaw);
$periodOptions = $dashboard['periodOptions'];
$scopeLabels = $dashboard['scopeLabels'];
$scopeModeLabels = $dashboard['scopeModeLabels'];
$topQueries = $dashboard['topQueries'];
$noResultQueries = $dashboard['noResultQueries'];
$scopeSummary = $dashboard['scopeSummary'];
$profileSummaryRows = $dashboard['profileSummaryRows'];
$hasAnyStats = $dashboard['hasAnyStats'];

$buildOverviewChartOptions = static function (array $scopeSummary, string $title): array {
    $labels = [];
    $values = [];
    foreach ($scopeSummary as $scopeKey => $value) {
        $labels[] = 'Frontend';
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

// Bewusst OHNE rex_escape() um die rex_url-Ausgabe: rex_url::backendPage()/
// currentBackendPage() escapen den "&"-Trenner zwischen mehreren Params bereits
// selbst (3. Parameter $escape, Standard true) - ein zusaetzliches rex_escape()
// wuerde bei mehr als einem Param zu "&amp;amp;" doppelt escapen.
//
// GET-Formulare verwerfen beim Absenden die komplette Query-String aus "action" und
// ersetzen sie NUR durch die eigenen Formularfelder - ohne das explizite Hidden-Feld
// "page" unten wuerde "?page=ai_chat/statistics" aus der action-URL beim Submit
// verloren gehen und REDAXO mangels erkanntem "page"-Parameter auf der
// Standardseite (Struktur) landen, statt auf dieser Seite zu bleiben.
$currentStatsPage = $dashboard['currentStatsPage'];
$periodHtml = '<form id="klxmchat-stats-period-form" method="get" action="' . $currentStatsPage . '" class="form-inline" style="display:inline-block; margin:0; vertical-align:top;">'
    . '<input type="hidden" name="page" value="ai_chat/statistics">'
    . '<div class="form-group" style="margin-right:15px;"><label for="profile" style="margin-right:6px;">Profil</label>'
    . '<select id="profile" name="profile" class="form-control selectpicker" data-width="auto" data-style="btn-default btn-sm" onchange="this.form.submit()">'
    . '<option value=""' . ('' === $profileFilterRaw ? ' selected' : '') . '>Alle Profile</option>'
    . '<option value="0"' . ('0' === $profileFilterRaw ? ' selected' : '') . '>Kein Profil (global)</option>';
foreach ($allProfiles as $profileEntry) {
    $selected = $profileFilterRaw === (string) $profileEntry->id ? ' selected' : '';
    $periodHtml .= '<option value="' . $profileEntry->id . '"' . $selected . '>' . rex_escape($profileEntry->name) . '</option>';
}
$periodHtml .= '</select></div>'
    . '<div class="form-group"><label for="days" style="margin-right:6px;">Zeitraum</label>'
    . '<select id="days" name="days" class="form-control selectpicker" data-width="auto" data-style="btn-default btn-sm" onchange="this.form.submit()">';
foreach ($periodOptions as $value => $label) {
    $selected = $days === (int) $value ? ' selected' : '';
    $periodHtml .= '<option value="' . (int) $value . '"' . $selected . '>' . rex_escape($label) . '</option>';
}
$periodHtml .= '</select></div>'
    . '</form>';

$resetHtml = '<form method="post" style="display:inline-block; margin:0 0 0 15px; vertical-align:top;">'
    . $resetToken->getHiddenField()
    . '<button type="submit" name="reset_stats" value="1" class="btn btn-danger btn-sm" onclick="return confirm(\'Die gesamte Statistik wirklich zurücksetzen?\');">Statistik zurücksetzen</button>'
    . '</form>';

$currentRetentionDays = max(1, (int) $addon->getConfig('stats_retention_days', 90));
$retentionHtml = '<form method="post" class="form-inline" style="display:inline-block; margin:0 0 0 15px; vertical-align:top;">'
    . $retentionToken->getHiddenField()
    . '<label for="stats_retention_days" style="margin-right:6px;" title="Statistik-Einträge werden automatisch gelöscht, sobald sie älter als diese Anzahl Tage sind.">Aufbewahrung (Tage)</label>'
    . '<input type="number" id="stats_retention_days" name="stats_retention_days" class="form-control input-sm" style="width:80px;display:inline-block;" min="1" value="' . $currentRetentionDays . '">'
    . ' <button type="submit" name="save_retention" value="1" class="btn btn-default btn-sm">Speichern</button>'
    . '</form>';

echo '<div class="klxmchat-statistics-shell">';
echo '<div class="klxmchat-statistics-toolbar">' . $periodHtml . $resetHtml . $retentionHtml . '</div>';

$panel = new rex_fragment();
$panel->setVar('title', 'Such- und Chat-Statistiken');

$scopeSummaryHtml = '<div class="klxmchat-statistics-summary">';
foreach ($scopeLabels as $scopeKey => $scopeName) {
    $scopeSummaryHtml .= '<div class="klxmchat-stat-card">'
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
        $topRows = $topQueries[$scopeKey][$modeKey];
        $noResultRows = $noResultQueries[$scopeKey][$modeKey];

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

if (!$hasAnyStats) {
    echo '<div class="alert alert-info">Es wurden noch keine passenden Such- oder Chat-Daten für den gewählten Zeitraum erfasst.</div>';
}
