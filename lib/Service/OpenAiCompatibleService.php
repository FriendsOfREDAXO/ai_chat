<?php

namespace FriendsOfRedaxo\AiChat\Service;

use rex_addon;

class OpenAiCompatibleService implements AiServiceInterface
{
    // Reasoning models (o1/o3/o4, gpt-5.x, ...) reject several classic Chat Completions
    // parameters (max_tokens, non-default temperature, ...) with an "unsupported_parameter"
    // error naming the offending field. Rather than hardcode a model list that will keep
    // going stale as OpenAI ships new families, we adapt the payload from that error and
    // retry a bounded number of times.
    private const MAX_UNSUPPORTED_PARAM_RETRIES = 3;

    // Keeps individual embedding requests small/fast rather than relying on
    // provider-specific input-count or token ceilings.
    private const EMBEDDING_BATCH_SIZE = 100;

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private string $embeddingModel;

    /**
     * @param array<string, string> $overrides Optionale Config-Overrides (siehe AiServiceFactory::create()).
     */
    public function __construct(array $overrides = [])
    {
        $addon = rex_addon::get('ai_chat');
        $this->apiKey = trim((string) ($overrides['openai_api_key'] ?? $addon->getConfig('openai_api_key')));
        $this->baseUrl = trim((string) ($overrides['openai_base_url'] ?? $addon->getConfig('openai_base_url')));

        // Use OpenAI default if empty
        if (empty($this->baseUrl)) {
            $this->baseUrl = 'https://api.openai.com/v1';
        }

        $this->model = trim((string) ($overrides['openai_model'] ?? $addon->getConfig('openai_model'))); // e.g. 'llama3' or 'gpt-4o'
        $this->embeddingModel = trim((string) ($overrides['openai_embedding_model'] ?? $addon->getConfig('openai_embedding_model', 'all-MiniLM-L6-v2'))); // Default for local openwebui/ollama often requires specific embedding model
        
        // Handle openai default embedding model if left as default while on main openai
        if ($this->baseUrl === 'https://api.openai.com/v1' && ($this->embeddingModel === 'all-MiniLM-L6-v2' || empty($this->embeddingModel))) {
            $this->embeddingModel = 'text-embedding-3-small';
        }
    }

    /**
     * @return list<float>
     */
    public function getEmbedding(string $text): array
    {
        $embeddings = $this->getEmbeddings([$text]);

        if (!isset($embeddings[0])) {
            throw new \Exception('Failed to get embedding.');
        }

        return $embeddings[0];
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function getEmbeddings(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($texts, self::EMBEDDING_BATCH_SIZE) as $batch) {
            $data = [
                'model' => $this->embeddingModel,
                'input' => $batch,
            ];

            $response = $this->executeWithUrlFallback('embeddings', $data, 'POST', 'batch embedding');

            if (!isset($response['data']) || !is_array($response['data'])) {
                throw new \Exception('Failed to get embeddings: ' . json_encode($response));
            }

            // The API ties each embedding back to its position in the request via
            // "index" rather than guaranteeing response order, so sort defensively.
            $items = $response['data'];
            usort($items, static fn($a, $b): int => (int) ($a['index'] ?? 0) <=> (int) ($b['index'] ?? 0));

            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['embedding']) || !is_array($item['embedding'])) {
                    throw new \Exception('Failed to get embeddings: ' . json_encode($response));
                }

                $results[] = array_values(array_map('floatval', $item['embedding']));
            }
        }

        return $results;
    }

    /**
        * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     * @param array<string, mixed>|null $personalization
     */
    public function generateAnswer(string $prompt, array $context = [], string $scope = 'frontend', ?array $personalization = null, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): string
    {
        $data = $this->buildChatCompletionPayload($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);

        $attempts = 0;
        while (true) {
            try {
                $response = $this->executeWithUrlFallback('chat/completions', $data, 'POST', 'chat answer');
                break;
            } catch (\Throwable $e) {
                $param = $this->extractUnsupportedParameter($e);
                if ($param === null || ++$attempts > self::MAX_UNSUPPORTED_PARAM_RETRIES) {
                    throw $e;
                }
                $data = $this->adaptPayloadForUnsupportedParameter($data, $param);
            }
        }

        if (isset($response['choices'][0]['message']['content'])) {
            return $response['choices'][0]['message']['content'];
        }

        throw new \Exception('Failed to generate answer: ' . json_encode($response));
    }

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     * @param array<string, mixed>|null $personalization
     * @param callable(string): void $onChunk
     */
    public function generateAnswerStream(string $prompt, array $context = [], string $scope = 'frontend', ?array $personalization = null, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?callable $onChunk = null, ?string $answerLanguageOverride = null): string
    {
        $data = $this->buildChatCompletionPayload($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
        $data['stream'] = true;

        $attempts = 0;
        while (true) {
            try {
                return $this->attemptStreamCandidates($data, $onChunk);
            } catch (\Throwable $e) {
                $param = $this->extractUnsupportedParameter($e);
                if ($param === null || ++$attempts > self::MAX_UNSUPPORTED_PARAM_RETRIES) {
                    throw $e;
                }
                $data = $this->adaptPayloadForUnsupportedParameter($data, $param);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(string): void|null $onChunk
     */
    private function attemptStreamCandidates(array $data, ?callable $onChunk): string
    {
        $candidates = $this->buildEndpointCandidates('chat/completions');
        if ($candidates === []) {
            throw new \Exception('Empty OpenAI-compatible API URL configured.');
        }

        $lastException = null;
        foreach ($candidates as $index => $candidate) {
            try {
                return $this->streamChatCompletionsFromUrl($candidate, $data, $onChunk);
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($index === 0 && isset($candidates[$index + 1])) {
                    continue;
                }
                throw $e;
            }
        }

        throw new \Exception('Failed to stream answer. Last error: ' . ($lastException ? $lastException->getMessage() : 'unknown'));
    }

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     * @param array<string, mixed>|null $personalization
     * @return array<string, mixed>
     */
    private function buildChatCompletionPayload(string $prompt, array $context, string $scope, ?array $personalization, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): array
    {
        // Construct context with clear delimiters
        $contextText = "Hier ist der relevante Kontext für die Beantwortung der Frage:\n";
        $contextText .= "--------------------------------------------------\n";
        foreach ($context as $i => $item) {
            $bracket = PromptBuilder::formatSourceLabelBracket($item);
            $header = 'DOKUMENT ' . ($i + 1) . ('' !== $bracket ? ' ' . $bracket : '') . ':';
            $contextText .= $header . "\n" . $item['content'] . "\n";
            $contextText .= "--------------------------------------------------\n";
        }

        $systemPrompt = "Wichtiger Zeit-Kontext: " . SystemToolService::getDateTimeContext() . "\n\n";

        if ($scope === 'developer') {
            $systemPrompt .= "Du bist ein erfahrener REDAXO CMS Entwickler und Experte. Nutze die folgende Dokumentation und Code-Beispiele um Fragen zur Entwicklung, API und Addons zu beantworten. Sei technisch präzise.";
            
            // Add system context for developer
            $systemPrompt .= "\n\nAktuelles System-Kontext:\n" .
                             "- REDAXO Version: " . \rex::getVersion() . "\n" .
                             "- Installierte Addons: " . SystemToolService::getAddonListContext() . "\n";

            // Add system tools to developer prompt
            $systemPrompt .= "\n\nHinweis: Du hast Zugriff auf bestimmte System-Aktionen. Wenn du eine Aktion ausführen möchtest, schreibe am Ende deiner Antwort oder an der passenden Stelle einen Befehl im Format: `[[ACTION:ACTION_NAME]]`. " .
                             "Mögliche Aktionen: \n" .
                             "- [[ACTION:CLEAR_CACHE]] (Löscht den REDAXO Cache)\n" .
                             "- [[ACTION:SYSTEM_INFO]] (Gibt REDAXO-Version, PHP-Version und Speicher aus)\n" .
                             "- [[ACTION:LIST_ADDONS]] (Listet alle installierten Addons auf)\n" .
                             "- [[ACTION:GET_LOGS]] (Zeigt die letzten Einträge aus dem System-Log)\n" .
                             "- [[ACTION:REINDEX_CHAT]] (Startet die Neu-Indizierung des Chats)\n";
        } else {
             // Frontend scope. Profil-eigener Prompt geht vor der globalen Einstellung.
            $addon = rex_addon::get('ai_chat');
            $customPrompt = $systemPromptOverride ?? $addon->getConfig('frontend_prompt');
            if (!empty($customPrompt)) {
                $systemPrompt = $customPrompt;
            } else {
                $systemPrompt = "Du bist ein hilfreicher Assistent für diese Website. Nutze den bereitgestellten Kontext um die Frage des Nutzers zu beantworten.";
            }

            $addressingMode = $addressingModeOverride ?? trim((string) $addon->getConfig('frontend_addressing_mode', 'auto'));
            if ($addressingMode === 'formal') {
                $systemPrompt .= "\n- Sprich den Nutzer durchgehend förmlich mit 'Sie' an.";
            } elseif ($addressingMode === 'informal') {
                $systemPrompt .= "\n- Sprich den Nutzer durchgehend mit 'Du' an.";
            } elseif ($addressingMode === 'neutral') {
                $systemPrompt .= "\n- Verwende eine neutrale Sprache ohne direkte Anrede mit 'Du' oder 'Sie'.";
            }

            if ($addressingMode === 'auto' && $personalization) {
                if (($personalization['mode'] ?? 'formal') === 'informal') {
                    $systemPrompt .= "\n- Sprich den Nutzer mit 'Du' an (Duzen).";
                    if (!empty($personalization['name'])) {
                        $systemPrompt .= "\n- Der Name des Nutzers ist: " . $personalization['name'];
                    }
                } else {
                    $systemPrompt .= "\n- Sprich den Nutzer förmlich mit 'Sie' an (Siezen).";
                }
            }

            $systemPrompt .= "\n- Stelle keine Rückfrage zur Anrede (Du/Sie), frage nicht nach dem Namen und frage nicht 'Wer bist du?', außer der Nutzer fragt ausdrücklich danach.";
            $systemPrompt .= "\n- Starte ohne Smalltalk und ohne reine Begrüßungsfloskel, sondern antworte direkt inhaltlich auf die Frage.";
            $systemPrompt .= "\n- Ein Kontext-Abschnitt kann mit \"[Bereich: Name]\" markiert sein - das ist der Themenbereich, aus dem der Abschnitt stammt (z.B. \"Allgemein\" vs. \"News\"). Ist ein Bereich zusätzlich mit \"(aktuell)\" gekennzeichnet, enthält er die neuesten/zeitkritischen Inhalte - bevorzuge ihn, wenn der Nutzer nach dem aktuellen/neuesten Stand fragt.";

            $additionalContext = $addon->getConfig('frontend_additional_context');
            if (!empty($additionalContext)) {
                $systemPrompt .= "\n\nZusätzliche Informationen:\n" . $additionalContext;
            }
        }

        $instruction = "\n\nANWEISUNG:\n1. Beantworte die Frage ausschließlich basierend auf dem oben genannten Kontext.\n2. Wenn die Information nicht im Kontext enthalten ist, sage höflich dass du dazu keine Informationen hast (frage ggf. nach weiteren Details).\n3. " . PromptBuilder::answerLanguageInstruction($answerLanguageOverride);
        
        $fullSystemPrompt = $systemPrompt . "\n\n" . $contextText . $instruction;

        $addon       = rex_addon::get('ai_chat');
        $temperature = (float) $addon->getConfig('ai_temperature', 0.7);
        $maxTokens   = (int)   $addon->getConfig('ai_max_tokens', 2048);

        return [
            'model'       => $this->model,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => $fullSystemPrompt,
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];
    }

    /**
     * Reads an OpenAI-compatible SSE stream via cURL and invokes $onChunk for every
     * incremental delta as it arrives on the wire (true token streaming).
     *
     * @param array<string, mixed> $data
     * @param callable(string): void|null $onChunk
     */
    private function streamChatCompletionsFromUrl(string $url, array $data, ?callable $onChunk = null): string
    {
        if ('' === $url) {
            throw new \Exception('cURL init failed: empty URL.');
        }

        $timeout = (int) rex_addon::get('ai_chat')->getConfig('openai_timeout', 120);
        if ($timeout <= 0) {
            $timeout = 120;
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
        ];
        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \Exception('cURL init failed.');
        }

        $buffer = '';
        $full = '';
        $emittedAnyData = false;

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => ! $this->shouldDisableTlsVerification($url),
            CURLOPT_SSL_VERIFYHOST => $this->shouldDisableTlsVerification($url) ? 0 : 2,
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $curl, string $chunk) use (&$buffer, &$full, &$emittedAnyData, $onChunk): int {
                $buffer .= $chunk;
                while (true) {
                    $pos = strpos($buffer, "\n\n");
                    if ($pos === false) {
                        break;
                    }
                    $frame = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $lines = preg_split('/\r?\n/', $frame) ?: [];
                    $decodedData = '';
                    foreach ($lines as $line) {
                        if (preg_match('/^data:\s*(.*)$/', trim($line), $matches)) {
                            $decodedData = $matches[1];
                        }
                    }
                    if ($decodedData === '' || $decodedData === '[DONE]') {
                        continue;
                    }
                    $payload = json_decode($decodedData, true);
                    if (!is_array($payload)) {
                        continue;
                    }
                    $delta = $payload['choices'][0]['delta']['content'] ?? '';
                    if (!is_string($delta) || $delta === '') {
                        $messageContent = $payload['choices'][0]['message']['content'] ?? '';
                        $delta = is_string($messageContent) ? $messageContent : '';
                    }
                    if ($delta === '') {
                        continue;
                    }
                    $full .= $delta;
                    $emittedAnyData = true;
                    if (is_callable($onChunk)) {
                        $onChunk($delta);
                    }
                }
                return strlen($chunk);
            },
        ]);

        $json = json_encode($data);
        if ($json === false) {
            throw new \Exception('JSON encoding failed: ' . json_last_error_msg());
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL Error: ' . $error);
        }
        if ($response === false) {
            throw new \Exception('cURL returned no response.');
        }
        if ($httpCode !== 200) {
            throw new \Exception("API Error HTTP $httpCode: " . $response);
        }

        if (!$emittedAnyData) {
            // Provider ignored stream:true (or a proxy stripped SSE frames) - fall back
            // to a normal request so the user still gets an answer.
            $nonStreamData = $data;
            unset($nonStreamData['stream']);
            $fallback = $this->makeRequest($url, $nonStreamData, 'POST');
            $full = (string) ($fallback['choices'][0]['message']['content'] ?? '');
            if ($full !== '' && is_callable($onChunk)) {
                $onChunk($full);
            }
        }

        return $full;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function makeRequest(string $url, array $data = [], string $method = 'POST'): array
    {
        if ($url === '') {
            throw new \Exception('Empty request URL.');
        }

        $headers = [
            'Content-Type: application/json'
        ];

        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $timeout = (int) rex_addon::get('ai_chat')->getConfig('openai_timeout', 120);
        if ($timeout <= 0) {
            $timeout = 120;
        }
        $disableTlsVerification = $this->shouldDisableTlsVerification($url);

        $ch = curl_init();
        if ($ch === false) {
            throw new \Exception('cURL init failed.');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(30, $timeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$disableTlsVerification);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $disableTlsVerification ? 0 : 2);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $json = json_encode($data);
            if ($json === false) {
                throw new \Exception("JSON encoding failed: " . json_last_error_msg());
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);
        
        if ($error) {
            throw new \Exception("cURL Error: " . $error);
        }

        if ($response === false) {
            throw new \Exception('cURL returned no response.');
        }

        if ($httpCode !== 200) {
             throw new \Exception("API Error HTTP $httpCode: " . $response);
        }
        
        /** @var mixed $decoded */
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function buildEndpointCandidates(string $endpoint): array
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            return [];
        }

        $normalizedEndpoint = ltrim($endpoint, '/');
        $candidates = [];

        if (preg_match('#/api$#i', $base)) {
            $candidates[] = $base . '/' . $normalizedEndpoint;
            $candidates[] = preg_replace('#/api$#i', '/api/v1', $base) . '/' . $normalizedEndpoint;
            return array_values(array_unique($candidates));
        }

        if (preg_match('#/api/v1$#i', $base)) {
            $candidates[] = $base . '/' . $normalizedEndpoint;
            return array_values(array_unique($candidates));
        }

        if (preg_match('#/v1$#i', $base)) {
            $candidates[] = $base . '/' . $normalizedEndpoint;
            return array_values(array_unique($candidates));
        }

        $candidates[] = $base . '/v1/' . $normalizedEndpoint;
        $candidates[] = $base . '/' . $normalizedEndpoint;

        return array_values(array_unique($candidates));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function executeWithUrlFallback(string $endpoint, array $data, string $method, string $label): array
    {
        $candidates = $this->buildEndpointCandidates($endpoint);
        if ($candidates === []) {
            throw new \Exception('Empty OpenAI-compatible API URL configured.');
        }

        $lastException = null;

        foreach ($candidates as $index => $candidate) {
            try {
                return $this->makeRequest($candidate, $data, $method);
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($index === 0 && isset($candidates[$index + 1])) {
                    \rex_logger::logError(
                        E_USER_WARNING,
                        'AiChat: Configured OpenAI-compatible API URL "' . $this->baseUrl . '" does not respond correctly for ' . $label . '. Retrying with fallback URL "' . $candidates[$index + 1] . '". Error: ' . $e->getMessage(),
                        __FILE__,
                        __LINE__
                    );
                }
            }
        }

        // $candidates ist nicht-leer (siehe Guard oben) und die Schleife kehrt bei Erfolg
        // sofort per return zurueck - wird dieser Punkt erreicht, hat jede Iteration eine
        // Exception geworfen, $lastException ist also garantiert gesetzt.
        $configuredUrl = $this->baseUrl !== '' ? $this->baseUrl : 'empty';
        throw new \Exception('Configured OpenAI-compatible API URL "' . $configuredUrl . '" appears invalid for ' . $label . '. Tried: ' . implode(', ', $candidates) . '. Last error: ' . $lastException->getMessage());
    }

    private function shouldDisableTlsVerification(string $url): bool
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return false;
        }

        $host = strtolower($host);
        $localhostHosts = ['localhost', '127.0.0.1', '::1', 'host.docker.internal'];
        if (in_array($host, $localhostHosts, true)) {
            return true;
        }

        // Optional for local dev domains like myapp.local in debug mode.
        return \rex::isDebugMode() && str_ends_with($host, '.local');
    }

    /**
     * Extracts the offending field from an OpenAI-style "unsupported_parameter" error,
     * e.g. {"error":{"message":"...","code":"unsupported_parameter","param":"max_tokens"}}.
     * makeRequest() throws with the raw response body appended after "API Error HTTP xxx: ".
     */
    private function extractUnsupportedParameter(\Throwable $e): ?string
    {
        $message = $e->getMessage();
        $jsonStart = strpos($message, '{');
        if ($jsonStart === false) {
            return null;
        }

        $decoded = json_decode(substr($message, $jsonStart), true);
        if (!is_array($decoded) || ($decoded['error']['code'] ?? null) !== 'unsupported_parameter') {
            return null;
        }

        $param = $decoded['error']['param'] ?? null;
        return is_string($param) && $param !== '' ? $param : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function adaptPayloadForUnsupportedParameter(array $data, string $param): array
    {
        // max_tokens -> max_completion_tokens carries the same semantics for reasoning
        // models, so keep the configured limit instead of just dropping it.
        if ($param === 'max_tokens' && array_key_exists('max_tokens', $data)) {
            $data['max_completion_tokens'] = $data['max_tokens'];
            unset($data['max_tokens']);
            return $data;
        }

        // Other rejected params (temperature, top_p, presence_penalty, ...) only accept
        // their model default, so the safest fix is to omit them entirely.
        unset($data[$param]);
        return $data;
    }

    /**
     * @return list<string>
     */
    public function getAvailableModels(): array
    {
        if (empty($this->baseUrl)) {
            return [];
        }

        $base = rtrim($this->baseUrl, '/');

        if (str_ends_with($base, '/chat/completions')) {
            $base = str_replace('/chat/completions', '', $base);
        }

        $tryUrls = [];
        if (preg_match('#/api$#i', $base)) {
            $tryUrls[] = $base . '/models';
            $tryUrls[] = preg_replace('#/api$#i', '/api/v1', $base) . '/models';
        } elseif (preg_match('#/api/v1$#i', $base)) {
            $tryUrls[] = $base . '/models';
        } else {
            $tryUrls = [
                $base . '/v1/models',
                $base . '/api/models',
                $base . '/models',
            ];
        }

        $lastException = null;
        foreach ($tryUrls as $tryUrl) {
            try {
                $response = $this->makeRequest($tryUrl, [], 'GET');

                if (isset($response['data']) && is_array($response['data'])) {
                    $models = [];
                    foreach ($response['data'] as $m) {
                        if (is_array($m) && isset($m['id']) && is_string($m['id']) && $m['id'] !== '') {
                            $models[] = $m['id'];
                        }
                    }
                    return $models;
                }

                if (isset($response['models']) && is_array($response['models'])) {
                    $models = [];
                    foreach ($response['models'] as $m) {
                        if (is_array($m) && isset($m['name']) && is_string($m['name']) && $m['name'] !== '') {
                            $models[] = $m['name'];
                        }
                    }
                    return $models;
                }
            } catch (\Exception $e) {
                $lastException = $e;
            }
        }

        if ($lastException !== null) {
            \rex_logger::logError(
                E_USER_WARNING,
                'AiChat: API model list check failed for base URL "' . $this->baseUrl . '". The configured URL may be wrong. Last error: ' . $lastException->getMessage(),
                __FILE__,
                __LINE__
            );
        }

        return [];
    }
}
