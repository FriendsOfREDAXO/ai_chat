<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Profile;

use rex_addon_interface;
use rex_url;

/**
 * Loest die darstellungsbezogenen Attribute des Frontend-Widgets auf: je Profil
 * gesetzte theme_*-Spalten gehen vor, leere/nicht gesetzte Felder fallen auf die
 * globale Darstellung-Einstellung zurueck (siehe pages/settings.appearance.php) -
 * exakt dasselbe "Profil overrides global default"-Muster wie bei
 * ChatProfile::$customPrompt/$greeting. Nur fuer den Frontend-Chat gedacht - der
 * automatisch eingebundene Backend-Chat nutzt bewusst weiterhin sein eigenes,
 * einheitliches "scope-accent"-Aussehen statt Custom-Branding (siehe boot.php).
 */
final class ProfileTheme
{
    public static function resolvePrimaryColor(ChatProfile $profile, rex_addon_interface $addon): string
    {
        return self::firstValidHexColor($profile->themePrimaryColor, (string) $addon->getConfig('primary_color', '#007bff'))
            ?? '#007bff';
    }

    public static function resolveAvatarUrl(ChatProfile $profile, rex_addon_interface $addon): string
    {
        $avatar = $profile->themeAvatar ?: $addon->getConfig('avatar');

        return $avatar ? rex_url::media((string) $avatar) : '';
    }

    public static function resolvePosition(ChatProfile $profile, rex_addon_interface $addon): string
    {
        $position = $profile->themePosition ?: (string) $addon->getConfig('position', 'bottom-right');

        return in_array($position, ['bottom-right', 'bottom-left'], true) ? $position : 'bottom-right';
    }

    /**
     * Baut den Inhalt fuer ein style="..."-Attribut auf dem <ai-chat>-Element (CSS-Custom-
     * Properties durchdringen die Shadow-DOM-Grenze und muessen daher nicht per <style>-Tag
     * mit Selektor gesetzt werden - ein Inline-Attribut reicht, da pro Seite ohnehin nur ein
     * Frontend-Widget existiert). Nur valide Hex-Farben/Zahlen werden uebernommen.
     */
    public static function buildInlineStyle(ChatProfile $profile, rex_addon_interface $addon): string
    {
        $vars = [];

        $addColorVar = static function (string $cssVar, ?string $profileValue, string $globalConfigKey) use (&$vars, $addon): void {
            $value = self::firstValidHexColor($profileValue, (string) $addon->getConfig($globalConfigKey));
            if (null !== $value) {
                $vars[] = $cssVar . ':' . $value;
            }
        };

        $addColorVar('--ai-chat-header-bg', $profile->themeHeaderBgColor, 'header_bg_color');
        $addColorVar('--ai-chat-bg', $profile->themeChatBgColor, 'chat_bg_color');
        $addColorVar('--ai-chat-text', $profile->themeTextColor, 'text_color');
        $addColorVar('--ai-chat-bot-msg-bg', $profile->themeBotMessageBgColor, 'bot_message_bg_color');

        // Bewusst KEIN ?: - PHP behandelt den String "0" als falsy, ein bewusst eingegebener
        // Eckenradius von 0 (eckige Ecken) wuerde damit wie "leer" behandelt und faelschlich
        // durch den globalen Wert ersetzt. Nur ein echter Leerstring bedeutet "globale
        // Einstellung".
        $profileRadius = trim((string) $profile->themeBorderRadius);
        $radius = '' !== $profileRadius ? $profileRadius : trim((string) $addon->getConfig('border_radius', ''));
        if ('' !== $radius && preg_match('/^\d{1,3}$/', $radius)) {
            $vars[] = '--ai-chat-radius:' . $radius . 'px';
        }

        return implode(';', $vars);
    }

    private static function firstValidHexColor(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ('' !== $value && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
                return $value;
            }
        }

        return null;
    }
}
