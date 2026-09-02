<?php

namespace FriendsOfRedaxo\AiChat\Service;

use rex_addon;
use rex_socket;
use rex_socket_response;

class GeminiService implements AiServiceInterface
{
    // text-embedding-004 accepts up to 250 requests per batchEmbedContents call;
    // stay well under that so a single batch also stays fast.
    private const EMBEDDING_BATCH_SIZE = 100;

    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * @param array<string, string> $overrides Optionale Config-Overrides (siehe AiServiceFactory::create()).
     */
    public function __construct(array $overrides = [])
    {
        $this->apiKey = trim((string) ($overrides['gemini_api_key'] ?? rex_addon::get('ai_chat')->getConfig('gemini_api_key')));
    }

    /**
     * @return list<float>
     */
    public function getEmbedding(string $text): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Gemini API Key is missing.');
        }

        $url = $this->baseUrl . 'text-embedding-004:embedContent?key=' . $this->apiKey;
        
        $data = [
            'model' => 'models/text-embedding-004',
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ]
        ];

        $response = $this->makeRequest($url, $data);

        if (isset($response['embedding']['values'])) {
            return $response['embedding']['values'];
        }

        throw new \Exception('Failed to get embedding: ' . json_encode($response));
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function getEmbeddings(array $texts): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Gemini API Key is missing.');
        }

        $texts = array_values($texts);
        if ($texts === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($texts, self::EMBEDDING_BATCH_SIZE) as $batch) {
            $url = $this->baseUrl . 'text-embedding-004:batchEmbedContents?key=' . $this->apiKey;

            $requests = [];
            foreach ($batch as $text) {
                $requests[] = [
                    'model' => 'models/text-embedding-004',
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ];
            }

            $response = $this->makeRequest($url, ['requests' => $requests]);

            if (!isset($response['embeddings']) || !is_array($response['embeddings'])) {
                throw new \Exception('Failed to get embeddings: ' . json_encode($response));
            }

            foreach ($response['embeddings'] as $embedding) {
                if (!is_array($embedding) || !isset($embedding['values']) || !is_array($embedding['values'])) {
                    throw new \Exception('Failed to get embeddings: ' . json_encode($response));
                }

                $results[] = array_values(array_map('floatval', $embedding['values']));
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
        if (empty($this->apiKey)) {
            throw new \Exception('Gemini API Key is missing.');
        }

        $url = $this->baseUrl . 'gemini-2.0-flash:generateContent?key=' . $this->apiKey;
        $data = $this->buildGenerationPayload($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);

        $response = $this->makeRequest($url, $data);

        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
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
        if (empty($this->apiKey)) {
            throw new \Exception('Gemini API Key is missing.');
        }

        $url = $this->baseUrl . 'gemini-2.0-flash:streamGenerateContent?alt=sse&key=' . $this->apiKey;
        $data = $this->buildGenerationPayload($prompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);

        return $this->streamGenerateContentFromUrl($url, $data, $onChunk);
    }

    /**
     * @param array<int, array{content: string, url?: string, title?: string, similarity?: float, source_label?: ?string, source_label_description?: ?string, source_label_is_timely?: bool}> $context
     * @param array<string, mixed>|null $personalization
     * @return array<string, mixed>
     */
    private function buildGenerationPayload(string $prompt, array $context, string $scope, ?array $personalization, ?string $systemPromptOverride = null, ?string $addressingModeOverride = null, ?string $answerLanguageOverride = null): array
    {
        // Construct the prompt with context
        $contextText = "";
        foreach ($context as $item) {
            $contextText .= PromptBuilder::formatContextPrefix($item) . $item['content'] . "\n\n";
        }

        $systemPrompt = "Wichtiger Zeit-Kontext: " . SystemToolService::getDateTimeContext() . "\n\n";

        if ($scope === 'developer') {
            $systemPrompt .= "Du bist ein erfahrener REDAXO CMS Entwickler und Experte. Nutze die folgende Dokumentation und Code-Beispiele, um Fragen zur Entwicklung, API und Addons zu beantworten. Sei technisch präzise.";
            
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
            // Frontend scope. Ein Profil-eigener Prompt (siehe ChatProfile::$customPrompt)
            // geht vor der globalen "frontend_prompt"-Einstellung - Reihenfolge nur für
            // den Frontend-Zweig relevant, der Developer-Systemprompt oben bleibt fest
            // (enthaelt die [[ACTION:...]]-Anleitung fuer die System-Tools).
            $addon = rex_addon::get('ai_chat');
            $customPrompt = $systemPromptOverride ?? $addon->getConfig('frontend_prompt');
            if (!empty($customPrompt)) {
                $systemPrompt = $customPrompt;
            } else {
                $systemPrompt = "Du bist ein hilfreicher Assistent für diese Website. Nutze den folgenden Kontext, um die Frage des Nutzers zu beantworten.";
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

        $instruction = "Wenn die Antwort nicht im Kontext enthalten ist, sage, dass du es nicht weißt. " . PromptBuilder::answerLanguageInstruction($answerLanguageOverride);

        $fullPrompt = $systemPrompt . " " . $instruction . "\n\n" .
                      $contextText .
                      "Frage des Nutzers: " . $prompt;

        $addon = rex_addon::get('ai_chat');
        $temperature = (float) $addon->getConfig('ai_temperature', 0.7);
        $maxTokens = (int) $addon->getConfig('ai_max_tokens', 2048);

        return [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $fullPrompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => $temperature
            ]
        ];
    }

    /**
     * Reads Gemini's SSE stream (alt=sse) via cURL and invokes $onChunk for every
     * incremental text delta as it arrives on the wire (true token streaming, no
     * post-hoc fragmentation of an already-complete answer).
     *
     * @param array<string, mixed> $data
     * @param callable(string): void|null $onChunk
     */
    private function streamGenerateContentFromUrl(string $url, array $data, ?callable $onChunk = null): string
    {
        if ('' === $url) {
            throw new \Exception('cURL init failed: empty URL.');
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
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/event-stream'],
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
                    if ($decodedData === '') {
                        continue;
                    }
                    $payload = json_decode($decodedData, true);
                    if (!is_array($payload)) {
                        continue;
                    }
                    $delta = $payload['candidates'][0]['content']['parts'][0]['text'] ?? '';
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
            // Provider responded but sent no SSE frames (e.g. proxy stripped them) -
            // fall back to a non-streaming call so the user still gets an answer.
            $fallbackUrl = str_replace(':streamGenerateContent', ':generateContent', $url);
            $fallbackUrl = str_replace('alt=sse&', '', $fallbackUrl);
            $fallbackResponse = $this->makeRequest($fallbackUrl, $data);
            $full = (string) ($fallbackResponse['candidates'][0]['content']['parts'][0]['text'] ?? '');
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
    private function makeRequest(string $url, array $data, int $retryCount = 0): array
    {
        $socket = rex_socket::factoryUrl($url);
        $socket->addHeader('Content-Type', 'application/json');
        
        $json = json_encode($data);
        if ($json === false) {
             throw new \Exception("JSON encoding failed: " . json_last_error_msg());
        }
        
        $response = $socket->doPost($json);
        
        if (!$response->isOk()) {
             $statusCode = $response->getStatusCode();
             
             // Retry on 429 (Too Many Requests)
             if ($statusCode === 429 && $retryCount < 3) {
                 sleep(2 * ($retryCount + 1)); // Exponential backoff: 2s, 4s, 6s
                 return $this->makeRequest($url, $data, $retryCount + 1);
             }

             $body = $response->getBody();
             $decoded = json_decode($body, true);
             throw new \Exception('API Error (' . $statusCode . '): ' . json_encode($decoded));
        }
        
        $body = (string) $response->getBody();
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
