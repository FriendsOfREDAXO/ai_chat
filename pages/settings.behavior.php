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

$form->addRawField('<p class="help-block">' . $rawMsg('config_section_behavior_hint') . '</p>');

// Ehemals "Hauptprofil: Verhalten & Antworten" - die frueher hier zusaetzlich vorhandenen
// Felder show_sources/personalization_mode/frontend_addressing_mode/suggest_followup_questions
// sind mit der Profil-Entflechtung entfallen: jedes Profil traegt diese Werte jetzt als
// echten, eigenstaendigen Wert (siehe pages/profiles.php), ein globaler Fallback wird von
// keiner Stelle im Code mehr gelesen. Was hier bleibt, sind ausschliesslich Felder OHNE
// Profil-Gegenstueck - reine globale Texte/Vorgaben, die fuer jedes Profil gleichermassen
// gelten.
$form->addRawField('<div id="klxm-behavior-chat-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_behavior_chat_section_title') . '</p>');
$form->addRawField('<p class="help-block">' . sprintf($addon->i18n('config_behavior_profiles_hint'), rex_url::backendPage('ai_chat/profiles')) . '</p>');

$field = $form->addTextField('sources_title');
$field->setLabel($addon->i18n('config_sources_title'));
$field->setNotice($addon->i18n('config_sources_title_notice'));
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Links:");
}

$field = $form->addTextAreaField('frontend_greeting');
$field->setLabel($addon->i18n('config_frontend_greeting'));
$field->setNotice($addon->i18n('config_frontend_greeting_notice') . ' Gilt nur, solange ein Profil keine eigene Begrüßung gesetzt hat (siehe AI Chat → Profile).');
$field->setAttribute('rows', '2');
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Hallo! Wie kann ich Ihnen helfen?");
}

$field = $form->addTextAreaField('frontend_prompt');
$field->setLabel($addon->i18n('config_frontend_prompt'));
$field->setNotice($addon->i18n('config_frontend_prompt_notice') . ' Gilt nur, solange ein Profil keinen eigenen Prompt gesetzt hat (siehe AI Chat → Profile).');
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Du bist ein hilfreicher Assistent für diese Website. Nutze den folgenden Kontext, um die Frage des Nutzers zu beantworten.");
}

$field = $form->addTextAreaField('frontend_additional_context');
$field->setLabel($addon->i18n('config_frontend_additional_context'));
$field->setNotice($addon->i18n('config_frontend_additional_context_notice'));

$field = $form->addTextAreaField('error_message');
$field->setLabel($addon->i18n('config_error_message'));
$field->setNotice($addon->i18n('config_error_message_notice'));
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Entschuldigung, ich bin gerade überlastet. Bitte versuchen Sie es später noch einmal oder nutzen Sie unser Kontaktformular.");
}

$form->addRawField('</div>');

// Ehemals eigene "Erscheinungsbild"-Seite - dorthin gehoerten nur noch diese zwei globalen
// Vorgaben (Farben/Avatar/Radius kommen bereits seit der zentralen Theme-Verwaltung nicht
// mehr von hier, siehe AI Chat -> Themes), eine eigene Seite dafuer lohnte sich nicht mehr.
$form->addRawField('<div class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">Darstellung</p>');

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

$field = $form->addSelectField('position');
$field->setLabel($addon->i18n('config_position'));
$select = $field->getSelect();
$select->addOption($addon->i18n('config_position_bottom_right'), 'bottom-right');
$select->addOption($addon->i18n('config_position_bottom_left'), 'bottom-left');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('bottom-right');
}

$form->addRawField('<p class="help-block">Farben, Avatar und Eckenradius werden zentral unter <a href="' . rex_url::backendPage('ai_chat/themes') . '">AI Chat → Themes</a> gepflegt (inkl. Live-Vorschau und Transparenz-Unterstützung) - dort auch, welches Theme als globaler Standard gilt.</p>');

$form->addRawField('</div>');

$form->addRawField($tooltipInitScript);

$renderSettingsPage($form, $renderTipsPanel($addon, 'behavior'));
