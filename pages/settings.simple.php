<?php

use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;

require __DIR__ . '/settings.shared.php';

/**
 * Von settings.shared.php per require in diesen Scope injiziert - PHPStan kann require-
 * Variablenfluss nicht ueber Dateigrenzen hinweg verfolgen, daher hier explizit annotiert.
 *
 * @var rex_addon_interface $addon
 * @var Closure(string): string $rawMsg
 */

/**
 * Bewusst kein eigenes Formular (kein rex_config_form, keine duplizierten Felder) - "Einfach"
 * ist eine Orientierungsseite, keine zweite Kopie der Provider-/Sicherheits-Formulare. Seit
 * der Hauptprofil-Entflechtung ist "Profile" ohnehin die zentrale Konfigurationsflaeche
 * (Wissen/Verhalten/Darstellung pro Profil) - hier stehen nur noch die zwei bis drei
 * Handgriffe, die vor dem ersten Profil global einmal noetig sind, plus Status/Links zu den
 * Detail-Seiten (Expert).
 */

$provider = (string) $addon->getConfig('provider', 'gemini');
$providerLabels = [
    'gemini' => 'Google Gemini',
    'cloudflare' => 'Cloudflare Workers AI',
    'openai' => 'OpenWebUI / OpenAI-kompatibel',
    'ai_platform' => 'ai_platform-Addon',
];
$providerLabel = $providerLabels[$provider] ?? $provider;

$providerReady = match ($provider) {
    'gemini' => '' !== trim((string) $addon->getConfig('gemini_api_key', '')),
    'cloudflare' => '' !== trim((string) $addon->getConfig('cloudflare_account_id', '')) && '' !== trim((string) $addon->getConfig('cloudflare_api_token', '')),
    'openai' => '' !== trim((string) $addon->getConfig('openai_base_url', '')),
    'ai_platform' => '' !== trim((string) $addon->getConfig('ai_platform_text_profile_id', '')) && '' !== trim((string) $addon->getConfig('ai_platform_embedding_profile_id', '')),
    default => false,
};

$profiles = (new ProfileRepository())->getAll();
$enabledProfileCount = count(array_filter($profiles, static fn ($profile) => $profile->status));

$statusRow = static function (bool $ok, string $okText, string $missingText): string {
    return $ok
        ? '<span class="text-success"><i class="rex-icon fa-check-circle"></i> ' . $okText . '</span>'
        : '<span class="text-warning"><i class="rex-icon fa-exclamation-triangle"></i> ' . $missingText . '</span>';
};

$body = '<div class="row">';

$body .= '<div class="col-md-6">';
$body .= '<div class="panel panel-default"><header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-plug"></i> 1. KI-Provider</div></header><div class="panel-body">';
$body .= '<p>Aktuell gewählt: <strong>' . rex_escape($providerLabel) . '</strong></p>';
$body .= '<p>' . $statusRow($providerReady, 'Zugangsdaten hinterlegt.', 'Noch keine Zugangsdaten hinterlegt - der Chat kann so noch keine Antworten erzeugen.') . '</p>';
$body .= '<a class="btn btn-primary" href="' . rex_url::backendPage('ai_chat/settings/provider') . '"><i class="rex-icon fa-cog"></i> KI-Provider & Parameter konfigurieren</a>';
$body .= '</div></div>';
$body .= '</div>';

$body .= '<div class="col-md-6">';
$body .= '<div class="panel panel-default"><header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-user-circle"></i> 2. Profile</div></header><div class="panel-body">';
$body .= '<p>' . $enabledProfileCount . ' aktive' . (1 === $enabledProfileCount ? 's Profil' : ' Profile') . ' von ' . count($profiles) . ' insgesamt.</p>';
$body .= '<p class="help-block">Ein Profil legt fest, wer den Chat/die Suche sieht, was er weiß und wie er antwortet - inklusive Anrede, Begrüßung, Folgefragen und eigenen Wissensquellen. Das ist seit Kurzem die zentrale Stelle für nahezu jede Anpassung, nicht mehr diese Einstellungsseite.</p>';
$body .= '<a class="btn btn-primary" href="' . rex_url::backendPage('ai_chat/profiles') . '"><i class="rex-icon fa-list"></i> Profile verwalten</a>';
$body .= '</div></div>';
$body .= '</div>';

$body .= '</div>';

$body .= '<div class="row"><div class="col-md-12">';
$body .= '<div class="panel panel-default"><header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-sitemap"></i> Weitere Bereiche</div></header><div class="panel-body">';
$body .= '<ul style="margin-bottom:0;">';
$body .= '<li><a href="' . rex_url::backendPage('ai_chat/content/overview') . '">Indexierung</a> (inkl. YForm-Tabellen, Trigger & Antworten, Cache-Fragen) - Wissensquellen befüllen, jedes Profil wählt dabei seine eigenen aus (siehe Profile).</li>';
$body .= '<li><a href="' . rex_url::backendPage('ai_chat/settings/behavior') . '">Verhalten & Antworten</a> (inkl. Darstellung), <a href="' . rex_url::backendPage('ai_chat/settings/search') . '">Suche</a>, <a href="' . rex_url::backendPage('ai_chat/settings/access') . '">Zugriff & Sicherheit</a>, <a href="' . rex_url::backendPage('ai_chat/settings/retrieval') . '">Chunking & Cache</a>, <a href="' . rex_url::backendPage('ai_chat/settings/systemcheck') . '">Systemcheck</a> - Detail-Einstellungen, die über die Grundeinrichtung hinausgehen.</li>';
$body .= '</ul>';
$body .= '</div></div>';
$body .= '</div></div>';

$fragment = new rex_fragment();
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');
