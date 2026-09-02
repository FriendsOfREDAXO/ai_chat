<?php

namespace FriendsOfRedaxo\AiChat\Api\RoutePackage;

use FriendsOfRedaxo\Api\Auth\BearerAuth;
use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\Api\RoutePackage;
use FriendsOfRedaxo\AiChat\Service\ChatQueryService;
use rex;
use rex_addon;
use rex_backend_login;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;

class AiChat extends RoutePackage
{
    public function loadRoutes(): void
    {
        RouteCollection::registerRoute(
            'ai_chat/capabilities',
            new Route(
                'ai_chat/capabilities',
                [
                    '_controller' => self::class . '::handleCapabilities',
                ],
                [],
                [],
                '',
                [],
                ['GET'],
            ),
            'Get ai_chat API capabilities and public endpoint status',
            null,
            new BearerAuth(),
            ['ai_chat'],
        );

        RouteCollection::registerRoute(
            'ai_chat/chat/query',
            new Route(
                'ai_chat/chat/query',
                [
                    '_controller' => self::class . '::handleProtectedQuery',
                ],
                [],
                [],
                '',
                [],
                ['POST'],
            ),
            'Query ai_chat via authenticated API access',
            null,
            new BearerAuth(),
            ['ai_chat'],
        );

        RouteCollection::registerRoute(
            'ai_chat/public/chat/query',
            new Route(
                'ai_chat/public/chat/query',
                [
                    '_controller' => self::class . '::handlePublicQuery',
                ],
                [],
                [],
                '',
                [],
                ['POST'],
            ),
            'Query ai_chat via public API access (requires setting)',
            null,
            null,
            ['ai_chat'],
        );
    }

    /**
     * @api
     * @param array<string, mixed> $route
     */
    public static function handleCapabilities(mixed $parameter, array $route = []): Response
    {
        $addon = rex_addon::get('ai_chat');

        return self::jsonResponse([
            'addon' => 'ai_chat',
            'version' => $addon->getVersion(),
            'public_api_enabled' => (bool) $addon->getConfig('api_public_enabled', false),
            'scopes' => ['frontend', 'developer'],
            'public_endpoint' => 'ai_chat/public/chat/query',
            'protected_endpoint' => 'ai_chat/chat/query',
        ]);
    }

    /**
     * @api
     * @param array<string, mixed> $route
     */
    public static function handleProtectedQuery(mixed $parameter, array $route = []): Response
    {
        return self::handleQuery(false, false);
    }

    /**
     * @api
     * @param array<string, mixed> $route
     */
    public static function handlePublicQuery(mixed $parameter, array $route = []): Response
    {
        return self::handleQuery(true, false);
    }

    private static function handleQuery(bool $requirePublicFlag, bool $enforceOrigin): Response
    {
        $addon = rex_addon::get('ai_chat');

        if ($requirePublicFlag && !(bool) $addon->getConfig('api_public_enabled', false)) {
            return self::jsonResponse(['error' => 'Public API access is disabled'], 403);
        }

        /** @var mixed $decoded */
        $decoded = json_decode(rex::getRequest()->getContent(), true);
        if (!is_array($decoded)) {
            return self::jsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        $requestedScope = strtolower((string) ($decoded['scope'] ?? 'frontend'));
        if ($requestedScope === 'developer' && (!rex_backend_login::hasSession() || null === rex_backend_login::createUser())) {
            return self::jsonResponse(['error' => 'Developer chat is only available for authenticated backend users.'], 403);
        }

        try {
            $service = new ChatQueryService();
            $result = $service->process($decoded, $enforceOrigin);

            return self::jsonResponse($result);
        } catch (\Exception $e) {
            $friendlyError = (string) $addon->getConfig('error_message');
            if ($friendlyError === '') {
                $friendlyError = 'Entschuldigung, ich bin gerade überlastet. Bitte versuchen Sie es später noch einmal.';
            }

            $message = $e->getMessage();
            if ($message === 'Zu viele Anfragen. Bitte warten Sie einen Moment.') {
                $friendlyError = '🛑 **Limit:** ' . $message;
            } elseif (str_contains($message, '429')) {
                $friendlyError = 'Entschuldigung, aktuell ist die Anfrage nicht verfügbar. Bitte versuchen Sie es in wenigen Minuten erneut.';
            } elseif ($message === 'Forbidden') {
                return self::jsonResponse(['error' => 'Forbidden'], 403);
            }

            return self::jsonResponse(['answer' => self::parseMarkdown($friendlyError)]);
        }
    }

    private static function parseMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $markdown);

        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true);
        $html = (string) $parsedown->text($markdown);

        return str_replace('<a href="', '<a target="_blank" rel="noopener noreferrer" href="', $html);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function jsonResponse(array $payload, int $status = 200): Response
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response = new Response($json === false ? '{}' : $json, $status);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }
}
