<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Retrieval;

use FriendsOfRedaxo\AiChat\Db\VectorIndexInstaller;
use rex;
use rex_addon;
use rex_sql;

/**
 * Reciprocal Rank Fusion (RRF): fusioniert zwei unabhaengige MariaDB-Rankings - ein
 * Volltext-Ranking (`MATCH() AGAINST()` gegen den in install.php angelegten FULLTEXT
 * INDEX auf title/content) und das bestehende Vektor-Ranking (`VEC_DISTANCE_COSINE()`,
 * siehe NativeVectorRetrieval) - statt nur eines der beiden zu nutzen. Ersetzt bei
 * Aktivierung NativeVectorRetrieval komplett (nicht nur ChatQueryService::
 * rerankResults()), damit rein lexikalisch starke, aber vektoriell eher schwache
 * Treffer ebenfalls eine faire Chance auf einen Top-Platz bekommen - rerankResults()
 * arbeitet sonst nur auf dem bereits vektor-vorgefilterten, kleinen Kandidatenpool.
 *
 * Formel je Ranking: 1 / (k + rank), rank ab 1 (RANK() OVER(), keine Luecken bei
 * Gleichstand ausgeschlossen). Ein Treffer, der nur in einem der beiden Rankings
 * vorkommt, bekommt fuer das jeweils andere Ranking 0 statt eines Straf-Werts (siehe
 * COALESCE im JOIN) - MariaDB kennt kein natives FULL OUTER JOIN, der LEFT JOIN +
 * COALESCE-Aufbau bildet das nach.
 *
 * Nur fuer den ohnehin MariaDB-exklusiven Vektor-Pfad gedacht (siehe TODO.md: bewusst
 * kein PHP-Fallback-Aequivalent) - ChatQueryService::resolveRetrievalStrategy() waehlt
 * diese Strategie nur, wenn VectorCapability::isSupported() UND der FULLTEXT-Index laut
 * install.php vorhanden ist UND der Nutzer die Option aktiviert hat.
 */
final class HybridRrfRetrieval implements RetrievalStrategyInterface
{
    public function findCandidates(array $userEmbedding, string $whereSql, array $whereParams, int $candidateLimit, ?string $searchText = null): array
    {
        $searchText = trim((string) $searchText);
        if ('' === $searchText) {
            // Kein Suchtext (z.B. reine FAQ-Cache-Abfrage ohne Nutzerfrage) - Volltext-
            // Ranking waere bedeutungslos, reiner Vektor-Pfad reicht dann aus.
            return (new NativeVectorRetrieval())->findCandidates($userEmbedding, $whereSql, $whereParams, $candidateLimit);
        }

        $vectorColumn = VectorIndexInstaller::columnName();
        $vectorLiteral = json_encode(array_values($userEmbedding));
        if (false === $vectorLiteral) {
            return [];
        }

        $k = $this->getRrfK();
        $table = rex::getTable('ai_chat_index');
        $whereFragment = '' !== $whereSql ? ' AND (' . $whereSql . ')' : '';

        $query = <<<SQL
            WITH fulltext_ranked AS (
                SELECT id, RANK() OVER (ORDER BY MATCH(title, content) AGAINST (? IN NATURAL LANGUAGE MODE) DESC) AS rnk
                FROM {$table}
                WHERE MATCH(title, content) AGAINST (? IN NATURAL LANGUAGE MODE) > 0{$whereFragment}
                LIMIT {$candidateLimit}
            ),
            vector_ranked AS (
                SELECT id, RANK() OVER (ORDER BY VEC_DISTANCE_COSINE({$vectorColumn}, VEC_FromText(?)) ASC) AS rnk
                FROM {$table}
                WHERE {$vectorColumn} IS NOT NULL{$whereFragment}
                LIMIT {$candidateLimit}
            )
            SELECT
                i.content, i.url, i.title, i.source_type, i.source_id, i.source_label,
                (COALESCE(1.0 / (? + ft.rnk), 0) + COALESCE(1.0 / (? + vr.rnk), 0)) AS rrf_score,
                ft.rnk AS fulltext_rank,
                vr.rnk AS vector_rank
            FROM {$table} i
            LEFT JOIN fulltext_ranked ft ON ft.id = i.id
            LEFT JOIN vector_ranked vr ON vr.id = i.id
            WHERE ft.id IS NOT NULL OR vr.id IS NOT NULL
            ORDER BY rrf_score DESC
            LIMIT {$candidateLimit}
            SQL;

        // Reihenfolge MUSS exakt der Platzhalter-Reihenfolge im SQL-Text entsprechen:
        // Volltext-Suchtext (2x, RANK-Ausdruck + WHERE-Bedingung), dann dessen
        // WHERE-Fragment-Params, dann Vektor-Literal, dann dessen WHERE-Fragment-Params,
        // dann die beiden RRF-k-Konstanten.
        $params = array_merge(
            [$searchText, $searchText],
            $whereParams,
            [$vectorLiteral],
            $whereParams,
            [$k, $k],
        );

        $sql = rex_sql::factory();
        $sql->setQuery($query, $params);

        $rows = [];
        $maxScore = 0.0;
        foreach ($sql as $row) {
            $score = (float) $row->getValue('rrf_score');
            $maxScore = max($maxScore, $score);

            $sourceLabel = trim((string) $row->getValue('source_label'));
            $fulltextRank = $row->getValue('fulltext_rank');
            $vectorRank = $row->getValue('vector_rank');

            $rows[] = [
                'content' => (string) $row->getValue('content'),
                'url' => (string) $row->getValue('url'),
                'title' => (string) $row->getValue('title'),
                'source_type' => (string) $row->getValue('source_type'),
                'source_id' => (string) $row->getValue('source_id'),
                'source_label' => '' !== $sourceLabel ? $sourceLabel : null,
                'rrf_score_raw' => $score,
                'fulltext_rank' => is_numeric($fulltextRank) ? (int) $fulltextRank : null,
                'vector_rank' => is_numeric($vectorRank) ? (int) $vectorRank : null,
            ];
        }

        // Normalisierung auf die bestehende 0-1-Similarity-Skala (hoechster Treffer im
        // Pool = 1.0) - haelt alle nachgelagerten, an Cosine-Similarity gewoehnten
        // Schwellenwerte (z.B. ChatQueryService::hasSufficientAnswerContext(),
        // trimLowSignalResults(), collectDisplaySources()) unveraendert funktionsfaehig,
        // ohne dass jede Konsumentenstelle einzeln auf die RRF-Score-Skala angepasst
        // werden muss. rrf_score_raw/fulltext_rank/vector_rank bleiben zusaetzlich fuer
        // das Retrieval-Debug-Log erhalten (siehe ChatQueryService::logRetrievalDebug()).
        $results = [];
        foreach ($rows as $row) {
            $row['similarity'] = $maxScore > 0.0 ? $row['rrf_score_raw'] / $maxScore : 0.0;
            $results[] = $row;
        }

        return $results;
    }

    private function getRrfK(): int
    {
        $configured = (int) rex_addon::get('ai_chat')->getConfig('hybrid_search_rrf_k', 60);

        return $configured > 0 ? $configured : 60;
    }
}
