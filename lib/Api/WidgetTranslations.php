<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Api;

use FriendsOfRedaxo\AiChat\Service\WidgetTranslator;
use rex_api_function;
use rex_request;
use rex_response;

/**
 * Liefert die Widget-Übersetzungen (siehe WidgetTranslator) als JSON an den
 * Browser aus. Öffentlich und ohne Auth (published=true), da es sich um reine
 * UI-Texte handelt, keine sensiblen Daten - Chat/Suche brauchen das auch für
 * anonyme Frontend-Besucher.
 */
class WidgetTranslations extends rex_api_function
{
    use JsonResponseTrait;

    protected $published = true;

    public function execute()
    {
        // Ohne diesen Reset bleibt die Antwort bei HTTP 404 stehen, obwohl der Body korrekt
        // befuellt ist: bei einer rex-api-call-Anfrage auf einer yrewrite-Domain ist der
        // HTTP-Status zu diesem Zeitpunkt bereits auf 404 vorbelegt (siehe ChatQuery::execute()
        // fuer denselben, dort bereits vorhandenen Reset - published=true-Endpunkte, die direkt
        // per fetch() vom Frontend aus aufgerufen werden, brauchen ihn alle).
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::cleanOutputBuffers();
        ob_start();

        $locale = rex_request::request('locale', 'string', 'de');
        $translations = WidgetTranslator::load($locale);

        // Reine UI-Texte, ändern sich selten - moderates Caching entlastet
        // wiederholte Ladevorgänge (jede Chat-/Suche-Instanz auf jeder Seite).
        rex_response::setHeader('Cache-Control', 'public, max-age=3600');

        $this->sendJsonClean($translations);
    }
}
