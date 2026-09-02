<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Retrieval;

use rex;
use rex_sql;

/**
 * Fallback-Strategie: laedt bis zu $candidateLimit Kandidatenzeilen (mediumtext-JSON-
 * Embedding) und berechnet die Cosine-Aehnlichkeit in PHP. Funktioniert unabhaengig von
 * DB-Version/-Hersteller - immer verfuegbar, aber O(n) pro Anfrage statt eines Index-
 * gestuetzten Nearest-Neighbor-Scans. Siehe NativeVectorRetrieval fuer die schnellere
 * Alternative auf MariaDB 11.7+/11.8+.
 */
final class BruteForceRetrieval implements RetrievalStrategyInterface
{
    public function findCandidates(array $userEmbedding, string $whereSql, array $whereParams, int $candidateLimit): array
    {
        $queryMagnitude = VectorMath::magnitude($userEmbedding);

        $query = 'SELECT content, embedding, embedding_norm, url, title, source_type, source_id, source_label FROM ' . rex::getTable('ai_chat_index');
        if ('' !== $whereSql) {
            $query .= ' WHERE ' . $whereSql;
        }
        // Bewusst KEIN "ORDER BY updatedate DESC": das würde ältere, aber inhaltlich
        // relevante Chunks (z.B. eine selten geänderte Kontaktseite) systematisch vor
        // dem eigentlichen Ähnlichkeitsvergleich aus dem Kandidatenpool verdrängen.
        $query .= ' ORDER BY id ASC LIMIT ' . $candidateLimit;

        $sql = rex_sql::factory();
        $sql->setQuery($query, $whereParams);

        $results = [];
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
            $results[] = [
                'content' => (string) $row->getValue('content'),
                'url' => (string) $row->getValue('url'),
                'title' => (string) $row->getValue('title'),
                'similarity' => $similarity,
                'source_type' => (string) $row->getValue('source_type'),
                'source_id' => (string) $row->getValue('source_id'),
                'source_label' => '' !== $sourceLabel ? $sourceLabel : null,
            ];
        }

        usort($results, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return $results;
    }
}
