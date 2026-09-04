<?php

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

$form = rex_config_form::factory('ai_chat');

$form->addRawField('<p class="help-block">Einstellungen, die ausschließlich die Suche betreffen (unabhängig vom Chat) - gebündelt, statt über mehrere Seiten verstreut.</p>');

$field = $form->addCheckboxField('frontend_search_current_page_only');
$field->addOption($addon->i18n('config_frontend_search_current_page_only'), 1);

// Standard AUS: jede Suche mit aktivem "Alle"-Filter und Treffern aus mehreren Bereichen
// (ChatProfile::$sitemapGroups/$mountpointGroups) loest sonst einen zusaetzlichen KI-Aufruf
// aus (siehe ChatQueryService::buildSearchSummary()) - bewusst opt-in statt stiller Kosten-/
// Latenz-Erhoehung fuer jede unfilterte Suche.
$addBoolSelectField($form, 'search_ai_summary_enabled', $addon->i18n('config_search_ai_summary_enabled'), $addon->i18n('config_search_ai_summary_enabled_notice'), false);

$field = $form->addTextAreaField('search_source_type_labels');
$field->setLabel($addon->i18n('config_search_source_type_labels'));
$field->setNotice($addon->i18n('config_search_source_type_labels_notice'));
$field->setAttribute('rows', '6');
if ($isConfigUnset($field->getValue())) {
    $field->setValue("sitemap_url=Seiten\narticle=Artikel der Website");
}

$field = $form->addCheckboxField('search_multi_context_snippets');
$field->addOption($addon->i18n('config_search_multi_context_snippets'), 1);
$field->setNotice($addon->i18n('config_search_multi_context_snippets_notice'));

$form->addRawField($tooltipInitScript);

$renderSettingsPage($form, $renderTipsPanel($addon, 'search'));
