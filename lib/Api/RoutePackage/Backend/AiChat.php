<?php

namespace FriendsOfRedaxo\AiChat\Api\RoutePackage\Backend;

use FriendsOfRedaxo\Api\Auth\BackendUser;
use FriendsOfRedaxo\Api\RouteCollection;
use FriendsOfRedaxo\AiChat\Api\RoutePackage\AiChat as ApiAiChatRoutePackage;
use Symfony\Component\Routing\Route;

use function strlen;

class AiChat extends ApiAiChatRoutePackage
{
    public function loadRoutes(): void
    {
        $routes = RouteCollection::getRoutes();

        foreach ($routes as $route) {
            if (!is_array($route) || !isset($route['scope'], $route['route'], $route['description'], $route['responses'])) {
                continue;
            }

            if (!is_string($route['scope']) || 'ai_chat/' !== substr($route['scope'], 0, strlen('ai_chat/'))) {
                continue;
            }

            if (!$route['route'] instanceof Route) {
                continue;
            }

            $scope = 'backend/' . $route['scope'];
            $newRoute = clone $route['route'];
            $newPath = '/' . ltrim($newRoute->getPath(), '/');
            $newRoute->setPath('/backend' . $newPath);

            RouteCollection::registerRoute(
                $scope,
                $newRoute,
                is_string($route['description']) ? $route['description'] : '',
                is_array($route['responses']) ? $route['responses'] : null,
                new BackendUser(),
                ['backend'],
            );
        }
    }
}
