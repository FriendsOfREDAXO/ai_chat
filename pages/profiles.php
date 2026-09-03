<?php

use FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderRegistry;
use FriendsOfRedaxo\AiChat\ContentProvider\MediaPoolContentProvider;
use FriendsOfRedaxo\AiChat\ContentProvider\YformProfiles;
use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;
use FriendsOfRedaxo\AiChat\Profile\ProfileTheme;

require __DIR__ . '/settings.shared.php';

/**
 * Von settings.shared.php per require in diesen Scope injiziert - PHPStan kann require-
 * Variablenfluss nicht ueber Dateigrenzen hinweg verfolgen, daher hier explizit annotiert.
 *
 * @var rex_addon_interface $addon
 * @var Closure(string): string $rawMsg
 * @var Closure(string, string): string $tooltipLabel
 * @var Closure(mixed): bool $isConfigUnset
 * @var Closure(rex_form_base, string, string, string, bool): rex_form_select_element $addBoolSelectField
 * @var Closure(string, string, string, string=): string $renderInfoPanel
 * @var Closure(string): string $renderTipsList
 * @var Closure(rex_addon_interface, string): string $renderTipsPanel
 * @var string $tooltipInitScript
 * @var Closure(rex_config_form, string): void $renderSettingsPage
 */

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');

if ('delete' === $func) {
    (new ProfileRepository())->delete($id);
    echo rex_view::success('Profil gelöscht.');
    $func = '';
}

if ('add' === $func || 'edit' === $func) {
    $form = rex_form::factory(rex::getTable('ai_chat_profile'), '', 'id=' . $id);
    $form->addParam('id', $id);

    $form->addRawField($renderInfoPanel(
        'Was ist ein Profil?',
        'fa-info-circle',
        '<p>Ein Profil legt fest, <strong>wer</strong> den Chat/die Suche sieht (Kontext, Rolle, Domain, Sprache), <strong>was</strong> er weiß (gemeinsamer Wissens-Pool und/oder eigene Quellen) und <strong>wie</strong> er antwortet (Prompt, Anrede, Begrüßung).</p>'
        . '<p>Mehrere Profile können nebeneinander existieren – z.B. ein offenes für alle Besucher und ein zweites, sprachlich oder inhaltlich isoliertes für einen bestimmten Bereich. Passen mehrere Profile auf dieselbe Anfrage, gewinnt das mit der höheren Priorität. Ein Profil ohne passende Rolle/Domain/Sprache wird nie angezeigt.</p>',
        'panel-info'
    ));

    // Profil testen: unabhaengig davon, ob es fuer die eigene Rolle/Domain/Sprache
    // gerade tatsaechlich aufgeloest wuerde, kann ein angemeldeter Backend-Nutzer
    // hier gezielt GENAU DIESES Profil durchklicken (ChatQueryService vertraut der
    // mitgeschickten profile_id nur authentifizierten Backend-Nutzern - siehe
    // Kommentar in ChatQueryService::process()). Testet immer den GESPEICHERTEN
    // Stand, nicht den ggf. noch ungespeicherten Formularinhalt. Bewusst NICHT Teil
    // von $form (kein addRawField) - lebt stattdessen als eigene Sidebar-Box neben
    // dem Formular, siehe Layout weiter unten.
    $testWidgetHtml = '';
    if ('edit' === $func && $id > 0) {
        $testProfile = (new ProfileRepository())->find($id);
        if (null !== $testProfile) {
            $apiUrl = rex_url::frontendController(['rex-api-call' => 'ai_chat_query']);
            if (strpos($apiUrl, 'rex-api-call') === false) {
                $apiUrl = '/index.php?rex-api-call=ai_chat_query';
            }
            if (strpos($apiUrl, 'http') === false) {
                $server = rtrim(rex::getServer(), '/');
                if ($server === '') {
                    $server = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . rex_server('HTTP_HOST', 'string', '');
                }
                $apiUrl = $server . '/' . ltrim($apiUrl, '/');
            }

            $testGreeting = $testProfile->greeting ?? 'Hallo! Wie kann ich helfen?';
            $testScope = $testProfile->context === 'backend' ? 'developer' : 'frontend';
            $testResetAttr = $testProfile->chatResetCountdown > 0 ? ' reset-countdown="' . $testProfile->chatResetCountdown . '"' : '';
            $testCopyAttr = $testProfile->chatCopyHistory ? ' copy-history="true"' : '';
            $testPersonalization = $testProfile->personalizationMode ?? (string) $addon->getConfig('personalization_mode', 'off');
            $testTheme = ProfileTheme::resolveTheme($testProfile, $addon);
            $testPrimaryColor = ProfileTheme::resolvePrimaryColor($testTheme);
            $testAvatarUrl = ProfileTheme::resolveAvatarUrl($testTheme);
            $testThemeVars = ProfileTheme::buildInlineStyle($testTheme);
            $testInlineStyle = 'display:block;width:100%;--ai-chat-height:480px;' . ('' !== $testThemeVars ? $testThemeVars . ';' : '');

            // Ohne dieses Attribut liest ai-chat.js "stream-enabled" als fehlend und sendet
            // immer non-streaming, unabhaengig von der globalen Einstellung - dieselbe
            // Normalisierung wie boot.php's $isStreamEnabled(), da rex_config je nach dem ob
            // der Wert je per Checkbox oder direkt gesetzt wurde bool, '1', '|1|' oder 'true'
            // enthalten kann.
            $rawStreamEnabled = $addon->getConfig('stream_enabled', false);
            if (is_bool($rawStreamEnabled)) {
                $testStreamEnabled = $rawStreamEnabled;
            } else {
                $normalizedStream = trim((string) $rawStreamEnabled);
                $testStreamEnabled = $normalizedStream === '1' || $normalizedStream === '|1|' || strtolower($normalizedStream) === 'true';
            }

            $testWidgetHtml = '<div class="panel panel-default" style="position:sticky;top:20px;">'
                . '<header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-comments"></i> Profil testen</div></header>'
                . '<div class="panel-body">'
                . '<p class="help-block" style="margin-top:0;">Testet den <strong>gespeicherten</strong> Stand dieses Profils live - ungespeicherte Änderungen links zuerst speichern, dann diese Seite neu laden. "Frontend"/"Developer" oben im Fenster wechselt zwischen den zwei Arten, wie dieses Profil im Chat verwendet werden kann (Website-Besucher vs. automatisch eingebundener Backend-Chat).</p>'
                . sprintf(
                    '<ai-chat id="ai-chat-profile-test-widget" mode="inline" style="%s" api-url="%s" scope="%s" allow-scope-switch="true" title="%s" greeting="%s" primary-color="%s" avatar-url="%s" position="%s" personalization-mode="%s" stream-enabled="%s" profile-id="%d" ui-language="%s"%s%s></ai-chat>',
                    rex_escape($testInlineStyle, 'html_attr'),
                    rex_escape($apiUrl, 'html_attr'),
                    rex_escape($testScope, 'html_attr'),
                    rex_escape($testProfile->name, 'html_attr'),
                    rex_escape($testGreeting, 'html_attr'),
                    rex_escape($testPrimaryColor, 'html_attr'),
                    rex_escape($testAvatarUrl, 'html_attr'),
                    rex_escape(ProfileTheme::resolvePosition($testProfile, $addon), 'html_attr'),
                    rex_escape($testPersonalization, 'html_attr'),
                    $testStreamEnabled ? 'true' : 'false',
                    $testProfile->id,
                    rex_escape($testProfile->uiLanguage, 'html_attr'),
                    $testResetAttr,
                    $testCopyAttr
                )
                . '</div></div>';
        }
    }

    // Reihenfolge der Boxen entspricht bewusst der Reihenfolge, in der man ein Profil
    // sinnvollerweise durcharbeitet: 1) benennen, 2) WER/WO es ueberhaupt sieht, 3) WAS es
    // weiss, 4) WIE es antwortet, 5) WIE es aussieht.
    $form->addRawField('<div class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">Allgemeine Einstellungen</p>');

    $field = $form->addTextField('name');
    $field->setLabel('Name');
    $field->setNotice('Nur intern sichtbar, zur Wiedererkennung in der Liste.');

    // Bewusst ein Select statt Checkbox: REDAXOs rex_form_element::setValue()
    // wandelt ein GEPRÜFTES Checkbox-Feld (kommt als Array im POST an, auch bei
    // nur einer Option) in einen von Pipes umschlossenen String ("|1|") um -
    // das landet dann als nicht-numerischer String in der echten tinyint(1)-
    // Spalte und wird beim Speichern stillschweigend zu 0. Ein normales
    // (nicht-multiples) Select liefert dagegen einfach den gewählten Wert.
    $field = $form->addSelectField('status');
    $field->setLabel('Status');
    $select = $field->getSelect();
    $select->addOption('Aktiv', '1');
    $select->addOption('Inaktiv', '0');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('1');
    }

    $field = $form->addTextField('priority');
    $field->setLabel($tooltipLabel('Priorität', 'config_profile_priority_notice'));
    $field->setNotice('Passen mehrere Profile auf dieselbe Anfrage, gewinnt die höchste Priorität (Zahl), bei Gleichstand die niedrigere ID.');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('0');
    }
    $form->addRawField('</div>');

    // Kontext & Sichtbarkeit
    $form->addRawField('<div class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">Sichtbarkeit</p>');
    $form->addRawField('<p class="help-block" style="margin-top:-8px;">Wer dieses Profil überhaupt sehen kann, und wo (welche Domain/Sprache) bzw. ob Chat/Suche automatisch eingebunden werden.</p>');

    // Kein "Kontext"-Auswahlfeld mehr: Profile sind ausschliesslich ein Frontend-Konzept,
    // der Backend-Chat (siehe boot.php) ist rein global per "backend_enabled" gesteuert und
    // kennt gar kein Profil. Die DB-Spalte bleibt (Default jetzt 'frontend', siehe
    // install.php) fuer Abwaertskompatibilitaet mit Alt-Profilen aus der Zeit, als ein
    // Profil auch den Backend-Chat steuern konnte - wird aber im Formular nicht mehr
    // angeboten, ein neues Profil bekommt immer 'frontend'.
    $field = $form->addHiddenField('context', 'frontend');

    $field = $form->addSelectField('viewer_roles');
    $field->setLabel('Sichtbar für');
    $field->setNotice('Ohne Auswahl sieht niemand dieses Profil – es wird dann nie aufgelöst. Ohne "Besucher" wirkt es wie ein Testmodus: nur eingeloggte Redakteure/Admins sehen es (mit Hinweis-Badge im Frontend). Alternative für nicht eingeloggte Tester: der globale IP-Testmodus (Einstellungen → Zugriff) hebt diese Einschränkung für zugelassene IPs auf, unabhängig vom Login.');
    $field->setAttribute('class', 'selectpicker');
    $field->setAttribute('data-actions-box', 'true');
    $select = $field->getSelect();
    $select->setMultiple();
    $select->setSize(3);
    $select->addOption('Besucher (nicht angemeldet)', 'visitor');
    $select->addOption('Redakteure (angemeldete Backend-User)', 'editor');
    $select->addOption('Admins', 'admin');

    // Anzeigebereich (Domain/Sprache) ist ein reines Frontend-Konzept - für ein
    // Profil mit Kontext "Backend" blendet JS diesen ganzen Block aus.
    $form->addRawField('<div id="ai-chat-profile-frontend-only">');

    // Sobald mindestens ein aktives Profil existiert, sind die globalen Schalter
    // (AI Chat → Zugriff) komplett wirkungslos und dort auch deaktiviert - Profile sind
    // dann die alleinige Instanz. "Standard" (leer) bedeutet deshalb "aktiv", nicht mehr
    // "globale Einstellung entscheidet" - siehe boot.php $showChat/$showSearch und
    // ChatQueryService::resolveFrontendAccessDenial(). "Ja"/"Nein" bleiben fuer den Fall,
    // ein Profil soll trotzdem NUR die Suche ohne Chat-Bubble zeigen o.ae.
    $field = $form->addSelectField('chat_enabled');
    $field->setLabel('Chat automatisch einbinden');
    $select = $field->getSelect();
    $select->addOption('Standard (aktiv)', '');
    $select->addOption('Ja (erzwungen)', '1');
    $select->addOption('Nein (deaktiviert)', '0');
    $field->setNotice('Solange mindestens ein aktives Profil existiert, hat die globale "Chat im Frontend anzeigen"-Einstellung keine Wirkung mehr - dieses Feld entscheidet dann allein. Ohne aktive Profile gilt stattdessen wieder ausschließlich die globale Einstellung.');

    $field = $form->addSelectField('search_enabled');
    $field->setLabel('Suche automatisch einbinden');
    $select = $field->getSelect();
    $select->addOption('Standard (aktiv)', '');
    $select->addOption('Ja (erzwungen)', '1');
    $select->addOption('Nein (deaktiviert)', '0');
    $field->setNotice('Solange mindestens ein aktives Profil existiert, hat die globale "Suche im Frontend aktivieren"-Einstellung keine Wirkung mehr - dieses Feld entscheidet dann allein. Ohne aktive Profile gilt stattdessen wieder ausschließlich die globale Einstellung.');

    $field = $form->addSelectField('target_mode');
    $field->setLabel($tooltipLabel('Anzeigebereich (Domain/Sprache)', 'config_profile_target_mode_notice'));
    $field->setNotice('Nur relevant im Frontend.');
    $field->setAttribute('id', 'ai-chat-profile-target-mode');
    $select = $field->getSelect();
    $select->addOption('Alle', 'all');
    $select->addOption('Nur bestimmte Domains', 'domains');
    $select->addOption('Nur bestimmte Sprachen', 'clangs');
    $select->addOption('Domains und Sprachen', 'domains_clangs');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('all');
    }

    $yrewriteDomains = [];
    if (rex_addon::get('yrewrite')->isAvailable() && class_exists('rex_yrewrite')) {
        $yrewriteDomains = rex_yrewrite::getDomains();
    }
    $form->addRawField('<div id="ai-chat-profile-target-domains">');
    if (count($yrewriteDomains) > 1) {
        $domainField = $form->addSelectField('domains');
        $domainField->setLabel('Domains');
        $domainField->setAttribute('class', 'selectpicker');
        $domainField->setAttribute('data-actions-box', 'true');
        $domainSelect = $domainField->getSelect();
        $domainSelect->setMultiple();
        $domainSelect->setSize(min(count($yrewriteDomains), 6));
        foreach ($yrewriteDomains as $domain) {
            $domainSelect->addOption($domain->getName() . ' (' . $domain->getHost() . ')', $domain->getName());
        }
    } else {
        // Nicht einfach ausblenden: "Nur bestimmte Domains"/"Domains und Sprachen" bleiben
        // im Anzeigebereich-Select trotzdem waehlbar (die Auswahl ist unabhaengig von der
        // aktuellen yrewrite-Konfiguration) - ohne diesen Hinweis wirkt eine gewaehlte
        // Domain-Einschraenkung wie ein leeres, verschwundenes Feld statt einer bewussten
        // Nicht-Verfuegbarkeit ("nur eine Domain vorhanden, es gibt nichts einzuschraenken").
        $form->addRawField('<p class="help-block">Nur eine Domain konfiguriert (siehe AI Chat → yrewrite → Domains) - eine Domain-Einschränkung ist damit wirkungslos, es gibt nichts zum Auswählen.</p>');
    }
    $form->addRawField('</div>');

    $allClangs = rex_clang::getAll();
    $form->addRawField('<div id="ai-chat-profile-target-clangs">');
    if (count($allClangs) > 1) {
        $clangField = $form->addSelectField('clangs');
        $clangField->setLabel('Sprachen');
        $clangField->setAttribute('class', 'selectpicker');
        $clangField->setAttribute('data-actions-box', 'true');
        $clangSelect = $clangField->getSelect();
        $clangSelect->setMultiple();
        $clangSelect->setSize(min(count($allClangs), 6));
        foreach ($allClangs as $clang) {
            $clangSelect->addOption($clang->getName(), (string) $clang->getId());
        }
    } else {
        $form->addRawField('<p class="help-block">Nur eine Sprache eingerichtet - eine Sprach-Einschränkung ist damit wirkungslos, es gibt nichts zum Auswählen.</p>');
    }
    $form->addRawField('</div>');
    $form->addRawField('</div>'); // #ai-chat-profile-frontend-only
    $form->addRawField('</div>');

    $form->addRawField('<div class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">Individuelle Einstellungen (Indizierung)</p>');
    $form->addRawField('<p class="help-block" style="margin-top:-8px;">Was dieses Profil wissen darf, unabhängig davon, wer es sieht: gemeinsamer Wissens-Pool und/oder eigene Quellen (Sitemap, Struktur-Bereich, YForm-Tabellen, PDFs).</p>');

    // use_shared_scope und extra_source stehen bewusst direkt nebeneinander (statt
    // extra_source erst nach den YForm-/PDF-Feldern) - beide bestimmen zusammen den
    // groben Wissens-Scope des Profils ("globalen Pool nutzen?" + "eine dritte, eigene
    // Quelle zusätzlich?"), bevor es an die konkrete Quellenauswahl (YForm/PDF) geht.

    // Select statt Checkbox - siehe Kommentar bei "status" oben.
    $field = $form->addSelectField('use_shared_scope');
    $field->setLabel($tooltipLabel('Gemeinsamer Wissens-Pool', 'config_profile_shared_scope_notice'));
    $select = $field->getSelect();
    $select->addOption('Zusätzlich nutzen', '1');
    $select->addOption('Nicht nutzen (isolierter Wissensstand)', '0');
    $field->setNotice('Der gemeinsame Pool ist alles, was unter Einstellungen → Indexierung global aktiviert/konfiguriert ist (Struktur/Artikel, globale Sitemap-URLs, Addon-/GitHub-Docs, globale PDFs sowie global aktivierte Content-Provider wie YForm/forcal). "Nicht nutzen": Dieses Profil sieht ausschließlich seine eigenen, unten gewählten Quellen - ein vollständig isolierter Wissensstand.');
    $currentUseSharedScope = (string) $field->getValue();
    if ('add' === $func && '' === $currentUseSharedScope) {
        $field->setValue('1');
        $currentUseSharedScope = '1';
    }

    $field = $form->addSelectField('extra_source');
    $field->setLabel('Eigene Sitemap/Struktur-Quelle');
    $field->setNotice('Eine optionale VIERTE Inhaltsquelle, zusätzlich zu „Gemeinsamer Wissens-Pool" oben und den YForm-/PDF-Auswahlen unten. „Keine" = einfach weglassen, ändert nichts an den anderen drei. Beispiel für eine auf eine einzige Quelle spezialisierte Suche (z.B. nur PDFs): hier „Keine" UND oben „Gemeinsamer Wissens-Pool" auf „Nicht nutzen" stellen, dann unten nur PDFs auswählen.');
    $field->setAttribute('id', 'ai-chat-profile-extra-source');
    $select = $field->getSelect();
    $select->addOption('Keine zusätzliche Quelle', 'none');
    $select->addOption('Eigene Sitemap', 'sitemap');
    $select->addOption('Struktur-Mountpoint (Kategorie-Teilbaum)', 'mountpoint');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('none');
    }

    $form->addRawField('<div id="ai-chat-profile-source-sitemap">');
    // Benannte Sitemap-Gruppen (z.B. "Allgemein"/"News") statt eines einzelnen Textfelds -
    // jede Gruppe wird beim Indexieren mit ihrem Namen als source_label markiert (siehe
    // IndexerService::collectProfileTasks()), taucht dadurch als eigener Filter in der Suche
    // auf (ChatQueryService::search()) und hilft dem Chat, Quellen einzuordnen
    // (PromptBuilder). Gepflegt per JS-Repeater (Vorbild: pages/yform.php), gespeichert als
    // JSON im versteckten Feld "sitemap_groups" (echter, an die DB-Spalte gebundener
    // rex_form-Wert - siehe $sitemapGroupsField unten).
    $sitemapGroupsField = $form->addTextField('sitemap_groups');
    $sitemapGroupsField->setAttribute('type', 'hidden');
    $sitemapGroupsField->setAttribute('id', 'ai-chat-sitemap-groups-value');
    $currentSitemapGroups = json_decode((string) $sitemapGroupsField->getValue(), true);
    $currentSitemapGroups = is_array($currentSitemapGroups) ? $currentSitemapGroups : [];

    $form->addRawField('<label>Sitemap-Quellen</label>');
    $form->addRawField('<p class="help-block" style="margin-top:0;">Ein oder mehrere Sitemap-URLs, optional mit Namen gruppiert (z.B. "News") - Treffer aus einer benannten Gruppe erscheinen in der Suche als eigener Filter und werden dem Chat als eigener Themenbereich mitgeteilt. Name leer lassen = unbenannt (bisheriges Verhalten).</p>');
    $form->addRawField('<div id="ai-chat-sitemap-groups-repeater">');
    $form->addRawField('<div class="ai-chat-sitemap-groups-items">');
    if ([] === $currentSitemapGroups) {
        $currentSitemapGroups = [['label' => '', 'urls' => ['']]];
    }
    foreach ($currentSitemapGroups as $group) {
        $groupLabel = is_array($group) ? (string) ($group['label'] ?? '') : '';
        $groupDescription = is_array($group) ? (string) ($group['description'] ?? '') : '';
        $groupIsTimely = is_array($group) && !empty($group['is_timely']);
        $groupUrls = is_array($group) && is_array($group['urls'] ?? null) ? implode("\n", $group['urls']) : '';
        $form->addRawField(
            '<div class="ai-chat-sitemap-group panel panel-default" style="padding:10px;margin-bottom:10px;">'
            . '<div class="row"><div class="col-md-4"><label>Name (optional)</label><input type="text" class="form-control" data-group-label placeholder="z.B. News" value="' . rex_escape($groupLabel, 'html_attr') . '"></div>'
            . '<div class="col-md-8"><label>Sitemap-URLs (eine pro Zeile)</label><textarea class="form-control" rows="2" data-group-urls placeholder="https://example.com/sitemap.xml">' . rex_escape($groupUrls) . '</textarea></div></div>'
            . '<div class="row" style="margin-top:8px;"><div class="col-md-8"><label>Beschreibung (optional)</label><input type="text" class="form-control" data-group-description placeholder="z.B. Aktuelle Nachrichten und Berichte des Verbands" value="' . rex_escape($groupDescription, 'html_attr') . '"><p class="help-block">Hilft der KI, diesen Bereich thematisch einzuordnen - fließt als Zusatzkontext mit ein.</p></div>'
            . '<div class="col-md-4"><label>&nbsp;</label><div class="checkbox"><label><input type="checkbox" data-group-is-timely' . ($groupIsTimely ? ' checked' : '') . '> Aktuelle/zeitkritische Inhalte (z.B. News)</label></div><p class="help-block">Wird bei Fragen nach "aktuell"/"neu"/"zuletzt" bevorzugt.</p></div></div>'
            . '<button type="button" class="btn btn-danger btn-xs" style="margin-top:8px;" data-remove-group>Gruppe entfernen</button>'
            . '</div>',
        );
    }
    $form->addRawField('</div>');
    $form->addRawField('<button type="button" class="btn btn-default btn-sm" id="ai-chat-sitemap-group-add">+ Sitemap-Gruppe hinzufügen</button>');
    $form->addRawField('<template id="ai-chat-sitemap-group-template"><div class="ai-chat-sitemap-group panel panel-default" style="padding:10px;margin-bottom:10px;">'
        . '<div class="row"><div class="col-md-4"><label>Name (optional)</label><input type="text" class="form-control" data-group-label placeholder="z.B. News" value=""></div>'
        . '<div class="col-md-8"><label>Sitemap-URLs (eine pro Zeile)</label><textarea class="form-control" rows="2" data-group-urls placeholder="https://example.com/sitemap.xml"></textarea></div></div>'
        . '<div class="row" style="margin-top:8px;"><div class="col-md-8"><label>Beschreibung (optional)</label><input type="text" class="form-control" data-group-description placeholder="z.B. Aktuelle Nachrichten und Berichte des Verbands"><p class="help-block">Hilft der KI, diesen Bereich thematisch einzuordnen - fließt als Zusatzkontext mit ein.</p></div>'
        . '<div class="col-md-4"><label>&nbsp;</label><div class="checkbox"><label><input type="checkbox" data-group-is-timely> Aktuelle/zeitkritische Inhalte (z.B. News)</label></div><p class="help-block">Wird bei Fragen nach "aktuell"/"neu"/"zuletzt" bevorzugt.</p></div></div>'
        . '<button type="button" class="btn btn-danger btn-xs" style="margin-top:8px;" data-remove-group>Gruppe entfernen</button>'
        . '</div></template>');
    $form->addRawField('</div>');
    $form->addRawField('</div>');

    $form->addRawField('<div id="ai-chat-profile-source-mountpoint">');
    $field = $form->addSelectField('mountpoint_category_id');
    $field->setLabel('Mountpoint-Kategorie');
    $field->setNotice('Dieses Profil indexiert dann exklusiv alle Artikel in dieser Kategorie und ihren Unterkategorien.');
    $field->setAttribute('class', 'selectpicker');
    $field->setAttribute('data-live-search', 'true');
    // rex_category_select (Kern-Widget aus dem structure-Addon, siehe auch
    // pages/settings.indexing.php) statt Zahlenfeld mit der ID von Hand - rendert die
    // Kategorien nativ hierarchisch eingerückt (inkl. "[ID]"-Suffix je Option), kein
    // Nachschlagen der ID mehr nötig. addHomepage=false + eigene Leer-Option zuerst, damit
    // "nichts ausgewählt" nicht mit der Kategorie 0 (Homepage) verwechselt werden kann - bei
    // einem Single-Select sendet der Browser sonst immer die erste Option, auch ohne
    // bewusste Auswahl.
    $mountpointCategorySelect = new rex_category_select(false, false, false, false);
    $mountpointCategorySelect->addOption('Bitte wählen…', '');
    $field->setSelect($mountpointCategorySelect);
    $form->addRawField('</div>');

    // Globaler YForm-Provider deckt IMMER ALLE Mappings ab (siehe YformContentProvider::
    // collectTasks() -> YformProfiles::getAll(), keine Teilauswahl moeglich) - waehlt ein
    // Profil mit aktivem Shared Pool zusaetzlich eine hier bereits global geteilte Tabelle
    // als "eigene" Quelle, wird exakt dieselbe Tabelle doppelt indexiert (einmal geteilt,
    // einmal profil-exklusiv) und taucht in der Suche/im Chat doppelt als Quelle auf.
    $globalYformProviderEnabled = in_array('yform', array_map(
        static fn ($provider) => $provider->getKey(),
        (new ContentProviderRegistry())->getEnabledProviders($addon),
    ), true);

    $yformProfiles = YformProfiles::getAll($addon);
    if ([] !== $yformProfiles) {
        $field = $form->addSelectField('yform_profile_ids');
        $field->setLabel('Eigene YForm-Quellen');
        $field->setNotice('Zusätzlich zum Shared Pool (falls aktiviert) für dieses Profil indexierte YForm-Tabellen (verwaltet unter AI Chat → YForm-Tabellen).' . ($globalYformProviderEnabled ? ' Achtung: YForm ist bereits global aktiviert (Einstellungen → Indexierung) - dort erfasste Tabellen sind damit schon Teil des Shared Pools, eine zusätzliche Auswahl hier würde sie doppelt indexieren.' : ''));
        $field->setAttribute('class', 'selectpicker');
        $field->setAttribute('data-actions-box', 'true');
        $select = $field->getSelect();
        $select->setMultiple();
        $select->setSize(min(count($yformProfiles), 6));
        foreach ($yformProfiles as $yformProfile) {
            $select->addOption((string) ($yformProfile['label'] ?? $yformProfile['id']), (string) $yformProfile['id']);
        }
        $currentYformProfileIds = array_filter((array) $field->getValue());
        if ($globalYformProviderEnabled && '1' === $currentUseSharedScope && [] !== $currentYformProfileIds) {
            $form->addRawField(rex_view::warning(
                'YForm ist global aktiviert UND dieses Profil hat eigene YForm-Quellen gewählt, bei aktivem Shared Pool - die gewählten Tabellen werden dadurch doppelt indexiert (geteilt + profil-exklusiv). Entweder den Shared Pool oben deaktivieren, oder hier nur Tabellen wählen, die NICHT bereits über den globalen YForm-Provider laufen.'
            ));
        }
    } else {
        $form->addRawField('<p class="help-block">Keine YForm-Tabellen-Mappings konfiguriert – siehe AI Chat → YForm-Tabellen, um welche anzulegen.</p>');
    }

    if (rex_addon::get('mediapool')->isAvailable()) {
        MediaPoolContentProvider::renderSourceFields(
            $form,
            'pdf_media_ids',
            'Eigene PDF-Dokumente',
            'Zusätzlich zum Shared Pool (falls aktiviert) für dieses Profil indexierte PDF-Dateien aus dem Medienpool. Nur PDFs werden verarbeitet, andere Dateitypen in der Auswahl werden ignoriert. Achtung: ist dieselbe Datei bereits global unter Einstellungen → Indexierung ausgewählt, wird sie bei aktivem Shared Pool doppelt indexiert.',
            'pdf_category_ids',
            'PDFs aus Medienpool-Kategorien',
            'Alle PDF-Dateien in diesen Medienpool-Kategorien (nicht rekursiv in Unterkategorien) werden zusätzlich zu den oben einzeln gewählten Dokumenten indexiert. Achtung: ist dieselbe Kategorie bereits global ausgewählt, wird sie bei aktivem Shared Pool doppelt indexiert.',
        );
    }

    $form->addRawField('</div>');

    // Verhalten
    $form->addRawField('<div class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">Verhalten</p>');

    $field = $form->addTextAreaField('custom_prompt');
    $field->setLabel($tooltipLabel('Eigener Prompt', 'config_profile_custom_prompt_notice'));
    $field->setNotice('Ersetzt den global konfigurierten Frontend-Prompt für dieses Profil. Leer = globale Einstellung wird verwendet. Wirkt nicht auf den festen Developer-Systemprompt im Backend-Kontext.');
    $field->setAttribute('rows', '4');

    $field = $form->addTextField('ui_language');
    $field->setLabel('Oberflächen-Sprache');
    $field->setNotice('Sprachcode für die Widget-Oberfläche (Buttons, Platzhalter wie "Nachricht schreiben..."), z.B. "de" oder "en" - unabhängig von der Sprache der KI-Antwort (siehe "Antwortsprache der KI" unten). Für nicht unterstützte Sprachcodes fällt die Oberfläche automatisch auf Deutsch zurück.');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('de');
    }

    $field = $form->addTextField('answer_language');
    $field->setLabel('Antwortsprache der KI (optional)');
    $field->setAttribute('placeholder', 'z.B. Englisch');
    $field->setNotice('Sprache, in der die KI antwortet - unabhängig von der Oberflächen-Sprache oben. Leer lassen = wie bisher Deutsch. Als Sprachname eintragen (z.B. "Englisch", "Französisch"), nicht als Code - die KI folgt einem ausgeschriebenen Namen zuverlässiger als "en"/"fr".');

    $field = $form->addTextAreaField('greeting');
    $field->setLabel('Begrüßung');
    $field->setNotice('Leer = Standard-Begrüßung (Frontend) bzw. dynamische Namens-Begrüßung (Backend).');
    $field->setAttribute('rows', '2');

    $addressingModeLabels = [
        'auto' => 'Automatisch (Personalisierung)',
        'formal' => 'Immer Sie',
        'informal' => 'Immer Du',
        'neutral' => 'Neutral',
    ];
    $currentGlobalAddressingMode = trim((string) $addon->getConfig('frontend_addressing_mode', 'auto'));
    $field = $form->addSelectField('addressing_mode');
    $field->setLabel('Anrede');
    $select = $field->getSelect();
    $select->addOption('Globale Einstellung übernehmen', '');
    foreach ($addressingModeLabels as $value => $label) {
        $select->addOption($label, $value);
    }
    $field->setNotice(sprintf(
        'Wie die KI den Besucher anspricht. Leer = aktuell global eingestellter Wert wird verwendet (derzeit „%s", siehe Einstellungen → Verhalten → Chat).',
        $addressingModeLabels[$currentGlobalAddressingMode] ?? $currentGlobalAddressingMode
    ));

    $personalizationModeLabels = [
        'off' => 'Aus',
        'simple' => 'Einfach (Du/Sie erfragen)',
        'name' => 'Mit Namen',
    ];
    $currentGlobalPersonalizationMode = trim((string) $addon->getConfig('personalization_mode', 'off'));
    $field = $form->addSelectField('personalization_mode');
    $field->setLabel('Personalisierung');
    $select = $field->getSelect();
    $select->addOption('Globale Einstellung übernehmen', '');
    foreach ($personalizationModeLabels as $value => $label) {
        $select->addOption($label, $value);
    }
    $field->setNotice(sprintf(
        'Ob/wie die KI den Besucher zu Beginn nach Anrede bzw. Namen fragt, um beides in spätere Antworten einzubauen. Leer = aktuell global eingestellter Wert wird verwendet (derzeit „%s", siehe Einstellungen → Verhalten → Chat).',
        $personalizationModeLabels[$currentGlobalPersonalizationMode] ?? $currentGlobalPersonalizationMode
    ));

    $field = $form->addTextField('chat_reset_countdown');
    $field->setLabel('Reset-Countdown (Sekunden)');
    $field->setNotice('Betrifft den "Verlauf löschen"-Button im Chat-Fenster: statt einer sofortigen Ja/Nein-Sicherheitsabfrage zeigt der Button beim Klick diese Anzahl Sekunden lang einen Countdown an, bevor der Verlauf automatisch gelöscht wird. 0 = stattdessen die normale Sicherheitsabfrage anzeigen.');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('0');
    }

    // Select statt Checkbox - siehe Kommentar bei "status" oben.
    $field = $form->addSelectField('chat_copy_history');
    $field->setLabel('Verlauf kopieren/downloaden');
    $field->setNotice('Blendet einen Button im Chat-Fenster ein, mit dem der Besucher den gesamten bisherigen Gesprächsverlauf als Text kopieren oder herunterladen kann.');
    $select = $field->getSelect();
    $select->addOption('Erlauben', '1');
    $select->addOption('Nicht erlauben', '0');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('0');
    }
    $form->addRawField('</div>');

    // Darstellung: Farben/Avatar/Eckenradius kommen seit der zentralen Theme-Verwaltung
    // aus einem zentral gepflegten, wiederverwendbaren Theme (AI Chat -> Themes) statt aus
    // eigenen Farbfeldern je Profil - hier wird nur noch AUSGEWAEHLT. Die Position bleibt
    // bewusst ein eigenes, vom Theme unabhaengiges Override (siehe ProfileTheme).
    $form->addRawField('<div class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">Darstellung</p>');

    $field = $form->addSelectField('theme_id');
    $field->setLabel('Theme');
    $select = $field->getSelect();
    $select->addOption('Globales Standard-Theme verwenden', '');
    foreach ((new \FriendsOfRedaxo\AiChat\Profile\ThemeRepository())->getAll() as $theme) {
        $select->addOption($theme->name, (string) $theme->id);
    }
    $field->setNotice('Farben, Avatar und Eckenradius kommen aus dem hier gewählten Theme. Themes werden zentral unter <a href="' . rex_url::backendPage('ai_chat/themes') . '">AI Chat → Themes</a> angelegt und gepflegt - dieselbe Änderung dort wirkt sich automatisch auf jedes Profil aus, das dieses Theme verwendet.');

    $field = $form->addSelectField('theme_position');
    $field->setLabel('Position');
    $select = $field->getSelect();
    $select->addOption('Globale Einstellung', '');
    $select->addOption('Unten rechts', 'bottom-right');
    $select->addOption('Unten links', 'bottom-left');
    $field->setNotice('Wo das Widget auf dem Bildschirm erscheint - unabhängig vom gewählten Theme.');

    $form->addRawField('</div>');

    $form->addRawField($tooltipInitScript);
    $form->addRawField('
<script>
(function() {
    function initAiChatProfileForm() {
        var contextSelect = document.getElementById("ai-chat-profile-context");
        var targetModeSelect = document.getElementById("ai-chat-profile-target-mode");
        var extraSourceSelect = document.getElementById("ai-chat-profile-extra-source");

        var frontendOnly = document.getElementById("ai-chat-profile-frontend-only");
        var targetDomains = document.getElementById("ai-chat-profile-target-domains");
        var targetClangs = document.getElementById("ai-chat-profile-target-clangs");
        var sourceSitemap = document.getElementById("ai-chat-profile-source-sitemap");
        var sourceMountpoint = document.getElementById("ai-chat-profile-source-mountpoint");

        function updateContextVisibility() {
            if (!contextSelect || !frontendOnly) return;
            frontendOnly.style.display = contextSelect.value === "backend" ? "none" : "block";
        }

        function updateTargetModeVisibility() {
            if (!targetModeSelect) return;
            var mode = targetModeSelect.value;
            if (targetDomains) {
                targetDomains.style.display = (mode === "domains" || mode === "domains_clangs") ? "block" : "none";
            }
            if (targetClangs) {
                targetClangs.style.display = (mode === "clangs" || mode === "domains_clangs") ? "block" : "none";
            }
        }

        function updateExtraSourceVisibility() {
            if (!extraSourceSelect) return;
            var source = extraSourceSelect.value;
            if (sourceSitemap) {
                sourceSitemap.style.display = source === "sitemap" ? "block" : "none";
            }
            if (sourceMountpoint) {
                sourceMountpoint.style.display = source === "mountpoint" ? "block" : "none";
            }
        }

        if (contextSelect) {
            contextSelect.addEventListener("change", updateContextVisibility);
            updateContextVisibility();
        }
        if (targetModeSelect) {
            targetModeSelect.addEventListener("change", updateTargetModeVisibility);
            updateTargetModeVisibility();
        }
        if (extraSourceSelect) {
            extraSourceSelect.addEventListener("change", updateExtraSourceVisibility);
            updateExtraSourceVisibility();
        }

        // Sitemap-Gruppen-Repeater: Gruppen hinzufuegen/entfernen per Template-Klonen
        // (Vorbild: pages/yform.php), Zustand wird erst beim Submit ins versteckte
        // sitemap_groups-Feld serialisiert - kein Zwischenspeichern bei jeder Aenderung noetig.
        var repeaterItems = document.querySelector("#ai-chat-sitemap-groups-repeater .ai-chat-sitemap-groups-items");
        var addGroupBtn = document.getElementById("ai-chat-sitemap-group-add");
        var groupTemplate = document.getElementById("ai-chat-sitemap-group-template");
        var hiddenGroupsField = document.getElementById("ai-chat-sitemap-groups-value");
        var profileForm = hiddenGroupsField ? hiddenGroupsField.closest("form") : null;

        function removeGroupBlock(event) {
            var target = event.target.closest(".ai-chat-sitemap-group");
            if (!target || !repeaterItems) return;
            // Mindestens eine Gruppe stehen lassen, sonst gibt es keinen Ausloeser mehr fuer
            // "+ Sitemap-Gruppe hinzufügen" bei einem komplett geleerten Profil.
            if (repeaterItems.querySelectorAll(".ai-chat-sitemap-group").length <= 1) {
                target.querySelector("[data-group-label]").value = "";
                target.querySelector("[data-group-urls]").value = "";
                target.querySelector("[data-group-description]").value = "";
                target.querySelector("[data-group-is-timely]").checked = false;
                return;
            }
            target.remove();
        }

        if (repeaterItems) {
            repeaterItems.addEventListener("click", function (event) {
                if (event.target.closest("[data-remove-group]")) {
                    removeGroupBlock(event);
                }
            });
        }

        if (addGroupBtn && groupTemplate && repeaterItems) {
            addGroupBtn.addEventListener("click", function () {
                var clone = groupTemplate.content.cloneNode(true);
                repeaterItems.appendChild(clone);
            });
        }

        if (profileForm && hiddenGroupsField) {
            profileForm.addEventListener("submit", function () {
                var groups = [];
                document.querySelectorAll("#ai-chat-sitemap-groups-repeater .ai-chat-sitemap-group").forEach(function (block) {
                    var labelInput = block.querySelector("[data-group-label]");
                    var urlsInput = block.querySelector("[data-group-urls]");
                    var descriptionInput = block.querySelector("[data-group-description]");
                    var isTimelyInput = block.querySelector("[data-group-is-timely]");
                    var label = labelInput ? labelInput.value.trim() : "";
                    var description = descriptionInput ? descriptionInput.value.trim() : "";
                    var isTimely = isTimelyInput ? isTimelyInput.checked : false;
                    var urls = urlsInput ? urlsInput.value.split(/\r?\n/).map(function (u) { return u.trim(); }).filter(function (u) { return u !== ""; }) : [];
                    if (urls.length === 0) return;
                    groups.push({ label: label, description: description, is_timely: isTimely, urls: urls });
                });
                hiddenGroupsField.value = JSON.stringify(groups);
            });
        }
    }

    if (typeof jQuery !== "undefined") {
        jQuery(document).on("rex:ready", initAiChatProfileForm);
    } else {
        document.addEventListener("DOMContentLoaded", initAiChatProfileForm);
    }
})();
</script>
');

    $content = $form->get();
    if ('' !== $testWidgetHtml) {
        // Zweispaltig, sobald es ein gespeichertes Profil zu testen gibt: Formular links,
        // Live-Test rechts - siehe $testWidgetHtml oben (bewusst nicht Teil von $form). Das
        // "row"/"col-md-*"-Grid dieses Backend-Themes ist float-basiert (keine automatische
        // Höhenangleichung der Spalten) - ohne display:flex bliebe die rechte Spalte nur so
        // hoch wie ihr eigener Inhalt und position:sticky hätte dadurch keinen Raum, in dem es
        // beim Scrollen tatsächlich "kleben" könnte (es würde direkt mit hochscrollen).
        $content = '<div class="row" style="display:flex;flex-wrap:wrap;"><div class="col-md-8" style="min-width:0;">' . $content . '</div><div class="col-md-4" style="min-width:280px;">' . $testWidgetHtml . '</div></div>';
    }

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', ('edit' === $func) ? 'Profil bearbeiten' : 'Neues Profil erstellen');
    $fragment->setVar('body', $content, false);
    echo $fragment->parse('core/page/section.php');

} else {
    // Kollisions-Hinweis: zwei aktivierte Profile, die sich bei GLEICHER Prioritaet
    // ueberschneiden koennten (Kontext/Rolle/Domain/Sprache), werden sonst nur durch die
    // niedrigere ID entschieden - unerwartet, wenn niemand das bewusst so geplant hat.
    // Reine Warnung, kein Block: unterschiedliche Prioritaet loest die Ueberschneidung
    // bereits eindeutig auf und wird deshalb nicht extra gemeldet.
    $enabledProfiles = array_values(array_filter((new ProfileRepository())->getAll(), static fn ($p) => $p->status));
    $priorityTies = [];
    foreach ($enabledProfiles as $i => $profileA) {
        foreach ($enabledProfiles as $j => $profileB) {
            if ($j <= $i || $profileA->priority !== $profileB->priority) {
                continue;
            }
            if ($profileA->overlapsWith($profileB)) {
                $priorityTies[] = [$profileA, $profileB];
            }
        }
    }
    if ([] !== $priorityTies) {
        $items = [];
        foreach ($priorityTies as [$profileA, $profileB]) {
            $items[] = '<li>„' . rex_escape($profileA->name) . '" und „' . rex_escape($profileB->name) . '" (beide Priorität ' . $profileA->priority . ') - Profil #' . min($profileA->id, $profileB->id) . ' gewinnt bei Gleichstand (niedrigere ID).</li>';
        }
        echo rex_view::warning(
            '<strong>Mögliche Profil-Kollision:</strong> Diese aktivierten Profile könnten auf dieselbe Anfrage (Kontext/Rolle/Domain/Sprache) zutreffen und haben dieselbe Priorität:'
            . '<ul style="margin:8px 0 0;">' . implode('', $items) . '</ul>'
            . '<p class="help-block" style="margin:8px 0 0;">Absichtlich? Dann ignorieren. Sonst eine der beiden Prioritäten anpassen, damit die Reihenfolge nicht von der Profil-ID abhängt.</p>'
        );
    }

    $list = rex_list::factory('SELECT id, name, context, status, priority FROM ' . rex::getTable('ai_chat_profile') . ' ORDER BY priority DESC, id ASC');
    $list->addTableAttribute('class', 'table-striped');

    $tdIcon = '<i class="rex-icon rex-icon-edit"></i>';
    $thIcon = '<a href="' . $list->getUrl(['func' => 'add']) . '" title="Hinzufügen"><i class="rex-icon rex-icon-add-module"></i></a>';

    $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
    $list->setColumnParams($thIcon, ['func' => 'edit', 'id' => '###id###']);

    $list->setColumnLabel('name', 'Name');
    $list->setColumnLabel('context', 'Kontext');
    $list->setColumnLabel('priority', 'Priorität');

    $list->setColumnLabel('status', 'Status');
    $list->setColumnFormat('status', 'custom', static function (array $params): string {
        return $params['list']->getValue('status')
            ? '<span class="label label-success">Aktiv</span>'
            : '<span class="label label-default">Inaktiv</span>';
    });

    $list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> Löschen', -1, ['', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###']);
    $list->addLinkAttribute('delete', 'data-confirm', 'Profil wirklich löschen? Eigene, exklusiv zugeordnete Wissensinhalte bleiben im Index stehen, werden aber von keinem Profil mehr genutzt.');

    $content = $list->get();

    $fragment = new rex_fragment();
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
}
