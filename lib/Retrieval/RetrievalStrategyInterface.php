<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Retrieval;

/**
 * Liefert die Top-N Kandidaten aus `ai_chat_index`, sortiert nach Aehnlichkeit zum
 * mitgegebenen Embedding - Filterung (Scope/Profil/aktuelle-URL) baut der Aufrufer
 * (ChatQueryService::findSimilarContent()) als WHERE-Fragment, damit beide
 * Implementierungen exakt dieselbe Filter-Semantik nutzen und nicht unabhaengig
 * voneinander gepflegt werden muessen. Deduplizierung nach Quelle und das Trimmen
 * schwacher Treffer bleiben ebenfalls beim Aufrufer - reine Kandidaten-Beschaffung hier.
 *
 * Zwei Implementierungen (siehe "Leichte Erweiterbarkeit" im Architektur-Plan):
 * NativeVectorRetrieval (MariaDB VEC_DISTANCE_COSINE, ab 11.7/11.8, siehe
 * FriendsOfRedaxo\AiChat\Db\VectorCapability) und BruteForceRetrieval (PHP-Cosine-
 * Berechnung, immer verfuegbarer Fallback). ChatQueryService waehlt einmalig anhand von
 * VectorCapability::isSupported().
 */
interface RetrievalStrategyInterface
{
    /**
     * @param float[] $userEmbedding
     * @param list<mixed> $whereParams Positionsgebundene Parameter fuer $whereSql (kann leer sein)
     * @return list<array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string, source_label: ?string}>
     */
    public function findCandidates(array $userEmbedding, string $whereSql, array $whereParams, int $candidateLimit): array;

    // Hinweis: 'source_label_description'/'source_label_is_timely' werden NICHT hier, sondern
    // erst in ChatQueryService::findSimilarContent() angereichert (ChatProfile::$sitemapGroups
    // ist der Retrieval-Strategie bewusst nicht bekannt - reine Kandidaten-Beschaffung, siehe
    // Klassen-Doc oben).
}
