<?php

namespace FriendsOfRedaxo\AiChat\Api;

use FriendsOfRedaxo\AiChat\Service\CloudflareService;
use rex;
use rex_addon;
use rex_api_function;
use rex_response;

class CloudflareModels extends rex_api_function
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
            $service = new CloudflareService();
            $models = $service->getAvailableChatModels();

            $selectedModel = trim((string) rex_addon::get('ai_chat')->getConfig('cloudflare_model', ''));

            $this->sendJsonClean([
                'success' => true,
                'models' => $models,
                'selected' => $selectedModel,
                'message' => 'Modelle erfolgreich geladen.',
            ]);
        } catch (\Exception $e) {
            $this->sendJsonClean([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
