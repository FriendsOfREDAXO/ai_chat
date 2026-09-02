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
}
