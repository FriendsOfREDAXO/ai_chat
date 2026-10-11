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
 *
 * Zweistufig: MariaDB nutzt den VECTOR INDEX nur fuer `ORDER BY VEC_DISTANCE_… LIMIT n` OHNE
 * weitere WHERE-Bedingungen. Mit Scope-/Profil-Filter in derselben Query rechnete sie die
 * Distanz fuer jede Zeile aus (bei ~18.000 Zeilen und 4096 Dimensionen ~13 s statt ~0,4 s).
 * Deshalb zuerst die naechsten Nachbarn ueber den Index, danach filtern. Bleiben nach dem
 * Filtern zu wenige uebrig (sehr enger Filter), folgt die bisherige gefilterte Query.
 */
final class NativeVectorRetrieval implements RetrievalStrategyInterface
{
    /** so viele Kandidaten sollen nach dem Filtern mindestens uebrig bleiben, sonst gefilterte Query */
    private const MIN_FILTERED = 40;

    public function findCandidates(array $userEmbedding, string $whereSql, array $whereParams, int $candidateLimit, ?string $searchText = null): array
    {
        $column = VectorIndexInstaller::columnName();
        $vectorLiteral = json_encode(array_values($userEmbedding));
        if (false === $vectorLiteral) {
            return [];
        }

        $table = rex::getTable('ai_chat_index');
        $select = 'i.content, i.url, i.title, i.source_type, i.source_id, i.source_label, i.image_url, i.updatedate, v.distance';

        if ('' === $whereSql) {
            $query = 'SELECT ' . $select . ' FROM (SELECT id, VEC_DISTANCE_COSINE(' . $column . ', VEC_FromText(?)) AS distance FROM ' . $table
                . ' WHERE ' . $column . ' IS NOT NULL ORDER BY distance ASC LIMIT ' . $candidateLimit . ') v JOIN ' . $table . ' i ON i.id = v.id ORDER BY v.distance ASC';
            $rows = $this->fetch($query, [$vectorLiteral]);
        } else {
            // 1) Index-Suche ohne Filter, 2) Filter auf die Kandidaten – die Unterabfrage liefert nur id und
            // distance, Spalten im Filter (source_type, profile_id, url …) beziehen sich damit eindeutig auf i
            $query = 'SELECT ' . $select . ' FROM (SELECT id, VEC_DISTANCE_COSINE(' . $column . ', VEC_FromText(?)) AS distance FROM ' . $table
                . ' WHERE ' . $column . ' IS NOT NULL ORDER BY distance ASC LIMIT ' . $candidateLimit . ') v JOIN ' . $table . ' i ON i.id = v.id'
                . ' WHERE ' . $whereSql . ' ORDER BY v.distance ASC';
            $rows = $this->fetch($query, array_merge([$vectorLiteral], $whereParams));

            // sehr enger Filter: zu wenige Kandidaten uebrig -> bisherige gefilterte Query (langsamer, aber vollstaendig)
            if (count($rows) < min(self::MIN_FILTERED, $candidateLimit)) {
                $query = 'SELECT content, url, title, source_type, source_id, source_label, image_url, updatedate, VEC_DISTANCE_COSINE(' . $column . ', VEC_FromText(?)) AS distance FROM '
                    . $table . ' WHERE ' . $column . ' IS NOT NULL AND (' . $whereSql . ') ORDER BY distance ASC LIMIT ' . $candidateLimit;
                $rows = $this->fetch($query, array_merge([$vectorLiteral], $whereParams));
            }
        }

        $results = [];
        foreach ($rows as $row) {
            $distance = $row['distance'] ?? null;
            $similarity = is_numeric($distance) ? 1.0 - (float) $distance : 0.0;

            $sourceLabel = trim((string) ($row['source_label'] ?? ''));
            $imageUrl = trim((string) ($row['image_url'] ?? ''));
            $results[] = [
                'content' => (string) ($row['content'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'similarity' => $similarity,
                'source_type' => (string) ($row['source_type'] ?? ''),
                'source_id' => (string) ($row['source_id'] ?? ''),
                'source_label' => '' !== $sourceLabel ? $sourceLabel : null,
                'image_url' => '' !== $imageUrl ? $imageUrl : null,
                'updatedate' => (string) ($row['updatedate'] ?? ''),
            ];
        }

        return $results;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetch(string $query, array $params): array
    {
        return rex_sql::factory()->getArray($query, $params);
    }
}
