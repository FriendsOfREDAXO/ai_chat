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

$form->addRawField('<p class="help-block">' . $rawMsg('config_section_access_hint') . '</p>');

// Alle Sichtbarkeits-/Zugriffsschalter gebündelt in einer Box, statt über die
// Seite verstreut: "wer/wo/wann sieht Chat und Suche überhaupt" ist eine
// zusammenhängende Fragestellung und war vorher an sechs verschiedenen
// Stellen zwischen Personalisierung und UX-Feineinstellungen verteilt.
$form->addRawField('<div id="klxm-access-visibility-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_visibility_section_title') . '</p>');

// Chat/Suche automatisch einbinden ist seit der Hauptprofil-Entflechtung ausschliesslich
// eine Profil-Einstellung (chat_enabled/search_enabled, Standard: aktiv, siehe AI Chat →
// Profile) - kein globaler Schalter mehr, der hier stehen koennte.
$form->addRawField('<p class="help-block">' . sprintf($addon->i18n('config_visibility_profiles_hint'), rex_url::backendPage('ai_chat/profiles')) . '</p>');

$field = $form->addTextField('frontend_allowed_ips');
$field->setLabel($addon->i18n('config_frontend_allowed_ips'));
$field->setNotice($addon->i18n('config_frontend_allowed_ips_notice'));

// Eigene IP direkt anzeigen (zum Reinkopieren) statt den Admin auf ein externes "Wie
// lautet meine IP"-Tool zu verweisen - plus ein Live-Status, ob die eingetragene Liste die
// aktuelle IP bereits enthaelt, damit nicht erst gespeichert und neu geladen werden muss,
// um das zu sehen.
$currentRequestIp = rex_server('REMOTE_ADDR', 'string', '');
if ('' !== $currentRequestIp) {
    $configuredIps = array_map('trim', explode(',', (string) $addon->getConfig('frontend_allowed_ips', '')));
    $currentIpListed = in_array($currentRequestIp, $configuredIps, true);
    $statusHtml = $currentIpListed
        ? '<span class="text-success"><i class="rex-icon fa-check"></i> ist bereits in der Liste (Testmodus für dich aktiv)</span>'
        : '<span class="text-muted">ist noch nicht in der Liste</span>';
    $form->addRawField(
        '<p class="help-block" style="margin-left:170px;">Deine aktuelle IP: '
        . '<code id="ai-chat-current-ip">' . rex_escape($currentRequestIp) . '</code> '
        . '<button type="button" class="btn btn-xs btn-default" onclick="navigator.clipboard.writeText(document.getElementById(\'ai-chat-current-ip\').textContent)"><i class="rex-icon fa-copy"></i> Kopieren</button> '
        . $statusHtml . '</p>'
    );
}

$form->addRawField('</div>');

// Sicherheits-/Missbrauchsschutz: Rate-Limit, Nachrichtenlängen, Datenschutz-Whitelist und
// Spam-Begriffe sind reine Abuse-/Kosten-Schutz-Mechanismen, keine Chat-Persönlichkeit - daher
// hier bei den übrigen globalen Zugriffs-/Sicherheitseinstellungen statt bei "Verhalten".
$form->addRawField('<div id="klxm-access-limits-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_access_limits_section_title') . '</p>');

$field = $form->addTextField('rate_limit');
$field->setLabel($addon->i18n('config_rate_limit'));
$field->setNotice($addon->i18n('config_rate_limit_notice'));
if ($isConfigUnset($field->getValue())) {
    $field->setValue(10);
}

$field = $form->addTextField('max_message_length_frontend');
$field->setLabel($addon->i18n('config_max_message_length_frontend'));
$field->setNotice($addon->i18n('config_max_message_length_frontend_notice'));
if ($isConfigUnset($field->getValue())) {
    $field->setValue(2000);
}

$field = $form->addTextAreaField('privacy_email_domain_whitelist');
$field->setLabel($addon->i18n('config_privacy_email_domain_whitelist'));
$field->setNotice($addon->i18n('config_privacy_email_domain_whitelist_notice'));
$field->setAttribute('rows', '4');

$field = $form->addTextAreaField('spam_badwords');
$field->setLabel($addon->i18n('config_spam_badwords'));
$field->setNotice($addon->i18n('config_spam_badwords_notice'));
$field->setAttribute('rows', '4');
$field->setAttribute('placeholder', "seo-dienstleistung\nbacklink kaufen\ncasino bonus");

$form->addRawField('</div>');

// Eigener Abschnitt statt Teil der Sichtbarkeits-Box: andere Zielgruppe
// (externe/programmatische Zugriffe statt Website-Besucher) und ein
// grundlegend anderes Risiko - "Public API" bedeutet hier wirklich komplett
// offen, ganz ohne Token.
$form->addRawField('<div id="klxm-access-api-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_api_section_title') . '</p>');

$field = $form->addCheckboxField('api_public_enabled');
$field->addOption($addon->i18n('config_api_public_enabled'), 1);
$field->setNotice($addon->i18n('config_api_public_enabled_notice'));

if (rex_addon::get('api')->isAvailable()) {
    $form->addRawField('<p class="help-block">' . sprintf($addon->i18n('config_api_token_hint'), rex_url::backendPage('api/token')) . '</p>');
} else {
    $form->addRawField('<p class="help-block">' . $addon->i18n('config_api_token_hint_unavailable') . '</p>');
}

$form->addRawField('</div>');

// Personalisierung, Anrede und Chat-Verlauf-UX sind hier bewusst NICHT mehr
// eingebunden - das steuert WIE geantwortet wird, nicht WER/WO Zugriff hat,
// und lebt jetzt gebündelt auf der Verhalten-Seite (Chat-Box).

$form->addRawField($tooltipInitScript);

$renderSettingsPage($form, $renderTipsPanel($addon, 'access'));
