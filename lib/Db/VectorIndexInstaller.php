<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Db;

use rex_sql;

/**
 * Legt die `embedding_vector VECTOR(n)`-Spalte samt `VECTOR INDEX` fuer eine Tabelle an,
 * oder baut sie neu auf, wenn sich die Dimension geaendert hat (anderes Embedding-Modell).
 *
 * Bewusster Bypass von `rex_sql_table`/`rex_sql_index`: `rex_sql_column`s Typ ist zwar ein
 * freier String, `rex_sql_table::ensure()` diffed Spaltentypen aber gegen den zuletzt
 * bekannten Zustand - fuer den REDAXO-fremden Typ `VECTOR(n)` ist nicht garantiert, dass
 * das zuverlaessig funktioniert. `rex_sql_index` unterstuetzt ohnehin nur INDEX/UNIQUE/
 * FULLTEXT, kein VECTOR INDEX. Diese Klasse kapselt deshalb bewusst rohes SQL mit eigener
 * Introspektion ueber INFORMATION_SCHEMA, statt den Kern-Klassen einen Typ unterzuschieben,
 * den sie nicht kennen.
 */
final class VectorIndexInstaller
{
    private const COLUMN = 'embedding_vector';
    private const INDEX_NAME = 'embedding_vector_idx';

    /**
     * @return bool true, wenn Spalte+Index fuer die gegebene Dimension jetzt (oder bereits
     *              vorher) korrekt vorhanden sind; false, wenn der Aufbau mangels leerer
     *              Tabelle uebersprungen wurde - der Aufrufer (VectorCapability::
     *              ensureDimension()) darf die Dimension dann NICHT als erledigt vormerken,
     *              sonst wird der naechste passende Versuch (leere Tabelle) faelschlich
     *              uebersprungen.
     */
    public static function ensureColumnAndIndex(string $table, int $dimension): bool
    {
        $desiredType = 'vector(' . $dimension . ')';
        $currentType = self::currentColumnType($table);

        // Beides muss stimmen, nicht nur der Spaltentyp: eine fruehere ALTER TABLE ... ADD
        // VECTOR INDEX kann fehlgeschlagen sein (z.B. weil die Spalte damals faelschlich
        // NULL statt NOT NULL war), waehrend ADD COLUMN bereits erfolgreich war - dann
        // existiert die Spalte mit passendem Typ, aber ohne Index. Ohne diese Pruefung
        // wuerde das fuer immer unbemerkt so bleiben, weil unten sonst sofort "true"
        // zurueckgegeben wird.
        if ($currentType === $desiredType && self::indexExists($table)) {
            return true;
        }

        // MariaDB verlangt fuer einen VECTOR INDEX eine NOT NULL-Spalte - ohne gueltigen
        // Default (den VECTOR(n) nicht sinnvoll anbietet) laesst sich das nur auf einer
        // LEEREN Tabelle sicher anlegen bzw. neu aufbauen. Der einzige Aufrufer
        // (IndexerService::setEmbeddingColumns(), aus runFull()) truncated die Tabelle immer
        // zuerst - die allererste Zeile eines Laufs trifft hier also auf eine leere Tabelle.
        // Ein Aufruf aus einem inkrementellen Einzel-Update (Tabelle nicht leer, Spalte fehlt
        // noch/hat eine andere Dimension/der Index fehlt noch) wird hier bewusst
        // uebersprungen statt zu raten - der naechste volle Reindex-Lauf legt Spalte/Index
        // dann sauber neu an.
        if (self::hasRows($table)) {
            return false;
        }

        $sql = rex_sql::factory();

        if (null !== $currentType) {
            // Abweichende Dimension ODER fehlender Index bei sonst passendem Typ - Index
            // und Spalte verwerfen, unten sauber neu anlegen. Ein direktes MODIFY COLUMN auf
            // eine andere VECTOR(n)-Breite ist nicht vorgesehen.
            if (self::indexExists($table)) {
                $sql->setQuery('ALTER TABLE ' . $table . ' DROP INDEX ' . self::INDEX_NAME);
            }
            $sql->setQuery('ALTER TABLE ' . $table . ' DROP COLUMN ' . self::COLUMN);
        }

        $sql->setQuery('ALTER TABLE ' . $table . ' ADD COLUMN ' . self::COLUMN . ' VECTOR(' . $dimension . ') NOT NULL');
        $sql->setQuery('ALTER TABLE ' . $table . ' ADD VECTOR INDEX ' . self::INDEX_NAME . ' (' . self::COLUMN . ') DISTANCE=cosine');

        return true;
    }

    private static function hasRows(string $table): bool
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT 1 FROM ' . $table . ' LIMIT 1');

        return $sql->getRows() > 0;
    }

    public static function columnName(): string
    {
        return self::COLUMN;
    }

    private static function currentColumnType(string $table): ?string
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, self::COLUMN],
        );

        if (0 === $sql->getRows()) {
            return null;
        }

        $type = $sql->getValue('COLUMN_TYPE');

        return is_string($type) ? $type : null;
    }

    private static function indexExists(string $table): bool
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, self::INDEX_NAME],
        );

        return $sql->getRows() > 0;
    }
}
