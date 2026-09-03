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

// Chat: alles, was ausschließlich den Konversationsmodus betrifft (Ton, Prompt,
// Personalisierung, Verlauf) - für die Suche irrelevant, da die Suche keine
// KI-Konversation führt, sondern Treffer liefert. Rate-Limit/Nachrichtenlängen/
// Datenschutz-Whitelist/Spam-Begriffe leben inzwischen bei "Global: Zugriff &
// Sicherheit" (reiner Abuse-/Kosten-Schutz, keine Chat-Persönlichkeit); die
// Such-Feineinstellungen bei "Hauptprofil: Erscheinungsbild & Suche".
$form->addRawField('<div id="klxm-behavior-chat-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_behavior_chat_section_title') . '</p>');
$form->addRawField('<p class="help-block">' . sprintf($addon->i18n('config_behavior_profiles_hint'), rex_url::backendPage('ai_chat/profiles')) . '</p>');

$field = $form->addTextField('sources_title');
$field->setLabel($addon->i18n('config_sources_title'));
$field->setNotice($addon->i18n('config_sources_title_notice'));
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Links:");
}

$field = $form->addCheckboxField('show_sources');
$field->addOption($addon->i18n('config_show_sources'), 1);
$field->setNotice($addon->i18n('config_show_sources_notice'));
if ($isConfigUnset($field->getValue())) {
    $field->setValue('|1|');
}

$field = $form->addTextAreaField('frontend_greeting');
$field->setLabel($addon->i18n('config_frontend_greeting'));
$field->setNotice($addon->i18n('config_frontend_greeting_notice'));
$field->setAttribute('rows', '2');
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Hallo! Wie kann ich Ihnen helfen?");
}

$field = $form->addSelectField('personalization_mode');
$field->setLabel($tooltipLabel($addon->i18n('config_personalization_mode'), 'config_personalization_notice'));
$select = $field->getSelect();
$select->addOption($addon->i18n('config_personalization_mode_off'), 'off');
$select->addOption($addon->i18n('config_personalization_mode_simple'), 'simple');
$select->addOption($addon->i18n('config_personalization_mode_name'), 'name');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('off');
}
$field->setNotice($addon->i18n('config_personalization_notice'));

$field = $form->addSelectField('frontend_addressing_mode');
$field->setLabel($tooltipLabel($addon->i18n('config_frontend_addressing_mode'), 'config_frontend_addressing_mode_notice'));
$select = $field->getSelect();
$select->addOption($addon->i18n('config_frontend_addressing_mode_auto'), 'auto');
$select->addOption($addon->i18n('config_frontend_addressing_mode_formal'), 'formal');
$select->addOption($addon->i18n('config_frontend_addressing_mode_informal'), 'informal');
$select->addOption($addon->i18n('config_frontend_addressing_mode_neutral'), 'neutral');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('auto');
}
$field->setNotice($addon->i18n('config_frontend_addressing_mode_notice'));

$field = $form->addTextAreaField('frontend_prompt');
$field->setLabel($addon->i18n('config_frontend_prompt'));
$field->setNotice($addon->i18n('config_frontend_prompt_notice'));
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

$field = $form->addCheckboxField('suggest_followup_questions');
$field->addOption($addon->i18n('config_suggest_followup_questions'), 1);
$field->setNotice($addon->i18n('config_suggest_followup_questions_notice'));

$field = $form->addCheckboxField('stream_enabled');
$field->addOption($addon->i18n('config_stream_enabled'), 1);
$field->setNotice($addon->i18n('config_stream_enabled_notice'));

// Reset-Countdown/Verlauf-kopieren für Backend- UND Frontend-Chat sind seit dem
// Profil-Feature vollständig durch die entsprechenden Profil-Felder abgelöst
// (siehe pages/profiles.php, Box "Verhalten") - boot.php liest sie nur noch aus
// dem aufgelösten ChatProfile, nie mehr aus dieser globalen Config. Die früher
// hier vorhandenen Felder backend_chat_reset_countdown/_copy_history und
// frontend_chat_reset_countdown/_copy_history wurden deshalb entfernt statt sie
// als wirkungslose Karteileichen stehen zu lassen.

$form->addRawField('</div>');

$form->addRawField($tooltipInitScript);

$renderSettingsPage($form, $renderTipsPanel($addon, 'behavior'));
