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

// Toolbar mit Buttons für schnellen Zugriff
$toolbar = '<button type="button" id="ai-chat-start-btn-toolbar" class="btn btn-primary" style="margin-right: 5px;"><i class="rex-icon fa-play"></i> ' . $addon->i18n('index_button') . '</button>';
$toolbar .= '<button type="button" id="ai-chat-start-background-btn-toolbar" class="btn btn-info" style="margin-right: 5px;" disabled title="' . rex_escape($addon->i18n('index_background_button_notice')) . '"><i class="rex-icon fa-cloud-upload"></i> ' . $addon->i18n('index_background_button') . '</button>';
$toolbar .= '<button type="button" id="ai-chat-refresh-btn-toolbar" class="btn btn-default" style="margin-right: 5px;"><i class="rex-icon fa-refresh"></i> ' . $addon->i18n('index_refresh_button') . '</button>';
$toolbar .= '<button type="button" id="ai-chat-cancel-btn-toolbar" class="btn btn-warning" style="margin-right: 15px;" disabled><i class="rex-icon fa-stop"></i> ' . $addon->i18n('index_btn_cancel') . '</button>';

$fragment = new rex_fragment();
$fragment->setVar('body', $toolbar, false);
echo $fragment->parse('core/page/section.php');

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

$content = '';

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

$content .= '<p>' . $addon->i18n('index_description') . '</p>';
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

if (!empty($stats)) {
    $content .= '<ul>';
    foreach ($stats as $type => $count) {
        $label = $sourceTypeLabels[$type] ?? ucfirst(str_replace('_', ' ', (string) $type));
        $content .= '<li>' . rex_escape($label) . ': ' . (int) $count . '</li>';
    }
    $content .= '</ul>';
}

if ($enabledProviderInstances !== []) {
    $content .= '<p><strong>Aktivierte Content-Provider</strong></p>';
    $content .= '<ul>';
    foreach ($enabledProviderInstances as $provider) {
        $count = 0;
        foreach ($provider->getSupportedSourceTypes() as $sourceType) {
            $count += (int) ($stats[$sourceType] ?? 0);
        }

        $content .= '<li>' . rex_escape($provider->getLabel()) . ': ' . $count . '</li>';
    }
    $content .= '</ul>';
}

if ($allProviders !== []) {
    $content .= '<p><strong>Verfügbare Content-Provider</strong></p>';
    $content .= '<ul>';
    foreach ($allProviders as $provider) {
        $isEnabled = in_array($provider->getKey(), $enabledProviders, true);
        $state = $isEnabled ? 'aktiv' : 'deaktiviert';
        $content .= '<li>' . rex_escape($provider->getLabel()) . ' <small class="text-muted">(' . rex_escape($state) . ')</small></li>';
    }
    $content .= '</ul>';
}

// GitHub Update Button anzeigen falls Repos konfiguriert
$githubRepos = (string) $addon->getConfig('github_repos', '');
if (!empty(trim($githubRepos))) {
    $content .= '<div style="margin-bottom: 20px; padding: 15px; background: #f0f7ff; border-left: 4px solid #007bff; border-radius: 4px;">';
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
<style>
    .ai-chat-success-state {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-top: 20px;
        padding: 18px 20px;
        border-radius: 12px;
        border: 1px solid rgba(37, 166, 96, 0.28);
        background: linear-gradient(135deg, rgba(40, 167, 69, 0.12), rgba(17, 160, 122, 0.08));
        box-shadow: 0 10px 25px rgba(40, 167, 69, 0.12);
        animation: klxmSuccessReveal 0.6s ease-out;
    }
    .ai-chat-success-badge {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        font-size: 28px;
        font-weight: 700;
        color: #fff;
        background: linear-gradient(135deg, #21a366, #17a2b8);
        box-shadow: 0 10px 18px rgba(33, 163, 102, 0.28);
        animation: klxmSuccessBadge 0.7s ease-out;
    }
    .ai-chat-success-copy {
        display: flex;
        flex-direction: column;
        gap: 4px;
        color: #1b4d3a;
        font-size: 1.02em;
    }
    .ai-chat-success-copy strong {
        font-size: 1.3em;
        letter-spacing: 0.02em;
    }
    @keyframes klxmSuccessReveal {
        0% { opacity: 0; transform: translateY(8px) scale(0.98); }
        100% { opacity: 1; transform: translateY(0) scale(1); }
    }
    @keyframes klxmSuccessBadge {
        0% { transform: scale(0.6); opacity: 0; }
        60% { transform: scale(1.15); opacity: 1; }
        100% { transform: scale(1); }
    }
    .klxm-index-statusbar {
        margin-bottom: 14px;
    }
    .klxm-index-statusbar .label {
        font-size: 0.95em;
        padding: 5px 10px;
        margin-right: 8px;
        vertical-align: middle;
    }
    /* Kein eigenes Rot/Grün/etc. definiert - die Bootstrap-"label"-Kontextklassen
       (label-default/-primary/-info/-success/-warning/-danger) kommen aus dem
       REDAXO-Backend-Theme selbst und passen sich damit automatisch an
       Light/Dark-Mode an, statt hier fest codierte Farben zu riskieren. */
    #ai-chat-status-badge .rex-icon {
        margin-right: 3px;
    }
    #ai-chat-status-badge.klxm-status-running .rex-icon {
        animation: klxmStatusSpin 1.2s linear infinite;
    }
    @keyframes klxmStatusSpin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .klxm-index-actiongroup {
        margin-top: 18px;
        padding: 14px 16px;
        border: 1px solid rgba(120, 130, 140, 0.25);
        border-radius: 8px;
    }
    .klxm-index-actiongroup-title {
        margin: 0 0 8px;
        font-weight: 600;
        font-size: 0.85em;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        opacity: 0.65;
    }
    .klxm-index-actiongroup-maintenance {
        margin-top: 12px;
    }

    /* ── Fortschritts-Karte ──────────────────────────────────────────────
       Farbpalette 1:1 aus dem REDAXO-Backend-Theme übernommen (be_style
       _variables.scss: $color-a-dark #324050, $color-b #4b9ad9, $color-d
       #5bb585, $brand-warning #cfb550, $brand-danger #d9534f) statt eigener
       Farben, inkl. denselben Dunkelmodus-Gegenstücken aus
       _variables-dark.scss - damit fügt sich das optisch in die bestehende
       REDAXO-Farbwelt statt wie ein Fremdkörper zu wirken. */
    .klxm-progress-card {
        margin-top: 20px;
        padding: 20px 22px;
        border-radius: 10px;
        border: 1px solid rgba(50, 64, 80, 0.14);
        background: linear-gradient(135deg, rgba(75, 154, 217, 0.07), rgba(50, 64, 80, 0.03));
        transition: background 0.4s ease, border-color 0.4s ease;
    }
    .klxm-progress-card--success {
        border-color: rgba(91, 181, 133, 0.35);
        background: linear-gradient(135deg, rgba(91, 181, 133, 0.12), rgba(75, 154, 217, 0.05));
    }
    .klxm-progress-card--warning {
        border-color: rgba(207, 181, 80, 0.4);
        background: linear-gradient(135deg, rgba(207, 181, 80, 0.14), rgba(50, 64, 80, 0.03));
    }
    .klxm-progress-card--error {
        border-color: rgba(217, 83, 79, 0.35);
        background: linear-gradient(135deg, rgba(217, 83, 79, 0.12), rgba(50, 64, 80, 0.03));
    }
    .klxm-progress-card-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 14px;
    }
    .klxm-progress-card-headline {
        flex: 1;
        min-width: 0;
    }
    .klxm-progress-card-headline #ai-chat-status-text {
        margin: 0;
        font-size: 1.15em;
        font-weight: 600;
    }
    .klxm-progress-card-headline #ai-chat-heartbeat {
        margin: 2px 0 0;
        font-size: 0.85em;
        opacity: 0.75;
    }
    .klxm-progress-card-detail {
        margin: 10px 0 0;
        font-size: 0.9em;
        opacity: 0.8;
    }

    /* Zwei gegenläufig rotierende Ringe statt des winzigen drehenden Icons im
       Badge zuvor - dasselbe Grundprinzip wie REDAXOs eigener PJAX-Ladeindikator
       (be_style .rex-ajax-loader-element), nur als eigenständiges Element
       innerhalb der Karte statt vollflächig über die Seite gelegt. */
    .klxm-donut {
        position: relative;
        width: 48px;
        height: 48px;
        flex: 0 0 auto;
        display: none;
    }
    .klxm-donut::before,
    .klxm-donut::after {
        content: \'\';
        position: absolute;
        top: 0; right: 0; bottom: 0; left: 0;
        border-radius: 50%;
        border: 4px solid transparent;
    }
    .klxm-donut::before {
        border-top-color: #324050;
        border-right-color: #324050;
        animation: klxmDonutSpin 2.2s linear infinite;
        opacity: 0.85;
    }
    .klxm-donut::after {
        border-top-color: #4b9ad9;
        animation: klxmDonutSpin 1s linear infinite;
    }
    @keyframes klxmDonutSpin {
        from { transform: rotate(0deg); }
        to   { transform: rotate(360deg); }
    }

    /* Kurzes "Aufblitzen" bei Zahlen, die sich live aktualisieren (Index-
       größe, Fortschritt) - macht sichtbar, dass gerade wirklich etwas
       passiert, statt dass sich Zahlen kommentarlos ändern. */
    .klxm-pulse {
        display: inline-block;
        animation: klxmPulse 0.5s ease;
    }
    @keyframes klxmPulse {
        0%   { transform: scale(1); }
        35%  { transform: scale(1.3); color: #4b9ad9; }
        100% { transform: scale(1); }
    }

    /* Dunkelmodus-Werte, zweifach ausgeschrieben statt per Sass-Mixin geteilt
       (hier reines CSS): einmal für explizit gewähltes Dunkel-Theme
       (body.rex-theme-dark), einmal für Betriebssystem-Präferenz ohne
       expliziten Gegenbefehl (body:not(.rex-theme-light) unter
       prefers-color-scheme: dark) - exakt dasselbe Muster wie be_style
       selbst in _loader.scss für den eigenen PJAX-Ladeindikator nutzt. */
    body.rex-theme-dark .klxm-progress-card {
        border-color: rgba(75, 154, 217, 0.22);
        background: linear-gradient(135deg, rgba(75, 154, 217, 0.1), rgba(21, 28, 34, 0.4));
    }
    body.rex-theme-dark .klxm-progress-card--success {
        border-color: rgba(13, 106, 56, 0.5);
        background: linear-gradient(135deg, rgba(13, 106, 56, 0.22), rgba(21, 28, 34, 0.4));
    }
    body.rex-theme-dark .klxm-progress-card--warning {
        border-color: rgba(120, 100, 30, 0.6);
        background: linear-gradient(135deg, rgba(120, 100, 30, 0.28), rgba(21, 28, 34, 0.4));
    }
    body.rex-theme-dark .klxm-progress-card--error {
        border-color: rgba(128, 25, 25, 0.55);
        background: linear-gradient(135deg, rgba(128, 25, 25, 0.24), rgba(21, 28, 34, 0.4));
    }
    body.rex-theme-dark .klxm-donut::before {
        border-top-color: #151c22;
        border-right-color: #151c22;
    }
    body.rex-theme-dark .klxm-donut::after {
        border-top-color: #409be4;
    }

    @media (prefers-color-scheme: dark) {
        body:not(.rex-theme-light) .klxm-progress-card {
            border-color: rgba(75, 154, 217, 0.22);
            background: linear-gradient(135deg, rgba(75, 154, 217, 0.1), rgba(21, 28, 34, 0.4));
        }
        body:not(.rex-theme-light) .klxm-progress-card--success {
            border-color: rgba(13, 106, 56, 0.5);
            background: linear-gradient(135deg, rgba(13, 106, 56, 0.22), rgba(21, 28, 34, 0.4));
        }
        body:not(.rex-theme-light) .klxm-progress-card--warning {
            border-color: rgba(120, 100, 30, 0.6);
            background: linear-gradient(135deg, rgba(120, 100, 30, 0.28), rgba(21, 28, 34, 0.4));
        }
        body:not(.rex-theme-light) .klxm-progress-card--error {
            border-color: rgba(128, 25, 25, 0.55);
            background: linear-gradient(135deg, rgba(128, 25, 25, 0.24), rgba(21, 28, 34, 0.4));
        }
        body:not(.rex-theme-light) .klxm-donut::before {
            border-top-color: #151c22;
            border-right-color: #151c22;
        }
        body:not(.rex-theme-light) .klxm-donut::after {
            border-top-color: #409be4;
        }
    }
</style>';

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
$content .= '<button type="button" id="ai-chat-refresh-btn" class="btn btn-default" style="margin-left:6px;">' . $addon->i18n('index_refresh_button') . '</button> ';
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


$fragment = new rex_fragment();
$fragment->setVar('title', $addon->i18n('index_title'), false);
$fragment->setVar('body', $content, false);
echo $fragment->parse('core/page/section.php');
