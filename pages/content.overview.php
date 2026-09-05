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

// Generischer Info-Panel-Baustein fuer die Sidebar (gleiches Muster wie
// settings.shared.php's $renderInfoPanel - hier lokal statt per require, da
// diese Seite kein Formular ist und den Rest der dortigen Formular-Helper
// nicht braucht).
$renderSidebarPanel = static function (string $title, string $icon, string $bodyHtml, string $panelClass = 'panel-default', string $wrapperId = ''): string {
    $idAttr = '' !== $wrapperId ? ' id="' . rex_escape($wrapperId, 'html_attr') . '"' : '';

    return '<div class="panel ' . rex_escape($panelClass) . '"' . $idAttr . ' style="margin-bottom:20px;">'
        . '<header class="panel-heading"><div class="panel-title"><i class="rex-icon ' . rex_escape($icon) . '"></i> ' . rex_escape($title) . '</div></header>'
        . '<div class="panel-body">' . $bodyHtml . '</div>'
        . '</div>';
};
$sidebar = '';

// (JS wird global über boot.php geladen und per rex:ready initialisiert)

// Config for JS – injected via data attribute (no inline JS)
$jsConfig = json_encode([
    'apiBase'           => 'index.php?rex-api-call=ai_chat_index',
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

// Animierter Kopfbereich statt der bisherigen nackten Titelzeile - reines
// CSS/@keyframes, siehe assets/ai-chat-indexing-backend.css fuer Details und
// Hintergrund zur Farb-/Icon-Wahl. Traegt den Seitentitel selbst, die
// umschliessende rex_fragment bekommt deshalb bewusst KEINEN eigenen Titel
// mehr (sonst stuende "Indexierung" doppelt da).
// Header traegt volle Seitenbreite (spaeter AUSSERHALB der zweispaltigen
// Row aus Hauptspalte + Sidebar), deshalb eine eigene Variable statt Teil
// von $content.
//
// Falls hier jemand mitliest: dem weissen Kaninchen folgen, aber keine Sorge,
// hier spielt niemand "Global Thermonuclear War".
$header = '
<div class="ai-chat-index-header">
    <span class="ai-chat-index-particle"></span>
    <span class="ai-chat-index-particle"></span>
    <span class="ai-chat-index-particle"></span>
    <span class="ai-chat-index-particle"></span>
    <span class="ai-chat-index-particle"></span>
    <span class="ai-chat-index-particle"></span>
    <div class="header-icon" title="Natürlich lasse ich dich das tun, Dave.">
        <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="32" cy="32" r="27" fill="#0b1210" stroke="#3d4b47" stroke-width="2"/>
            <circle cx="32" cy="32" r="19" fill="none" stroke="#5B6D69" stroke-width="1.5" opacity="0.6"/>
            <circle class="ai-chat-index-eye-core" cx="32" cy="32" r="10" fill="#ff8a4c"/>
            <circle cx="32" cy="32" r="10" fill="none" stroke="#ffcda0" stroke-width="0.5" opacity="0.6"/>
            <circle cx="28" cy="27" r="2.5" fill="#ffe4cc" opacity="0.85"/>
        </svg>
    </div>
    <div class="header-content">
        <h1>' . rex_escape($addon->i18n('index_title')) . '</h1>
        <div class="header-subtitle">' . rex_escape($addon->i18n('index_description')) . '</div>
    </div>
</div>';

// Hidden config element – read by ai-chat-indexer.js via data attribute
$content = '<div id="ai-chat-indexer-config" data-config=\'' . $jsConfig . '\' style="display:none"></div>';

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

// Chunking-Einblick: Durchschnittliche Chunk-Länge und Chunks je Quelle (ein "Quelle" =
// ein Artikel/eine URL/ein PDF vor dem Chunking, identifiziert über source_type+source_id) -
// zeigt an, ob die aktuelle chunk_size zur tatsächlichen Inhaltsmenge passt. Reine
// Empfehlung statt automatischer Anwendung (anders als beim RAG-Kandidatenfenster oben):
// chunk_size/chunk_overlap wirken erst auf NEU indexierte Inhalte, ein stilles Umschalten
// der Einstellung würde bereits indexierte Chunks in einer inkonsistenten, alten Größe
// zurücklassen, bis eine vollständige Neu-Indexierung läuft.
$chunkInsightSql = rex_sql::factory();
$chunkInsightSql->setQuery(
    'SELECT COUNT(DISTINCT CONCAT(source_type, \':\', source_id)) AS total_sources, AVG(CHAR_LENGTH(content)) AS avg_chunk_length FROM ' . rex::getTable('ai_chat_index'),
);
$totalSources = (int) $chunkInsightSql->getValue('total_sources');
$avgChunkLength = (float) ($chunkInsightSql->getValue('avg_chunk_length') ?? 0.0);
$avgChunksPerSource = $totalSources > 0 ? $total / $totalSources : 0.0;
$currentChunkSize = (int) $addon->getConfig('chunk_size', 1000);

$sidebar .= $renderSidebarPanel('Provider-Wechsel', 'fa-exchange', $addon->i18n('index_provider_hint'), 'panel-info');

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
    $ragWarningBody = '<p>' . sprintf($addon->i18n('index_rag_limit_warning'), $total, $ragCandidateLimit) . '</p>'
        . '<button type="button" id="ai-chat-optimize-rag-btn" class="btn btn-warning btn-sm"><i class="rex-icon fa-magic"></i> ' . $addon->i18n('index_rag_limit_optimize_button') . '</button>'
        . ' <span id="ai-chat-optimize-rag-result" style="margin-left: 10px;"></span>';
    $sidebar .= $renderSidebarPanel('RAG-Kandidatenfenster', 'fa-exclamation-triangle', $ragWarningBody, 'panel-warning', 'ai-chat-rag-limit-warning');
}

// Chunking-Einblick nur zeigen, wenn genug Daten fuer eine sinnvolle Aussage vorhanden sind -
// bei sehr kleinem Index (wenige Quellen) schwankt der Durchschnitt zu stark, um daraus eine
// Empfehlung abzuleiten.
if ($totalSources >= 5) {
    $chunkAdvice = '';
    $chunkAdvicePanelClass = 'panel-info';
    if ($avgChunksPerSource > 8.0) {
        $suggestedChunkSize = (int) round($currentChunkSize * 1.5 / 100) * 100;
        $chunkAdvice = sprintf(
            'Inhalte werden im Schnitt in %.1f Chunks pro Quelle zerlegt (aktuell %d Zeichen je Chunk) - das ist recht feinteilig und kann Zusammenhänge über Chunk-Grenzen hinweg zerreißen. Eine größere Chunk-Größe (z.B. %d Zeichen statt %d) fasst mehr zusammenhängenden Kontext pro Chunk. Gilt nur für künftig indexierte Inhalte - danach einmal vollständig neu indexieren.',
            $avgChunksPerSource,
            (int) round($avgChunkLength),
            $suggestedChunkSize,
            $currentChunkSize,
        );
        $chunkAdvicePanelClass = 'panel-warning';
    } elseif ($avgChunksPerSource <= 1.3 && $avgChunkLength < $currentChunkSize * 0.5) {
        $chunkAdvice = sprintf(
            'Inhalte passen im Schnitt bequem in einen einzelnen Chunk (Ø %d von %d möglichen Zeichen) - die aktuelle Chunk-Größe passt gut zur vorhandenen Datenmenge, keine Änderung nötig.',
            (int) round($avgChunkLength),
            $currentChunkSize,
        );
    } else {
        $chunkAdvice = sprintf(
            'Ø %.1f Chunks pro Quelle bei %d Zeichen Chunk-Größe - unauffällig, keine Änderung nötig.',
            $avgChunksPerSource,
            $currentChunkSize,
        );
    }

    $chunkAdviceBody = '<p>' . rex_escape($chunkAdvice) . '</p>'
        . '<a href="' . rex_url::backendPage('ai_chat/settings/retrieval') . '">Chunking &amp; Cache öffnen</a>';
    $sidebar .= $renderSidebarPanel('Chunking-Einblick', 'fa-magic', $chunkAdviceBody, $chunkAdvicePanelClass);
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

// Bewusst ueber ALLE verfuegbaren Provider (nicht nur die global "aktivierten" - siehe
// Kommentar bei der zweiten Liste weiter unten) und gefiltert auf tatsaechlich indexierten
// Inhalt: die globale "index_content_providers"-Auswahl sagt fuer profil-exklusive Provider
// (mediapool/yform) nichts mehr aus, ein bereits indexiertes Profil mit PDFs wuerde hier
// sonst schlicht fehlen, obwohl sein Inhalt laengst durchsuchbar ist.
$providersWithContent = [];
foreach ($allProviders as $provider) {
    $count = 0;
    foreach ($provider->getSupportedSourceTypes() as $sourceType) {
        $count += (int) ($stats[$sourceType] ?? 0);
    }
    if ($count > 0) {
        $providersWithContent[] = [$provider, $count];
    }
}

if ($providersWithContent !== []) {
    $statsColumns .= '<div><h4>Aktivierte Content-Provider</h4><ul>';
    foreach ($providersWithContent as [$provider, $count]) {
        $statsColumns .= '<li><span>' . rex_escape($provider->getLabel()) . '</span><strong>' . $count . '</strong></li>';
    }
    $statsColumns .= '</ul></div>';
}

if ($allProviders !== []) {
    // "mediapool"/"yform" sind seit der Hauptprofil-Entflechtung rein profil-exklusiv
    // (siehe settings.indexing.php, das beide bewusst aus der globalen
    // "index_content_providers"-Auswahl ausschliesst) - ein globaler "aktiviert/
    // deaktiviert"-Status waere hier IMMER irrefuehrend: entweder dauerhaft "deaktiviert",
    // obwohl laengst ein Profil PDFs/YForm-Tabellen nutzt, oder (bei einem Upgrade von vor
    // der Entflechtung) ein stehengebliebenes "aktiv" aus der alten globalen Auswahl, das
    // schon lange nichts mehr bedeutet. Stattdessen wird hier gezaehlt, wie viele Profile
    // die jeweilige Quelle TATSAECHLICH nutzen.
    $profiles = (new FriendsOfRedaxo\AiChat\Profile\ProfileRepository())->getAll();
    $mediapoolProfileCount = 0;
    $yformProfileCount = 0;
    foreach ($profiles as $profileForCount) {
        if ($profileForCount->pdfMediaIds !== [] || $profileForCount->pdfCategoryIds !== []) {
            ++$mediapoolProfileCount;
        }
        if ($profileForCount->yformProfileIds !== []) {
            ++$yformProfileCount;
        }
    }

    $statsColumns .= '<div><h4>Verfügbare Content-Provider</h4><ul>';
    foreach ($allProviders as $provider) {
        if ('mediapool' === $provider->getKey() || 'yform' === $provider->getKey()) {
            $profileCount = 'mediapool' === $provider->getKey() ? $mediapoolProfileCount : $yformProfileCount;
            $state = $profileCount > 0
                ? sprintf('genutzt von %d Profil%s', $profileCount, 1 === $profileCount ? '' : 'en')
                : 'von keinem Profil genutzt';
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
// Kopfbereich oben - ein zusaetzlicher Panel-Titel wuerde "Indexierung"
// doppelt zeigen. Evergreen-Hinweise (Provider-Wechsel, Chunking-Einblick,
// RAG-Kandidatenfenster) leben in der Sidebar statt als volle-Breite-Balken
// im Hauptfluss - dort konkurrieren sie sonst optisch mit dem eigentlichen
// Status/Fortschritt, obwohl sie meist gar nicht akut handlungsrelevant sind.
// $sidebar enthaelt immer mindestens den Provider-Wechsel-Hinweis (einzige
// unbedingte Ergaenzung, siehe oben) - keine leere Sidebar-Spalte moeglich.
$body = $header . '<div class="row"><div class="col-md-9">' . $content . '</div><div class="col-md-3">' . $sidebar . '</div></div>';

$fragment = new rex_fragment();
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');
