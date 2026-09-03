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

$form->addRawField('<p class="help-block">' . $rawMsg('config_section_appearance_hint') . '</p>');

$field = $form->addSelectField('frontend_mode');
$field->setLabel($addon->i18n('config_frontend_mode'));
$select = $field->getSelect();
$select->addOption($addon->i18n('config_frontend_mode_bubble'), 'bubble');
$select->addOption($addon->i18n('config_frontend_mode_inline'), 'inline');
$select->addOption($addon->i18n('config_frontend_mode_anchored'), 'anchored');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('bubble');
}
$field->setNotice($addon->i18n('config_frontend_mode_notice'));

$field = $form->addCheckboxField('frontend_allow_scope_switch');
$field->addOption($addon->i18n('config_frontend_allow_scope_switch'), 1);
$field->setNotice($addon->i18n('config_frontend_allow_scope_switch_notice'));

$field = $form->addSelectField('position');
$field->setLabel($addon->i18n('config_position'));
$select = $field->getSelect();
$select->addOption($addon->i18n('config_position_bottom_right'), 'bottom-right');
$select->addOption($addon->i18n('config_position_bottom_left'), 'bottom-left');

// Farben/Avatar/Eckenradius kommen seit der zentralen Theme-Verwaltung nicht mehr von
// hier, sondern aus einem zentral gepflegten Theme (siehe AI Chat -> Themes) - das
// globale Standard-Theme wird dort verwaltet, nicht mehr auf dieser Seite.
$form->addRawField('<p class="help-block">Farben, Avatar und Eckenradius werden zentral unter <a href="' . rex_url::backendPage('ai_chat/themes') . '">AI Chat → Themes</a> gepflegt (inkl. Live-Vorschau und Transparenz-Unterstützung) - dort auch, welches Theme als globaler Standard gilt.</p>');

// Suche: ausschließlich Verhalten der semantischen Trefferliste, unabhängig vom Chat -
// gehört konzeptionell zur "Hauptprofil"-Optik/UX des Such-Widgets, genau wie die
// Darstellungs-Einstellungen oben.
$form->addRawField('<div id="klxm-appearance-search-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_appearance_search_section_title') . '</p>');

$field = $form->addCheckboxField('frontend_search_current_page_only');
$field->addOption($addon->i18n('config_frontend_search_current_page_only'), 1);

$field = $form->addTextAreaField('search_source_type_labels');
$field->setLabel($addon->i18n('config_search_source_type_labels'));
$field->setNotice($addon->i18n('config_search_source_type_labels_notice'));
$field->setAttribute('rows', '6');
if ($isConfigUnset($field->getValue())) {
    $field->setValue("sitemap_url=Seiten\narticle=Artikel der Website\naddon_docs=AddOn Dokumentation\ngithub_docs=GitHub Dokumentation");
}

$field = $form->addCheckboxField('search_multi_context_snippets');
$field->addOption($addon->i18n('config_search_multi_context_snippets'), 1);
$field->setNotice($addon->i18n('config_search_multi_context_snippets_notice'));

$form->addRawField('</div>');

$form->addRawField($tooltipInitScript);

$renderSettingsPage($form, $renderTipsPanel($addon, 'appearance'));
