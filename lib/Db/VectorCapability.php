<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Db;

use rex_addon;
use rex_sql;

/**
 * Erkennt, ob natives Vektor-Retrieval (MariaDB `VECTOR`/`VECTOR INDEX`, ab 11.7 Preview /
 * 11.8 GA) auf der aktuellen DB verfuegbar ist, und haelt die aktuell fuer den
 * `embedding_vector`-Spalte genutzte Dimension nach (abhaengig vom konfigurierten
 * Embedding-Modell - z.B. 768 fuer Gemini text-embedding-004, 1536 fuer OpenAI
 * text-embedding-3-small). MySQL wird nicht unterstuetzt (siehe TODO.md/Plan).
 *
 * Ergebnis wird in rex_config gecacht, damit nicht bei jeder Anfrage `SELECT VERSION()`
 * laufen muss - ein manueller "Neu pruefen"-Button auf der Indexierungsseite deckt den
 * Fall ab, dass die DB nach der Installation aktualisiert wurde.
 */
final class VectorCapability
{
    private const CONFIG_SUPPORTED = 'vector_capability_supported';
    private const CONFIG_CHECKED_VERSION = 'vector_capability_checked_version';
    private const CONFIG_DIMENSION = 'vector_embedding_dimension';

    public static function isSupported(): bool
    {
        $addon = rex_addon::get('ai_chat');
        $cached = $addon->getConfig(self::CONFIG_SUPPORTED);
        if (is_bool($cached)) {
            return $cached;
        }

        return self::recheck();
    }

    /**
     * Erzwingt eine frische Pruefung (z.B. nach einem DB-Upgrade) statt den gecachten
     * Wert zu nutzen, und schreibt das Ergebnis wieder in den Cache.
     */
    public static function recheck(): bool
    {
        $addon = rex_addon::get('ai_chat');
        $version = self::detectVersion();
        $supported = null !== $version && self::versionSupportsVector($version);

        $addon->setConfig(self::CONFIG_SUPPORTED, $supported);
        $addon->setConfig(self::CONFIG_CHECKED_VERSION, $version ?? '');

        return $supported;
    }

    public static function checkedVersion(): string
    {
        return (string) rex_addon::get('ai_chat')->getConfig(self::CONFIG_CHECKED_VERSION, '');
    }

    /**
     * Aktuell fuer die embedding_vector-Spalte angelegte Dimension, oder null, wenn noch
     * keine Spalte existiert (z.B. vor dem ersten Reindex-Lauf mit aktiver Vektor-
     * Unterstuetzung).
     */
    public static function trackedDimension(): ?int
    {
        $raw = rex_addon::get('ai_chat')->getConfig(self::CONFIG_DIMENSION);

        return is_int($raw) && $raw > 0 ? $raw : null;
    }

    /**
     * Stellt sicher, dass die `embedding_vector`-Spalte/der Index zur gegebenen Dimension
     * passen - legt sie beim ersten Aufruf an bzw. baut sie neu auf, wenn sich die
     * Dimension seit dem letzten Lauf geaendert hat (z.B. anderes Embedding-Modell
     * gewaehlt). Kein Aufwand, wenn die Dimension bereits passt (das ist der Normalfall
     * bei jedem einzelnen Insert waehrend einer Indexierung).
     */
    public static function ensureDimension(int $dimension): void
    {
        if ($dimension <= 0 || !self::isSupported()) {
            return;
        }

        if (self::trackedDimension() === $dimension) {
            return;
        }

        // Nur als erledigt vormerken, wenn der Installer Spalte+Index tatsaechlich bestaetigt
        // hat (nicht z.B. mangels leerer Tabelle uebersprungen) - sonst wuerde dieser Aufruf
        // hier für immer als "schon erledigt" gelten, obwohl nie eine Spalte/ein Index
        // entstanden ist.
        $ready = VectorIndexInstaller::ensureColumnAndIndex(\rex::getTable('ai_chat_index'), $dimension);
        if ($ready) {
            rex_addon::get('ai_chat')->setConfig(self::CONFIG_DIMENSION, $dimension);
        }
    }

    private static function detectVersion(): ?string
    {
        $sql = rex_sql::factory();
        try {
            $sql->setQuery('SELECT VERSION() AS v');
        } catch (\Throwable) {
            return null;
        }

        $version = $sql->getValue('v');

        return is_string($version) && '' !== $version ? $version : null;
    }

    /**
     * MariaDB meldet sich als "11.8.9-MariaDB-...", MySQL/Percona/anderer Fork ohne
     * "MariaDB" im String - wird bewusst NICHT unterstuetzt (siehe Plan: MySQL/HeatWave
     * hat keine echte ANN-Suche in der Community Edition).
     */
    private static function versionSupportsVector(string $version): bool
    {
        if (!str_contains(strtolower($version), 'mariadb')) {
            return false;
        }

        if (!preg_match('/^(\d+)\.(\d+)/', $version, $m)) {
            return false;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];

        return $major > 11 || ($major === 11 && $minor >= 7);
    }
}
