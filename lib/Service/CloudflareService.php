<?php

namespace FriendsOfRedaxo\AiChat\Service;

use rex_addon;
use rex_socket;

class CloudflareService implements AiServiceInterface
{
    private const DEFAULT_CHAT_MODEL = '@cf/meta/llama-3.1-8b-instruct';
    private const EMBEDDING_BATCH_SIZE = 100;

    private string $accountId;
    private string $apiToken;
    private ?string $modelOverride;
    private string $baseUrl = 'https://api.cloudflare.com/client/v4/accounts/';

    /**
     * @param array<string, string> $overrides Optionale Config-Overrides (siehe AiServiceFactory::create()).
     */
    public function __construct(array $overrides = [])
    {
        $addon = rex_addon::get('ai_chat');
        $this->accountId = trim((string) ($overrides['cloudflare_account_id'] ?? $addon->getConfig('cloudflare_account_id')));
        $this->apiToken = trim((string) ($overrides['cloudflare_api_token'] ?? $addon->getConfig('cloudflare_api_token')));
        $this->modelOverride = isset($overrides['cloudflare_model']) ? trim($overrides['cloudflare_model']) : null;

        // Basic validation for Account ID (should be 32 chars hex)
        if (!empty($this->accountId) && !preg_match('/^[a-f0-9]{32}$/', $this->accountId)) {
            // If it looks like a token (longer, non-hex), warn the user
            if (preg_match('/[g-zG-Z]/', $this->accountId)) {
                throw new \Exception('Invalid Cloudflare Account ID. It looks like you pasted an API Token into the Account ID field. Account IDs are 32-character hexadecimal strings (0-9, a-f).');
            }
        }
    }

    /**
     * @return list<float>
     */
    public function getEmbedding(string $text): array
    {
        if (empty($this->accountId) || empty($this->apiToken)) {
            throw new \Exception('Cloudflare Account ID or API Token is missing.');
        }

        $embeddings = $this->getEmbeddings([$text]);

        if (!isset($embeddings[0])) {
            throw new \Exception('Failed to get embedding from Cloudflare.');
        }

        return $embeddings[0];
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function getEmbeddings(array $texts): array
    {
        if (empty($this->accountId) || empty($this->apiToken)) {
            throw new \Exception('Cloudflare Account ID or API Token is missing.');
        }

        if ($texts === []) {
            return [];
        }

        // Model: BGE Base (Good general purpose embedding)
        $model = '@cf/baai/bge-base-en-v1.5';
        $url = $this->baseUrl . $this->accountId . '/ai/run/' . $model;

        $results = [];

        foreach (array_chunk($texts, self::EMBEDDING_BATCH_SIZE) as $batch) {
            // Cloudflare Workers AI expects an array of strings and returns embeddings
            // in the same order under result.data.
            $response = $this->makeRequest($url, ['text' => $batch]);

            if (!isset($response['result']['data']) || !is_array($response['result']['data'])) {
                throw new \Exception('Failed to get embeddings from Cloudflare: ' . json_encode($response));
            }

            foreach ($response['result']['data'] as $embedding) {
                $results[] = $embedding;
            }
        }

        return $results;
    }

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     * @param array<string, mixed>|null $personalization
     * @return array<string, mixed>
     */
    private function buildChatPayload(string $prompt, array $context, string $scope, ?array $personalization, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): array
    {
        if (empty($this->accountId) || empty($this->apiToken)) {
            throw new \Exception('Cloudflare Account ID or API Token is missing.');
        }

        // Construct the prompt with context
        $contextText = "";
        foreach ($context as $item) {
            $contextText .= PromptBuilder::formatContextPrefix($item) . $item['content'] . "\n\n";
        }

        $systemInstruction = "Wichtiger Zeit-Kontext: " . SystemToolService::getDateTimeContext() . "\n\n";

        // Profil-eigener Prompt geht vor der globalen Einstellung.
        $addon = rex_addon::get('ai_chat');
        $customPrompt = $systemPromptOverride ?? $addon->getConfig('frontend_prompt');
        if (!empty($customPrompt)) {
            $systemInstruction = $customPrompt;
        } else {
            $systemInstruction = "Du bist ein hilfreicher Assistent für diese Website. Nutze den folgenden Kontext, um die Frage des Nutzers zu beantworten.";
        }

        $addressingMode = $addressingModeOverride ?? 'auto';
        if ($addressingMode === 'formal') {
            $systemInstruction .= "\n- Sprich den Nutzer durchgehend förmlich mit 'Sie' an.";
        } elseif ($addressingMode === 'informal') {
            $systemInstruction .= "\n- Sprich den Nutzer durchgehend mit 'Du' an.";
        } elseif ($addressingMode === 'neutral') {
            $systemInstruction .= "\n- Verwende eine neutrale Sprache ohne direkte Anrede mit 'Du' oder 'Sie'.";
        }

        if ($addressingMode === 'auto' && $personalization) {
             if (($personalization['mode'] ?? 'formal') === 'informal') {
                 $systemInstruction .= "\n- Sprich den Nutzer mit 'Du' an (Duzen).";
                 if (!empty($personalization['name'])) {
                     $systemInstruction .= "\n- Der Name des Nutzers ist: " . $personalization['name'];
                 }
             } else {
                 $systemInstruction .= "\n- Sprich den Nutzer förmlich mit 'Sie' an (Siezen).";
             }
        }

        $systemInstruction .= "\n- Stelle keine Rückfrage zur Anrede (Du/Sie), frage nicht nach dem Namen und frage nicht 'Wer bist du?', außer der Nutzer fragt ausdrücklich danach.";
        $systemInstruction .= "\n- Starte ohne Smalltalk und ohne reine Begrüßungsfloskel, sondern antworte direkt inhaltlich auf die Frage.";
        $systemInstruction .= "\n- Ein Kontext-Abschnitt kann mit \"[Bereich: Name]\" markiert sein - das ist der Themenbereich, aus dem der Abschnitt stammt (z.B. \"Allgemein\" vs. \"News\"). Ist ein Bereich zusätzlich mit \"(aktuell)\" gekennzeichnet, enthält er die neuesten/zeitkritischen Inhalte - bevorzuge ihn, wenn der Nutzer nach dem aktuellen/neuesten Stand fragt.";

        $additionalContext = $addon->getConfig('frontend_additional_context');
        if (!empty($additionalContext)) {
            $systemInstruction .= "\n\nZusätzliche Informationen:\n" . $additionalContext;
        }

        $systemPrompt = $systemInstruction . " Wenn die Antwort nicht im Kontext enthalten ist, sage, dass du es nicht weißt. " . PromptBuilder::answerLanguageInstruction($answerLanguageOverride) . " " . PromptBuilder::markdownFormattingInstruction() . "\n\n" . $contextText;

        return [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 2048
        ];
    }

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     * @param array<string, mixed>|null $personalization
     */
    public function generateAnswer(string $prompt, array $context = [], string $scope = 'frontend', ?array $personalization = null, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): string
    {
        if (empty($this->accountId) || empty($this->apiToken)) {
            throw new \Exception('Cloudflare Account ID or API Token is missing.');
        }

        $data = $this->buildChatPayload($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);

        $lastModelError = '';
        foreach ($this->getChatModelCandidates() as $model) {
            $url = $this->baseUrl . $this->accountId . '/ai/run/' . $model;

            try {
                $response = $this->makeRequest($url, $data);

                if (isset($response['result']['response'])) {
                    return (string) $response['result']['response'];
                }

                throw new \Exception('Failed to generate answer from Cloudflare: ' . json_encode($response));
            } catch (\Exception $e) {
                $lastModelError = $e->getMessage();

                if ($this->isModelDeprecatedError($lastModelError)) {
                    continue;
                }

                throw $e;
            }
        }

        throw new \Exception('Cloudflare Modell ist veraltet oder nicht verfügbar. Bitte in den Einstellungen ein aktuelles Modell setzen. Letzter Fehler: ' . $lastModelError);
    }

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     * @param array<string, mixed>|null $personalization
     * @param callable(string): void $onChunk
     */
    public function generateAnswerStream(string $prompt, array $context = [], string $scope = 'frontend', ?array $personalization = null, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?callable $onChunk = null, ?string $answerLanguageOverride = null): string
    {
        if (empty($this->accountId) || empty($this->apiToken)) {
            throw new \Exception('Cloudflare Account ID or API Token is missing.');
        }

        $data = $this->buildChatPayload($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
        $data['stream'] = true;

        $lastModelError = '';
        foreach ($this->getChatModelCandidates() as $model) {
            $url = $this->baseUrl . $this->accountId . '/ai/run/' . $model;

            try {
                return $this->streamRunFromUrl($url, $data, $onChunk);
            } catch (\Exception $e) {
                $lastModelError = $e->getMessage();

                if ($this->isModelDeprecatedError($lastModelError)) {
                    continue;
                }

                throw $e;
            }
        }

        throw new \Exception('Cloudflare Modell ist veraltet oder nicht verfügbar. Bitte in den Einstellungen ein aktuelles Modell setzen. Letzter Fehler: ' . $lastModelError);
    }

    /**
     * Reads Cloudflare Workers AI's SSE stream via cURL and invokes $onChunk for every
     * incremental text delta as it arrives on the wire (true token streaming).
     *
     * @param array<string, mixed> $data
     * @param callable(string): void|null $onChunk
     */
    private function streamRunFromUrl(string $url, array $data, ?callable $onChunk = null): string
    {
        if ('' === $url) {
            throw new \Exception('cURL init failed: empty URL.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Authorization: Bearer ' . $this->apiToken,
        ];

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
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
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
                    $delta = $payload['response'] ?? '';
                    if (!is_string($delta) || $delta === '') {
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
            $nonStreamData = $data;
            unset($nonStreamData['stream']);
            $fallback = $this->makeRequest($url, $nonStreamData);
            $full = (string) ($fallback['result']['response'] ?? '');
            if ($full !== '' && is_callable($onChunk)) {
                $onChunk($full);
            }
        }

        return $full;
    }

    /**
     * @return list<string>
     */
    private function getChatModelCandidates(): array
    {
        $configuredModel = $this->modelOverride ?? trim((string) rex_addon::get('ai_chat')->getConfig('cloudflare_model', self::DEFAULT_CHAT_MODEL));
        if ($configuredModel === '') {
            $configuredModel = self::DEFAULT_CHAT_MODEL;
        }

        $candidates = [
            $configuredModel,
            self::DEFAULT_CHAT_MODEL,
            '@cf/meta/llama-3.1-70b-instruct',
        ];

        /** @var list<string> $filtered */
        $filtered = array_values(array_unique($candidates));

        return $filtered;
    }

    private function isModelDeprecatedError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'deprecated')
            || str_contains($normalized, '"code":5028')
            || str_contains($normalized, 'model has been deprecated');
    }

    /**
     * @return list<string>
     */
    public function getAvailableChatModels(): array
    {
        if (empty($this->accountId) || empty($this->apiToken)) {
            throw new \Exception('Cloudflare Account ID oder API Token fehlt.');
        }

        $url = $this->baseUrl . $this->accountId . '/ai/models/search?task=Text%20Generation&hide_experimental=true&per_page=100';
        $response = $this->makeGetRequest($url);

        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return [];
        }

        $models = [];

        foreach ($result as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->isDeprecatedModelItem($item)) {
                continue;
            }

            $modelId = $this->extractModelId($item);
            if ($modelId !== '') {
                $models[] = $modelId;
            }
        }

        /** @var list<string> $uniqueModels */
        $uniqueModels = array_values(array_unique($models));

        return $uniqueModels;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isDeprecatedModelItem(array $item): bool
    {
        if (isset($item['deprecated']) && is_bool($item['deprecated']) && $item['deprecated']) {
            return true;
        }

        if (isset($item['description']) && is_string($item['description']) && str_contains(strtolower($item['description']), 'deprecated')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractModelId(array $item): string
    {
        $candidates = [];
        foreach (['name', 'id', 'model', 'slug'] as $key) {
            if (isset($item[$key]) && is_string($item[$key])) {
                $candidates[] = trim($item[$key]);
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && str_starts_with($candidate, '@cf/')) {
                return $candidate;
            }
        }

        // Fallback: Build @cf/author/model when API returns split fields.
        $author = isset($item['author']) && is_string($item['author']) ? trim($item['author']) : '';
        $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
        if ($author !== '' && $name !== '') {
            return '@cf/' . $author . '/' . $name;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function makeRequest(string $url, array $data): array
    {
        $socket = rex_socket::factoryUrl($url);
        $socket->addHeader('Authorization', 'Bearer ' . $this->apiToken);
        $socket->addHeader('Content-Type', 'application/json');
        
        $json = json_encode($data);
        if ($json === false) {
            throw new \Exception("JSON encoding failed: " . json_last_error_msg());
        }
        
        $response = $socket->doPost($json);
        
        if (!$response->isOk()) {
             $statusCode = $response->getStatusCode();
             $body = $response->getBody();
             $decoded = json_decode($body, true);
             throw new \Exception('Cloudflare API Error (' . $statusCode . '): ' . json_encode($decoded));
        }
        
        $body = (string) $response->getBody();
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeGetRequest(string $url): array
    {
        $socket = rex_socket::factoryUrl($url);
        $socket->addHeader('Authorization', 'Bearer ' . $this->apiToken);
        $socket->addHeader('Content-Type', 'application/json');

        $response = $socket->doGet();

        if (!$response->isOk()) {
            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            /** @var mixed $decoded */
            $decoded = json_decode($body, true);
            throw new \Exception('Cloudflare API Error (' . $statusCode . '): ' . json_encode($decoded));
        }

        $body = (string) $response->getBody();
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
