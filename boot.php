<?php

use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\AiChat\Api\ChatIndex as ApiChatIndex;
use FriendsOfRedaxo\AiChat\Api\ChatQuery as ApiChatQuery;
use FriendsOfRedaxo\AiChat\Api\ChatTest as ApiChatTest;
use FriendsOfRedaxo\AiChat\Api\CloudflareModels as ApiCloudflareModels;
use FriendsOfRedaxo\AiChat\Api\ReindexWorker as ApiReindexWorker;
use FriendsOfRedaxo\AiChat\Api\SelfCallPing as ApiSelfCallPing;
use FriendsOfRedaxo\AiChat\Api\RoutePackage\AiChat as ApiAiChatRoutePackage;
use FriendsOfRedaxo\AiChat\Api\WidgetTranslations as ApiWidgetTranslations;
use FriendsOfRedaxo\AiChat\Profile\ProfileResolver;
use FriendsOfRedaxo\AiChat\Profile\ProfileTheme;
use FriendsOfRedaxo\AiChat\Service\ChatQueryService;
use FriendsOfRedaxo\AiChat\Service\YrewriteDomainResolver;

// smalot/pdfparser fuer die PDF-Indexierung (MediaPoolContentProvider) - falls composer
// install im Addon-Verzeichnis nie gelaufen ist (z.B. manuelles Deployment ohne vendor/),
// bleibt die Klasse einfach ungeladen; MediaPoolContentProvider::isAvailable() prueft
// class_exists() und deaktiviert sich dann selbst, kein Fataler Fehler.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$addon = rex_addon::get('ai_chat');

// Namespace-Registrierung der rex-api-call-Endpunkte (seit REDAXO 5.17), siehe
// https://redaxo.org/doku/5.x/api#namespace-registrierung - die rex-api-call-Namen
// selbst (ai_chat_query/_index/_test/_cloudflare_models) bleiben unverändert,
// nur die dahinterliegenden Klassen sind jetzt unter FriendsOfRedaxo\AiChat\Api
// statt im globalen Namespace als rex_api_ai_chat_* organisiert.
rex_api_function::register('ai_chat_query', ApiChatQuery::class);
rex_api_function::register('ai_chat_index', ApiChatIndex::class);
rex_api_function::register('ai_chat_test', ApiChatTest::class);
rex_api_function::register('ai_chat_cloudflare_models', ApiCloudflareModels::class);
// published=true (siehe ReindexWorker) - wird vom detached curl/wget-Hintergrundprozess
// ohne Backend-Session aufgerufen, absichern übernimmt der Einmal-Token aus IndexRunStore.
rex_api_function::register('ai_chat_reindex_worker', ApiReindexWorker::class);
// published=true: wirkungsloser Echo-Endpunkt fuer den Selbstaufruf-Diagnosetest
// (Einstellungen -> Systemcheck), aus demselben Grund wie ai_chat_reindex_worker ohne
// Backend-Session erreichbar sein muss - gibt aber keinerlei Daten preis.
rex_api_function::register('ai_chat_selfcall_ping', ApiSelfCallPing::class);
// published=true: reine Widget-UI-Texte, auch für anonyme Frontend-Besucher ohne Session.
rex_api_function::register('ai_chat_widget_translations', ApiWidgetTranslations::class);

$assetVersion = static function (string $asset) use ($addon): string {
    $assetPath = rex_path::addon($addon->getName(), 'assets/' . $asset);
    if (is_file($assetPath)) {
        return $addon->getVersion() . '-' . (string) filemtime($assetPath);
    }

    return $addon->getVersion();
};

$isStreamEnabled = static function () use ($addon): bool {
    $raw = $addon->getConfig('stream_enabled', false);
    if (is_bool($raw)) {
        return $raw;
    }
    $normalized = trim((string) $raw);

    return $normalized === '1' || $normalized === '|1|' || strtolower($normalized) === 'true';
};
$streamEnabledAttr = $isStreamEnabled() ? 'true' : 'false';

if (rex_addon::get('api')->isAvailable() && class_exists(RouteCollection::class)) {
    RouteCollection::registerRoutePackage(new ApiAiChatRoutePackage());
}

if (rex_addon::get('cronjob')->isAvailable()) {
    rex_cronjob_manager::registerType('FriendsOfRedaxo\AiChat\Cronjob\IndexCronjob');
}

rex_extension::register('PACKAGES_INCLUDED', function () {
    // is_callable()-Guards statt direktem Array-Callable: Verhindert einen fatalen
    // "must be of type callable, array given"-TypeError (der den kompletten Backend-Boot
    // crashen wuerde), falls z.B. durch ein inkonsistentes Deployment eine aeltere Version
    // von lib/EventListener.php ohne die neueren Methoden ausgeliefert wird.
    $listenerClass = 'FriendsOfRedaxo\\AiChat\\EventListener';

    if (rex::isBackend()) {
        $extensionPoints = [
            'SLICE_ADDED', 'SLICE_UPDATED', 'SLICE_DELETED', 'SLICE_MOVE',
            'ART_ADDED', 'ART_UPDATED', 'ART_STATUS', 'ART_DELETED', 'ART_META_UPDATED'
        ];

        if (is_callable([$listenerClass, 'handleEvent'])) { // @phpstan-ignore if.alwaysTrue
            foreach ($extensionPoints as $ep) {
                rex_extension::register($ep, [$listenerClass, 'handleEvent'], rex_extension::LATE);
            }
        }

        if (is_callable([$listenerClass, 'handleCategoryEvent'])) { // @phpstan-ignore if.alwaysTrue
            rex_extension::register('CAT_STATUS', [$listenerClass, 'handleCategoryEvent'], rex_extension::LATE);
        }
    }

    // YForm-Datensaetze koennen auch ueber Frontend-Formulare (yform_frontend_controller o.ae.)
    // geaendert werden, daher NICHT an einen eingeloggten Backend-User binden.
    if (rex_addon::get('yform')->isAvailable() && is_callable([$listenerClass, 'handleYformEvent'])) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
        rex_extension::register('YFORM_DATA_ADDED', [$listenerClass, 'handleYformEvent'], rex_extension::LATE);
        rex_extension::register('YFORM_DATA_UPDATED', [$listenerClass, 'handleYformEvent'], rex_extension::LATE);
        rex_extension::register('YFORM_DATA_DELETED', [$listenerClass, 'handleYformEvent'], rex_extension::LATE);
    }
});

// Backend: Indexer JS und Component JS laden
if (rex::isBackend() && rex::getUser()) {
    rex_view::addJsFile($addon->getAssetsUrl('ai-chat-indexer.js?v=' . $assetVersion('ai-chat-indexer.js')));
    rex_view::addJsFile($addon->getAssetsUrl('ai-chat-warm-cache.js?v=' . $assetVersion('ai-chat-warm-cache.js')));
    rex_view::addJsFile($addon->getAssetsUrl('ai-chat-statistics.js?v=' . $assetVersion('ai-chat-statistics.js')));
    rex_view::addCssFile($addon->getAssetsUrl('ai-chat-backend.css?v=' . $assetVersion('ai-chat-backend.css')));
    rex_view::addCssFile($addon->getAssetsUrl('ai-chat-statistics.css?v=' . $assetVersion('ai-chat-statistics.css')));

    // Nur auf der Demo-Seite laden, nicht global: ai-search.js haengt sein
    // Overlay per document.body.appendChild() direkt an <body>, ausserhalb des
    // von PJAX ersetzten Inhaltsbereichs. Global geladen bliebe eine einmal auf
    // der Demo-Seite geoeffnete Suche beim Wegnavigieren (PJAX) bestehen und
    // wuerde auf JEDER anderen Backend-Seite ueber dem Inhalt schweben.
    if (
        rex_be_controller::getCurrentPagePart(1) === 'ai_chat'
        && rex_be_controller::getCurrentPagePart(2) === 'demo'
    ) {
        rex_view::addJsFile($addon->getAssetsUrl('ai-i18n.js?v=' . $assetVersion('ai-i18n.js')));
        rex_view::addJsFile($addon->getAssetsUrl('ai-search.js?v=' . $assetVersion('ai-search.js')));
        rex_view::addJsFile($addon->getAssetsUrl('ai-search-backend-demo.js?v=' . $assetVersion('ai-search-backend-demo.js')));
        rex_view::addCssFile($addon->getAssetsUrl('ai-search.css?v=' . $assetVersion('ai-search.css')));
    }

    if (
        rex_be_controller::getCurrentPagePart(1) === 'ai_chat'
        && rex_be_controller::getCurrentPagePart(2) === 'content'
        && rex_be_controller::getCurrentPagePart(3) === 'yform'
    ) {
        rex_view::addJsFile($addon->getAssetsUrl('ai-yform-mapping.js?v=' . $assetVersion('ai-yform-mapping.js')));
    }

    // Pickit Color (https://github.com/skerbis/pickit_color) - alpha-faehiger Colorpicker
    // fuer die zentrale Theme-Verwaltung (siehe pages/themes.php). Profile waehlen nur noch
    // ein fertiges Theme aus einem Dropdown, brauchen also keinen eigenen Colorpicker mehr.
    if (
        rex_be_controller::getCurrentPagePart(1) === 'ai_chat'
        && rex_be_controller::getCurrentPagePart(2) === 'themes'
    ) {
        rex_view::addCssFile($addon->getAssetsUrl('pickit-color/colorpicker.min.css?v=' . $assetVersion('pickit-color/colorpicker.min.css')));
        rex_view::addJsFile($addon->getAssetsUrl('pickit-color/colorpicker.min.js?v=' . $assetVersion('pickit-color/colorpicker.min.js')));
    }

    if (
        rex_be_controller::getCurrentPagePart(1) === 'ai_chat'
        && in_array(rex_be_controller::getCurrentPagePart(2), ['settings', 'content'], true)
    ) {
        rex_view::addCssFile($addon->getAssetsUrl('ai-chat-settings-backend.css?v=' . $assetVersion('ai-chat-settings-backend.css')));
    }

    // Animierter Kopfbereich + Fortschritts-/Statuskarten-Styling der Indexierung-Seite -
    // eigene Datei statt eines langen <style>-Blocks direkt im PHP-Output (siehe
    // pages/content.php).
    if (
        rex_be_controller::getCurrentPagePart(1) === 'ai_chat'
        && rex_be_controller::getCurrentPagePart(2) === 'content'
    ) {
        rex_view::addCssFile($addon->getAssetsUrl('ai-chat-indexing-backend.css?v=' . $assetVersion('ai-chat-indexing-backend.css')));
    }

    // Wir registrieren einen Filter, um das Modul-Script immer zu laden (für die Demo-Seite)
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon, $assetVersion) {
        $page = rex_be_controller::getCurrentPage();
        if (!str_contains($page, 'ai_chat')) {
            return $ep->getSubject();
        }

        $content = $ep->getSubject();
        // Eigenes Marker-Attribut statt eines naiven strpos() auf "ai-chat.js" - der reine
        // Dateiname taucht auch in Code-Kommentaren innerhalb von Seiten-eigenem Inline-JS
        // auf (z.B. pages/themes.php's Live-Vorschau-Script referenziert "assets/ai-chat.js"
        // in einem Kommentar), was den urspruenglichen Substring-Check faelschlich als
        // "bereits geladen" werten liess - das Skript wurde dann nie eingebunden, die
        // <ai-chat>-Komponente blieb undefiniert (siehe Theme-Vorschau-Bug).
        if (strpos($content, 'data-ai-chat-widget-script') === false) {
            $i18nScript = '<script src="' . $addon->getAssetsUrl('ai-i18n.js?v=' . $assetVersion('ai-i18n.js')) . '"></script>';
            $script = $i18nScript . '<script type="module" data-ai-chat-widget-script src="' . $addon->getAssetsUrl('ai-chat.js?v=' . (is_file(rex_path::addon($addon->getName(), 'assets/ai-chat.js')) ? $addon->getVersion() . '-' . (string) filemtime(rex_path::addon($addon->getName(), 'assets/ai-chat.js')) : $addon->getVersion())) . '"></script>';
            $content = str_replace('</body>', $script . '</body>', $content);
        }
        return $content;
    });
}

// Frontend: Visitor Chat + eigenständiges Such-Widget (klxm-search) - unabhängig
// voneinander aktivierbar, damit z.B. nur die Suche ohne Chat-Bubble laufen kann
// oder umgekehrt. Seit der Hauptprofil-Entflechtung gibt es dafuer keine globalen
// Schalter mehr - jedes Profil traegt sein eigenes chatEnabled/searchEnabled
// (Standard: aktiv, siehe ChatProfile), ein Profil existiert nach der Installation
// immer (Standard-Profil-Seed in install.php).
if (rex::isFrontend()) {

    // Domain-/Profil-Aufloesung passiert bewusst ERST HIER, in der OUTPUT_FILTER-
    // Closure, NICHT auf oberster boot.php-Ebene: yrewrite hat seine eigene
    // Domain-/Pfad-Zuordnung (rex_yrewrite::getCurrentDomain()) zum Zeitpunkt, an dem
    // ai_chats eigenes boot.php laeuft, noch nicht fertig aufgebaut - der Aufruf liefert
    // dort zuverlaessig null, obwohl exakt dieselbe Anfrage beim tatsaechlichen
    // Output-Rendering (hier) korrekt aufloest. Ohne diese Verzoegerung matcht JEDES
    // domain-/sprach-eingeschraenkte Profil nie, das Chat-/Suche-Widget verschwindet
    // komplett, sobald "Anzeigebereich" auf eine bestimmte Domain/Sprache eingeschraenkt
    // wird (unabhaengig davon, ob die aktuelle Domain/Sprache eigentlich passt).
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon, $assetVersion, $streamEnabledAttr) {
        $content = $ep->getSubject();

        $currentDomain = YrewriteDomainResolver::getCurrentDomain();

        // IP-Testmodus (globale Einschränkung, gilt für Chat UND Suche) - siehe
        // ChatQueryService::resolveIpTestGate() für die genaue Bedeutung der beiden Werte.
        // Serverseitig wird exakt dieselbe Prüfung nochmal in process() durchgesetzt (siehe
        // dort) - diese hier steuert nur, ob das Widget überhaupt ins Markup injiziert wird.
        [$ipGateConfigured, $ipAllowed] = ChatQueryService::resolveIpTestGate();
        $ipTestModeActive = $ipGateConfigured && $ipAllowed;

        $frontendProfile = (!$ipGateConfigured || $ipAllowed)
            ? (new ProfileResolver())->resolveForFrontend($currentDomain, rex_clang::getCurrentId(), ChatQueryService::getAuthenticatedBackendUser(), $ipTestModeActive)
            : null;

        // Ohne aufgeloestes Profil ist der Zugriff verweigert - identische Regel
        // serverseitig in ChatQueryService::resolveFrontendAccessDenial().
        $showChat = null !== $frontendProfile && $frontendProfile->chatEnabled;
        $showSearch = null !== $frontendProfile && $frontendProfile->searchEnabled;
        $isTestingMode = null !== $frontendProfile && !in_array('visitor', $frontendProfile->viewerRoles, true);

        if (!$showChat && !$showSearch) {
            return $content;
        }

        if (strpos($content, 'ai-i18n.js') === false) {
            $i18nScript = '<script src="' . $addon->getAssetsUrl('ai-i18n.js?v=' . $assetVersion('ai-i18n.js')) . '"></script>';
            $content = str_replace('</body>', $i18nScript . '</body>', $content);
        }

        if ($showSearch) {
            $searchCss = '<link rel="stylesheet" href="' . $addon->getAssetsUrl('ai-search.css?v=' . $assetVersion('ai-search.css')) . '">';
            $searchScript = '<script src="' . $addon->getAssetsUrl('ai-search.js?v=' . $assetVersion('ai-search.js')) . '"></script>';

            if (strpos($content, 'ai-search.css') === false) {
                if (strpos($content, '</head>') !== false) {
                    $content = str_replace('</head>', $searchCss . '</head>', $content);
                } else {
                    $content = $searchCss . $content;
                }
            }

            if (strpos($content, 'ai-search.js') === false) {
                $content = str_replace('</body>', $searchScript . '</body>', $content);
            }
        }

        $testModeBadge = '';
        if ($isTestingMode) {
            $testModeBadge = '<div style="position:fixed;bottom:8px;left:8px;z-index:99999;background:#e0551b;color:#fff;font:12px/1.4 sans-serif;padding:3px 8px;border-radius:4px;opacity:.85;pointer-events:none;">Testmodus</div>';
        }

        if (!$showChat) {
            return str_replace('</body>', $testModeBadge . '</body>', $content);
        }

        $script = '<script type="module" src="' . $addon->getAssetsUrl('ai-chat.js?v=' . $assetVersion('ai-chat.js')) . '"></script>';

        // Robust URL Generation for Frontend
        $apiUrl = rex_url::frontendController(['rex-api-call' => 'ai_chat_query'], false);
        
        // If the URL doesn't look like a full URL or index.php call, force a standard one
        if (strpos($apiUrl, 'rex-api-call') === false) {
             $apiUrl = '/index.php?rex-api-call=ai_chat_query';
        }

        if (strpos($apiUrl, 'http') === false) {
             $server = rtrim(rex::getServer(), '/');
             if (empty($server)) {
                  $server = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
             }
             $apiUrl = $server . '/' . ltrim($apiUrl, '/');
        }

        // Config - Begrüßung, Personalisierung, Reset/Copy kommen aus dem aufgeloesten
        // Profil. $frontendProfile ist an dieser Stelle garantiert nicht null - $showChat
        // ist oben bereits false (und die Funktion damit verlassen), wenn kein Profil
        // aufgeloest wurde.
        $searchCurrentPageOnly = $addon->getConfig('frontend_search_current_page_only') ? 'true' : 'false';
        $greeting = $frontendProfile->greeting ?? $addon->getConfig('frontend_greeting', 'Hallo! Wie kann ich Ihnen helfen?');
        $frontendResetCountdown = $frontendProfile->chatResetCountdown;
        $frontendCopyHistory = $frontendProfile->chatCopyHistory;
        $personalization = $frontendProfile->personalizationMode;
        $profileIdAttrValue = $frontendProfile->id;
        $uiLanguage = $frontendProfile->uiLanguage;
        // Darstellung: das per Profil gewaehlte Theme (oder, falls keins gewaehlt, das
        // globale Standard-Theme) liefert Farben/Avatar/Eckenradius - die Position bleibt
        // bewusst ein eigenes, vom Theme unabhaengiges Profil-Override (siehe ProfileTheme).
        $frontendTheme = ProfileTheme::resolveTheme($frontendProfile, $addon);
        $position = ProfileTheme::resolvePosition($frontendProfile, $addon);
        $primaryColor = ProfileTheme::resolvePrimaryColor($frontendTheme);
        $mode = $addon->getConfig('frontend_mode', 'bubble');
        $avatarUrl = ProfileTheme::resolveAvatarUrl($frontendTheme);
        $frontendResetAttr = $frontendResetCountdown > 0 ? ' reset-countdown="' . $frontendResetCountdown . '"' : '';
        $frontendCopyAttr = $frontendCopyHistory ? ' copy-history="true"' : '';

        // Theme-Farben/-Radius als CSS-Custom-Properties direkt im style-Attribut des
        // Host-Elements (durchdringen die Shadow-DOM-Grenze), damit keine JS-Änderung am
        // Web Component nötig ist. Nur valide Hex-Farben/Zahlen werden übernommen. Ein
        // Inline-Attribut statt eines <style>-Tags mit Selektor reicht, da pro Seite
        // ohnehin nur ein Frontend-Widget existiert.
        $themeStyleAttr = ProfileTheme::buildInlineStyle($frontendTheme);
        $styleAttr = '' !== $themeStyleAttr ? ' style="' . rex_escape($themeStyleAttr, 'html_attr') . '"' : '';

        $tag = sprintf(
            '<ai-chat api-url="%s" title="Website Chat" search-current-page-only="%s" greeting="%s" position="%s" primary-color="%s" avatar-url="%s" mode="%s" personalization-mode="%s" stream-enabled="%s" max-length-frontend="%d" profile-id="%d" ui-language="%s"%s%s%s></ai-chat>',
            rex_escape($apiUrl, 'html_attr'),
            $searchCurrentPageOnly,
            rex_escape($greeting, 'html_attr'),
            $position,
            $primaryColor,
            $avatarUrl,
            $mode,
            $personalization,
            $streamEnabledAttr,
            (int) $addon->getConfig('max_message_length_frontend', 2000),
            $profileIdAttrValue,
            rex_escape($uiLanguage, 'html_attr'),
            $frontendResetAttr,
            $frontendCopyAttr,
            $styleAttr
        );

        return str_replace('</body>', $script . $tag . $testModeBadge . '</body>', $content);
    });
}
