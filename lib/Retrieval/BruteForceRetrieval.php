<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Retrieval;

use rex;
use rex_addon;
use rex_sql;

/**
 * Fallback-Strategie ohne native Vektor-Unterstuetzung der Datenbank: berechnet die
 * Cosine-Aehnlichkeit in PHP. Funktioniert unabhaengig von DB-Version/-Hersteller, aber O(n)
 * pro Anfrage statt eines Index-gestuetzten Nearest-Neighbor-Scans. Siehe
 * NativeVectorRetrieval fuer die schnellere Alternative auf MariaDB 11.7+/11.8+.
 *
 * Auswahl der Kandidaten (bis $candidateLimit Zeilen):
 *  1. zuerst die Abschnitte, die per Volltext (FULLTEXT title/content, falls angelegt) am
 *     besten zur Anfrage passen - so fallen inhaltlich passende Seiten nie aus dem Pool,
 *  2. danach die uebrigen Zeilen in Index-Reihenfolge, bis das Limit erreicht ist.
 * Frueher wurden nur die ersten $candidateLimit Zeilen nach id verglichen: bei einem Index mit
 * mehr Zeilen (z.B. viele News, die beim Neuaufbau zuerst eingelesen werden) kamen die spaeter
 * eingelesenen Seiten gar nicht in den Vergleich - die KI fand Kontakt- oder Kreisseiten nie.
 *
 * Embeddings werden blockweise geladen und nur die besten Treffer behalten, damit auch ein
 * grosses Limit den Speicher nicht sprengt.
 */
final class BruteForceRetrieval implements RetrievalStrategyInterface
{
    private const BATCH = 250;
    private const KEEP = 200;

    public function findCandidates(array $userEmbedding, string $whereSql, array $whereParams, int $candidateLimit, ?string $searchText = null): array
    {
        $queryMagnitude = VectorMath::magnitude($userEmbedding);
        $table = rex::getTable('ai_chat_index');
        $where = '' !== $whereSql ? ' WHERE ' . $whereSql : '';

        $ids = [];
        $searchText = trim((string) $searchText);
        if ('' !== $searchText && (bool) rex_addon::get('ai_chat')->getConfig('fulltext_index_ready', false)) {
            try {
                $match = 'MATCH(title, content) AGAINST (? IN NATURAL LANGUAGE MODE)';
                $rows = rex_sql::factory()->getArray(
                    'SELECT id FROM ' . $table . ('' !== $where ? $where . ' AND ' : ' WHERE ') . $match . ' > 0'
                    . ' ORDER BY ' . $match . ' DESC LIMIT ' . min($candidateLimit, 400),
                    array_merge($whereParams, [$searchText, $searchText]),
                );
                foreach ($rows as $row) {
                    $ids[(int) $row['id']] = true;
                }
            } catch (\rex_sql_exception) {
                // kein FULLTEXT-Index - nur Schritt 2
            }
        }

        $remaining = $candidateLimit - count($ids);
        if ($remaining > 0) {
            $rows = rex_sql::factory()->getArray(
                'SELECT id FROM ' . $table . $where . ' ORDER BY id ASC LIMIT ' . ($remaining + count($ids)),
                $whereParams,
            );
            foreach ($rows as $row) {
                if (count($ids) >= $candidateLimit) {
                    break;
                }
                $ids[(int) $row['id']] = true;
            }
        }

        $results = [];
        foreach (array_chunk(array_keys($ids), self::BATCH) as $chunk) {
            $sql = rex_sql::factory();
            $sql->setQuery(
                'SELECT content, embedding, embedding_norm, url, title, source_type, source_id, source_label, image_url, updatedate FROM ' . $table
                . ' WHERE id IN (' . implode(',', array_map('intval', $chunk)) . ')',
            );
            foreach ($sql as $row) {
                /** @var mixed $decoded */
                $decoded = json_decode((string) $row->getValue('embedding'), true);
                if (!is_array($decoded)) {
                    continue;
                }

                $storedMagnitude = $row->getValue('embedding_norm');
                $similarity = VectorMath::cosineSimilarity(
                    $userEmbedding,
                    $decoded,
                    $queryMagnitude,
                    is_numeric($storedMagnitude) ? (float) $storedMagnitude : null,
                );

                $sourceLabel = trim((string) $row->getValue('source_label'));
                $imageUrl = trim((string) $row->getValue('image_url'));
                $results[] = [
                    'content' => (string) $row->getValue('content'),
                    'url' => (string) $row->getValue('url'),
                    'title' => (string) $row->getValue('title'),
                    'similarity' => $similarity,
                    'source_type' => (string) $row->getValue('source_type'),
                    'source_id' => (string) $row->getValue('source_id'),
                    'source_label' => '' !== $sourceLabel ? $sourceLabel : null,
                    'image_url' => '' !== $imageUrl ? $imageUrl : null,
                'updatedate' => (string) $row->getValue('updatedate'),
                ];
            }

            // nur die besten behalten (Speicher)
            if (count($results) > self::KEEP * 2) {
                usort($results, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
                $results = array_slice($results, 0, self::KEEP);
            }
        }

        usort($results, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($results, 0, self::KEEP);
    }
}
