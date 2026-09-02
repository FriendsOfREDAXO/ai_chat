<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Retrieval;

use FriendsOfRedaxo\AiChat\Db\VectorIndexInstaller;
use rex;
use rex_sql;

/**
 * Nutzt MariaDBs natives Vektor-Retrieval (`VEC_DISTANCE_COSINE()` gegen die per
 * `VectorCapability`/`VectorIndexInstaller` gepflegte `embedding_vector`-Spalte samt
 * `VECTOR INDEX`) statt einer PHP-Schleife - kombiniert Nearest-Neighbor-Suche und die
 * SQL-Filterung (Scope/Profil/aktuelle-URL) in einer einzigen, indexgestuetzten Query.
 *
 * `VEC_DISTANCE_COSINE()` liefert die Cosine-DISTANCE (0 = identisch, 1 = orthogonal,
 * 2 = entgegengesetzt) - `similarity = 1 - distance` bringt das auf dieselbe Skala wie
 * BruteForceRetrieval, damit nachgelagerte Schwellenwerte (z.B. in
 * ChatQueryService::hasSufficientAnswerContext()) unabhaengig von der aktiven Strategie
 * funktionieren.
 *
 * Zeilen ohne `embedding_vector` (z.B. vor Aktivierung der Vektor-Unterstuetzung indexiert)
 * werden ausgeschlossen statt stillschweigend mit Distanz 0 sortiert zu werden - ein
 * vollstaendiger Reindex fuellt sie nach.
 */
final class NativeVectorRetrieval implements RetrievalStrategyInterface
{
    public function findCandidates(array $userEmbedding, string $whereSql, array $whereParams, int $candidateLimit): array
    {
        $column = VectorIndexInstaller::columnName();
        $vectorLiteral = json_encode(array_values($userEmbedding));
        if (false === $vectorLiteral) {
            return [];
        }

        $conditions = [$column . ' IS NOT NULL'];
        if ('' !== $whereSql) {
            $conditions[] = $whereSql;
        }

        $query = 'SELECT content, url, title, source_type, source_id, source_label, VEC_DISTANCE_COSINE(' . $column . ', VEC_FromText(?)) AS distance FROM '
            . rex::getTable('ai_chat_index')
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY distance ASC LIMIT ' . $candidateLimit;

        $sql = rex_sql::factory();
        $sql->setQuery($query, array_merge([$vectorLiteral], $whereParams));

        $results = [];
        foreach ($sql as $row) {
            $distance = $row->getValue('distance');
            $similarity = is_numeric($distance) ? 1.0 - (float) $distance : 0.0;

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

        return $results;
    }
}
