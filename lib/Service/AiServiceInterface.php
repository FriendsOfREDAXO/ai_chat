<?php

namespace FriendsOfRedaxo\AiChat\Service;

interface AiServiceInterface
{
    /**
     * @return list<float>
     */
    public function getEmbedding(string $text): array;

    /**
     * Embeds multiple texts, batching them into as few provider requests as
     * possible. Implementations should preserve input order in the result.
     *
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function getEmbeddings(array $texts): array;

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float}> $context
     * @param array<string, mixed>|null $personalization
     * @param string|null $systemPromptOverride Ersetzt den konfigurierten System-Prompt
     *                                           (z.B. aus einem Profil, siehe ChatProfile::$customPrompt)
     * @param string|null $addressingModeOverride Ersetzt die konfigurierte Anrede-Einstellung
     *                                             (z.B. aus einem Profil, siehe ChatProfile::$addressingMode)
     * @param string|null $answerLanguageOverride Sprache der KI-Antwort (z.B. aus einem Profil,
     *                                             siehe ChatProfile::$answerLanguage) - null/leer
     *                                             = unveraendertes Verhalten (Deutsch)
     */
    public function generateAnswer(string $prompt, array $context = [], string $scope = 'frontend', ?array $personalization = null, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): string;
}
