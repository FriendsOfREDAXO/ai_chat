<?php

namespace FriendsOfRedaxo\AiChat\Service;

use rex_addon;
use rex_extension;
use rex_extension_point;

class AiServiceFactory
{
    /**
     * @param array<string, string> $overrides Config-Werte, die statt der gespeicherten Werte
     *                                          verwendet werden sollen (z.B. für den Verbindungstest
     *                                          mit noch ungespeicherten Formularwerten). Fehlende Keys
     *                                          fallen weiterhin auf die gespeicherte Config zurück.
     */
    public static function create(array $overrides = []): AiServiceInterface
    {
        $addon = rex_addon::get('ai_chat');
        $provider = $overrides['provider'] ?? $addon->getConfig('provider', 'gemini');

        /** @var array<string, class-string<AiServiceInterface>> $providers */
        $providers = [
            'gemini' => GeminiService::class,
            'cloudflare' => CloudflareService::class,
            'openai' => OpenAiCompatibleService::class,
            'openai_compatible' => OpenAiCompatibleService::class,
            'ai_platform' => AiPlatformService::class,
        ];

        // Erlaubt Dritt-Addons, eigene Provider unter einem neuen Schlüssel zu
        // registrieren, ohne diese Factory zu patchen.
        // Ein Dritt-Addon-Listener koennte hier theoretisch einen Nicht-Array oder eine
        // Klasse zurueckgeben, die AiServiceInterface gar nicht implementiert - PHPStan
        // vertraut dem generischen rex_extension_point<T>-Typ, der zur Laufzeit verletzt
        // werden kann, daher trotz "immer wahr" nicht entfernen.
        $subject = rex_extension::registerPoint(new rex_extension_point('AI_CHAT_REGISTER_PROVIDERS', $providers));
        if (is_array($subject)) { // @phpstan-ignore function.alreadyNarrowedType
            $providers = $subject;
        }

        $class = $providers[$provider] ?? GeminiService::class;
        if (!is_a($class, AiServiceInterface::class, true)) { // @phpstan-ignore function.alreadyNarrowedType
            $class = GeminiService::class;
        }

        return new $class($overrides);
    }

    /**
     * Baut den System-Prompt-Text, der beim naechsten echten Chat fuer den aktuell
     * konfigurierten Provider TATSAECHLICH gesendet wuerde - fuer die Systemprompt-
     * Transparenz-Ansicht (pages/profiles.php, siehe TODO.md "Feste Systemprompt-
     * Zusatzregeln einsehbar machen"). Kein echter API-Call, reiner Text-Zusammenbau.
     *
     * Die drei aelteren Provider (Gemini/Cloudflare/OpenAI-kompatibel) haben je eine
     * eigene, oeffentliche buildSystemPromptText()-Methode (Extraktion aus ihrer
     * jeweiligen buildXPayload()) - AiPlatformService nutzt stattdessen direkt
     * PromptBuilder::buildSystemPrompt() (siehe dortige generateAnswer()). $personalization
     * bleibt hier bewusst null: das ist zur Laufzeit ein dynamischer, clientseitig
     * mitgeschickter Wert (z.B. eingeloggter Name), fuer eine statische Vorschau nicht
     * sinnvoll simulierbar - der Aufrufer sollte das in der UI kenntlich machen.
     *
     * @param array<string, string> $overrides Wie bei create() - Config-Overrides fuer noch
     *                                          ungespeicherte Formularwerte.
     */
    public static function previewSystemPrompt(?string $systemPromptOverride, ?string $addressingModeOverride, ?string $answerLanguageOverride, array $overrides = []): string
    {
        $service = self::create($overrides);

        if ($service instanceof OpenAiCompatibleService) {
            // Bei diesem Provider kommt die ANWEISUNG erst nach dem Kontext (siehe
            // buildChatCompletionPayload()) - fuer die Vorschau trotzdem direkt
            // angehaengt, damit "der komplette System-seitige Text" an einer Stelle
            // sichtbar ist statt zwei getrennte Textbloecke zu zeigen.
            return $service->buildSystemPromptText(null, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride)
                . "\n\n[... Kontext-Abschnitte ...]\n\n" . $service->buildInstructionText($answerLanguageOverride);
        }

        if ($service instanceof GeminiService || $service instanceof CloudflareService) {
            return $service->buildSystemPromptText(null, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
        }

        // AiPlatformService (und jeder Dritt-Addon-Provider ohne eigene Preview-Methode)
        // nutzt PromptBuilder::buildSystemPrompt() direkt (siehe AiPlatformService::
        // generateAnswer()) - das ist fuer diese Faelle bereits der tatsaechlich
        // gesendete Text, keine weitere Provider-spezifische Anpassung noetig.
        return PromptBuilder::buildSystemPrompt(null, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
    }
}
