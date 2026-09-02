<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Retrieval;

use rex_logger;

/**
 * Reine Vektor-Mathematik fuer die Brute-Force-Cosine-Berechnung - geteilt zwischen
 * BruteForceRetrieval (Wissens-Retrieval) und ChatQueryService (FAQ-Cache-Treffer-Abgleich,
 * der unabhaengig von der gewaehlten Retrieval-Strategie immer brute-force bleibt, siehe
 * TODO.md).
 */
final class VectorMath
{
    /**
     * @param float[] $vector
     */
    public static function magnitude(array $vector): float
    {
        $sumOfSquares = 0.0;
        foreach ($vector as $value) {
            $sumOfSquares += $value * $value;
        }

        return sqrt($sumOfSquares);
    }

    /**
     * $magnitude1 (die Query-Vektor-Magnitude) ist fuer eine einzelne Suche bei jeder Zeile
     * gleich, daher berechnen Aufrufer sie einmal und reichen sie durch statt pro Kandidat
     * erneut die Wurzel zu ziehen. $magnitude2 (die gespeicherte Vektor-Magnitude) ist seit
     * dem letzten (Re-)Index/Cache-Schreiben konstant und wird deshalb vorab berechnet und
     * neben dem Embedding gespeichert (embedding_norm) - null nur fuer Zeilen von vor dieser
     * Spalte, das faellt hier auf eine Neuberechnung zurueck.
     *
     * @param float[] $vec1
     * @param float[] $vec2
     */
    public static function cosineSimilarity(array $vec1, array $vec2, float $magnitude1, ?float $magnitude2 = null): float
    {
        if (count($vec1) !== count($vec2)) {
            rex_logger::factory()->warning('AiChat: Embedding dimensions mismatch! Query: ' . count($vec1) . ', DB: ' . count($vec2) . '. Please re-index.');
            return 0;
        }

        $dotProduct = 0.0;
        foreach ($vec1 as $key => $value) {
            if (!isset($vec2[$key])) {
                continue;
            }
            $dotProduct += $value * $vec2[$key];
        }

        $magnitude2 ??= self::magnitude($vec2);

        if ($magnitude1 * $magnitude2 == 0.0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }
}
