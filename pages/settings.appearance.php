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

$field = $form->addTextField('primary_color');
$field->setLabel($addon->i18n('config_primary_color'));
$field->setAttribute('type', 'color');
$field->setAttribute('id', 'klxmchat-primary-color-input');
$field->setAttribute('style', 'width: 100px; height: 40px; padding: 0;');
$field->setAttribute('oninput', 'document.getElementById("klxmchat-primary-color-notice").textContent = this.value;');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('#007bff');
}
$field->setNotice('Hex: <span id="klxmchat-primary-color-notice" style="font-family:monospace;">' . ($field->getValue() ?: '#007bff') . '</span>');

$field = $form->addMediaField('avatar');
$field->setLabel($addon->i18n('config_avatar'));

$form->addRawField('<h4 class="klxmchat-theme-heading">' . $addon->i18n('config_theme_section_title') . '</h4>');
$form->addRawField('<p class="help-block">' . $rawMsg('config_theme_section_notice') . '</p>');

$field = $form->addTextField('header_bg_color');
$field->setLabel($addon->i18n('config_header_bg_color'));
$field->setAttribute('type', 'color');
$field->setAttribute('id', 'klxmchat-header-bg-color-input');
$field->setAttribute('style', 'width: 100px; height: 40px; padding: 0;');
$field->setAttribute('oninput', "document.getElementById('klxmchat-header-bg-color-notice').textContent = this.value; document.getElementById('klxmchat-theme-preview-header').style.background = this.value;");
if ($isConfigUnset($field->getValue())) {
    $field->setValue('#f8f9fa');
}
$field->setNotice('Hex: <span id="klxmchat-header-bg-color-notice" style="font-family:monospace;">' . ($field->getValue() ?: '#f8f9fa') . '</span>');

$field = $form->addTextField('chat_bg_color');
$field->setLabel($addon->i18n('config_chat_bg_color'));
$field->setAttribute('type', 'color');
$field->setAttribute('id', 'klxmchat-chat-bg-color-input');
$field->setAttribute('style', 'width: 100px; height: 40px; padding: 0;');
$field->setAttribute('oninput', "document.getElementById('klxmchat-chat-bg-color-notice').textContent = this.value; document.getElementById('klxmchat-theme-preview').style.background = this.value;");
if ($isConfigUnset($field->getValue())) {
    $field->setValue('#ffffff');
}
$field->setNotice('Hex: <span id="klxmchat-chat-bg-color-notice" style="font-family:monospace;">' . ($field->getValue() ?: '#ffffff') . '</span>');

$field = $form->addTextField('text_color');
$field->setLabel($addon->i18n('config_text_color'));
$field->setAttribute('type', 'color');
$field->setAttribute('id', 'klxmchat-text-color-input');
$field->setAttribute('style', 'width: 100px; height: 40px; padding: 0;');
$field->setAttribute('oninput', "document.getElementById('klxmchat-text-color-notice').textContent = this.value; document.getElementById('klxmchat-theme-preview-header').style.color = this.value;");
if ($isConfigUnset($field->getValue())) {
    $field->setValue('#333333');
}
$field->setNotice('Hex: <span id="klxmchat-text-color-notice" style="font-family:monospace;">' . ($field->getValue() ?: '#333333') . '</span>');

$field = $form->addTextField('bot_message_bg_color');
$field->setLabel($addon->i18n('config_bot_message_bg_color'));
$field->setAttribute('type', 'color');
$field->setAttribute('id', 'klxmchat-bot-msg-bg-color-input');
$field->setAttribute('style', 'width: 100px; height: 40px; padding: 0;');
$field->setAttribute('oninput', "document.getElementById('klxmchat-bot-msg-bg-color-notice').textContent = this.value; document.getElementById('klxmchat-theme-preview-bot').style.background = this.value;");
if ($isConfigUnset($field->getValue())) {
    $field->setValue('#f1f3f5');
}
$field->setNotice('Hex: <span id="klxmchat-bot-msg-bg-color-notice" style="font-family:monospace;">' . ($field->getValue() ?: '#f1f3f5') . '</span>');

$field = $form->addTextField('border_radius');
$field->setLabel($addon->i18n('config_border_radius'));
$field->setAttribute('type', 'number');
$field->setAttribute('min', '0');
$field->setAttribute('max', '30');
$field->setAttribute('style', 'width: 80px;');
$field->setAttribute('id', 'klxmchat-border-radius-input');
$field->setAttribute('oninput', "document.getElementById('klxmchat-theme-preview').style.borderRadius = this.value + 'px';");
if ($isConfigUnset($field->getValue())) {
    $field->setValue(12);
}
$field->setNotice($addon->i18n('config_border_radius_notice'));

$previewHeaderBg = $addon->getConfig('header_bg_color', '#f8f9fa');
$previewChatBg = $addon->getConfig('chat_bg_color', '#ffffff');
$previewTextColor = $addon->getConfig('text_color', '#333333');
$previewBotBg = $addon->getConfig('bot_message_bg_color', '#f1f3f5');
$previewRadius = (int) $addon->getConfig('border_radius', 12);

$form->addRawField('
<div class="klxmchat-theme-preview-wrapper">
    <p class="help-block">' . $addon->i18n('config_theme_preview_label') . '</p>
    <div id="klxmchat-theme-preview" class="klxmchat-theme-preview" style="max-width:320px;border:1px solid rgba(0,0,0,0.15);box-shadow:0 5px 20px rgba(0,0,0,0.15);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;background:' . rex_escape($previewChatBg, 'html_attr') . ';border-radius:' . $previewRadius . 'px;">
        <div id="klxmchat-theme-preview-header" style="padding:15px;font-weight:bold;background:' . rex_escape($previewHeaderBg, 'html_attr') . ';color:' . rex_escape($previewTextColor, 'html_attr') . ';">Website Chat</div>
        <div style="padding:15px;">
            <div id="klxmchat-theme-preview-bot" style="display:inline-block;padding:8px 12px;border-radius:12px;background:' . rex_escape($previewBotBg, 'html_attr') . ';color:' . rex_escape($previewTextColor, 'html_attr') . ';">Hallo! Wie kann ich Ihnen helfen?</div>
        </div>
    </div>
</div>
');

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
