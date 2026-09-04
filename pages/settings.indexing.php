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

// Seit der Hauptprofil-Entflechtung waehlt jedes Profil seine Quellen selbst (Sitemap/
// Struktur/YForm/PDFs, siehe AI Chat → Profile) - es gibt keine globale Struktur-/Sitemap-/
// PDF-Indexierung mehr. Was hier bleibt: technische Retrieval-Infrastruktur und die
// Aktivierung optionaler, profil-unabhaengiger Content-Provider (z.B. forcal).
$form->addRawField('<p class="help-block">' . sprintf('Wissensquellen (Sitemap, Struktur, YForm, PDFs) werden seit Kurzem je Profil gewählt, nicht mehr global - siehe <a href="%s">AI Chat → Profile</a>.', rex_url::backendPage('ai_chat/profiles')) . '</p>');

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

// Select statt Checkbox - siehe Kommentar bei $addBoolSelectField in settings.shared.php.
// Betrifft die event-getriebene Einzelartikel-Neuindizierung (siehe IndexerService::
// updateArticleIndex()) - yrewrite steuert pro Artikel Indexierbarkeit ueber das
// Metainfo-Feld 'yrewrite_index'.
$addBoolSelectField($form, 'index_respect_yrewrite_seo', $addon->i18n('config_index_respect_yrewrite_seo'), $addon->i18n('config_index_respect_yrewrite_seo_notice'), true);

$form->addRawField('<div id="klxm-index-provider-settings">');
$providerRegistry = new FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderRegistry();
$availableProviders = array_filter(
    $providerRegistry->getAll(),
    // "mediapool"/"yform" hier bewusst ausgeschlossen: beide sind rein profil-exklusiv
    // (siehe pages/profiles.php) und tragen seit der Hauptprofil-Entflechtung nicht mehr
    // zu einem globalen Pool bei - ein globales "aktivieren" haette hier keine Wirkung.
    static fn ($provider): bool => $provider->isAvailable() && !in_array($provider->getKey(), ['mediapool', 'yform'], true),
);

if ($availableProviders !== []) {
    // Als Multi-Select statt Checkbox-Gruppe: bei mehreren Optionen speichert REDAXO Checkboxen
    // als Pipe-String ("|forcal|"), was fehleranfaellig ist.
    $providerField = $form->addSelectField('index_content_providers');
    $providerField->setLabel($addon->i18n('config_index_content_providers'));
    $providerField->setNotice('Profil-unabhängige Zusatzquellen (z.B. forcal-Kalendereinträge), sichtbar für jedes Profil.');
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

$form->addRawField('<p class="help-block" style="margin-left:170px;">YForm-Tabellen und PDF-Dokumente werden je Profil gewählt (siehe <a href="' . rex_url::backendPage('ai_chat/profiles') . '">AI Chat → Profile</a>), YForm-Mappings selbst weiterhin zentral unter <a href="' . rex_url::backendPage('ai_chat/content/yform') . '">AI Chat → Indexierung → YForm-Tabellen</a>.</p>');

if (rex_addon::exists('knowledgebase') && !rex_addon::get('knowledgebase')->isAvailable()) {
    $form->addRawField('<p class="help-block" style="margin-left:170px;">' . $addon->i18n('config_index_content_provider_knowledgebase_unavailable') . '</p>');
}

if ($availableProviders === []) {
    $form->addRawField('<p class="help-block" style="margin-left:170px;">' . $addon->i18n('config_index_content_providers_unavailable') . '</p>');
}

$form->addRawField('</div>');

$form->addRawField($tooltipInitScript);

$renderSettingsPage($form, $renderTipsPanel($addon, 'indexing'));
