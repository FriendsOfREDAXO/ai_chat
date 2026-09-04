<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Service;

use FriendsOfRedaxo\AiPlatform\Service as AiPlatformCoreService;
use rex_addon;
use rex_logger;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * Delegiert Text-Generierung und Embeddings an das ai_platform-Addon, statt eigene
 * Provider-Zugangsdaten zu verwalten. ai_platform bringt eine eigene, Symfony-AI-
 * basierte Profilverwaltung (OpenAI/Anthropic/Google/Ollama/OpenAI-kompatibel) mit -
 * hier wird nur ausgewählt, welches ai_platform-Profil für Text bzw. Embedding
 * verwendet wird (config keys ai_platform_text_profile_id/ai_platform_embedding_profile_id).
 */
class AiPlatformService implements AiServiceInterface
{
    private ?int $textProfileId;
    private ?int $embeddingProfileId;

    /**
     * @param array<string, string> $overrides Optionale Config-Overrides (siehe AiServiceFactory::create()).
     */
    public function __construct(array $overrides = [])
    {
        $addon = rex_addon::get('ai_chat');
        $this->textProfileId = $this->resolveProfileId($overrides['ai_platform_text_profile_id'] ?? $addon->getConfig('ai_platform_text_profile_id'));
        $this->embeddingProfileId = $this->resolveProfileId($overrides['ai_platform_embedding_profile_id'] ?? $addon->getConfig('ai_platform_embedding_profile_id'));
    }

    private function resolveProfileId(mixed $raw): ?int
    {
        $value = trim((string) $raw);

        return '' === $value ? null : (int) $value;
    }

    /**
     * @return list<float>
     */
    public function getEmbedding(string $text): array
    {
        $this->assertEmbeddingProfileConfigured();

        /** @var list<float> $vector */
        $vector = AiPlatformCoreService::getInstance()->generateEmbedding($text, $this->embeddingProfileId);

        return $vector;
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function getEmbeddings(array $texts): array
    {
        if ([] === $texts) {
            return [];
        }

        $this->assertEmbeddingProfileConfigured();

        /** @var list<list<float>> $vectors */
        $vectors = AiPlatformCoreService::getInstance()->generateEmbedding($texts, $this->embeddingProfileId);

        return $vectors;
    }

    public function generateAnswer(string $prompt, array $context = [], string $scope = 'frontend', ?array $personalization = null, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): string
    {
        if (null === $this->textProfileId) {
            throw new \Exception('ai_platform: Kein Text-Profil ausgewählt.');
        }

        $systemPrompt = PromptBuilder::buildSystemPrompt($personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
        $userPrompt = PromptBuilder::buildUserPrompt($prompt, $context);

        return AiPlatformCoreService::getInstance()->generateText($userPrompt, $systemPrompt, $this->textProfileId);
    }

    /**
     * ai_platform selbst bietet keinen generateTextStream()-Helfer (nur das
     * fertige generateText(), siehe dortige Service-Klasse - bewusst nicht
     * angefasst, "Ändere keine anderen AddOns"). Symfony AI (das ai_platform
     * intern nutzt) unterstuetzt Streaming aber bereits providerübergreifend
     * über die Option 'stream' => true und DeferredResult::asStream() - beides
     * oeffentliche API von ai_platform (Service::getPlatform()/
     * getProfileOptions() sind public), hier also ohne Aenderung an ai_platform
     * selbst nachgebaut. Provider/Modelle ohne Streaming-Unterstuetzung werfen
     * beim invoke() oder liefern keine verwertbaren Chunks - in beiden Faellen
     * greift der Fallback auf generateAnswer() (non-streaming), kein stiller
     * Bruch, siehe TODO.md.
     *
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float}> $context
     * @param array<string, mixed>|null $personalization
     * @param callable(string): void $onChunk
     */
    public function generateAnswerStream(string $prompt, array $context = [], string $scope = 'frontend', ?array $personalization = null, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?callable $onChunk = null, ?string $answerLanguageOverride = null): string
    {
        if (null === $this->textProfileId) {
            throw new \Exception('ai_platform: Kein Text-Profil ausgewählt.');
        }

        $systemPrompt = PromptBuilder::buildSystemPrompt($personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
        $userPrompt = PromptBuilder::buildUserPrompt($prompt, $context);

        $core = AiPlatformCoreService::getInstance();
        $profile = $core->getProfile($this->textProfileId);
        $model = is_array($profile) ? (string) ($profile['model'] ?? '') : '';

        if ('' === $model) {
            return $this->generateAnswer($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
        }

        $full = '';
        $emittedAnyText = false;

        try {
            $platform = $core->getPlatform($this->textProfileId);
            $options = $core->getProfileOptions($this->textProfileId);
            $options['stream'] = true;

            $messages = new MessageBag(Message::forSystem($systemPrompt), Message::ofUser($userPrompt));
            $result = $platform->invoke($model, $messages, $options);

            foreach ($result->asStream() as $chunk) {
                // Der Stream liefert nicht ausschließlich Text-Deltas - je nach Provider
                // koennen z.B. Token-Usage- oder Tool-Call-Ereignisse dazwischen auftauchen
                // (siehe Symfony AI ResultConverter-Bridges), nur echte, nicht-leere Strings
                // sind fuer den Chat relevant.
                if (!is_string($chunk) || '' === $chunk) {
                    continue;
                }
                $full .= $chunk;
                $emittedAnyText = true;
                if (null !== $onChunk) {
                    $onChunk($chunk);
                }
            }
        } catch (\Throwable $e) {
            // Bricht der Stream NACH bereits gesendeten Chunks ab, wird bewusst NICHT mehr
            // auf generateAnswer() zurueckgefallen (siehe unten) - das wuerde eine zweite,
            // abweichende Antwort ueber das "complete"-Event nachschieben, obwohl der Client
            // schon Teile der ersten gesehen hat. Nur ein von Anfang an erfolgloser Stream
            // faellt sauber zurueck.
            rex_logger::logException($e);
        }

        if ($emittedAnyText) {
            return $full;
        }

        // Kein einziger Text-Chunk angekommen (Provider/Modell ohne Streaming-Unterstuetzung,
        // oder ein Fehler vor dem ersten Chunk) - sauberer Fallback auf die fertige, nicht
        // gestreamte Antwort, kein stiller Bruch.
        return $this->generateAnswer($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
    }

    private function assertEmbeddingProfileConfigured(): void
    {
        if (null === $this->embeddingProfileId) {
            throw new \Exception('ai_platform: Kein Embedding-Profil ausgewählt.');
        }
    }
}
