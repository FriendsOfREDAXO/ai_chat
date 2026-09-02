<?php

namespace FriendsOfRedaxo\AiChat\Api;

use FriendsOfRedaxo\AiChat\Service\AiServiceFactory;
use FriendsOfRedaxo\AiChat\Service\OpenAiCompatibleService;
use rex;
use rex_addon;
use rex_api_function;
use rex_request;
use rex_response;

class ChatTest extends rex_api_function
{
    use JsonResponseTrait;

    protected $published = false; // Only for logged in backend users

    public function execute()
    {
        $user = rex::getUser();
        if (!$user) {
            rex_response::setStatus(rex_response::HTTP_UNAUTHORIZED);
            rex_response::sendJson(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        rex_response::cleanOutputBuffers();
        ob_start();

        try {
            // Testet bewusst den aktuellen (ggf. noch ungespeicherten) Formularstand der
            // Provider-Einstellungsseite statt nur der gespeicherten Config - der Test-Button
            // sendet dafür die aktuellen Feldwerte mit. Nicht mitgesendete Felder fallen auf
            // die gespeicherte Config zurück.
            $overrides = [];
            foreach (['provider', 'gemini_api_key', 'openai_base_url', 'openai_api_key', 'openai_model', 'openai_embedding_model', 'cloudflare_account_id', 'cloudflare_api_token', 'cloudflare_model', 'ai_platform_text_profile_id', 'ai_platform_embedding_profile_id'] as $key) {
                $value = rex_request::post($key, 'string', null);
                if (null !== $value) {
                    $overrides[$key] = $value;
                }
            }

            $service = AiServiceFactory::create($overrides);

            $addon = rex_addon::get('ai_chat');
            $provider = $overrides['provider'] ?? $addon->getConfig('provider');

            // Check keys
            if ($provider === 'gemini' && empty($overrides['gemini_api_key'] ?? $addon->getConfig('gemini_api_key'))) {
                throw new \Exception('Gemini API Key is missing');
            }
            // Base URL is optional for OpenAI (defaults to api.openai.com)
            if ($provider === 'cloudflare' && (empty($overrides['cloudflare_account_id'] ?? $addon->getConfig('cloudflare_account_id')) || empty($overrides['cloudflare_api_token'] ?? $addon->getConfig('cloudflare_api_token')))) {
                throw new \Exception('Cloudflare credentials missing');
            }
            if ($provider === 'ai_platform' && empty($overrides['ai_platform_text_profile_id'] ?? $addon->getConfig('ai_platform_text_profile_id'))) {
                throw new \Exception('ai_platform: Kein Text-Profil ausgewählt');
            }

            // Test Generation
            $testPrompt = 'Hallo, das ist ein Verbindungstest. Antworte bitte nur mit "Verbindung erfolgreich".';
            $response = $service->generateAnswer($testPrompt, []);

            $modelsInfo = '';
            // Try fetching models if OpenAI
            if ($service instanceof OpenAiCompatibleService) {
                $models = $service->getAvailableModels();
                if (!empty($models)) {
                    $modelsInfo = '<br><br><strong>Gefundene Modelle:</strong><br>' . implode(', ', $models);
                }
            }

            $this->sendJsonClean([
                'success' => true,
                'message' => 'Test erfolgreich! Antwort: ' . mb_substr($response, 0, 50) . '...' . $modelsInfo
            ]);

        } catch (\Exception $e) {
            $this->sendJsonClean([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
