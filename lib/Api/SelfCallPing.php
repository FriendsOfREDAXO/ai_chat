<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Api;

use rex_api_function;
use rex_response;

/**
 * Winziger, wirkungsloser Echo-Endpunkt fuer den Hintergrund-Selbstaufruf-Test (siehe
 * ChatIndex::handleTestBackgroundSelfCall()) - antwortet sofort, ohne irgendeine echte
 * Aktion auszuloesen. Getrennt von Api\ReindexWorker (dem echten Arbeiter), damit ein Test
 * niemals versehentlich eine echte Indexierung anstoesst, selbst wenn gerade zufaellig ein
 * gueltiger Lauf-Token existiert.
 *
 * `published = true` aus demselben Grund wie bei ReindexWorker: der self-curl/wget-Aufruf
 * laeuft ohne Backend-Session/Cookies, REDAXOs Dispatcher wuerde die Anfrage sonst schon vor
 * execute() mit 401/403 ablehnen. Unproblematisch oeffentlich erreichbar, da die Antwort
 * keinerlei Daten preisgibt und keine Aktion ausloest.
 */
class SelfCallPing extends rex_api_function
{
    protected $published = true;

    public function execute()
    {
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::cleanOutputBuffers();
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        rex_response::sendJson(['ok' => true, 'time' => time()]);
        exit;
    }
}
