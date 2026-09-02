<?php

use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\AiChat\Api\ChatIndex as ApiChatIndex;
use FriendsOfRedaxo\AiChat\Api\ChatQuery as ApiChatQuery;
use FriendsOfRedaxo\AiChat\Api\ChatTest as ApiChatTest;
use FriendsOfRedaxo\AiChat\Api\CloudflareModels as ApiCloudflareModels;
use FriendsOfRedaxo\AiChat\Api\ReindexWorker as ApiReindexWorker;
use FriendsOfRedaxo\AiChat\Api\RoutePackage\Backend\AiChat as ApiBackendAiChatRoutePackage;
use FriendsOfRedaxo\AiChat\Api\RoutePackage\AiChat as ApiAiChatRoutePackage;
use FriendsOfRedaxo\AiChat\Api\WidgetTranslations as ApiWidgetTranslations;
use FriendsOfRedaxo\AiChat\Profile\ProfileResolver;
use FriendsOfRedaxo\AiChat\Profile\ProfileTheme;
use FriendsOfRedaxo\AiChat\Service\ChatQueryService;

$addon = rex_addon::get('ai_chat');

// Kein Seitenrecht (steuert keine eigene Unterseite, sondern nur ob der automatisch
// eingebundene Backend-Chat fuer den jeweiligen Nutzer angezeigt wird) - muss daher
// explizit registriert werden, damit es in der Rollen-Verwaltung waehlbar ist.
rex_perm::register('ai_chat[backend_chat]', 'Backend Chat nutzen');

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
    RouteCollection::registerRoutePackage(new ApiBackendAiChatRoutePackage());
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

        if (is_callable([$listenerClass, 'handleEvent'])) {
            foreach ($extensionPoints as $ep) {
                rex_extension::register($ep, [$listenerClass, 'handleEvent'], rex_extension::LATE);
            }
        }

        if (is_callable([$listenerClass, 'handleCategoryEvent'])) {
            rex_extension::register('CAT_STATUS', [$listenerClass, 'handleCategoryEvent'], rex_extension::LATE);
        }
    }

    // YForm-Datensaetze koennen auch ueber Frontend-Formulare (yform_frontend_controller o.ae.)
    // geaendert werden, daher NICHT an einen eingeloggten Backend-User binden.
    if (rex_addon::get('yform')->isAvailable() && is_callable([$listenerClass, 'handleYformEvent'])) {
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
        && rex_be_controller::getCurrentPagePart(2) === 'yform'
    ) {
        rex_view::addJsFile($addon->getAssetsUrl('ai-yform-mapping.js?v=' . $assetVersion('ai-yform-mapping.js')));
    }

    if (
        rex_be_controller::getCurrentPagePart(1) === 'ai_chat'
        && rex_be_controller::getCurrentPagePart(2) === 'settings'
    ) {
        rex_view::addCssFile($addon->getAssetsUrl('ai-chat-settings-backend.css?v=' . $assetVersion('ai-chat-settings-backend.css')));
    }

    // Wir registrieren einen Filter, um das Modul-Script immer zu laden (für die Demo-Seite)
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon, $assetVersion) {
        $page = rex_be_controller::getCurrentPage();
        if (!str_contains($page, 'ai_chat')) {
            return $ep->getSubject();
        }

        $content = $ep->getSubject();
        // Nur wenn nicht schon durch den Backend-Chat hinzugefügt
        if (strpos($content, 'ai-chat.js') === false) {
            $i18nScript = '<script src="' . $addon->getAssetsUrl('ai-i18n.js?v=' . $assetVersion('ai-i18n.js')) . '"></script>';
            $script = $i18nScript . '<script type="module" src="' . $addon->getAssetsUrl('ai-chat.js?v=' . (is_file(rex_path::addon($addon->getName(), 'assets/ai-chat.js')) ? $addon->getVersion() . '-' . (string) filemtime(rex_path::addon($addon->getName(), 'assets/ai-chat.js')) : $addon->getVersion())) . '"></script>';
            $content = str_replace('</body>', $script . '</body>', $content);
        }
        return $content;
    });
}

// Backend: Developer Chat
if (
    rex::isBackend()
    && rex::getUser()
    && $addon->getConfig('backend_enabled')
    && (rex::getUser()->isAdmin() || rex::getUser()->hasPerm('ai_chat[backend_chat]'))
) {
    // backend_enabled bleibt ein globaler Not-Aus-Schalter unabhaengig vom
    // Profil-Matching; zusaetzlich muss ein Backend-Profil fuer die aktuelle
    // Rolle (admin/editor) ueberhaupt existieren, sonst kein Widget.
    $backendProfile = (new ProfileResolver())->resolveForBackend(rex::getUser());

    if (null !== $backendProfile) {
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon, $assetVersion, $streamEnabledAttr, $backendProfile) {
        $content = $ep->getSubject();

        $scriptAttr = 'script-v4-load';
        if (strpos($content, $scriptAttr) === false) {
               $i18nScript = '<script src="' . $addon->getAssetsUrl('ai-i18n.js?v=' . $assetVersion('ai-i18n.js')) . '"></script>';
               $script = $i18nScript . '<script type="module" ' . $scriptAttr . '="true" src="' . $addon->getAssetsUrl('ai-chat.js?v=' . $assetVersion('ai-chat.js')) . '"></script>';
             $content = str_replace('</body>', $script . '</body>', $content);
        }
        
        // Robust URL Generation: Try to use rex_url, fallback to rex::getServer()
        $apiUrl = rex_url::frontendController(['rex-api-call' => 'ai_chat_query']);
        
        // If the URL doesn't look like a full URL or index.php call, force a standard one
        if (strpos($apiUrl, 'rex-api-call') === false) {
             $apiUrl = '/index.php?rex-api-call=ai_chat_query';
        }

        if (strpos($apiUrl, 'http') === false) {
             $server = rtrim(rex::getServer(), '/');
             if (empty($server)) {
                  // Last fallback if server is not set
                  $server = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
             }
             $apiUrl = $server . '/' . ltrim($apiUrl, '/');
        }

        $backendUser = rex::getUser();
        $backendName = 'Admin';
        if ($backendUser) {
            $userName = trim((string) $backendUser->getName());
            if ($userName !== '') {
                $backendName = $userName;
            } else {
                $login = trim((string) $backendUser->getLogin());
                if ($login !== '') {
                    $backendName = $login;
                }
            }
        }

        // Profil-Begrüßung ersetzt bei Bedarf komplett, sonst bleibt die
        // dynamische Namens-Begrüßung wie bisher.
        $backendGreeting = $backendProfile->greeting ?? ('Hallo ' . $backendName . '! Wie kann ich dir im Backend helfen?');

        // UX-Helfer fuer den automatisch eingebundenen Backend-Chat kommen jetzt
        // aus dem aufgeloesten Profil statt aus globaler Config.
        $backendResetCountdown = $backendProfile->chatResetCountdown;
        $backendCopyHistory = $backendProfile->chatCopyHistory;
        $backendResetAttr = $backendResetCountdown > 0 ? ' reset-countdown="' . $backendResetCountdown . '"' : '';
        $backendCopyAttr = $backendCopyHistory ? ' copy-history="true"' : '';
        $maxLengthFrontend = (int) $addon->getConfig('max_message_length_frontend', 2000);
        $maxLengthBackend = (int) $addon->getConfig('max_message_length_backend', 20000);

        // Explicitly set scope to developer and allow switching
        // scope-accent="true" nur hier setzen: die Frontend-/Custom-Branding-Instanz (primary-color,
        // avatar-url, ...) soll von der Backend-only Scope-Akzentfarbe unberührt bleiben.
        $tag = sprintf(
            '<ai-chat api-url="%s" scope="developer" title="REDAXO Chat" allow-scope-switch="true" scope-accent="true" greeting="%s" personalization-mode="off" stream-enabled="%s" max-length-frontend="%d" max-length-backend="%d" profile-id="%d" ui-language="%s"%s%s></ai-chat>',
            rex_escape($apiUrl, 'html_attr'),
            rex_escape($backendGreeting, 'html_attr'),
            $streamEnabledAttr,
            $maxLengthFrontend,
            $maxLengthBackend,
            $backendProfile->id,
            rex_escape($backendProfile->uiLanguage, 'html_attr'),
            $backendResetAttr,
            $backendCopyAttr
        );

        return str_replace('</body>', $tag . '</body>', $content);
    });
    }
}

// Frontend: Visitor Chat + eigenständiges Such-Widget (klxm-search) - unabhängig
// voneinander aktivierbar, damit z.B. nur die Suche ohne Chat-Bubble laufen kann
// oder umgekehrt.
$frontendChatEnabled = (bool) $addon->getConfig('frontend_enabled');
$frontendSearchEnabled = (bool) $addon->getConfig('frontend_search_enabled', true);

if (rex::isFrontend() && ($frontendChatEnabled || $frontendSearchEnabled)) {

    // Chat und Suche im Frontend sind Teil desselben Scopes: EIN aufgeloestes
    // Profil (Kontext/Rolle/Domain/Sprache, siehe ChatProfile) entscheidet
    // Sichtbarkeit fuer BEIDE. "frontend_enabled"/"frontend_search_enabled"
    // bleiben die zwei unabhaengigen Ein/Aus-Schalter innerhalb dieses Scopes
    // (z.B. nur Suche ohne Chat-Bubble) - die alten globalen Testmodus-/
    // Domain-/Sprach-Einstellungen wurden dafuer vollstaendig durch das
    // Profil ersetzt.
    $currentDomain = null;
    if (rex_addon::get('yrewrite')->isAvailable() && class_exists('rex_yrewrite')) {
        $currentDomain = rex_yrewrite::getCurrentDomain();
    }

    // Check Allowed IPs (globale Einschränkung, gilt für Chat UND Suche)
    $allowedIps = $addon->getConfig('frontend_allowed_ips');
    $ipAllowed = true;

    if (!empty($allowedIps)) {
        $ips = array_map('trim', explode(',', $allowedIps));
        $userIp = rex_server('REMOTE_ADDR', 'string', '');
        if (!in_array($userIp, $ips)) {
            $ipAllowed = false;
        }
    }

    $frontendProfile = $ipAllowed
        ? (new ProfileResolver())->resolveForFrontend($currentDomain, rex_clang::getCurrentId(), ChatQueryService::getAuthenticatedBackendUser())
        : null;
    // Profil-Felder chat_enabled/search_enabled sind Tri-State (null = globale Einstellung
    // entscheidet weiterhin, sonst erzwingt das Profil an/aus unabhaengig davon).
    $showChat = null !== $frontendProfile && ($frontendProfile->chatEnabled ?? $frontendChatEnabled);
    $showSearch = null !== $frontendProfile && ($frontendProfile->searchEnabled ?? $frontendSearchEnabled);
    $isTestingMode = null !== $frontendProfile && !in_array('visitor', $frontendProfile->viewerRoles, true);

    if ($showChat || $showSearch) {
        rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon, $assetVersion, $streamEnabledAttr, $showChat, $showSearch, $isTestingMode, $frontendProfile) {
            $content = $ep->getSubject();

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

            // Config - Begrüßung, Personalisierung, Reset/Copy kommen jetzt aus dem
            // aufgeloesten Profil statt aus globaler Config (siehe ChatProfile).
            $searchCurrentPageOnly = $addon->getConfig('frontend_search_current_page_only') ? 'true' : 'false';
            $greeting = $frontendProfile->greeting ?? $addon->getConfig('frontend_greeting', 'Hallo! Wie kann ich Ihnen helfen?');
            // Darstellung: profil-eigene theme_*-Werte gehen vor der globalen Einstellung
            // (siehe ProfileTheme) - ermoeglicht z.B. unterschiedliches Branding je Domain.
            $position = ProfileTheme::resolvePosition($frontendProfile, $addon);
            $primaryColor = ProfileTheme::resolvePrimaryColor($frontendProfile, $addon);
            $mode = $addon->getConfig('frontend_mode', 'bubble');
            // War hier lange hart auf 'false' verdrahtet, obwohl "Scope-Switch erlauben"
            // auf der Darstellung-Einstellungsseite als aktiv nutzbare Option angezeigt
            // wird - die Einstellung blieb dadurch wirkungslos. Serverseitig bleibt
            // scope=developer ohnehin nur fuer authentifizierte Backend-Nutzer erlaubt
            // (siehe ChatQueryService::process()), ein sichtbarer Umschalter fuer
            // anonyme Besucher ist also ungefaehrlich, nur je nach Website ggf. unerwuenschte UX.
            $allowSwitch = $addon->getConfig('frontend_allow_scope_switch') ? 'true' : 'false';
            $avatarUrl = ProfileTheme::resolveAvatarUrl($frontendProfile, $addon);
            $frontendResetCountdown = $frontendProfile->chatResetCountdown;
            $frontendCopyHistory = $frontendProfile->chatCopyHistory;
            $frontendResetAttr = $frontendResetCountdown > 0 ? ' reset-countdown="' . $frontendResetCountdown . '"' : '';
            $frontendCopyAttr = $frontendCopyHistory ? ' copy-history="true"' : '';

            $personalization = $frontendProfile->personalizationMode;
            if ($personalization === '') $personalization = 'off';

            // Theme: profil-eigene Farben/Radius gehen vor der globalen Darstellung-
            // Einstellung (siehe ProfileTheme) - als CSS-Custom-Properties direkt im
            // style-Attribut des Host-Elements (durchdringen die Shadow-DOM-Grenze), damit
            // keine JS-Änderung am Web Component nötig ist. Nur valide Hex-Farben/Zahlen
            // werden übernommen. Ein Inline-Attribut statt eines <style>-Tags mit Selektor
            // reicht, da pro Seite ohnehin nur ein Frontend-Widget existiert.
            $themeStyleAttr = ProfileTheme::buildInlineStyle($frontendProfile, $addon);
            $styleAttr = '' !== $themeStyleAttr ? ' style="' . rex_escape($themeStyleAttr, 'html_attr') . '"' : '';

            $tag = sprintf(
                '<ai-chat api-url="%s" scope="frontend" title="Website Chat" search-current-page-only="%s" greeting="%s" position="%s" primary-color="%s" avatar-url="%s" mode="%s" allow-scope-switch="%s" personalization-mode="%s" stream-enabled="%s" max-length-frontend="%d" max-length-backend="%d" profile-id="%d" ui-language="%s"%s%s%s></ai-chat>',
                rex_escape($apiUrl, 'html_attr'),
                $searchCurrentPageOnly,
                rex_escape($greeting, 'html_attr'),
                $position,
                $primaryColor,
                $avatarUrl,
                $mode,
                $allowSwitch,
                $personalization,
                $streamEnabledAttr,
                (int) $addon->getConfig('max_message_length_frontend', 2000),
                (int) $addon->getConfig('max_message_length_backend', 20000),
                $frontendProfile->id,
                rex_escape($frontendProfile->uiLanguage, 'html_attr'),
                $frontendResetAttr,
                $frontendCopyAttr,
                $styleAttr
            );

            return str_replace('</body>', $script . $tag . $testModeBadge . '</body>', $content);
        });
    }
}
