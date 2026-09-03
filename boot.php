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
use FriendsOfRedaxo\AiChat\Profile\ChatProfile;
use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;
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

// Backend: Developer Chat - haengt bewusst an KEINEM Profil (Profile sind ausschliesslich
// ein Frontend-Konzept, siehe ChatProfile/Sichtbarkeit) - "backend_enabled" (global) plus
// die Berechtigung sind die alleinigen Kriterien. Frueher musste zusaetzlich ein Profil mit
// context=backend/both existieren, sonst blieb der Chat trotz aktivem Schalter unsichtbar.
if (
    rex::isBackend()
    && rex::getUser()
    && $addon->getConfig('backend_enabled')
    && (rex::getUser()->isAdmin() || rex::getUser()->hasPerm('ai_chat[backend_chat]'))
) {
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon, $assetVersion, $streamEnabledAttr) {
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

        // Kein Profil mehr beteiligt (Profile sind rein Frontend, siehe oben) - die
        // dynamische Namens-Begruessung bleibt der einzige Begruessungs-Mechanismus hier.
        $backendGreeting = 'Hallo ' . $backendName . '! Wie kann ich dir im Backend helfen?';
        $maxLengthFrontend = (int) $addon->getConfig('max_message_length_frontend', 2000);
        $maxLengthBackend = (int) $addon->getConfig('max_message_length_backend', 20000);

        // Alle aktiven, nicht rein backend-exklusiven Profile stehen im Scope-Umschalter
        // NEBEN "Developer" zur Auswahl (siehe ai-chat.js render()/getSelectedScopeAndProfileId())
        // - ein Backend-Nutzer kann so jedes Profil live durchklicken, ohne die Seite zu
        // wechseln. "context !== 'backend'" ist reine Altlasten-Absicherung fuer Profile aus
        // vor dieser Aenderung - neue Profile sind ohnehin ausschliesslich Frontend-Profile.
        $profileOptions = [];
        foreach ((new ProfileRepository())->getEnabled() as $profile) {
            if ('backend' === $profile->context) {
                continue;
            }
            $profileOptions[] = ['id' => $profile->id, 'name' => $profile->name];
        }
        // Kein JSON_THROW_ON_ERROR - ein einzelner kaputter Profilname (ungueltiges UTF-8)
        // soll nicht die komplette Seitenausgabe mit einer unbehandelten Exception abreissen.
        // json_encode() liefert dann bool false, (string) false wird '' - derselbe Fall wie
        // "keine Profile", der Scope-Umschalter faellt einfach auf "nur Developer" zurueck.
        $profileOptionsJson = [] !== $profileOptions ? (string) json_encode($profileOptions) : '';

        // Explicitly set scope to developer and allow switching
        // scope-accent="true" nur hier setzen: die Frontend-/Custom-Branding-Instanz (primary-color,
        // avatar-url, ...) soll von der Backend-only Scope-Akzentfarbe unberührt bleiben.
        $tag = sprintf(
            '<ai-chat api-url="%s" scope="developer" title="REDAXO Chat" allow-scope-switch="true" scope-accent="true" greeting="%s" personalization-mode="off" stream-enabled="%s" max-length-frontend="%d" max-length-backend="%d" ui-language="de"%s></ai-chat>',
            rex_escape($apiUrl, 'html_attr'),
            rex_escape($backendGreeting, 'html_attr'),
            $streamEnabledAttr,
            $maxLengthFrontend,
            $maxLengthBackend,
            '' !== $profileOptionsJson ? ' profile-options="' . rex_escape($profileOptionsJson, 'html_attr') . '"' : ''
        );

        return str_replace('</body>', $tag . '</body>', $content);
    });
}

// Frontend: Visitor Chat + eigenständiges Such-Widget (klxm-search) - unabhängig
// voneinander aktivierbar, damit z.B. nur die Suche ohne Chat-Bubble laufen kann
// oder umgekehrt.
$frontendChatEnabled = (bool) $addon->getConfig('frontend_enabled');
$frontendSearchEnabled = (bool) $addon->getConfig('frontend_search_enabled', true);

// Bewusst NICHT zusaetzlich auf ($frontendChatEnabled || $frontendSearchEnabled)
// prüfen: chat_enabled/search_enabled sind je Profil erzwingbar (Tri-State, siehe
// $showChat/$showSearch unten) UNABHAENGIG von den globalen Schaltern - waeren
// beide global aus, wuerde dieser fruehe Kurzschluss ein Profil, das Chat/Suche
// explizit erzwingt, nie zum Zug kommen lassen. Die eigentliche Entscheidung
// passiert ausschliesslich in $showChat/$showSearch weiter unten.
if (rex::isFrontend()) {

    // Chat und Suche im Frontend sind Teil desselben Scopes: EIN aufgeloestes
    // Profil (Kontext/Rolle/Domain/Sprache, siehe ChatProfile) entscheidet
    // Sichtbarkeit fuer BEIDE. "frontend_enabled"/"frontend_search_enabled"
    // bleiben die zwei unabhaengigen Ein/Aus-Schalter innerhalb dieses Scopes
    // (z.B. nur Suche ohne Chat-Bubble) - die alten globalen Testmodus-/
    // Domain-/Sprach-Einstellungen wurden dafuer vollstaendig durch das
    // Profil ersetzt.
    //
    // Domain-/Profil-Aufloesung passiert bewusst ERST HIER, in der OUTPUT_FILTER-
    // Closure, NICHT auf oberster boot.php-Ebene: yrewrite hat seine eigene
    // Domain-/Pfad-Zuordnung (rex_yrewrite::getCurrentDomain()) zum Zeitpunkt, an dem
    // ai_chats eigenes boot.php laeuft, noch nicht fertig aufgebaut - der Aufruf liefert
    // dort zuverlaessig null, obwohl exakt dieselbe Anfrage beim tatsaechlichen
    // Output-Rendering (hier) korrekt aufloest. Ohne diese Verzoegerung matcht JEDES
    // domain-/sprach-eingeschraenkte Profil nie, das Chat-/Suche-Widget verschwindet
    // komplett, sobald "Anzeigebereich" auf eine bestimmte Domain/Sprache eingeschraenkt
    // wird (unabhaengig davon, ob die aktuelle Domain/Sprache eigentlich passt).
    rex_extension::register('OUTPUT_FILTER', function (rex_extension_point $ep) use ($addon, $assetVersion, $streamEnabledAttr, $frontendChatEnabled, $frontendSearchEnabled) {
        $content = $ep->getSubject();

        $currentDomain = YrewriteDomainResolver::getCurrentDomain();

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

        // Sobald mindestens ein aktives, frontend-faehiges Profil existiert, sind die globalen
        // Schalter komplett wirkungslos (siehe auch pages/settings.access.php, dort dann
        // deaktiviert) - Profile sind dann fuer JEDE Anfrage die alleinige Instanz, unabhaengig
        // davon, ob sie zu DIESEM Besucher passen (chat_enabled/search_enabled je Profil
        // defaulten dabei auf "aktiv"). Ohne aktive Profile bleiben die globalen Schalter die
        // alleinige Instanz - Profile sind optional. Identische Pruefung nochmal serverseitig
        // in ChatQueryService::resolveFrontendAccessDenial().
        $hasFrontendProfiles = [] !== array_filter(
            (new ProfileRepository())->getEnabled(),
            static fn (ChatProfile $profile): bool => $profile->context !== 'backend',
        );
        $showChat = $hasFrontendProfiles
            ? (null !== $frontendProfile && ($frontendProfile->chatEnabled ?? true))
            : $frontendChatEnabled;
        $showSearch = $hasFrontendProfiles
            ? (null !== $frontendProfile && ($frontendProfile->searchEnabled ?? true))
            : $frontendSearchEnabled;
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

        // Config - Begrüßung, Personalisierung, Reset/Copy kommen jetzt aus dem
        // aufgeloesten Profil statt aus globaler Config (siehe ChatProfile). Ohne
        // Profil (Profile sind optional, siehe $hasFrontendProfiles oben) faellt jedes
        // einzelne Feld auf sein globales Aequivalent zurueck statt auf einen Absturz
        // durch Property-/Methodenaufruf auf null.
        $searchCurrentPageOnly = $addon->getConfig('frontend_search_current_page_only') ? 'true' : 'false';
        // Explizite if/else statt verketteter ?->/?? - Eigenschaftszugriff auf ein
        // null-Objekt via ?-> waere hier zwar unschaedlich (PHP wertet zu null aus,
        // rex_logger sammelt aber trotzdem eine Warnung je Aufruf, was bei jedem
        // Seitenaufruf ohne aufgeloestes Profil den Log fluten wuerde), daher lieber
        // einmal klar verzweigen als $frontendProfile fuenfmal einzeln "vorsichtig" lesen.
        if (null !== $frontendProfile) {
            $greeting = $frontendProfile->greeting ?? $addon->getConfig('frontend_greeting', 'Hallo! Wie kann ich Ihnen helfen?');
            $frontendResetCountdown = $frontendProfile->chatResetCountdown;
            $frontendCopyHistory = $frontendProfile->chatCopyHistory;
            $personalization = '' !== $frontendProfile->personalizationMode ? $frontendProfile->personalizationMode : (string) $addon->getConfig('personalization_mode', 'off');
            $profileIdAttrValue = $frontendProfile->id;
            $uiLanguage = $frontendProfile->uiLanguage;
        } else {
            $greeting = (string) $addon->getConfig('frontend_greeting', 'Hallo! Wie kann ich Ihnen helfen?');
            $frontendResetCountdown = 0;
            $frontendCopyHistory = false;
            $personalization = (string) $addon->getConfig('personalization_mode', 'off');
            $profileIdAttrValue = 0;
            $uiLanguage = 'de';
        }
        if ('' === $personalization) {
            $personalization = 'off';
        }
        // Darstellung: profil-eigene theme_*-Werte gehen vor der globalen Einstellung
        // (siehe ProfileTheme) - ermoeglicht z.B. unterschiedliches Branding je Domain.
        // ProfileTheme::resolve*()/buildInlineStyle() akzeptieren bewusst ein nullbares
        // Profil und fallen dann direkt auf die globale Einstellung zurueck.
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
        $frontendResetAttr = $frontendResetCountdown > 0 ? ' reset-countdown="' . $frontendResetCountdown . '"' : '';
        $frontendCopyAttr = $frontendCopyHistory ? ' copy-history="true"' : '';

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
            $profileIdAttrValue,
            rex_escape($uiLanguage, 'html_attr'),
            $frontendResetAttr,
            $frontendCopyAttr,
            $styleAttr
        );

        return str_replace('</body>', $script . $tag . $testModeBadge . '</body>', $content);
    });
}
