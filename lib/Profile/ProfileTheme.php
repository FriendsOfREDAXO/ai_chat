<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Profile;

use rex_addon_interface;
use rex_url;

/**
 * Loest die darstellungsbezogenen Attribute des Frontend-Widgets auf. Farben/Avatar/
 * Eckenradius kommen seit der zentralen Theme-Verwaltung aus GENAU EINEM ChatTheme:
 * dem per ChatProfile::$themeId gewaehlten, oder - falls das Profil keins gewaehlt hat -
 * dem globalen Standard-Theme (Config "default_theme_id", siehe install.php). Die
 * Widget-Position ist bewusst KEIN Theme-Bestandteil und bleibt weiterhin ein eigenes,
 * unabhaengiges Override je Profil (ChatProfile::$themePosition), exakt wie zuvor. Nur
 * fuer den Frontend-Chat gedacht - der automatisch eingebundene Backend-Chat nutzt
 * bewusst weiterhin sein eigenes, einheitliches "scope-accent"-Aussehen statt
 * Custom-Branding (siehe boot.php).
 */
final class ProfileTheme
{
    /**
     * Loest das fuer dieses Profil (bzw. global, wenn kein Profil) effektive Theme auf -
     * einmal pro Aufruf-Kontext berechnen und an die anderen resolve*()-Methoden
     * durchreichen, statt es fuer jede einzeln neu aus der DB zu laden.
     */
    public static function resolveTheme(?ChatProfile $profile, rex_addon_interface $addon): ?ChatTheme
    {
        $themeId = (null !== $profile ? $profile->themeId : null) ?? (int) $addon->getConfig('default_theme_id', 0);
        if ($themeId <= 0) {
            return null;
        }

        return (new ThemeRepository())->find($themeId);
    }

    public static function resolvePrimaryColor(?ChatTheme $theme): string
    {
        return self::firstValidHexColor($theme?->primaryColor) ?? '#007bff';
    }

    public static function resolveAvatarUrl(?ChatTheme $theme): string
    {
        return $theme?->avatar ? rex_url::media($theme->avatar) : '';
    }

    public static function resolvePosition(?ChatProfile $profile, rex_addon_interface $addon): string
    {
        $position = $profile?->themePosition ?: (string) $addon->getConfig('position', 'bottom-right');

        return in_array($position, ['bottom-right', 'bottom-left'], true) ? $position : 'bottom-right';
    }

    /**
     * Baut den Inhalt fuer ein style="..."-Attribut auf dem <ai-chat>-Element (CSS-Custom-
     * Properties durchdringen die Shadow-DOM-Grenze und muessen daher nicht per <style>-Tag
     * mit Selektor gesetzt werden - ein Inline-Attribut reicht, da pro Seite ohnehin nur ein
     * Frontend-Widget existiert). Nur valide Hex-Farben/Zahlen werden uebernommen.
     */
    public static function buildInlineStyle(?ChatTheme $theme): string
    {
        $vars = [];

        $addColorVar = static function (string $cssVar, ?string $value) use (&$vars): void {
            $value = self::firstValidHexColor($value);
            if (null !== $value) {
                $vars[] = $cssVar . ':' . $value;
            }
        };

        $addColorVar('--ai-chat-header-bg', $theme?->headerBgColor);
        $addColorVar('--ai-chat-bg', $theme?->chatBgColor);
        $addColorVar('--ai-chat-text', $theme?->textColor);
        $addColorVar('--ai-chat-bot-msg-bg', $theme?->botMessageBgColor);

        // Bewusst KEIN ?: - PHP behandelt den String "0" als falsy, ein bewusst eingegebener
        // Eckenradius von 0 (eckige Ecken) wuerde damit wie "leer" behandelt.
        $radius = trim((string) $theme?->borderRadius);
        if ('' !== $radius && preg_match('/^\d{1,3}$/', $radius)) {
            $vars[] = '--ai-chat-radius:' . $radius . 'px';
        }

        return implode(';', $vars);
    }

    /**
     * Farbwerte duerfen inzwischen auch 8-stelliges RGBA-Hex sein (#RRGGBBAA, ueber den
     * alpha-faehigen Colorpicker in pages/themes.php erzeugt) - CSS und dieses Regex
     * unterstuetzen das bereits seit jeher (3-8 Hexziffern), nur der frueher genutzte
     * native <input type="color"> konnte kein Alpha erzeugen.
     */
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
