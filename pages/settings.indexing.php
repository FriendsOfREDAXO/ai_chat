<?php

use FriendsOfRedaxo\AiChat\Db\VectorCapability;

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

if ('recheck_vector' === rex_request('func', 'string')) {
    VectorCapability::recheck();
    echo rex_view::success('Vektor-Unterstützung neu geprüft.');
}

$form = rex_config_form::factory('ai_chat');

$form->addRawField('<p class="help-block">' . $rawMsg('config_section_indexing_hint') . '</p>');

// Status-Box: rein informativ, kein Formularfeld - die Strategie (nativ vs. Brute-Force)
// wird ausschliesslich automatisch per VectorCapability::isSupported() gewaehlt, siehe
// ChatQueryService::resolveRetrievalStrategy(). "Neu prüfen" deckt den Fall ab, dass die
// DB nach der Installation aktualisiert wurde, ohne auf den naechsten Cache-Ablauf zu warten.
$vectorSupported = VectorCapability::isSupported();
$vectorVersion = VectorCapability::checkedVersion();
$vectorDimension = VectorCapability::trackedDimension();
$form->addRawField('<div class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">Vektor-Retrieval</p>');
$form->addRawField('<p class="help-block">'
    . ($vectorSupported
        ? '<span class="label label-success">Aktiv</span> Natives MariaDB-Vektor-Retrieval wird genutzt' . ($vectorDimension ? ' (Dimension ' . $vectorDimension . ', ab dem nächsten Reindex-Lauf aktuell gehalten)' : ' (wird beim nächsten Reindex-Lauf angelegt)') . '.'
        : '<span class="label label-default">Inaktiv</span> Fallback auf PHP-Brute-Force-Berechnung (funktioniert immer, ist bei sehr großem Index aber langsamer). Natives Vektor-Retrieval braucht MariaDB 11.7+ (Preview) bzw. 11.8+ (GA/LTS).')
    . (('' !== $vectorVersion) ? ' Erkannte Datenbank: <code>' . rex_escape($vectorVersion) . '</code>.' : '')
    . '</p>');
$form->addRawField('<a class="btn btn-default" href="' . rex_url::currentBackendPage(['func' => 'recheck_vector']) . '">Neu prüfen</a>');
$form->addRawField('</div>');

// Select statt Checkbox - siehe Kommentar bei $addBoolSelectField in settings.shared.php.
// War vorher eine Checkbox mit "true"-Default und liess sich dadurch nie dauerhaft
// deaktivieren: nach dem Speichern kam der Haken immer zurueck, siehe
// https://github.com/KLXM/klxmchat/issues/23.
$addBoolSelectField($form, 'live_reindex_enabled', $addon->i18n('config_live_reindex_enabled'), $addon->i18n('config_live_reindex_enabled_notice'), true);

$field = $form->addSelectField('index_source');
$field->setLabel($tooltipLabel($addon->i18n('config_index_source'), 'config_index_source_notice'));
$field->setAttribute('class', 'selectpicker');
$field->setAttribute('id', 'klxm-index-source-select');
$select = $field->getSelect();
$select->addOption($addon->i18n('config_index_source_structure'), 'structure');
$select->addOption($addon->i18n('config_index_source_sitemap'), 'sitemap');
$select->addOption($addon->i18n('config_index_source_structure_sitemap'), 'structure_sitemap');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('structure');
}
$field->setNotice($addon->i18n('config_index_source_notice'));

$form->addRawField('<div id="klxm-index-structure-settings">');

$field = $form->addSelectField('index_method');
$field->setLabel($tooltipLabel($addon->i18n('config_index_method'), 'config_index_method_notice'));
$field->setNotice($addon->i18n('config_index_method_notice'));
$field->setAttribute('class', 'selectpicker');
$select = $field->getSelect();
$select->addOption($addon->i18n('config_index_method_internal'), 'internal');
$select->addOption($addon->i18n('config_index_method_http'), 'http');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('internal');
}

$field = $form->addTextField('index_http_selector');
$field->setLabel($tooltipLabel($addon->i18n('config_index_http_selector'), 'config_index_http_selector_notice'));
$field->setNotice($addon->i18n('config_index_http_selector_notice'));
$field->setAttribute('placeholder', 'main');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('body');
}

$field = $form->addTextField('index_http_exclude_selectors');
$field->setLabel($tooltipLabel($addon->i18n('config_index_http_exclude_selectors'), 'config_index_http_exclude_selectors_notice'));
$field->setNotice($addon->i18n('config_index_http_exclude_selectors_notice'));
$field->setAttribute('placeholder', 'nav, footer, .cookie-banner');

// Select statt Checkbox - siehe Kommentar bei $addBoolSelectField in settings.shared.php.
$addBoolSelectField($form, 'index_frontend', $addon->i18n('config_index_frontend'), '', true);

$form->addRawField('</div>');

// Diese Feineinstellungen gelten für den Embedding-Text jeder Quelle (Struktur, Sitemap, Provider) und dürfen daher nie ausgeblendet werden.
$form->addRawField('<div id="klxm-index-embedding-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_index_embedding_section_title') . '</p>');
$form->addRawField('<p class="help-block" style="margin: 0 0 15px;">' . $addon->i18n('config_index_embedding_section_notice') . '</p>');

$field = $form->addSelectField('chunk_size');
$field->setLabel($tooltipLabel($addon->i18n('config_chunk_size'), 'config_chunk_size_notice'));
$field->setNotice($addon->i18n('config_chunk_size_notice'));
$select = $field->getSelect();
$select->addOption('600 Zeichen – sehr fokussiert', 600);
$select->addOption('1000 Zeichen – Standard (empfohlen)', 1000);
$select->addOption('1500 Zeichen – mehr Zusammenhang', 1500);
$select->addOption('2000 Zeichen – lange, zusammenhängende Abschnitte', 2000);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(1000);
}

$field = $form->addSelectField('chunk_overlap');
$field->setLabel($tooltipLabel($addon->i18n('config_chunk_overlap'), 'config_chunk_overlap_notice'));
$field->setNotice($addon->i18n('config_chunk_overlap_notice'));
$select = $field->getSelect();
$select->addOption('0 – kein Overlap', 0);
$select->addOption('100 Zeichen', 100);
$select->addOption('200 Zeichen – Standard (empfohlen)', 200);
$select->addOption('300 Zeichen – viel Überlappung', 300);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(200);
}

$field = $form->addTextAreaField('embedding_context_hint');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_context_hint'), 'config_embedding_context_hint_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_context_hint_notice')));
$field->setAttribute('rows', '3');
$field->setAttribute('placeholder', "Dies ist die Website eines Vereins/Verbands. Kontakt-, Ansprechpartner- und Terminangaben sind für Besucher besonders wichtig und sollten bevorzugt gefunden werden.");

$field = $form->addTextAreaField('embedding_context_hint_sources');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_context_hint_sources'), 'config_embedding_context_hint_sources_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_context_hint_sources_notice')));
$field->setAttribute('rows', '5');
$field->setAttribute('placeholder', "article=Dies ist redaktioneller Inhalt der eigenen Website.\nsitemap_url=Diese Seite wurde über die externe Sitemap eingebunden.");

$field = $form->addTextAreaField('embedding_focus_rules');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_focus_rules'), 'config_embedding_focus_rules_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_focus_rules_notice')));
$field->setAttribute('rows', '6');
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Kontakt-/Ansprechpartnerinformationen|kontakt|ansprechpartner|telefon|email|e-mail|adresse\nÖffnungs-/Sprechzeiten|öffnungszeiten|sprechzeiten|geöffnet\nTermine & Veranstaltungen|termin|veranstaltung|kalender|event\nMitgliedschaft & Beitritt|mitglied|beitritt|aufnahmeantrag|beitrag\nDokumente & Formulare|formular|antrag|satzung|download|pdf");
}

$field = $form->addTextAreaField('embedding_focus_rules_sources');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_focus_rules_sources'), 'config_embedding_focus_rules_sources_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_focus_rules_sources_notice')));
$field->setAttribute('rows', '5');
$field->setAttribute('placeholder', "article=Kontakt|telefon|email|ansprechpartner\nsitemap_url=Formulare|antrag|download|pdf");

$form->addRawField('</div>');

$form->addRawField('<div id="index-source-sitemap-settings" class="ai-chat-settings-box" style="display:none;">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_index_sitemap_section_title') . '</p>');
$form->addRawField('<p class="help-block" style="margin: 0 0 15px;">' . $addon->i18n('config_index_sitemap_section_notice') . '</p>');
$field = $form->addTextAreaField('index_sitemap_url');
$field->setLabel($addon->i18n('config_index_sitemap_url'));
$field->setNotice($addon->i18n('config_index_sitemap_url_notice'));
$field->setAttribute('id', 'ai-chat-index-sitemap-url');
$field->setAttribute('rows', '4');
$field->setAttribute('placeholder', "https://example.com/sitemap.xml\nhttps://shop.example.com/sitemap.xml");

// Button zum Testen aller eingetragenen Sitemap-URLs (eine pro Zeile)
$form->addRawField('<div style="margin-left: 170px; margin-bottom: 15px;">');
$form->addRawField('<button type="button" class="btn btn-default" id="test-sitemap-url-btn"><i class="rex-icon fa-globe"></i> Sitemaps prüfen</button>');
$form->addRawField('<div id="sitemap-test-result" style="margin-top: 10px;"></div>');
$form->addRawField('</div>');
$form->addRawField('</div>');

$form->addRawField('<div id="klxm-index-provider-settings">');
$providerRegistry = new FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderRegistry();
$availableProviders = array_filter(
    $providerRegistry->getAll(),
    static fn ($provider): bool => $provider instanceof FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderInterface
        && $provider->isAvailable(),
);

$providerField = null;

if ($availableProviders !== []) {
    // Als Multi-Select statt Checkbox-Gruppe: bei mehreren Optionen speichert REDAXO Checkboxen
    // als Pipe-String ("|forcal|yform|"), was fehleranfaellig ist. Ein Multi-Select nutzt exakt
    // dasselbe Speicherformat/rex_select-Mechanismus wie bereits bei "Kategorien ausschließen".
    $providerField = $form->addSelectField('index_content_providers');
    $providerField->setLabel($addon->i18n('config_index_content_providers'));
    $providerField->setNotice($addon->i18n('config_index_content_providers_notice'));
    $providerField->setAttribute('class', 'selectpicker');
    $providerField->setAttribute('data-actions-box', 'true');

    $providerSelect = $providerField->getSelect();
    $providerSelect->setMultiple();
    $providerSelect->setSize(min(count($availableProviders), 6));

    foreach ($availableProviders as $provider) {
        $providerSelect->addOption($provider->getLabel(), $provider->getKey());
    }
}

if (isset($availableProviders['forcal'])) {

    $field = $form->addTextField('forcal_url_schema');
    $field->setLabel($addon->i18n('config_forcal_url_schema'));
    $field->setNotice($addon->i18n('config_forcal_url_schema_notice'));

    $field = $form->addTextAreaField('forcal_intent_keywords');
    $field->setLabel($addon->i18n('config_forcal_intent_keywords'));
    $field->setNotice($addon->i18n('config_forcal_intent_keywords_notice'));
    $field->setAttribute('rows', '4');
    if ($isConfigUnset($field->getValue())) {
        $field->setValue("termin\ntermine\nkalender\nevent\nveranstaltung\ndemnächst\nnächste\nletzte\nzuletzt\nvergangen");
    }
}

if (isset($availableProviders['yform'])) {

    $form->addRawField('<p class="help-block" style="margin-left:170px;">' . $addon->i18n('config_yform_provider_profiles_notice') . ' <a href="' . rex_url::backendPage('ai_chat/yform') . '">YForm-Mappings öffnen</a></p>');
}

if (rex_addon::exists('knowledgebase') && !rex_addon::get('knowledgebase')->isAvailable()) {
    $form->addRawField('<p class="help-block" style="margin-left:170px;">' . $addon->i18n('config_index_content_provider_knowledgebase_unavailable') . '</p>');
}

if ($availableProviders === []) {
    $form->addRawField('<p class="help-block" style="margin-left:170px;">' . $addon->i18n('config_index_content_providers_unavailable') . '</p>');
}

$form->addRawField('</div>');

$form->addRawField('<div id="klxm-index-local-content-settings">');

$field = $form->addSelectField('index_article_status');
$field->setLabel($addon->i18n('config_index_article_status'));
$field->setNotice($addon->i18n('config_index_article_status_notice'));
$field->setAttribute('class', 'selectpicker');
$select = $field->getSelect();
$select->addOption($addon->i18n('config_index_article_status_online'), 'online');
$select->addOption($addon->i18n('config_index_article_status_all'), 'all');
$select->addOption($addon->i18n('config_index_article_status_offline'), 'offline');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('online');
}

$field = $form->addLinklistField('index_exclude_articles');
$field->setLabel($addon->i18n('config_index_exclude_articles'));
$field->setNotice($addon->i18n('config_index_exclude_articles_notice'));

$field = $form->addSelectField('index_exclude_categories');
$field->setLabel($addon->i18n('config_index_exclude_categories'));
$field->setNotice($addon->i18n('config_index_exclude_categories_notice'));
$field->setAttribute('class', 'selectpicker');
$field->setAttribute('data-live-search', 'true');
$field->setAttribute('data-actions-box', 'true');
$field->setAttribute('data-selected-text-format', 'count > 3');

// rex_category_select (Kern-Widget aus dem structure-Addon) statt manuellem Aufbau: rendert
// die Kategorien nativ hierarchisch eingerückt (rex_select::outOption() nutzt &nbsp;-Einrückung
// je Baumtiefe) und respektiert dabei automatisch echte Eltern-Kind-Beziehungen.
$categorySelect = new \rex_category_select(false, false, false, true);
$categorySelect->setMultiple();
$categorySelect->setSize(10);
$field->setSelect($categorySelect);

// Select statt Checkbox - siehe Kommentar bei $addBoolSelectField in settings.shared.php.
$addBoolSelectField($form, 'index_respect_yrewrite_seo', $addon->i18n('config_index_respect_yrewrite_seo'), $addon->i18n('config_index_respect_yrewrite_seo_notice'), true);

$form->addRawField('</div>');

// Addon-Docs/GitHub/Provider-Indexierung ist eine von index_source unabhängige Zusatzquelle und muss immer erreichbar sein.
$form->addRawField('<div id="klxm-index-addon-settings">');
$form->addRawField('<p class="help-block" style="margin-left:170px;">' . $addon->i18n('config_index_addons_section_notice') . '</p>');

$field = $form->addSelectField('index_addons_mode');
$field->setLabel($tooltipLabel($addon->i18n('config_index_addons_mode'), 'config_index_addons_section_notice'));
$field->setAttribute('class', 'selectpicker');
$select = $field->getSelect();
$select->addOption($addon->i18n('config_index_addons_mode_all'), 'all');
$select->addOption($addon->i18n('config_index_addons_mode_selected'), 'selected');
$select->addOption($addon->i18n('config_index_addons_mode_none'), 'none');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('all');
}

$field = $form->addSelectField('index_addons_list');
$field->setLabel($addon->i18n('config_index_addons_list'));
$field->setAttribute('class', 'selectpicker');
$field->setAttribute('data-live-search', 'true');
$field->setAttribute('data-actions-box', 'true');
$field->setAttribute('data-selected-text-format', 'count > 3');
$field->setAttribute('multiple', 'multiple');
$field->setAttribute('size', '10');
$select = $field->getSelect();
foreach (rex_addon::getAvailableAddons() as $a) {
    $select->addOption($a->getName() . ' (' . $a->getPackageId() . ')', $a->getPackageId());
}

$field = $form->addTextField('github_token');
$field->setLabel($addon->i18n('config_github_token'));
$field->setNotice($addon->i18n('config_github_token_notice'));

$field = $form->addTextAreaField('github_repos');
$field->setLabel($addon->i18n('config_github_repos'));
$field->setNotice($addon->i18n('config_github_repos_notice'));

$form->addRawField('</div>');

$form->addRawField($tooltipInitScript);

$form->addRawField('
<script>
(function() {
    function initKlxmIndexingSettings() {
        // Index Source Toggle
        var indexSourceSelect = document.getElementById("klxm-index-source-select");
        function updateIndexSourceVisibility() {
            if (!indexSourceSelect) return;
            var value = indexSourceSelect.value;
            var sitemapSettings = document.getElementById("index-source-sitemap-settings");
            var structureSettings = document.getElementById("klxm-index-structure-settings");
            var localContentSettings = document.getElementById("klxm-index-local-content-settings");
            var isSitemapOnly = value === "sitemap";
            var includesSitemap = value === "sitemap" || value === "structure_sitemap";
            if (sitemapSettings) {
                sitemapSettings.style.display = includesSitemap ? "block" : "none";
            }
            if (structureSettings) {
                structureSettings.style.display = isSitemapOnly ? "none" : "block";
            }
            if (localContentSettings) {
                localContentSettings.style.display = isSitemapOnly ? "none" : "block";
            }
            // Addon-Docs/GitHub/Provider-Indexierung (#klxm-index-addon-settings) ist eine eigene Zusatzquelle
            // und bleibt bewusst IMMER sichtbar, unabh\u00e4ngig von index_source.
        }
        if (indexSourceSelect) {
            indexSourceSelect.addEventListener("change", updateIndexSourceVisibility);
            updateIndexSourceVisibility();
        }

        // Test Sitemap URLs (eine pro Zeile) - jede Zeile wird einzeln geprüft,
        // damit man bei mehreren Sitemaps sofort sieht, welche davon fehlschlägt.
        var testSitemapBtn = document.getElementById("test-sitemap-url-btn");
        if (testSitemapBtn) {
            testSitemapBtn.addEventListener("click", function() {
               var input = document.getElementById("ai-chat-index-sitemap-url");
               var raw = input ? input.value : "";
               var urls = raw.split("\n").map(function(line) { return line.trim(); }).filter(function(line) { return line !== ""; });
               var resultBox = document.getElementById("sitemap-test-result");
               if (urls.length === 0) { alert("Bitte mindestens eine URL eingeben"); return; }

               resultBox.innerHTML = "<i class=\'rex-icon fa-spinner fa-spin\'></i> Prüfe " + urls.length + " Sitemap(s)...";
               testSitemapBtn.disabled = true;

               Promise.all(urls.map(function(url) {
                   return fetch("index.php?rex-api-call=ai_chat_index&action=test_sitemap&url=" + encodeURIComponent(url))
                       .then(function(response) { return response.json(); })
                       .then(function(data) { return { url: url, data: data }; })
                       .catch(function(error) { return { url: url, data: { success: false, message: String(error) } }; });
               })).then(function(results) {
                   testSitemapBtn.disabled = false;
                   resultBox.innerHTML = results.map(function(result) {
                       var icon = result.data.success ? "fa-check" : "fa-exclamation-triangle";
                       var cls = result.data.success ? "text-success" : "text-danger";
                       return "<div class=\'" + cls + "\'><i class=\'rex-icon " + icon + "\'></i> " +
                           "<strong>" + result.url + "</strong>: " + result.data.message + "</div>";
                   }).join("");
               });
            });
        }
    }

    if (typeof jQuery !== "undefined") {
        jQuery(document).on("rex:ready", function() {
            initKlxmIndexingSettings();
        });
    } else {
        document.addEventListener("DOMContentLoaded", function() {
            initKlxmIndexingSettings();
        });
    }
})();
</script>
');

$sidebar = $renderTipsPanel($addon, 'indexing')
    . '<div class="panel panel-info" style="margin-bottom:20px;">'
    . '<header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-sitemap"></i> ' . $addon->i18n('config_sidebar_index_modes_title') . '</div></header>'
    . '<div class="panel-body">'
    . '<p><strong>' . $addon->i18n('config_index_source_structure') . ':</strong> ' . $addon->i18n('config_sidebar_index_modes_structure') . '</p>'
    . '<p><strong>' . $addon->i18n('config_index_source_sitemap') . ':</strong> ' . $addon->i18n('config_sidebar_index_modes_sitemap') . '</p>'
    . '<p style="margin-bottom:0;"><strong>' . $addon->i18n('config_index_source_structure_sitemap') . ':</strong> ' . $addon->i18n('config_sidebar_index_modes_structure_sitemap') . '</p>'
    . '</div></div>';

$renderSettingsPage($form, $sidebar);
