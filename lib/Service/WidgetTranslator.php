<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Service;

use rex_addon;
use rex_extension;
use rex_extension_point;
use rex_path;

/**
 * Lädt die Übersetzungen für die Widget-Oberfläche (Chat/Suche - Buttons,
 * Platzhalter, Statusmeldungen; NICHT die KI-Antwort selbst) aus
 * `assets/i18n/{locale}.json`. Getrennt von `lang/de_de.lang`, das REDAXOs
 * Backend-Admin-i18n ist und über `rex_i18n` läuft - hier sind es rohe
 * JSON-Dateien, damit sie ohne PHP-Umweg direkt per fetch() aus dem Browser
 * ladbar sind (siehe Api\WidgetTranslations).
 *
 * Neue Sprache hinzufügen: einfach `assets/i18n/<locale>.json` mit denselben
 * Schlüsseln wie `de.json` anlegen - kein Code nötig. Fehlende Schlüssel
 * fallen automatisch auf Deutsch zurück, eine unvollständige Übersetzung
 * bricht also nichts.
 */
class WidgetTranslator
{
    private const FALLBACK_LOCALE = 'de';

    /**
     * @return array<string, string>
     */
    public static function load(string $locale): array
    {
        $locale = self::sanitizeLocale($locale);
        $fallback = self::readLocaleFile(self::FALLBACK_LOCALE);
        $translations = self::FALLBACK_LOCALE === $locale ? $fallback : array_merge($fallback, self::readLocaleFile($locale));

        // Erlaubt Dritt-Addons, zusätzliche Sprachen/Schlüssel nachzuliefern (z.B.
        // aus ihrem eigenen assets/-Verzeichnis), ohne dieses Addon zu patchen.
        $subject = rex_extension::registerPoint(new rex_extension_point(
            'AI_CHAT_WIDGET_TRANSLATIONS',
            $translations,
            ['locale' => $locale],
        ));

        // Ein Dritt-Addon-Listener koennte hier theoretisch einen Nicht-Array zurueckgeben -
        // PHPStan vertraut dem generischen rex_extension_point<T>-Typ, der zur Laufzeit
        // verletzt werden kann.
        return is_array($subject) ? $subject : $translations; // @phpstan-ignore function.alreadyNarrowedType
    }

    private static function sanitizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return '' !== $locale && preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $locale) ? $locale : self::FALLBACK_LOCALE;
    }

    /**
     * @return array<string, string>
     */
    private static function readLocaleFile(string $locale): array
    {
        $path = rex_path::addon(rex_addon::get('ai_chat')->getName(), 'assets/i18n/' . $locale . '.json');
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
