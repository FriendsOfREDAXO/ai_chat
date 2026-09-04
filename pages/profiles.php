<?php

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
        '<p>Ein Profil legt fest, <strong>wer</strong> den Chat/die Suche sieht (Rolle, Domain, Sprache), <strong>was</strong> er weiß (eigene Quellen: Sitemap, Struktur, YForm, PDFs) und <strong>wie</strong> er antwortet (Prompt, Anrede, Begrüßung).</p>'
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
            $testResetAttr = $testProfile->chatResetCountdown > 0 ? ' reset-countdown="' . $testProfile->chatResetCountdown . '"' : '';
            $testCopyAttr = $testProfile->chatCopyHistory ? ' copy-history="true"' : '';
            $testPersonalization = $testProfile->personalizationMode;
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
                . '<p class="help-block" style="margin-top:0;">Testet den <strong>gespeicherten</strong> Stand dieses Profils live - ungespeicherte Änderungen links zuerst speichern, dann diese Seite neu laden.</p>'
                . sprintf(
                    '<ai-chat id="ai-chat-profile-test-widget" mode="inline" style="%s" api-url="%s" scope="frontend" title="%s" greeting="%s" primary-color="%s" avatar-url="%s" position="%s" personalization-mode="%s" stream-enabled="%s" profile-id="%d" ui-language="%s"%s%s></ai-chat>',
                    rex_escape($testInlineStyle, 'html_attr'),
                    rex_escape($apiUrl, 'html_attr'),
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
    $field->setAttribute('class', 'form-control selectpicker');
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

    // Kein globaler Schalter mehr (siehe AI Chat → Einstellungen) - jedes Profil
    // entscheidet allein, ob es Chat/Suche automatisch einbindet.
    $field = $form->addSelectField('chat_enabled');
    $field->setLabel('Chat automatisch einbinden');
    $select = $field->getSelect();
    $select->addOption('Ja', '1');
    $select->addOption('Nein', '0');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('1');
    }

    $field = $form->addSelectField('search_enabled');
    $field->setLabel('Suche automatisch einbinden');
    $select = $field->getSelect();
    $select->addOption('Ja', '1');
    $select->addOption('Nein', '0');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('1');
    }

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
        $domainField->setAttribute('class', 'form-control selectpicker');
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
        $clangField->setAttribute('class', 'form-control selectpicker');
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
    $form->addRawField('<p class="ai-chat-settings-box-title">Wissen (Indizierung)</p>');
    $form->addRawField('<p class="help-block" style="margin-top:-8px;">Jedes Profil ist vollständig isoliert und durchsucht ausschließlich seine eigenen, hier gewählten Quellen (Sitemap, Struktur-Bereich, YForm-Tabellen, PDFs) - beliebig kombinierbar. Es gibt keinen gemeinsamen globalen Wissens-Pool mehr.</p>');

    // Gezieltes Teilen zwischen zwei bestimmten Profilen - die einzige Möglichkeit, Wissen
    // zwischen Profilen zu teilen (kein globaler Pool mehr).
    $otherProfiles = array_values(array_filter((new ProfileRepository())->getAll(), static fn ($p) => $p->id !== $id));
    if ([] !== $otherProfiles) {
        $field = $form->addSelectField('include_profile_ids');
        $field->setLabel('Wissen teilen mit Profil(en)');
        $field->setNotice('Zusätzlich zu den eigenen Quellen dieses Profils werden auch die Quellen der hier gewählten Profile durchsucht.');
        $field->setAttribute('class', 'form-control selectpicker');
        $field->setAttribute('data-actions-box', 'true');
        $select = $field->getSelect();
        $select->setMultiple();
        $select->setSize(min(count($otherProfiles), 6));
        foreach ($otherProfiles as $otherProfile) {
            $select->addOption($otherProfile->name, (string) $otherProfile->id);
        }
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

    // Benannte Struktur-Bereiche (Kategorie-Teilbaum) - strukturell identisch zum
    // Sitemap-Gruppen-Repeater oben (Name/Beschreibung/"aktuell"), nur mit einer
    // Kategorie-Auswahl statt URLs, UND seit Phase 6 gleichzeitig mit Sitemap-Gruppen
    // kombinierbar (kein "Eigene Quelle"-Entweder-Oder-Select mehr).
    $mountpointGroupsField = $form->addTextField('mountpoint_groups');
    $mountpointGroupsField->setAttribute('type', 'hidden');
    $mountpointGroupsField->setAttribute('id', 'ai-chat-mountpoint-groups-value');
    $currentMountpointGroups = json_decode((string) $mountpointGroupsField->getValue(), true);
    $currentMountpointGroups = is_array($currentMountpointGroups) ? $currentMountpointGroups : [];

    // Eine einzelne rex_category_select-Instanz liefert die komplette, rechteabhaengige
    // Options-Liste (inkl. "[ID]"-Suffix je Option) - wird fuer jede Repeater-Zeile
    // wiederverwendet (JS setzt danach nur noch .value), statt sie pro Zeile neu
    // aufzubauen. addHomepage=false + eigene Leer-Option zuerst, siehe Kommentar beim
    // frueheren Einzel-Select.
    $mountpointCategorySelect = new rex_category_select(false, false, false, false);
    $mountpointCategorySelect->addOption('Bitte wählen…', '');
    $mountpointCategorySelectHtml = $mountpointCategorySelect->get();
    $mountpointCategoryOptionsHtml = '';
    if (preg_match('/<select[^>]*>(.*)<\/select>/s', $mountpointCategorySelectHtml, $matches)) {
        $mountpointCategoryOptionsHtml = $matches[1];
    }

    $form->addRawField('<label>Struktur-Bereiche</label>');
    $form->addRawField('<p class="help-block" style="margin-top:0;">Ein oder mehrere Kategorie-Teilbäume, optional mit Namen gruppiert - genau wie die Sitemap-Quellen oben, nur mit einer Kategorie statt URLs. Beide Quellenarten sind beliebig gleichzeitig nutzbar.</p>');
    $form->addRawField('<div id="ai-chat-mountpoint-groups-repeater">');
    $form->addRawField('<div class="ai-chat-mountpoint-groups-items">');
    if ([] === $currentMountpointGroups) {
        $currentMountpointGroups = [['label' => '', 'description' => '', 'is_timely' => false, 'category_id' => '']];
    }
    foreach ($currentMountpointGroups as $group) {
        $groupLabel = is_array($group) ? (string) ($group['label'] ?? '') : '';
        $groupDescription = is_array($group) ? (string) ($group['description'] ?? '') : '';
        $groupIsTimely = is_array($group) && !empty($group['is_timely']);
        $groupCategoryId = is_array($group) && !empty($group['category_id']) ? (string) $group['category_id'] : '';
        $form->addRawField(
            '<div class="ai-chat-mountpoint-group panel panel-default" style="padding:10px;margin-bottom:10px;">'
            . '<div class="row"><div class="col-md-4"><label>Name (optional)</label><input type="text" class="form-control" data-group-label placeholder="z.B. Service" value="' . rex_escape($groupLabel, 'html_attr') . '"></div>'
            . '<div class="col-md-8"><label>Kategorie</label><select class="form-control" data-group-category data-selected-value="' . rex_escape($groupCategoryId, 'html_attr') . '">' . $mountpointCategoryOptionsHtml . '</select></div></div>'
            . '<div class="row" style="margin-top:8px;"><div class="col-md-8"><label>Beschreibung (optional)</label><input type="text" class="form-control" data-group-description placeholder="z.B. Alle Service-Seiten" value="' . rex_escape($groupDescription, 'html_attr') . '"><p class="help-block">Hilft der KI, diesen Bereich thematisch einzuordnen - fließt als Zusatzkontext mit ein.</p></div>'
            . '<div class="col-md-4"><label>&nbsp;</label><div class="checkbox"><label><input type="checkbox" data-group-is-timely' . ($groupIsTimely ? ' checked' : '') . '> Aktuelle/zeitkritische Inhalte (z.B. News)</label></div><p class="help-block">Wird bei Fragen nach "aktuell"/"neu"/"zuletzt" bevorzugt.</p></div></div>'
            . '<button type="button" class="btn btn-danger btn-xs" style="margin-top:8px;" data-remove-group>Bereich entfernen</button>'
            . '</div>',
        );
    }
    $form->addRawField('</div>');
    $form->addRawField('<button type="button" class="btn btn-default btn-sm" id="ai-chat-mountpoint-group-add">+ Struktur-Bereich hinzufügen</button>');
    $form->addRawField('<template id="ai-chat-mountpoint-group-template"><div class="ai-chat-mountpoint-group panel panel-default" style="padding:10px;margin-bottom:10px;">'
        . '<div class="row"><div class="col-md-4"><label>Name (optional)</label><input type="text" class="form-control" data-group-label placeholder="z.B. Service" value=""></div>'
        . '<div class="col-md-8"><label>Kategorie</label><select class="form-control" data-group-category>' . $mountpointCategoryOptionsHtml . '</select></div></div>'
        . '<div class="row" style="margin-top:8px;"><div class="col-md-8"><label>Beschreibung (optional)</label><input type="text" class="form-control" data-group-description placeholder="z.B. Alle Service-Seiten"><p class="help-block">Hilft der KI, diesen Bereich thematisch einzuordnen - fließt als Zusatzkontext mit ein.</p></div>'
        . '<div class="col-md-4"><label>&nbsp;</label><div class="checkbox"><label><input type="checkbox" data-group-is-timely> Aktuelle/zeitkritische Inhalte (z.B. News)</label></div><p class="help-block">Wird bei Fragen nach "aktuell"/"neu"/"zuletzt" bevorzugt.</p></div></div>'
        . '<button type="button" class="btn btn-danger btn-xs" style="margin-top:8px;" data-remove-group>Bereich entfernen</button>'
        . '</div></template>');
    $form->addRawField('</div>');

    $yformProfiles = YformProfiles::getAll($addon);
    if ([] !== $yformProfiles) {
        $field = $form->addSelectField('yform_profile_ids');
        $field->setLabel('Eigene YForm-Quellen');
        $field->setNotice('Für dieses Profil indexierte YForm-Tabellen (verwaltet unter AI Chat → YForm-Tabellen).');
        $field->setAttribute('class', 'form-control selectpicker');
        $field->setAttribute('data-actions-box', 'true');
        $select = $field->getSelect();
        $select->setMultiple();
        $select->setSize(min(count($yformProfiles), 6));
        foreach ($yformProfiles as $yformProfile) {
            $select->addOption((string) ($yformProfile['label'] ?? $yformProfile['id']), (string) $yformProfile['id']);
        }
    } else {
        $form->addRawField('<p class="help-block">Keine YForm-Tabellen-Mappings konfiguriert – siehe AI Chat → YForm-Tabellen, um welche anzulegen.</p>');
    }

    if (rex_addon::get('mediapool')->isAvailable()) {
        MediaPoolContentProvider::renderSourceFields(
            $form,
            'pdf_media_ids',
            'Eigene PDF-Dokumente',
            'Für dieses Profil indexierte PDF-Dateien aus dem Medienpool. Nur PDFs werden verarbeitet, andere Dateitypen in der Auswahl werden ignoriert.',
            'pdf_category_ids',
            'PDFs aus Medienpool-Kategorien',
            'Alle PDF-Dateien in diesen Medienpool-Kategorien (nicht rekursiv in Unterkategorien) werden zusätzlich zu den oben einzeln gewählten Dokumenten indexiert.',
        );
    }

    $form->addRawField('</div>');

    // Verhalten
    $form->addRawField('<div class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">Verhalten</p>');

    $field = $form->addTextAreaField('custom_prompt');
    $field->setLabel($tooltipLabel('Eigener Prompt', 'config_profile_custom_prompt_notice'));
    $field->setNotice('Ersetzt den global konfigurierten Prompt für dieses Profil. Leer = globale Einstellung wird verwendet.');
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
    $field->setNotice('Leer = Standard-Begrüßung.');
    $field->setAttribute('rows', '2');

    $addressingModeLabels = [
        'auto' => 'Automatisch (Personalisierung)',
        'formal' => 'Immer Sie',
        'informal' => 'Immer Du',
        'neutral' => 'Neutral',
    ];
    $field = $form->addSelectField('addressing_mode');
    $field->setLabel('Anrede');
    $select = $field->getSelect();
    foreach ($addressingModeLabels as $value => $label) {
        $select->addOption($label, $value);
    }
    $field->setNotice('Wie die KI den Besucher anspricht.');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('neutral');
    }

    $personalizationModeLabels = [
        'off' => 'Aus',
        'simple' => 'Einfach (Du/Sie erfragen)',
        'name' => 'Mit Namen',
    ];
    $field = $form->addSelectField('personalization_mode');
    $field->setLabel('Personalisierung');
    $select = $field->getSelect();
    foreach ($personalizationModeLabels as $value => $label) {
        $select->addOption($label, $value);
    }
    $field->setNotice('Ob/wie die KI den Besucher zu Beginn nach Anrede bzw. Namen fragt, um beides in spätere Antworten einzubauen.');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('off');
    }

    $field = $form->addSelectField('suggest_followup_questions');
    $field->setLabel('Vorgeschlagene Folgefragen anzeigen');
    $select = $field->getSelect();
    $select->addOption('An', '1');
    $select->addOption('Aus', '0');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('1');
    }

    $field = $form->addSelectField('show_sources');
    $field->setLabel('Quellen/Links in Antworten anzeigen');
    $select = $field->getSelect();
    $select->addOption('An', '1');
    $select->addOption('Aus', '0');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('1');
    }

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

    // FAQ-Vorcaching: eine Frage pro Zeile, gilt ausschliesslich fuer dieses Profil (siehe
    // AI Chat → Indexierung → Cache-Fragen fuer den "Vorcache aufwaermen"-Button, der ueber
    // alle Profile mit aktiviertem Vorcaching laeuft).
    $form->addRawField('<div class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">FAQ-Vorcaching</p>');

    $field = $form->addSelectField('faq_precache_enabled');
    $field->setLabel('Vorcaching aktivieren');
    $select = $field->getSelect();
    $select->addOption('Aus', '0');
    $select->addOption('An', '1');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue('0');
    }

    $field = $form->addTextAreaField('faq_precache_questions');
    $field->setLabel('Vorcache-Fragen');
    $field->setNotice('Eine Frage pro Zeile - wird per Klick auf "Vorcache aufwärmen" (AI Chat → Indexierung) einmalig durch dieses Profil beantwortet und die Antwort gecacht, damit ein Besucher mit einer dieser (oder sehr ähnlichen) Fragen sofort eine bereits fertige Antwort bekommt.');
    $field->setAttribute('rows', '4');
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
        var targetModeSelect = document.getElementById("ai-chat-profile-target-mode");

        var targetDomains = document.getElementById("ai-chat-profile-target-domains");
        var targetClangs = document.getElementById("ai-chat-profile-target-clangs");

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

        if (targetModeSelect) {
            targetModeSelect.addEventListener("change", updateTargetModeVisibility);
            updateTargetModeVisibility();
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

        // Mountpoint-Gruppen-Repeater: identisches Muster wie der Sitemap-Gruppen-Repeater
        // oben, nur mit einer Kategorie-Auswahl statt eines URL-Textfelds pro Zeile. Der
        // gespeicherte Kategoriewert kommt aus "data-selected-value" (serverseitig gerendert)
        // und wird beim Initialisieren einmalig auf das <select> uebertragen, weil das
        // wiederverwendete Options-HTML selbst kein "selected" pro Zeile kennt.
        var mpRepeaterItems = document.querySelector("#ai-chat-mountpoint-groups-repeater .ai-chat-mountpoint-groups-items");
        var mpAddGroupBtn = document.getElementById("ai-chat-mountpoint-group-add");
        var mpGroupTemplate = document.getElementById("ai-chat-mountpoint-group-template");
        var mpHiddenGroupsField = document.getElementById("ai-chat-mountpoint-groups-value");
        var mpProfileForm = mpHiddenGroupsField ? mpHiddenGroupsField.closest("form") : null;

        function applySelectedCategory(block) {
            var select = block.querySelector("[data-group-category]");
            if (!select) return;
            var selectedValue = select.getAttribute("data-selected-value");
            if (selectedValue) {
                select.value = selectedValue;
            }
        }

        if (mpRepeaterItems) {
            mpRepeaterItems.querySelectorAll(".ai-chat-mountpoint-group").forEach(applySelectedCategory);
        }

        function removeMountpointGroupBlock(event) {
            var target = event.target.closest(".ai-chat-mountpoint-group");
            if (!target || !mpRepeaterItems) return;
            if (mpRepeaterItems.querySelectorAll(".ai-chat-mountpoint-group").length <= 1) {
                target.querySelector("[data-group-label]").value = "";
                target.querySelector("[data-group-category]").value = "";
                target.querySelector("[data-group-description]").value = "";
                target.querySelector("[data-group-is-timely]").checked = false;
                return;
            }
            target.remove();
        }

        if (mpRepeaterItems) {
            mpRepeaterItems.addEventListener("click", function (event) {
                if (event.target.closest("[data-remove-group]")) {
                    removeMountpointGroupBlock(event);
                }
            });
        }

        if (mpAddGroupBtn && mpGroupTemplate && mpRepeaterItems) {
            mpAddGroupBtn.addEventListener("click", function () {
                var clone = mpGroupTemplate.content.cloneNode(true);
                mpRepeaterItems.appendChild(clone);
            });
        }

        if (mpProfileForm && mpHiddenGroupsField) {
            mpProfileForm.addEventListener("submit", function () {
                var groups = [];
                document.querySelectorAll("#ai-chat-mountpoint-groups-repeater .ai-chat-mountpoint-group").forEach(function (block) {
                    var labelInput = block.querySelector("[data-group-label]");
                    var categorySelect = block.querySelector("[data-group-category]");
                    var descriptionInput = block.querySelector("[data-group-description]");
                    var isTimelyInput = block.querySelector("[data-group-is-timely]");
                    var label = labelInput ? labelInput.value.trim() : "";
                    var description = descriptionInput ? descriptionInput.value.trim() : "";
                    var isTimely = isTimelyInput ? isTimelyInput.checked : false;
                    var categoryId = categorySelect ? parseInt(categorySelect.value, 10) : 0;
                    if (!categoryId) return;
                    groups.push({ label: label, description: description, is_timely: isTimely, category_id: categoryId });
                });
                mpHiddenGroupsField.value = JSON.stringify(groups);
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
