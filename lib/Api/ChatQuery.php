<?php

namespace FriendsOfRedaxo\AiChat\Api;

use FriendsOfRedaxo\AiChat\Service\ChatQueryService;
use rex;
use rex_addon;
use rex_api_function;
use rex_logger;
use rex_response;

class ChatQuery extends rex_api_function
{
    use JsonResponseTrait;

    protected $published = true;

    public function execute()
    {
        rex_response::setStatus(rex_response::HTTP_OK);
        rex_response::cleanOutputBuffers();

        // Ohne dies bleibt die PHP-Session fuer die GESAMTE Dauer dieser Anfrage gesperrt (PHPs
        // Standard-Session-Handler serialisiert nebeneinanderlaufende Requests derselben Session
        // ueber eine Datei-Sperre) - bei einem eingeloggten Backend-Nutzer (z.B. beim Testen im
        // Frontend, waehrend die Backend-Admin-Leiste eigene Session-gebundene AJAX-Aufrufe
        // macht) fuehrte das zu Wartezeiten von mehreren Sekunden fuer eine eigentlich in
        // Millisekunden fertige Suche/Chat-Anfrage, obwohl ChatQueryService::process() selbst
        // longst durchgelaufen war. sendStreamResponse() macht das fuer den Streaming-Zweig
        // bereits - hier fuer den (haeufigeren) nicht-streamenden Zweig nachgezogen. Sicher, da
        // process() nur LESEND auf $_SESSION-abgeleiteten Login-Status zugreift (rex::getUser()),
        // was nach session_write_close() weiterhin funktioniert - nur Schreibzugriffe waeren
        // betroffen, die hier nicht gebraucht werden.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($decoded)) {
                ob_start();
                $this->sendJsonClean(['answer' => '']);
            }

            if ($this->clientWantsStream($decoded) && $this->isStreamingEnabled()) {
                // sendStreamResponse() räumt selbst alle Output-Buffer, bevor die
                // SSE-Header gesetzt werden - kein zusätzliches ob_start() hier,
                // das würde den Stream nur unnötig zwischenpuffern.
                $this->sendStreamResponse($decoded);
                exit;
            }

            ob_start();
            $service = new ChatQueryService();
            $result = $service->process($decoded, true);

            $this->sendJsonClean($result);

        } catch (\Exception $e) {
            $message = $e->getMessage();
            $isExpected = $message === 'Forbidden'
                || $message === 'Zu viele Anfragen. Bitte warten Sie einen Moment.'
                || strpos($message, '429') !== false;

            if (!$isExpected) {
                rex_logger::logError(E_USER_WARNING, 'AiChat Error: ' . $message, __FILE__, __LINE__);
            }

            if ($message === 'Forbidden') {
                rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
                $this->sendJsonClean(['error' => 'Forbidden']);
            }

            if ($message === 'Zu viele Anfragen. Bitte warten Sie einen Moment.') {
                $this->sendJsonClean(['answer' => '🛑 **Limit:** ' . $message]);
            }

            if (strpos($message, '429') !== false) {
                $this->sendJsonClean(['answer' => 'Entschuldigung, aktuell ist die Anfrage nicht verfügbar. Bitte versuchen Sie es in wenigen Minuten erneut.']);
            }

            $friendlyError = (string) rex_addon::get('ai_chat')->getConfig('error_message');
            if ($friendlyError === '') {
                $friendlyError = 'Entschuldigung, ich bin gerade überlastet. Bitte versuchen Sie es später noch einmal.';
            }

            if (rex::getUser() && rex::getUser()->isAdmin()) {
                $friendlyError .= "\n\n**Admin Debug Info:**\n_" . $message . '_';
            }

            $parsedown = new \Parsedown();
            $parsedown->setSafeMode(true);
            $answerHtml = $parsedown->text($friendlyError);
            $answerHtml = str_replace('<a href="', '<a target="_blank" rel="noopener noreferrer" href="', $answerHtml);
            $this->sendJsonClean(['answer' => $answerHtml]);
        }
    }

    private function isStreamingEnabled(): bool
    {
        $raw = rex_addon::get('ai_chat')->getConfig('stream_enabled', false);

        return $this->normalizeCheckboxValue($raw);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function clientWantsStream(array $decoded): bool
    {
        $acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($acceptHeader, 'text/event-stream')) {
            return true;
        }

        $streamHeader = strtolower((string) ($_SERVER['HTTP_X_AICHAT_STREAM'] ?? ''));
        if ($streamHeader === '1' || $streamHeader === 'true') {
            return true;
        }

        return ($decoded['stream'] ?? null) === true;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function sendStreamResponse(array $decoded): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Disable every layer that could buffer the response before it reaches the
        // browser (PHP output buffering, gzip, reverse proxy buffering).
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', '1');
        set_time_limit(0);

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $flushFrame = static function (string $eventName, array $payload): void {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            echo "event: {$eventName}\ndata: {$json}\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        };

        $flushFrame('start', ['status' => 'streaming']);

        try {
            $service = new ChatQueryService();
            $result = $service->process($decoded, true, static function (string $chunk) use ($flushFrame): void {
                if ($chunk === '') {
                    return;
                }
                $flushFrame('chunk', ['text' => $chunk]);
            });

            $flushFrame('complete', [
                'answer' => $result['answer'] ?? '',
                'answer_text' => $result['answer_text'] ?? '',
                'follow_up_questions' => $result['follow_up_questions'] ?? [],
            ]);
        } catch (\Throwable $e) {
            rex_logger::logError(E_USER_WARNING, 'AiChat Stream Error: ' . $e->getMessage(), __FILE__, __LINE__);
            $friendlyError = (string) rex_addon::get('ai_chat')->getConfig('error_message');
            if ($friendlyError === '') {
                $friendlyError = 'Entschuldigung, ich bin gerade überlastet. Bitte versuchen Sie es später noch einmal.';
            }
            $flushFrame('error', ['message' => $friendlyError]);
        }
    }

    private function normalizeCheckboxValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            if ($normalized === '' || $normalized === '0') {
                return false;
            }

            if ($normalized === '1' || $normalized === '|1|' || strtolower($normalized) === 'true') {
                return true;
            }
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }
}
