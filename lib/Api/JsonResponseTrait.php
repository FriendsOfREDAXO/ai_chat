<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Api;

use rex_response;

/**
 * Gemeinsame Absicherung für alle rex_api_function-Endpunkte dieses Addons: eine
 * einzelne PHP-Warning/Notice, die REDAXOs Error-Handler im Debug-Modus direkt in
 * die Ausgabe schreibt, würde sonst VOR dem JSON (bzw. vor den SSE-Frames bei
 * Streaming-Antworten) im Response-Body landen und die Antwort für jeden
 * JSON-Parser/EventSource-Client ungültig machen - sichtbar am Client oft nur als
 * kryptischer Netzwerk-/Zugriffsfehler statt eines klaren Parse-Fehlers.
 *
 * Nutzung: `ob_start()` am Anfang von execute() (fängt Stray-Output ab), dann
 * `sendJsonClean()` statt `rex_response::sendJson()` direkt vor jedem Response.
 */
trait JsonResponseTrait
{
    /**
     * @param array<string, mixed> $data
     */
    private function sendJsonClean(array $data): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        rex_response::sendJson($data);
        exit;
    }
}
