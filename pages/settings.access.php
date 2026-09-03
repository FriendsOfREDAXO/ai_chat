<?php

use FriendsOfRedaxo\AiChat\Profile\ChatProfile;
use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;

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

// Warnt statt den globalen Schalter zu verstecken: Profile mit chat_enabled/search_enabled
// als bewusstem Tri-State-Override (siehe ChatProfile::$chatEnabled/$searchEnabled) gelten
// weiterhin fuer sich selbst, unabhaengig von der hier gewaehlten globalen Einstellung -
// ohne diesen Hinweis wundert man sich, warum Chat/Suche trotz "aus" auf der Website
// erscheinen (oder trotz "an" bei einem bestimmten Profil fehlen). Verstecken waere falsch,
// da der globale Schalter fuer alle NICHT-ueberschreibenden Profile weiterhin der massgebliche
// Default ist.
$overridingProfiles = array_values(array_filter(
    (new ProfileRepository())->getAll(),
    static fn (ChatProfile $profile): bool => $profile->status && (null !== $profile->chatEnabled || null !== $profile->searchEnabled),
));
if ([] !== $overridingProfiles) {
    $overrideLabels = implode(', ', array_map(
        static fn (ChatProfile $profile): string => rex_escape($profile->name),
        $overridingProfiles,
    ));
    $form->addRawField(
        '<div class="alert alert-warning">' .
        sprintf($addon->i18n('config_frontend_profile_override_hint'), rex_url::backendPage('ai_chat/profiles'), $overrideLabels) .
        '</div>',
    );
}

$field = $form->addCheckboxField('frontend_enabled');
$field->addOption($addon->i18n('config_frontend_enabled'), 1);

// Select statt Checkbox - eine per Checkbox+rex_config_form deaktivierte Einstellung mit
// "true" als Default liesse sich nie dauerhaft abschalten, siehe Kommentar bei
// $addBoolSelectField in settings.shared.php.
$addBoolSelectField($form, 'frontend_search_enabled', $addon->i18n('config_frontend_search_enabled'), $addon->i18n('config_frontend_search_enabled_notice'), true);

// Standard AUS: jede Suche mit aktivem "Alle"-Filter und Treffern aus mehreren Bereichen
// (ChatProfile::$sitemapGroups) loest sonst einen zusaetzlichen KI-Aufruf aus (siehe
// ChatQueryService::buildSearchSummary()) - bewusst opt-in statt stiller Kosten-/Latenz-
// Erhoehung fuer jede unfilterte Suche.
$addBoolSelectField($form, 'search_ai_summary_enabled', $addon->i18n('config_search_ai_summary_enabled'), $addon->i18n('config_search_ai_summary_enabled_notice'), false);

$field = $form->addCheckboxField('backend_enabled');
$field->addOption($addon->i18n('config_backend_enabled'), 1);

$form->addRawField('<p class="help-block">' . sprintf($addon->i18n('config_visibility_profiles_hint'), rex_url::backendPage('ai_chat/profiles')) . '</p>');

$field = $form->addTextField('frontend_allowed_ips');
$field->setLabel($addon->i18n('config_frontend_allowed_ips'));
$field->setNotice($addon->i18n('config_frontend_allowed_ips_notice'));

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
