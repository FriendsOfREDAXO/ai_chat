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
 * unabhaengiges Override je Profil (ChatProfile::$themePosition), exakt wie zuvor.
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
     * Wert fuer das "open-animation"-Attribut auf dem <ai-chat>-Element (siehe
     * assets/ai-chat.js, :host([open-animation="..."])-Regeln). Whitelist statt Direkt-
     * Durchreichen, damit ein manipulierter/veralteter DB-Wert nie ein beliebiges,
     * nicht existierendes CSS-Attribut erzeugt - Fallback ist "fade_slide" (bisheriges,
     * einziges Verhalten vor diesem Feature).
     */
    public static function resolveOpenAnimation(?ChatTheme $theme): string
    {
        $value = trim((string) $theme?->openAnimation);
        $allowed = ['fade_slide', 'zoom', 'slide_up', 'flip_3d'];

        return in_array($value, $allowed, true) ? $value : 'fade_slide';
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

        // Folgefragen-Chips waren bisher fest an --ai-chat-primary gekoppelt (siehe
        // showFollowUpQuestions() in assets/ai-chat.js) und dadurch nicht unabhaengig von
        // der Akzentfarbe themebar. Fehlt dieser Wert, greift dort weiterhin die Fallback-
        // Kette auf --ai-chat-primary - unveraendertes Verhalten fuer jedes Theme, das
        // dieses Feld nicht setzt.
        $addColorVar('--ai-chat-followup', $theme?->followupColor);
        $addColorVar('--ai-chat-header-bg', $theme?->headerBgColor);
        $addColorVar('--ai-chat-bg', $theme?->chatBgColor);
        $addColorVar('--ai-chat-text', $theme?->textColor);
        $addColorVar('--ai-chat-bot-msg-bg', $theme?->botMessageBgColor);
        // Fehlen diese, greift im Widget-CSS eine Fallback-Kette (Bot-Sprechblase ->
        // --ai-chat-text -> #333, Nutzer-Sprechblase -> fest "white") - unveraendertes
        // Verhalten fuer jedes Theme, das diese Felder nicht setzt.
        $addColorVar('--ai-chat-bot-msg-text', $theme?->botMessageTextColor);
        $addColorVar('--ai-chat-user-msg-text', $theme?->userMessageTextColor);
        // --ai-chat-input-* existierten im Widget-CSS bereits vorher, waren aber von
        // keinem Theme-Feld aus befuellbar - ohne diese Zeilen bliebe das Eingabefeld auf
        // einem dunklen Theme stur weiss/dunkelgrau umrandet.
        $addColorVar('--ai-chat-input-bg', $theme?->inputBgColor);
        $addColorVar('--ai-chat-input-text', $theme?->inputTextColor);
        $addColorVar('--ai-chat-input-border', $theme?->inputBorderColor);
        // Fehlt dieser Wert, greift im Widget-CSS die Fallback-Kette auf --ai-chat-primary
        // (assets/ai-chat.js .chat-toggle) - die Bubble war bisher fest an dieselbe Farbe
        // wie jedes andere Akzent-Element gekoppelt, jetzt unabhaengig davon themebar.
        $addColorVar('--ai-chat-bubble', $theme?->bubbleColor);

        // Bewusst KEIN ?: - PHP behandelt den String "0" als falsy, ein bewusst eingegebener
        // Eckenradius von 0 (eckige Ecken) wuerde damit wie "leer" behandelt.
        $radius = trim((string) $theme?->borderRadius);
        if ('' !== $radius && preg_match('/^\d{1,3}$/', $radius)) {
            $vars[] = '--ai-chat-radius:' . $radius . 'px';
        }

        $bubbleShadow = self::resolveShadow($theme?->bubbleShadowColor, $theme?->bubbleShadowIntensity, 'rgba(0,0,0,0.15)', [
            'light' => '0 2px 6px',
            'medium' => '0 4px 12px',
            'strong' => '0 6px 20px',
        ]);
        if ('' !== $bubbleShadow) {
            $vars[] = '--ai-chat-bubble-shadow:' . $bubbleShadow;
        }

        $containerShadow = self::resolveShadow($theme?->containerShadowColor, $theme?->containerShadowIntensity, 'rgba(0,0,0,0.2)', [
            'light' => '0 3px 10px',
            'medium' => '0 5px 20px',
            'strong' => '0 8px 32px',
        ]);
        if ('' !== $containerShadow) {
            $vars[] = '--ai-chat-container-shadow:' . $containerShadow;
        }

        // Nur sichtbar, wenn --ai-chat-bg (s.o.) zusaetzlich transparent/teiltransparent ist
        // (Alpha im Colorpicker) - siehe Notice-Text im Theme-Editor.
        $blur = trim((string) $theme?->backdropBlur);
        if ('' !== $blur && preg_match('/^\d{1,3}$/', $blur)) {
            $vars[] = '--ai-chat-backdrop-filter:blur(' . $blur . 'px)';
        }

        return implode(';', $vars);
    }

    /**
     * Baut einen fertigen "box-shadow"-Wert aus einer vom Colorpicker gewaehlten Farbe
     * (mit Alpha) + einer festen Intensitaetsstufe (Blur/Offset vordefiniert, siehe
     * $offsetsByIntensity) statt einzelner Zahlenfelder fuer jeden Schatten-Parameter.
     * "none" liefert den CSS-Literalwert "none" (Schatten explizit deaktiviert), eine
     * unbekannte/leere Stufe liefert '' (keine CSS-Var gesetzt, der bisherige
     * Hartcode-Fallback im Widget-CSS greift dann unveraendert weiter).
     *
     * @param array<string, string> $offsetsByIntensity Schluessel "light"/"medium"/"strong"
     */
    private static function resolveShadow(?string $color, ?string $intensity, string $defaultColor, array $offsetsByIntensity): string
    {
        $intensity = trim((string) $intensity);
        if ('' === $intensity) {
            $intensity = 'medium';
        }
        if ('none' === $intensity || !isset($offsetsByIntensity[$intensity])) {
            return 'none' === $intensity ? 'none' : '';
        }

        $resolvedColor = self::firstValidHexColor($color) ?? $defaultColor;

        return $offsetsByIntensity[$intensity] . ' ' . $resolvedColor;
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
