<?php

$addon = rex_addon::get('ai_chat');

$providerRegistry = new FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderRegistry();
$allProviders = array_filter(
    $providerRegistry->getAll(),
    static fn ($provider): bool => $provider->isAvailable(),
);
$enabledProviderInstances = $providerRegistry->getEnabledProviders($addon);

$providerLabels = [];
$providerSourceTypeLabels = [];
foreach ($allProviders as $provider) {
    $providerLabels[$provider->getKey()] = $provider->getLabel();

    foreach ($provider->getSourceTypeLabels() as $sourceType => $label) {
        $sourceType = trim((string) $sourceType);
        $label = trim((string) $label);
        if ($sourceType === '' || $label === '') {
            continue;
        }

        $providerSourceTypeLabels[$sourceType] = $label;
    }
}

$enabledProviders = [];
$providersConfig = $addon->getConfig('index_content_providers');
if (is_array($providersConfig)) {
    $enabledProviders = array_values(array_filter(array_map('strval', $providersConfig)));
} elseif (is_string($providersConfig) && trim($providersConfig) !== '') {
    $normalized = trim($providersConfig, "\"'");
    if (str_contains($normalized, '|')) {
        $enabledProviders = array_values(array_filter(explode('|', $normalized)));
    } else {
        $parts = preg_split('/[\s,]+/', $normalized);
        $enabledProviders = array_values(array_filter(array_map('strval', is_array($parts) ? $parts : [])));
    }
}

if ($enabledProviderInstances !== []) {
    $enabledProviders = array_map(
        static fn ($provider): string => $provider->getKey(),
        $enabledProviderInstances,
    );
}

$sourceTypeLabels = [
    'sitemap_url' => 'Seiten',
    'article' => 'Artikel',
    'forcal_entry' => 'forcal Termine',
    'addon_docs' => 'AddOn Dokumentation',
    'github_docs' => 'GitHub Dokumentation',
];

foreach ($providerSourceTypeLabels as $sourceType => $label) {
    $sourceTypeLabels[$sourceType] = $label;
}

$rawConfiguredLabels = (string) $addon->getConfig('search_source_type_labels', '');
if ($rawConfiguredLabels !== '') {
    $lines = preg_split('/\r\n|\r|\n/', $rawConfiguredLabels);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $entry = trim($line);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            $parts = explode('=', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if ($key !== '' && $value !== '') {
                $sourceTypeLabels[$key] = $value;
            }
        }
    }
}

// (JS wird global über boot.php geladen und per rex:ready initialisiert)

// Config for JS – injected via data attribute (no inline JS)
$jsConfig = json_encode([
    'apiBase'           => 'index.php?rex-api-call=ai_chat_index',
    'indexSource'       => (string) $addon->getConfig('index_source', 'structure'),
    'confirmClear'      => $addon->i18n('index_clear_confirm'),
    'confirmClearCache' => $addon->i18n('index_clear_cache_confirm'),
    'confirmWarmCache'  => $addon->i18n('index_warm_cache_confirm'),
    'btnRetry'          => $addon->i18n('index_btn_retry'),
    'reloadHint'        => $addon->i18n('index_reload_hint'),
    'errorLog'          => $addon->i18n('index_error_log'),
    'errorLogPartial'   => $addon->i18n('index_error_log_partial'),
    'refreshRunning'    => $addon->i18n('index_refresh_running'),
    'refreshDone'       => $addon->i18n('index_refresh_done'),
    'warmCacheRunning'  => $addon->i18n('index_warm_cache_running'),
    'warmCacheDone'     => $addon->i18n('index_warm_cache_done'),
    'backgroundButtonChecking'    => $addon->i18n('index_background_button_notice'),
    'backgroundButtonAvailable'   => $addon->i18n('index_background_button_available'),
    'backgroundButtonUnavailable' => $addon->i18n('index_background_button_unavailable'),
    'statusIdle'              => $addon->i18n('index_status_idle'),
    'statusRunningForeground' => $addon->i18n('index_status_running_foreground'),
    'statusRunningBackground' => $addon->i18n('index_status_running_background'),
    'statusDone'              => $addon->i18n('index_status_done'),
    'statusDoneWithErrors'    => $addon->i18n('index_status_done_with_errors'),
    'statusCancelled'         => $addon->i18n('index_status_cancelled'),
    'statusError'             => $addon->i18n('index_status_error'),
    'enabledProviders'  => $enabledProviders,
    'providerLabels'    => $providerLabels,
    'sourceTypeLabels'  => $sourceTypeLabels,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

// Animierter Kopfbereich statt der bisherigen nackten Titelzeile - Technik 1:1
// vom cke5-Addon-Header uebernommen (Aurora-Blobs + Scan-Lichtstrahl, reines
// CSS/@keyframes, siehe assets/ai-chat-indexing-backend.css), inhaltlich aber
// auf "Indexierung/Scannen von Inhalten" uebertragen statt CKEditor-Branding.
// Traegt den Seitentitel selbst, die umschliessende rex_fragment bekommt
// deshalb bewusst KEINEN eigenen Titel mehr (sonst stuende "Indexierung"
// doppelt da).
$content = '
<div class="ai-chat-index-header">
    <div class="ai-chat-index-scan"></div>
    <div class="header-icon">
        <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <rect x="9" y="7" width="34" height="44" rx="4" fill="#38bdf8" fill-opacity="0.16" stroke="#38bdf8" stroke-width="2"/>
            <line x1="16" y1="19" x2="36" y2="19" stroke="#e8f7f2" stroke-width="2" stroke-linecap="round"/>
            <line x1="16" y1="27" x2="36" y2="27" stroke="#e8f7f2" stroke-width="2" stroke-linecap="round" opacity="0.7"/>
            <line x1="16" y1="35" x2="29" y2="35" stroke="#e8f7f2" stroke-width="2" stroke-linecap="round" opacity="0.5"/>
            <circle cx="43" cy="43" r="12" fill="#0a1e2e" stroke="#21a366" stroke-width="3"/>
            <line x1="52" y1="52" x2="59" y2="59" stroke="#21a366" stroke-width="4" stroke-linecap="round"/>
        </svg>
    </div>
    <div class="header-content">
        <h1>' . rex_escape($addon->i18n('index_title')) . '</h1>
        <div class="header-subtitle">' . rex_escape($addon->i18n('index_description')) . '</div>
    </div>
</div>';

// Hidden config element – read by ai-chat-indexer.js via data attribute
$content .= '<div id="ai-chat-indexer-config" data-config=\'' . $jsConfig . '\' style="display:none"></div>';

// Show current status
$sql = rex_sql::factory();
$sql->setQuery('SELECT source_type, COUNT(*) as count FROM ' . rex::getTable('ai_chat_index') . ' GROUP BY source_type');
$stats = [];
$total = 0;
foreach ($sql as $row) {
    $type = (string) $row->getValue('source_type');
    $count = (int) $row->getValue('count');
    $stats[$type] = $count;
    $total += $count;
}

$content .= rex_view::info($addon->i18n('index_provider_hint'));

// Zentrale Statusanzeige: EIN Element, das den aktuellen Lauf-Zustand eindeutig
// zeigt (Bereit/Läuft im Browser/Läuft im Hintergrund/Fertig/Fehler/Abgebrochen),
// über Bootstrap-"label"-Klassen theme-/dark-mode-kompatibel statt fest codierter
// Farben. Ersetzt die bisherige, rein textuelle Statuszeile als primäres Signal.
$content .= '<div class="klxm-index-statusbar">'
    . '<span id="ai-chat-status-badge" class="label label-default"><i class="rex-icon fa-circle-o"></i> ' . $addon->i18n('index_status_idle') . '</span>'
    . ' <strong><span id="ai-chat-index-count">' . $total . '</span></strong> ' . $addon->i18n('index_current_size')
    . ' <i class="rex-icon fa-question-circle text-muted" title="' . rex_escape($addon->i18n('index_current_size_hint')) . '"></i>'
    . '</div>';

// Warnt, wenn das RAG-Kandidatenfenster kleiner ist als der Index – dann werden beim
// Ähnlichkeitsvergleich nicht alle Inhalte berücksichtigt (führt zu unpassenden Antworten/Links).
// Gilt NUR fuer BruteForceRetrieval (ORDER BY id ASC LIMIT vor der eigentlichen Aehnlichkeits-
// berechnung - echter blinder Fleck jenseits des Fensters, siehe Klassen-Doc dort).
// NativeVectorRetrieval (ab MariaDB 11.7/11.8, siehe VectorCapability) sortiert dagegen per
// echter Distanz-Berechnung/VECTOR-INDEX UEBER DEN GESAMTEN gefilterten Index, bevor das Limit
// greift - kein blinder Fleck, die Warnung waere hier irrefuehrend und wird deshalb ausgeblendet.
$ragCandidateLimit = (int) $addon->getConfig('rag_candidate_limit', 800);
if ($total > $ragCandidateLimit && !\FriendsOfRedaxo\AiChat\Db\VectorCapability::isSupported()) {
    $content .= '<div id="ai-chat-rag-limit-warning" class="alert alert-warning">'
        . '<p>' . sprintf($addon->i18n('index_rag_limit_warning'), $total, $ragCandidateLimit) . '</p>'
        . '<button type="button" id="ai-chat-optimize-rag-btn" class="btn btn-warning btn-sm"><i class="rex-icon fa-magic"></i> ' . $addon->i18n('index_rag_limit_optimize_button') . '</button>'
        . ' <span id="ai-chat-optimize-rag-result" style="margin-left: 10px;"></span>'
        . '</div>';
}

// Drei Uebersichten (Fundstellen je Quellentyp, aktivierte/verfuegbare Content-
// Provider) nebeneinander statt drei lose untereinander gestapelter, unstyled
// <ul>-Bloecke - gleiches Kachel-Raster fuer alle drei sorgt fuer einheitliches
// visuelles Gewicht statt eines optischen Bruchs zwischen Statuszeile/
// Fortschritts-Karte (beide gestylt) und diesen Listen (bisher gar nicht).
$statsColumns = '';
if (!empty($stats)) {
    $statsColumns .= '<div><h4>' . rex_escape($addon->i18n('index_stats_by_source_type')) . '</h4><ul>';
    foreach ($stats as $type => $count) {
        $label = $sourceTypeLabels[$type] ?? ucfirst(str_replace('_', ' ', (string) $type));
        $statsColumns .= '<li><span>' . rex_escape($label) . '</span><strong>' . (int) $count . '</strong></li>';
    }
    $statsColumns .= '</ul></div>';
}

if ($enabledProviderInstances !== []) {
    $statsColumns .= '<div><h4>Aktivierte Content-Provider</h4><ul>';
    foreach ($enabledProviderInstances as $provider) {
        $count = 0;
        foreach ($provider->getSupportedSourceTypes() as $sourceType) {
            $count += (int) ($stats[$sourceType] ?? 0);
        }

        $statsColumns .= '<li><span>' . rex_escape($provider->getLabel()) . '</span><strong>' . $count . '</strong></li>';
    }
    $statsColumns .= '</ul></div>';
}

if ($allProviders !== []) {
    $statsColumns .= '<div><h4>Verfügbare Content-Provider</h4><ul>';
    foreach ($allProviders as $provider) {
        // "mediapool" hat kein Haekchen in der Content-Provider-Liste (siehe
        // pages/settings.indexing.php) - die PDF-/Kategorie-Auswahl dort IST die
        // Aktivierung, global wie je Profil. "aktiv" bedeutet hier deshalb: global
        // ist mindestens eine Datei/Kategorie gewaehlt.
        if ('mediapool' === $provider->getKey()) {
            $hasGlobalPdfSelection = '' !== trim((string) $addon->getConfig('pdf_media_ids'))
                || '' !== trim((string) $addon->getConfig('pdf_category_ids'));
            $state = $hasGlobalPdfSelection ? 'aktiv' : 'deaktiviert (siehe AI Chat → Profile)';
        } else {
            $isEnabled = in_array($provider->getKey(), $enabledProviders, true);
            $state = $isEnabled ? 'aktiv' : 'deaktiviert';
        }
        $statsColumns .= '<li><span>' . rex_escape($provider->getLabel()) . '</span><small class="text-muted">' . rex_escape($state) . '</small></li>';
    }
    $statsColumns .= '</ul></div>';
}

if ('' !== $statsColumns) {
    $content .= '<div class="ai-chat-settings-box"><div class="ai-chat-index-stats-grid">' . $statsColumns . '</div></div>';
}

// GitHub Update Button anzeigen falls Repos konfiguriert
$githubRepos = (string) $addon->getConfig('github_repos', '');
if (!empty(trim($githubRepos))) {
    $content .= '<div class="ai-chat-index-github-box">';
    $content .= '<h4>' . $addon->i18n('index_github_title') . '</h4>';
    $content .= '<p>' . $addon->i18n('index_github_notice') . '</p>';
    $content .= '<button type="button" id="ai-chat-github-sync-btn" class="btn btn-info"><i class="rex-icon fa-github"></i> ' . $addon->i18n('index_github_button') . '</button>';
    $content .= ' <span id="github-sync-result" style="margin-left: 10px;"></span>';
    $content .= '</div>';
}

// Progress area (hidden until indexing starts)
$content .= '
<div id="ai-chat-progress-container" class="klxm-progress-card" style="display:none;">
    <div id="ai-chat-background-hint" class="alert alert-info" style="display:none; margin-bottom:12px;">
        <i class="rex-icon fa-check-circle"></i> ' . $addon->i18n('index_background_hint') . '
    </div>
    <div class="klxm-progress-card-header">
        <div id="ai-chat-donut" class="klxm-donut" aria-hidden="true"></div>
        <div class="klxm-progress-card-headline">
            <p id="ai-chat-status-text">' . $addon->i18n('index_waiting') . '</p>
            <p id="ai-chat-heartbeat">&nbsp;</p>
        </div>
    </div>
    <div class="progress">
        <div id="ai-chat-progress-bar"
             class="progress-bar progress-bar-striped active"
             role="progressbar"
             aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
             style="width:0%; min-width:2em;">
            0%
        </div>
    </div>
    <p id="ai-chat-detail-text" class="klxm-progress-card-detail">&nbsp;</p>
</div>
<div id="ai-chat-success-state" class="ai-chat-success-state" style="display:none;">
    <div class="ai-chat-success-badge">✓</div>
    <div class="ai-chat-success-copy">
        <strong>HEUREKA!</strong>
        <span>Der Index wurde erfolgreich aufgebaut.</span>
    </div>
</div>
';

// Delay-Selektor (Throttle zwischen den Tasks)
$delayOptions = [
    0    => $addon->i18n('index_delay_none'),
    200  => '200 ms',
    500  => '500 ms',
    1000 => '1 s',
    2000 => '2 s',
    3000 => '3 s',
];
$delaySelect  = '<select id="ai-chat-delay-select" class="form-control" style="display:inline-block;width:auto;vertical-align:middle;">';
foreach ($delayOptions as $ms => $label) {
    $delaySelect .= '<option value="' . $ms . '">' . $label . '</option>';
}
$delaySelect .= '</select>';

// Zwei klar getrennte Gruppen statt einer einzigen, ununterschiedenen
// Button-Reihe: "Indexierung" (die eigentliche Aktion, für die Nutzer i.d.R.
// hier sind) vs. "Wartung" (seltener gebrauchte, teils destruktive Aktionen
// wie Index/Cache leeren) - bisher optisch nicht unterscheidbar.
$content .= '<div class="klxm-index-actiongroup">';
$content .= '<p class="klxm-index-actiongroup-title">' . $addon->i18n('index_group_indexing') . '</p>';
$content .= '<p style="margin-bottom:4px;">';
$content .= '<button type="button" id="ai-chat-start-btn" class="btn btn-primary">' . $addon->i18n('index_button') . '</button> ';
$content .= '<button type="button" id="ai-chat-start-background-btn" class="btn btn-info" style="margin-left:6px;" disabled title="' . rex_escape($addon->i18n('index_background_button_notice')) . '"><i class="rex-icon fa-cloud-upload"></i> ' . $addon->i18n('index_background_button') . '</button> ';
$content .= '<button type="button" id="ai-chat-refresh-btn" class="btn btn-default" style="margin-left:6px;" disabled title="' . rex_escape($addon->i18n('index_background_button_notice')) . '">' . $addon->i18n('index_refresh_button') . '</button> ';
$content .= '<button type="button" id="ai-chat-cancel-btn" class="btn btn-warning" style="margin-left:6px;" disabled>' . $addon->i18n('index_btn_cancel') . '</button>';
$content .= '</p>';
$content .= '<p style="margin-top:4px; margin-bottom:0;">';
$content .= '<label style="margin-right:8px; font-weight:normal;">' . $addon->i18n('index_delay_label') . '</label>';
$content .= $delaySelect;
$content .= ' <small class="text-muted">' . $addon->i18n('index_delay_hint') . '</small>';
$content .= '</p>';
$content .= '</div>';

$content .= '<div class="klxm-index-actiongroup klxm-index-actiongroup-maintenance">';
$content .= '<p class="klxm-index-actiongroup-title">' . $addon->i18n('index_group_maintenance') . '</p>';
$content .= '<p style="margin-bottom:0;">';
$content .= '<button type="button" id="ai-chat-clear-btn" class="btn btn-delete">' . $addon->i18n('index_clear_button') . '</button> ';
$content .= '<button type="button" id="ai-chat-clear-cache-btn" class="btn btn-delete" style="margin-left:6px;">' . $addon->i18n('index_clear_cache_button') . '</button>';
$content .= '<button type="button" id="ai-chat-warm-cache-btn" class="btn btn-default" style="margin-left:6px;">' . $addon->i18n('index_warm_cache_button') . '</button>';
$content .= '</p>';
$content .= '</div>';


// Bewusst OHNE 'title'-Var: der Seitentitel steht bereits im animierten
// Kopfbereich oben (siehe $content-Aufbau weiter oben) - ein zusaetzlicher
// Panel-Titel wuerde "Indexierung" doppelt zeigen.
$fragment = new rex_fragment();
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
