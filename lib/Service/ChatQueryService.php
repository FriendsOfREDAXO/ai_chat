<?php

namespace FriendsOfRedaxo\AiChat\Service;

use FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderRegistry;
use FriendsOfRedaxo\AiChat\ContentProvider\YformProfiles;
use FriendsOfRedaxo\AiChat\Db\VectorCapability;
use FriendsOfRedaxo\AiChat\Profile\ChatProfile;
use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;
use FriendsOfRedaxo\AiChat\Profile\ProfileResolver;
use FriendsOfRedaxo\AiChat\Retrieval\BruteForceRetrieval;
use FriendsOfRedaxo\AiChat\Retrieval\NativeVectorRetrieval;
use FriendsOfRedaxo\AiChat\Retrieval\RetrievalStrategyInterface;
use FriendsOfRedaxo\AiChat\Retrieval\VectorMath;
use rex;
use rex_addon;
use rex_backend_login;
use rex_clang;
use rex_logger;
use rex_server;
use rex_sql;
use rex_user;

class ChatQueryService
{
    /**
     * Ab wie vielen Angriffsversuchen (Code-Injection + Prompt-Injection zusammen, siehe
     * combinedAttackStrikes()) die Konversation nicht mehr nur einzeln blockiert, sondern
     * ueber conversationClosingMessage() hoeflich beendet wird.
     */
    private const ATTACK_STRIKES_BEFORE_CLOSING = 3;

    public static function getAuthenticatedBackendUser(): ?rex_user
    {
        $currentUser = rex::getUser();
        if ($currentUser instanceof rex_user) {
            return $currentUser;
        }

        if (!rex_backend_login::hasSession()) {
            return null;
        }

        $backendUser = rex_backend_login::createUser();

        return $backendUser instanceof rex_user ? $backendUser : null;
    }

    /**
     * Wertet die globale IP-Testmodus-Einschraenkung aus (Einstellungen -> Zugriff,
     * "Erlaubte IPs") - von boot.php (Widget-Injektion) UND process() (server-seitige
     * Durchsetzung, siehe dort) genutzt, damit beide exakt dieselbe Regel anwenden statt
     * einer nur kosmetischen Pruefung beim Rendern.
     *
     * [0]=false: Feld ist leer, keine Einschraenkung aktiv - [1] ist dann bedeutungslos.
     * [0]=true, [1]=false: eine Einschraenkung ist konfiguriert, die aktuelle IP steht aber
     * NICHT auf der Liste - Chat/Suche muessen fuer diese Anfrage komplett blockiert werden.
     * [0]=true, [1]=true: Testmodus fuer DIESE Anfrage aktiv - die aktuelle IP ist erlaubt,
     * was zusaetzlich die "Sichtbar für"-Rolleneinschraenkung einzelner Profile umgeht (siehe
     * ProfileResolver::resolveForFrontend()'s $bypassViewerRoles) - genau der Fall, fuer den
     * das Feld gedacht ist: als Tester JEDES Profil sehen koennen, auch ein bewusst auf
     * "nur Redakteure/Admins" beschraenktes, ohne dafuer eingeloggt sein zu muessen.
     *
     * @return array{0: bool, 1: bool}
     */
    public static function resolveIpTestGate(): array
    {
        $allowedIps = trim((string) rex_addon::get('ai_chat')->getConfig('frontend_allowed_ips', ''));
        if ('' === $allowedIps) {
            return [false, false];
        }

        $ips = array_map('trim', explode(',', $allowedIps));
        $userIp = rex_server('REMOTE_ADDR', 'string', '');

        return [true, in_array($userIp, $ips, true)];
    }

    /**
     * @return array<string, mixed>|null Response to short-circuit with, or null if the request may proceed.
     */
    private function resolveFrontendAccessDenial(string $mode, ?ChatProfile $profile): ?array
    {
        $addon = rex_addon::get('ai_chat');

        // Wer ueberhaupt sichtbar sein darf (Domain/Sprache/Rolle) entscheidet seit dem
        // Profil-Feature ausschliesslich ChatProfile::$viewerRoles/$targetMode - der
        // frueher hier zusaetzlich gepruefte globale "frontend_visibility"-Testmodus-
        // Schalter wurde dadurch vollstaendig abgeloest und ist aus den Einstellungen
        // entfernt (siehe pages/settings.access.php); ihn hier weiter auszuwerten wuerde
        // nur einen toten, nicht mehr einstellbaren Config-Wert reaktivieren.
        $globalFeatureEnabled = $mode === 'search'
            ? (bool) $addon->getConfig('frontend_search_enabled', true)
            : (bool) $addon->getConfig('frontend_enabled');

        // Sobald mindestens ein aktives, frontend-faehiges Profil existiert, ist der globale
        // Schalter komplett wirkungslos - exakt dieselbe Regel wie boot.php's
        // $showChat/$showSearch (dort auch ausfuehrlicher kommentiert) und
        // pages/settings.access.php (dort dann deaktiviert). Nur DIESES aufgeloeste Profil
        // (chat_enabled/search_enabled, Standard: aktiv) entscheidet dann - kein Fallback
        // mehr auf den globalen Wert, auch nicht bei leerem Tri-State. Ohne aktive Profile
        // bleibt der globale Schalter die alleinige Instanz (Profile sind optional).
        $hasFrontendProfiles = [] !== array_filter(
            (new ProfileRepository())->getEnabled(),
            static fn (ChatProfile $p): bool => $p->context !== 'backend',
        );
        $featureEnabled = $hasFrontendProfiles
            ? (null !== $profile && ($mode === 'search' ? ($profile->searchEnabled ?? true) : ($profile->chatEnabled ?? true)))
            : $globalFeatureEnabled;

        if ($featureEnabled) {
            return null;
        }

        if ($mode === 'search') {
            return [
                'mode' => 'search',
                'query' => '',
                'hits' => [],
                'filters' => ['source_types' => []],
            ];
        }

        return [
            'answer' => '',
            'answer_text' => '',
            'follow_up_questions' => [],
        ];
    }

    /**
     * Spiegelt exakt die Domain-Ermittlung aus boot.php, damit die serverseitige
     * Neuzuordnung des Profils (siehe process()) dieselbe Domain sieht wie die
     * Widget-Injektion beim Seitenaufruf.
     */
    private static function resolveCurrentFrontendDomain(): ?\rex_yrewrite_domain
    {
        return YrewriteDomainResolver::getCurrentDomain();
    }

    /**
     * @return array{prepared: int, processed: int, skipped: int, errors: int, error_details: list<string>}
     */
    public function warmupFaqCache(int $maxItems = 0): array
    {
        if (!$this->isFaqPrecacheEnabled()) {
            throw new \RuntimeException('FAQ-Vorcaching ist deaktiviert. Bitte zuerst in den Einstellungen aktivieren.');
        }

        $questions = $this->getFaqPrecacheQuestions();
        if ($questions === []) {
            throw new \RuntimeException('Es sind keine Vorcache-Fragen konfiguriert. Bitte in den Einstellungen Fragen hinterlegen.');
        }

        if ($maxItems > 0) {
            $questions = array_slice($questions, 0, $maxItems);
        }

        $stats = [
            'prepared' => count($questions),
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => [],
            'processed_questions' => [],
            'skipped_questions' => [],
        ];

        foreach ($questions as $question) {
            if ($this->hasExactCachedQuestion($question, 'frontend')) {
                $stats['skipped']++;
                $stats['skipped_questions'][] = $question;
                continue;
            }

            try {
                $result = $this->process([
                    'message' => $question,
                    'scope' => 'frontend',
                    'mode' => 'chat',
                    'include_followups' => false,
                    'current_url' => null,
                ], false);

                $answerText = trim((string) ($result['answer_text'] ?? ''));
                if ($answerText === '') {
                    $stats['skipped']++;
                    $stats['skipped_questions'][] = $question;
                    continue;
                }

                $stats['processed']++;
                $stats['processed_questions'][] = $question;
            } catch (\Throwable $e) {
                $stats['errors']++;
                if (count($stats['error_details']) < 10) {
                    $stats['error_details'][] = $e->getMessage();
                }
                $stats['skipped_questions'][] = $question;
                $stats['skipped']++;
                rex_logger::logException($e);
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $input
     * @param callable(string): void|null $onChunk
     * @return array<string, mixed>
     */
    public function process(array $input, bool $enforceOrigin = true, ?callable $onChunk = null): array
    {
        $message = (string) ($input['message'] ?? '');
        $requestedScope = strtolower((string) ($input['scope'] ?? 'frontend'));
        $scope = $requestedScope === 'search' ? 'frontend' : $requestedScope;
        $currentUrl = isset($input['current_url']) ? (string) $input['current_url'] : null;
        $history = $this->normalizeConversationHistory($input['history'] ?? []);
        $retrievalMessage = $this->buildConversationRetrievalQuery($message, $history);

        // IP-Testmodus (siehe resolveIpTestGate()) wird weiter unten, sobald $mode feststeht,
        // zusammen mit der uebrigen Frontend-Zugriffskontrolle durchgesetzt - hier schon
        // vorab ausgewertet, weil resolveForFrontend() direkt danach den Bypass braucht.
        [$ipGateConfigured, $ipAllowed] = self::resolveIpTestGate();
        $ipTestModeActive = $scope === 'frontend' && $ipGateConfigured && $ipAllowed;

        // Vom Client mitgeschicktes Profil (siehe boot.php: dort per ProfileResolver
        // fuer die aktuelle Domain/Sprache/Rolle aufgeloest) - steuert Wissens-Scope
        // (findSimilarContent) und optional einen eigenen System-Prompt/Anrede. Kein
        // Profil = unveraendertes Verhalten ueber den kompletten Shared Pool, wie vor
        // dem Profil-Feature.
        $profileId = isset($input['profile_id']) && '' !== (string) $input['profile_id'] ? (int) $input['profile_id'] : null;
        $profile = null !== $profileId ? (new ProfileRepository())->find($profileId) : null;

        // Sicherheitsgrenze: die mitgeschickte profile_id kommt vom Client und ist damit
        // manipulierbar. Fuer echte Besucher (kein authentifizierter Backend-Nutzer) wird
        // das Profil deshalb serverseitig NEU aufgeloest (exakt dieselbe Logik wie in
        // boot.php anhand der aktuellen Domain/Sprache/Rolle) statt der Client-Angabe
        // blind zu vertrauen - sonst koennte sich ein Besucher per manipulierter ID ein
        // fremdes Profil (andere Domain/Sprache, backend-only, deaktiviert) samt dessen
        // eigenem Prompt/Wissens-Scope erschleichen. Ein authentifizierter Backend-Nutzer
        // darf ein beliebiges Profil explizit waehlen (genutzt vom "Profil testen"-Dialog
        // in pages/profiles.php) - schickt er dagegen GAR KEINE profile_id (z.B. beim
        // Testen des echten Widgets auf AI Chat → Demos, das keine setzt), soll er
        // dasselbe automatisch aufgeloeste Profil sehen wie ein echter Besucher an seiner
        // Stelle - sonst wirkt profil-exklusiver Inhalt (PDFs/YForm) fuer jeden testenden
        // Admin faelschlich unauffindbar, obwohl er fuer echte Besucher funktioniert.
        if ($scope === 'frontend' && (null === self::getAuthenticatedBackendUser() || null === $profileId)) {
            // Fuer eine echte Besucherin bleibt $backendUser hier null (Rolle "visitor");
            // fuer einen testenden Backend-Nutzer ohne explizite profile_id wird stattdessen
            // dessen eigene Rolle (admin/editor) verwendet, damit auch ein nur fuer
            // Redakteure/Admins sichtbares Profil beim Testen korrekt matcht. $ipTestModeActive
            // umgeht zusaetzlich die "Sichtbar für"-Rolleneinschraenkung selbst (siehe
            // ProfileResolver) - eine zugelassene Test-IP soll jedes Profil sehen koennen.
            $profile = (new ProfileResolver())->resolveForFrontend(
                self::resolveCurrentFrontendDomain(),
                rex_clang::getCurrentId(),
                self::getAuthenticatedBackendUser(),
                $ipTestModeActive,
            );
        }

        $systemPromptOverride = $profile?->customPrompt;
        $addressingModeOverride = $profile?->addressingMode;
        $answerLanguageOverride = $profile?->answerLanguage;

        // Developer-Anfragen laufen über den Frontend-Controller, können aber trotzdem
        // eine gültige Backend-Session des angemeldeten REDAXO-Benutzers mitbringen.
        if ($scope === 'developer' && null === self::getAuthenticatedBackendUser()) {
            throw new \RuntimeException('Developer chat ist nur für angemeldete Backend-Benutzer verfügbar.');
        }
        /** @var array<string, mixed>|null $personalization */
        $personalization = is_array($input['personalization'] ?? null) ? $input['personalization'] : null;
        // $profile->personalizationMode ist NULL, wenn das Profil hierfuer die globale
        // Einstellung uebernehmen soll (siehe ChatProfile/pages/profiles.php) - genau wie bei
        // addressingModeOverride oben faellt das dann auf die globale Konfiguration zurueck,
        // statt eine eigene Festlegung zu erzwingen.
        $configuredPersonalizationMode = (null !== $profile ? $profile->personalizationMode : null)
            ?? trim((string) rex_addon::get('ai_chat')->getConfig('personalization_mode', 'off'));
        if ($configuredPersonalizationMode === '') {
            $configuredPersonalizationMode = 'off';
        }

        if ($requestedScope === 'developer') {
            $personalization = $this->getDeveloperPersonalization();
        } elseif ($requestedScope === 'search' || $configuredPersonalizationMode === 'off') {
            $personalization = null;
        } elseif (is_array($personalization)) {
            $mode = (string) ($personalization['mode'] ?? 'formal');
            $normalizedMode = $mode === 'informal' ? 'informal' : 'formal';

            $normalizedPersonalization = [
                'mode' => $normalizedMode,
            ];

            if (
                $normalizedMode === 'informal'
                && isset($personalization['name'])
                && is_string($personalization['name'])
                && trim($personalization['name']) !== ''
            ) {
                $normalizedPersonalization['name'] = trim((string) $personalization['name']);
            }

            $personalization = $normalizedPersonalization;
        }
        $mode = (string) ($input['mode'] ?? 'chat');
        $includeFollowUpQuestions = (bool) ($input['include_followups'] ?? true);

        // Frontend-Zugriffskontrolle server-seitig durchsetzen: die Settings-Schalter
        // (frontend_enabled/frontend_search_enabled) steuern sonst nur, ob das Widget
        // ins Markup injiziert wird. Ohne diesen Check ließe sich der Endpoint trotzdem
        // direkt aufrufen, selbst wenn Chat/Suche im Frontend deaktiviert ist. Wer
        // überhaupt sichtbar sein darf, ist bereits über die Profil-Neuzuordnung oben
        // (viewerRoles/targetMode/domains/clangs) durchgesetzt.
        //
        // IP-Testmodus greift davor: ist eine Einschraenkung konfiguriert und die aktuelle
        // IP steht NICHT auf der Liste, ist fuer nicht angemeldete Besucher hier komplett
        // Schluss - boot.php prueft das zwar bereits, verhindert damit aber nur die
        // Widget-Injektion ins Markup, nicht den direkten Aufruf dieses Endpunkts.
        if ($scope === 'frontend') {
            if ($ipGateConfigured && !$ipAllowed && null === self::getAuthenticatedBackendUser()) {
                if ($mode === 'search') {
                    return [
                        'mode' => 'search',
                        'query' => '',
                        'hits' => [],
                        'filters' => ['source_types' => []],
                    ];
                }

                return [
                    'answer' => '',
                    'answer_text' => '',
                    'follow_up_questions' => [],
                ];
            }

            $frontendDenial = $this->resolveFrontendAccessDenial($mode, $profile);
            if ($frontendDenial !== null) {
                return $frontendDenial;
            }
        }

        if ($enforceOrigin) {
            $this->validateOrigin();
        }

        $this->checkRateLimit();

        $messageLengthGuard = $this->evaluateMessageLengthGuard($message, $scope);
        if ($messageLengthGuard['blocked']) {
            $warningText = $messageLengthGuard['message'];

            if ($mode === 'search') {
                return [
                    'mode' => 'search',
                    'query' => trim($message),
                    'hits' => [],
                    'filters' => [
                        'source_types' => [],
                    ],
                    'privacy_warning_message' => $warningText,
                    'ai_disabled_for_session' => false,
                    'privacy_strikes' => 0,
                ];
            }

            return [
                'answer' => $this->parseMarkdown($warningText),
                'answer_text' => $warningText,
                'follow_up_questions' => [],
                'security_warning' => true,
            ];
        }

        $codeInjectionGuard = $this->evaluateCodeInjectionGuard($message);
        if ($codeInjectionGuard['blocked']) {
            $warningText = $codeInjectionGuard['message'];

            if ($mode === 'search') {
                return [
                    'mode' => 'search',
                    'query' => trim($message),
                    'hits' => [],
                    'filters' => [
                        'source_types' => [],
                    ],
                    'privacy_warning_message' => $warningText,
                    'ai_disabled_for_session' => false,
                    'privacy_strikes' => 0,
                ];
            }

            return [
                'answer' => $this->parseMarkdown($warningText),
                'answer_text' => $warningText,
                'follow_up_questions' => [],
                'security_warning' => true,
            ];
        }

        $promptInjectionGuard = $this->evaluatePromptInjectionGuard($message);
        if ($promptInjectionGuard['blocked']) {
            $warningText = $promptInjectionGuard['message'];

            if ($mode === 'search') {
                return [
                    'mode' => 'search',
                    'query' => trim($message),
                    'hits' => [],
                    'filters' => [
                        'source_types' => [],
                    ],
                    'privacy_warning_message' => $warningText,
                    'ai_disabled_for_session' => false,
                    'privacy_strikes' => 0,
                ];
            }

            return [
                'answer' => $this->parseMarkdown($warningText),
                'answer_text' => $warningText,
                'follow_up_questions' => [],
                'security_warning' => true,
            ];
        }

        // Sinnlose Chat-Anfragen (siehe looksLikeNonsenseQuery()) sollen die KI nicht
        // belasten - gilt hier fuer scope=frontend (Website-Besucher, inkl. des mode=chat-
        // Aufrufs, den ai-search.js bei treffer-loser Suche selbst absetzt, siehe
        // fetchChatAnswer()/nonsense_query weiter unten in search()). Nur mode=chat, nicht
        // mode=search: Suche selbst ruft ohnehin keine KI auf (reines SQL-LIKE-Matching),
        // Kauderwelsch liefert dort einfach 0 Treffer, kein Grund zu blockieren. Der
        // Developer-Chat (scope=developer) ist bewusst ausgenommen - kurze technische
        // Fragmente sind dort normal, kein Angriff/Grund fuer eine Rueckfrage.
        if ($scope === 'frontend' && $mode === 'chat' && $this->looksLikeNonsenseQuery($message)) {
            $clarification = 'Entschuldigung, das konnte ich nicht recht verstehen. Können Sie Ihre Frage etwas genauer formulieren?';

            return [
                'answer' => $this->parseMarkdown($clarification),
                'answer_text' => $clarification,
                'follow_up_questions' => [],
            ];
        }

        $spamGuard = $this->evaluateSpamGuard($message);
        if ($spamGuard['blocked']) {
            $warningText = $spamGuard['message'];

            if ($mode === 'search') {
                return [
                    'mode' => 'search',
                    'query' => trim($message),
                    'hits' => [],
                    'filters' => [
                        'source_types' => [],
                    ],
                    'privacy_warning_message' => $warningText,
                    'ai_disabled_for_session' => false,
                    'privacy_strikes' => 0,
                ];
            }

            return [
                'answer' => $this->parseMarkdown($warningText),
                'answer_text' => $warningText,
                'follow_up_questions' => [],
                'security_warning' => true,
            ];
        }

        $privacyGuard = $this->evaluatePrivacyGuard($message, $mode);
        if ($privacyGuard['blocked']) {
            $warningText = $privacyGuard['message'];

            if ($mode === 'search') {
                return [
                    'mode' => 'search',
                    'query' => trim($message),
                    'hits' => [],
                    'filters' => [
                        'source_types' => [],
                    ],
                    'privacy_warning_message' => $warningText,
                    'ai_disabled_for_session' => $privacyGuard['disabled'],
                    'privacy_strikes' => $privacyGuard['strikes'],
                ];
            }

            return [
                'answer' => $this->parseMarkdown($warningText),
                'answer_text' => $warningText,
                'follow_up_questions' => [],
                'privacy_warning' => true,
                'ai_disabled_for_session' => $privacyGuard['disabled'],
                'privacy_strikes' => $privacyGuard['strikes'],
            ];
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if ($message === '') {
            return ['answer' => ''];
        }

        if ($this->isStatsLoggingEnabled()) {
            $this->recordUsageStat($mode, $scope, $message, 'request_started', 0, $profile?->id);
        }

        $showSources = $this->isShowSourcesEnabled();

        $aiService = AiServiceFactory::create();
        $hasPreviousUserMessage = false;
        foreach ($history as $historyEntry) {
            if ($historyEntry['role'] === 'user') {
                $hasPreviousUserMessage = true;
                break;
            }
        }
        $faqPrecacheEnabled = !$hasPreviousUserMessage && $this->isFaqPrecacheEnabled();

        if ($mode === 'search') {
            $result = $this->search($message, $input, $scope, $profile, $aiService);
            $hits = is_array($result['hits'] ?? null) ? count($result['hits']) : 0;
            $status = $hits > 0 ? 'search_hit' : 'search_no_result';
            if ($this->isStatsLoggingEnabled()) {
                $this->recordUsageStat('search', $scope, $message, $status, $hits, $profile?->id);
            }

            // KI-Hilfe bei treffer-loser Suche uebernimmt bereits ai-search.js
            // (shouldAskChatForEmptyResults() -> fetchChatAnswer(), ein regulaerer
            // mode=chat-Aufruf mit voller RAG-Suche+KI-Antwort - siehe dort). Ein
            // zusaetzlicher KI-Aufruf HIER waere ein zweiter, ungenutzter API-Call auf jede
            // treffer-lose Suche. Was hier bewusst zusaetzlich passiert: eine offensichtlich
            // sinnlose Anfrage (siehe looksLikeNonsenseQuery()) wird markiert, damit das
            // Frontend den KI-Fallback fuer SOLCHE Anfragen erst gar nicht anstoesst - die
            // eigentliche Absicherung gegen sinnlose/angreifende Anfragen an die KI selbst
            // liegt im gemeinsamen Guard oben in process() (gilt fuer Chat UND Suche
            // gleichermassen, da beide ueber process() laufen).
            if (0 === $hits && $this->looksLikeNonsenseQuery($message)) {
                $result['nonsense_query'] = true;
            }

            return $result;
        }

        // Asynchroner Folgeaufruf zu mode=search (siehe dortiges 'summary_available'):
        // ai-search.js zeigt die Trefferliste sofort an und holt die KI-Zusammenstellung erst
        // DANACH separat nach, damit eine Suche nicht mehr auf einen kompletten KI-Aufruf warten
        // muss, bevor ueberhaupt Treffer sichtbar werden. $input['hits'] sind dieselben Treffer,
        // die der Client bereits aus der mode=search-Antwort hat (title/snippet/label/url) -
        // spart eine erneute Datenbank-Suche fuer denselben Query hier.
        if ($mode === 'search_summary') {
            $summaryHits = [];
            foreach (is_array($input['hits'] ?? null) ? $input['hits'] : [] as $hit) {
                if (is_array($hit)) {
                    $summaryHits[] = $hit;
                }
            }

            return ['summary' => $this->buildSearchSummary($message, $summaryHits, $aiService)];
        }

        if ($mode === 'followups') {
            $answerForFollowups = (string) ($input['answer'] ?? '');
            $followUpQuestions = [];

            if ($scope === 'frontend' && rex_addon::get('ai_chat')->getConfig('suggest_followup_questions')) {
                try {
                    $followUpQuestions = $this->generateFollowUpQuestions($aiService, $message, $answerForFollowups, $scope, $answerLanguageOverride);
                } catch (\Exception $e) {
                    // Folgefragen sind optional.
                }
            }

            return ['follow_up_questions' => $followUpQuestions];
        }

        $userEmbedding = $aiService->getEmbedding($retrievalMessage);

        // Trigger VOR der Antwortgenerierung pruefen (nicht erst danach, siehe unten) - ein
        // Treffer zaehlt als eigene, verlaessliche Antwortquelle: er darf sowohl den
        // "reicht der Kontext nicht"-Fallback unten verhindern (der Trigger beantwortet die
        // Frage ja gerade, auch wenn der RAG-Kontext dazu duenn ist) als auch der KI per
        // Prompt-Hinweis mitteilen, dass es dazu bereits einen Extra-Block gibt - sonst
        // behauptet die KI mangels eigenem Wissen faelschlich, ihr fehle die Information,
        // obwohl direkt im Anschluss der (unveraendert woertlich angehaengte, siehe unten)
        // Trigger-Inhalt mit genau dieser Information folgt.
        $triggerContent = $this->checkTriggers($message);

        $answer = '';
        $fromCache = false;
        // Wird gesetzt, sobald [[ACTION:...]]-Tokens bereits live während des Streamings
        // ausgeführt wurden, damit sie beim finalen Answer nicht erneut ausgeführt werden.
        $streamedToolResults = null;

        if ($faqPrecacheEnabled) {
            $cachedAnswer = $this->findCachedAnswer($userEmbedding, $scope, $message);
            if ($cachedAnswer) {
                $answer = $cachedAnswer;
                $fromCache = true;
            }
        }

        if (!$fromCache) {
            $ragResults = (int) rex_addon::get('ai_chat')->getConfig('rag_results', 3);
            $context = $this->findSimilarContent($userEmbedding, $ragResults, $scope, $currentUrl, $retrievalMessage, $profile);
            $context = $this->ensureForcalContextForIntent($context, $retrievalMessage, $scope, $ragResults);
            $context = $this->ensureProviderContextByKeyword($context, $retrievalMessage, $scope, $ragResults);
            $context = $this->ensureKeywordMatchedContext($context, $retrievalMessage, $scope, $profile, $ragResults);

            if ($triggerContent === '' && !$this->hasSufficientAnswerContext($context, $retrievalMessage, $scope)) {
                $answer = $this->buildInsufficientContextAnswer($scope);
                $answer = $this->normalizeAnswerMarkdown($answer);
                $answerHtml = $this->parseMarkdown($answer);

                return [
                    'answer' => $answerHtml,
                    'answer_text' => strip_tags($answer),
                    'follow_up_questions' => [],
                ];
            }

            $modelPrompt = $this->buildConversationPrompt($message, $history);
            $providerInstructions = $this->getProviderInstructionsForContext($context);
            if ($providerInstructions !== '') {
                $modelPrompt .= "\n\nProvider-Hinweise:\n" . $providerInstructions;
            }
            if ($triggerContent !== '') {
                $modelPrompt .= "\n\nHinweis: Zu dieser Anfrage ist ein hinterlegter Zusatzinhalt vorhanden "
                    . '(z.B. Öffnungszeiten, Kontaktdaten o.ä.), der direkt im Anschluss an deine Antwort '
                    . 'automatisch angezeigt wird. Behaupte deshalb NICHT, dass dir diese Information fehlt '
                    . 'oder im Kontext nicht enthalten ist. Beantworte die Frage kurz allgemein bzw. verweise '
                    . 'darauf, dass die Details direkt im Anschluss folgen - wiederhole den Zusatzinhalt nicht '
                    . 'selbst.';
            }

            if ($onChunk !== null && method_exists($aiService, 'generateAnswerStream')) {
                $streamOnChunk = $onChunk;
                $toolFilter = null;
                if ($scope === 'developer') {
                    $toolFilter = $this->wrapOnChunkForSystemTools($onChunk);
                    $streamOnChunk = $toolFilter['chunk'];
                }

                $answer = $aiService->generateAnswerStream($modelPrompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $streamOnChunk, $answerLanguageOverride);

                if ($toolFilter !== null) {
                    $toolFilter['flush']();
                    $streamedToolResults = ($toolFilter['getResults'])();
                }
            } else {
                $answer = $aiService->generateAnswer($modelPrompt, $context, $scope, $personalization, $systemPromptOverride, $addressingModeOverride, $answerLanguageOverride);
            }

            $answer = $this->appendSourcesFromContext($answer, $context, $retrievalMessage, $scope, $showSources);

            if ($faqPrecacheEnabled) {
                $this->cacheAnswer($message, $userEmbedding, $answer, $scope);
            }
        } elseif ($showSources) {
            // Cached answers can contain stale source blocks from older context.
            // Rebuild displayed sources from the current retrieval context.
            $answer = $this->removeSourcesSection($answer);
            $ragResults = (int) rex_addon::get('ai_chat')->getConfig('rag_results', 3);
            $context = $this->findSimilarContent($userEmbedding, $ragResults, $scope, $currentUrl, $retrievalMessage, $profile);
            $context = $this->ensureForcalContextForIntent($context, $retrievalMessage, $scope, $ragResults);
            $context = $this->ensureProviderContextByKeyword($context, $retrievalMessage, $scope, $ragResults);
            $context = $this->ensureKeywordMatchedContext($context, $retrievalMessage, $scope, $profile, $ragResults);
            $answer = $this->appendSourcesFromContext($answer, $context, $retrievalMessage, $scope, $showSources);
        }

        if (!$showSources) {
            $answer = $this->removeSourcesSection($answer);
        }

        if ($triggerContent !== '') {
            $answer .= "\n\n" . $triggerContent;
        }

        if ($scope === 'developer') {
            $answer = $streamedToolResults !== null
                ? $this->replaceActionsWithPrecomputedResults($answer, $streamedToolResults)
                : $this->processSystemTools($answer);
        }

        $answer = $this->removeUnwantedGreetingPrefix($answer, $scope);

        if ($this->isStatsLoggingEnabled()) {
            $status = trim((string) $answer) === '' ? 'chat_no_answer' : 'chat_answer';
            $this->recordUsageStat('chat', $scope, $message, $status, 0, $profile?->id);
        }

        $answer = $this->normalizeAnswerMarkdown($answer);
        $answerHtml = $this->parseMarkdown($answer);

        $followUpQuestions = [];
        if ($scope === 'frontend' && $includeFollowUpQuestions && rex_addon::get('ai_chat')->getConfig('suggest_followup_questions')) {
            try {
                $followUpQuestions = $this->generateFollowUpQuestions($aiService, $message, $answer, $scope, $answerLanguageOverride);
            } catch (\Exception $e) {
                // Folgefragen sind optional.
            }
        }

        return [
            'answer' => $answerHtml,
            'answer_text' => strip_tags($answer),
            'follow_up_questions' => $followUpQuestions,
        ];
    }

    /**
     * @param mixed $rawHistory
     * @return list<array{role: 'user'|'assistant', text: string}>
     */
    private function normalizeConversationHistory(mixed $rawHistory): array
    {
        if (!is_array($rawHistory)) {
            return [];
        }

        $history = [];
        foreach (array_slice($rawHistory, -6) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $role = (string) ($entry['role'] ?? '');
            $text = $entry['text'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true) || !is_string($text)) {
                continue;
            }

            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
            if ($text === '') {
                continue;
            }

            $history[] = [
                'role' => $role,
                'text' => mb_substr($text, 0, 1200),
            ];
        }

        return $history;
    }

    /**
     * @param list<array{role: 'user'|'assistant', text: string}> $history
     */
    private function buildConversationRetrievalQuery(string $message, array $history): string
    {
        if ($history === []) {
            return $message;
        }

        $turns = [];
        foreach (array_slice($history, -4) as $entry) {
            $turns[] = ($entry['role'] === 'user' ? 'Nutzer' : 'Assistent') . ': ' . $entry['text'];
        }

        return "Aktuelle Frage: {$message}\n\nVorheriger Gesprächskontext:\n" . implode("\n", $turns);
    }

    /**
     * @param list<array{role: 'user'|'assistant', text: string}> $history
     */
    private function buildConversationPrompt(string $message, array $history): string
    {
        if ($history === []) {
            return $message;
        }

        $turns = [];
        foreach ($history as $entry) {
            $turns[] = ($entry['role'] === 'user' ? 'Nutzer' : 'Assistent') . ': ' . $entry['text'];
        }

        return "Berücksichtige den bisherigen Gesprächskontext. Beziehe Fürwörter und Verweise wie „diese Personen“ auf die zuvor genannten Inhalte.\n\n"
            . "Bisheriger Gesprächsverlauf:\n" . implode("\n", $turns)
            . "\n\nAktuelle Nutzerfrage: {$message}";
    }

    /**
     * @return string[]
     */
    private function generateFollowUpQuestions(AiServiceInterface $aiService, string $userMessage, string $answer, string $scope, ?string $answerLanguageOverride = null): array
    {
        $language = trim((string) $answerLanguageOverride);
        $languageInstruction = '' !== $language ? $language : 'Deutsch';
        $prompt = "Generiere exakt 2-3 kurze, natürliche Folgefragen auf {$languageInstruction}, die ein Nutzer nach dieser Konversation stellen könnte.\n\n"
            . "Nutzerfrage: " . $userMessage . "\n"
            . "Antwort (Auszug): " . mb_substr(strip_tags($answer), 0, 500) . "\n\n"
            . "Antworte NUR mit einem JSON-Array der Fragen, ohne weiteren Text. Beispiel: [\"Frage 1?\", \"Frage 2?\"]";

        $raw = $aiService->generateAnswer($prompt, [], $scope, null);

        if (preg_match('/\[.*\]/s', $raw, $m)) {
            /** @var mixed $decoded */
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                $questions = [];
                foreach ($decoded as $q) {
                    if (is_string($q) && trim($q) !== '') {
                        $questions[] = trim($q);
                    }
                }

                return array_slice($questions, 0, 3);
            }
        }

        return [];
    }

    private function processSystemTools(string $answer): string
    {
        $pattern = '/\[\[ACTION:(.*?)\]\]/';

        return (string) preg_replace_callback($pattern, function ($matches) {
            $command = $matches[1];
            try {
                return SystemToolService::execute($command);
            } catch (\Exception $e) {
                return 'Fehler beim Ausführen von ' . $command . ': ' . $e->getMessage();
            }
        }, $answer);
    }

    /**
     * Puffert einzelne Stream-Chunks, damit ein [[ACTION:...]]-Token nie roh (teilweise
     * über mehrere Chunks verteilt) beim Client landet. Vollständige Tokens werden sofort
     * ausgeführt und durch ihr Ergebnis ersetzt, alles andere wird unverändert durchgereicht.
     * Die Ergebnisse werden zusätzlich gesammelt (getResults), damit der finale, komplette
     * Answer-Text die Tokens nachträglich ersetzen kann, ohne die Aktionen erneut auszuführen.
     *
     * @return array{chunk: \Closure(string): void, flush: \Closure(): void, getResults: \Closure(): list<string>}
     */
    private function wrapOnChunkForSystemTools(callable $onChunk): array
    {
        $buffer = '';
        $results = [];

        $chunkHandler = function (string $chunk) use ($onChunk, &$buffer, &$results): void {
            $buffer .= $chunk;

            while (true) {
                $openPos = strpos($buffer, '[[');
                if ($openPos === false) {
                    if ($buffer !== '') {
                        $onChunk($buffer);
                        $buffer = '';
                    }
                    return;
                }

                if ($openPos > 0) {
                    $onChunk(substr($buffer, 0, $openPos));
                    $buffer = substr($buffer, $openPos);
                }

                $closePos = strpos($buffer, ']]');
                if ($closePos === false) {
                    // Token noch nicht vollständig angekommen, auf weitere Chunks warten.
                    return;
                }

                $token = substr($buffer, 2, $closePos - 2);
                $buffer = substr($buffer, $closePos + 2);

                if (str_starts_with($token, 'ACTION:')) {
                    $command = substr($token, strlen('ACTION:'));
                    try {
                        $result = SystemToolService::execute($command);
                    } catch (\Exception $e) {
                        $result = 'Fehler beim Ausführen von ' . $command . ': ' . $e->getMessage();
                    }
                    $results[] = $result;
                    $onChunk($result);
                } else {
                    $onChunk('[[' . $token . ']]');
                }
            }
        };

        $flushHandler = function () use ($onChunk, &$buffer): void {
            if ($buffer !== '') {
                $onChunk($buffer);
                $buffer = '';
            }
        };

        return [
            'chunk' => $chunkHandler,
            'flush' => $flushHandler,
            'getResults' => function () use (&$results) {
                return $results;
            },
        ];
    }

    /**
     * Ersetzt [[ACTION:...]]-Tokens im finalen Answer-Text durch bereits während des Streamings
     * berechnete Ergebnisse (in Auftrittsreihenfolge), statt die Aktionen erneut auszuführen.
     *
     * @param list<string> $results
     */
    private function replaceActionsWithPrecomputedResults(string $answer, array $results): string
    {
        $index = 0;

        return (string) preg_replace_callback('/\[\[ACTION:(.*?)\]\]/', static function () use (&$index, $results): string {
            $result = $results[$index] ?? '';
            $index++;

            return $result;
        }, $answer);
    }


    private function checkTriggers(string $message): string
    {
        $sql = rex_sql::factory();
        $triggers = $sql->getArray('SELECT keyword, content FROM ' . rex::getTable('ai_chat_triggers'));

        $append = '';
        foreach ($triggers as $trigger) {
            if (stripos($message, (string) $trigger['keyword']) !== false) {
                $append .= "\n\n" . (string) $trigger['content'];
            }
        }

        return $append;
    }

    /**
     * @param float[] $userEmbedding
        * @return array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}>
     */
    private function findSimilarContent(array $userEmbedding, int $limit = 3, string $scope = 'frontend', ?string $currentUrl = null, string $message = '', ?ChatProfile $profile = null): array
    {
        $candidateLimit = $this->getRagCandidateLimit($limit, $currentUrl !== null && $currentUrl !== '');

        [$whereSql, $params, $ok] = $this->buildScopeVisibilityWhere($scope, $profile);
        if (!$ok) {
            rex_logger::logError(E_USER_WARNING, 'AiChat API: Unknown scope "' . $scope . '". Returning empty result.', __FILE__, __LINE__);
            return [];
        }

        // Fuer den "aktuelle Seite"-Fokus unten weiterhin benoetigt (Provider-Inhalte sollen
        // auch bei aktivem Seiten-Fokus global sichtbar bleiben, nicht nur von der aktuellen URL).
        $frontendProviderTypes = $scope === 'frontend' ? array_values(array_unique(array_merge(
            $this->getEnabledFrontendProviderSourceTypes(),
            $this->getProfileExclusiveSourceTypes($profile),
        ))) : [];

        if ($currentUrl) {
            $path = parse_url($currentUrl, PHP_URL_PATH);
            if ($path) {
                if ($scope === 'frontend' && $frontendProviderTypes !== []) {
                    // Keep current-page focus for page content, but always allow enabled provider content globally.
                    $whereSql .= ' AND (source_type IN (' . implode(', ', array_fill(0, count($frontendProviderTypes), '?')) . ') OR url LIKE ?)';
                    $params = array_merge($params, $frontendProviderTypes);
                    $params[] = '%' . $path . '%';
                } else {
                    $whereSql .= ' AND url LIKE ?';
                    $params[] = '%' . $path . '%';
                }
            }
        }

        // Strategie einmal pro Aufruf waehlen: natives MariaDB-Vektor-Retrieval, wenn
        // verfuegbar (siehe VectorCapability - ab 11.7/11.8, Spalte/Index werden beim
        // Indexieren gepflegt), sonst die immer verfuegbare PHP-Brute-Force-Variante.
        // Beide bekommen exakt dasselbe WHERE-Fragment, damit Scope-/Profil-/URL-Filterung
        // nicht unabhaengig voneinander gepflegt werden muss.
        $strategy = $this->resolveRetrievalStrategy();
        $results = $strategy->findCandidates($userEmbedding, $whereSql, $params, $candidateLimit);

        $labelDescriptions = $this->buildSourceLabelDescriptions($profile);
        $timelyLabels = $this->buildTimelyLabelSet($profile);
        $isRecencyQuery = $timelyLabels !== [] && $this->looksLikeRecencyQuery($message);

        foreach ($results as &$result) {
            if ($result['source_type'] === 'forcal_entry') {
                $nextSummary = $this->getForcalNextOccurrenceSummaryBySourceId($result['source_id'], $this->hasForcalPastIntent($message));
                if ($nextSummary !== '') {
                    $result['content'] .= "\n\n" . $nextSummary;
                }
            }

            $resultLabel = trim((string) ($result['source_label'] ?? ''));
            $result['source_label_description'] = '' !== $resultLabel ? ($labelDescriptions[$resultLabel] ?? null) : null;
            $isTimely = '' !== $resultLabel && isset($timelyLabels[$resultLabel]);
            $result['source_label_is_timely'] = $isTimely;

            // Nutzer fragt erkennbar nach Aktuellem/Neuestem und mindestens ein als "aktuell"
            // markierter Bereich existiert (ChatProfile::$sitemapGroups) - hebt dessen Score
            // gezielt an, damit z.B. News-Inhalte einen breiteren "Allgemein"-Bereich nicht
            // automatisch verlieren, nur weil sie inhaltlich etwas weniger textaehnlich sind.
            if ($isRecencyQuery && $isTimely) {
                $result['similarity'] = min(1.0, (float) $result['similarity'] + 0.15);
            }
        }
        unset($result);

        if ($isRecencyQuery) {
            usort($results, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);
        }

        // Titel-Dedupe ZUSAETZLICH zur Source-Dedupe: mehrere Seiten mit erkennbar demselben
        // Titel (z.B. eine jaehrlich wiederholte Ankuendigung wie "KLXM macht Weihnachtsferien",
        // jedes Jahr eine eigene URL/eigener source_id) sind embedding-technisch sich selbst so
        // aehnlich, dass sie sonst mehrere/alle Top-K-Plaetze belegen - ohne Titel-Dedupe bliebe
        // dann kein Platz mehr fuer inhaltlich verschiedene, eigentlich relevantere Kandidaten
        // (siehe Nutzer-Report: "Referenzen"-Anfrage lieferte 3x dieselbe Ankuendigung statt
        // echter Projekt-Referenzen). $results ist bereits nach Similarity sortiert, die erste
        // (beste) Instanz je Titel bleibt erhalten.
        $uniqueResults = [];
        $seenSources = [];
        $seenTitles = [];
        foreach ($results as $result) {
            $sourceKey = $result['source_type'] . '|' . $result['source_id'];
            if (isset($seenSources[$sourceKey])) {
                continue;
            }

            $titleKey = mb_strtolower(trim($result['title']));
            if ('' !== $titleKey && isset($seenTitles[$titleKey])) {
                continue;
            }

            $seenSources[$sourceKey] = true;
            if ('' !== $titleKey) {
                $seenTitles[$titleKey] = true;
            }
            $uniqueResults[] = $result;

            if (count($uniqueResults) >= $limit) {
                break;
            }
        }

        return $this->trimLowSignalResults($uniqueResults, $limit);
    }

    /**
     * Baut das Scope-/Profil-Sichtbarkeits-WHERE, das findSimilarContent() UND
     * ensureKeywordMatchedContext() identisch brauchen (letztere darf keinesfalls andere
     * Zeilen sehen als die eigentliche Vektorsuche - sonst koennte per Keyword-Fallback
     * exklusiver Inhalt eines FREMDEN Profils in die Antwort gelangen). Der aktuelle-Seite-
     * Fokus (url LIKE) ist bewusst NICHT Teil davon, siehe Aufrufer.
     *
     * @return array{0: string, 1: list<mixed>, 2: bool} [whereSql, params, "scope war bekannt"]
     */
    private function buildScopeVisibilityWhere(string $scope, ?ChatProfile $profile): array
    {
        $whereSql = '';
        $params = [];

        if ($scope === 'frontend') {
            // getEnabledFrontendProviderSourceTypes() liest nur die globale Shared-Pool-
            // Freigabe - getProfileExclusiveSourceTypes() ergaenzt Quellen, die ein Profil
            // exklusiv fuer sich selbst gewaehlt hat (PDFs/YForm), unabhaengig davon (siehe
            // dortiger Kommentar; identisches Muster wie in search()).
            $frontendProviderTypes = array_values(array_unique(array_merge(
                $this->getEnabledFrontendProviderSourceTypes(),
                $this->getProfileExclusiveSourceTypes($profile),
            )));
            $frontendTypes = array_values(array_unique(array_merge(['article', 'sitemap_url'], $frontendProviderTypes)));
            $whereSql = 'source_type IN (' . implode(', ', array_fill(0, count($frontendTypes), '?')) . ')';
            $params = array_merge($params, $frontendTypes);
        } elseif ($scope === 'developer') {
            $whereSql = "source_type IN ('addon_docs', 'github_docs')";
        } else {
            return ['', [], false];
        }

        // Profil-Scope: Shared Pool (profile_id IS NULL) ist die bestehende globale
        // Indexierung und bleibt fuer jedes Profil mit use_shared_scope=1 sichtbar.
        // Ein Profil ohne Shared Scope sieht ausschliesslich seine eigenen,
        // exklusiv markierten Chunks. Kein Profil aufgeloest = unveraendertes
        // Verhalten ueber den kompletten Shared Pool (Stand vor dem Profil-Feature).
        if (null !== $profile) {
            if ($profile->useSharedScope) {
                $whereSql .= ' AND (profile_id = ? OR profile_id IS NULL)';
                $params[] = $profile->id;
            } else {
                $whereSql .= ' AND profile_id = ?';
                $params[] = $profile->id;
            }
        }

        return [$whereSql, $params, true];
    }

    /**
     * Sicherheitsnetz fuer Anfragen, bei denen die Vektorsuche an einem einzelnen, generischen
     * Substantiv vorbeitrifft (z.B. "Referenzen", "Team", "Leistungen" - ein Wort, das selbst
     * kaum Fliesstext-Aehnlichkeit zu einer EINZELNEN Unterseite hat, aber oft wortwoertlich im
     * URL-Pfad einer Kategorie steht, z.B. "/agentur/referenzen/..."). Ohne dieses Netz kann die
     * Vektorsuche fuer so eine Anfrage eine thematisch zufaellige, aber embedding-technisch
     * "naheliegende" Seite in die Top-Kandidaten heben (siehe Nutzer-Report: "Referenzen"-Anfrage
     * fand einen unabhaengigen "Weihnachtsferien"-Blogbeitrag statt echter Referenz-Unterseiten).
     *
     * Greift NUR, wenn KEIN bereits gefundener Kandidat den Suchbegriff selbst enthaelt - sonst
     * hat die Vektorsuche vermutlich schon etwas thematisch Passendes gefunden, ein zusaetzlicher
     * Volltext-Treffer wuerde dann eher verwaessern als helfen. Nutzt bewusst dieselbe
     * Sichtbarkeits-WHERE wie findSimilarContent() (buildScopeVisibilityWhere()), damit ein
     * exklusiver Inhalt eines fremden Profils hierueber nicht sichtbar werden kann.
     *
     * @param array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}> $context
     * @return array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}>
     */
    private function ensureKeywordMatchedContext(array $context, string $message, string $scope, ?ChatProfile $profile, int $maxContext): array
    {
        if ($scope !== 'frontend') {
            return $context;
        }

        $terms = $this->extractSearchTerms($message);
        if ($terms === []) {
            return $context;
        }

        // Bewusst KEIN frueher Ausstieg mehr, nur weil ein bereits gefundener Kandidat den
        // Suchbegriff selbst enthaelt (fruehere Fassung dieser Methode) - bei einer Anfrage wie
        // "Referenzen" reicht dafuer schon irgendeine Zufallsseite aus derselben URL-Kategorie
        // (z.B. "/agentur/referenzen/referenz/irgendwas"), was den Fallback praktisch immer
        // vorzeitig beendete, obwohl die Vektorsuche NUR diese eine schwache Seite gefunden
        // hatte. Ergaenzung/Dedupe unten uebernimmt stattdessen komplett, ob ein Kandidat schon
        // vorhanden ist (per source_type+source_id) - stellt sicher, dass zusaetzliche,
        // tatsaechlich per Stichwort gefundene Kandidaten den bereits vollen Kontext trotzdem
        // ergaenzen koennen (findSimilarContent() dedupt jetzt ausserdem per Titel, damit dafuer
        // auch wirklich Platz frei wird - siehe dortiger Kommentar).
        [$whereSql, $baseParams, $ok] = $this->buildScopeVisibilityWhere($scope, $profile);
        if (!$ok) {
            return $context;
        }

        $clauses = [];
        $params = $baseParams;
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $clauses[] = '(title LIKE ? OR content LIKE ? OR url LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT source_type, source_id, title, content, url
             FROM ' . rex::getTable('ai_chat_index') . '
             WHERE ' . $whereSql . ' AND (' . implode(' OR ', $clauses) . ')
             ORDER BY updatedate DESC, id DESC
             LIMIT 20',
            $params,
        );

        $seen = [];
        $keywordItems = [];
        foreach ($rows as $row) {
            $sourceType = (string) ($row['source_type'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            if ('' === $sourceType || '' === $sourceId) {
                continue;
            }

            $key = $sourceType . '|' . $sourceId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // similarity < findProviderKeywordMatches()s 1.0: ein reiner Substring-Treffer ohne
            // jede semantische Bestaetigung ist weniger verlaesslich als ein direkter
            // Provider-Tabellen-Treffer, soll aber die Mindestschwellen in
            // hasSufficientAnswerContext()/collectDisplaySources() zuverlaessig ueberschreiten.
            $keywordItems[] = [
                'content' => (string) ($row['content'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? 'Inhalt'),
                'similarity' => 0.6,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];

            if (count($keywordItems) >= $maxContext) {
                break;
            }
        }

        if ($keywordItems === []) {
            return $context;
        }

        // Keyword-Treffer duerfen schwache Vektor-Kandidaten VERDRAENGEN (nicht nur freie
        // Plaetze auffuellen) - sonst haetten sie nie eine Chance, wenn $context durch
        // findSimilarContent() bereits vollstaendig (aber nur schwach relevant) gefuellt ist,
        // genau der Fall aus dem Nutzer-Report ("Referenzen" fand 3 schwache, thematisch
        // zufaellige Kandidaten OHNE freien Platz fuer einen echten Volltext-Treffer). Ein
        // bereits GUT sitzender Vektor-Treffer (similarity >= 0.6, derselbe Schwellenwert wie
        // oben fuer Keyword-Treffer) bleibt dagegen erhalten, da eine echte semantische
        // Uebereinstimmung verlaesslicher ist als ein reiner Substring-Treffer.
        $strongVectorItems = array_values(array_filter($context, static fn(array $c): bool => $c['similarity'] >= 0.6));

        $seenAfterStrong = [];
        foreach ($strongVectorItems as $item) {
            $seenAfterStrong[$item['source_type'] . '|' . $item['source_id']] = true;
        }

        $merged = $strongVectorItems;
        foreach ($keywordItems as $item) {
            if (count($merged) >= $maxContext) {
                break;
            }
            $key = $item['source_type'] . '|' . $item['source_id'];
            if (isset($seenAfterStrong[$key])) {
                continue;
            }
            $seenAfterStrong[$key] = true;
            $merged[] = $item;
        }

        return $merged;
    }

    private function resolveRetrievalStrategy(): RetrievalStrategyInterface
    {
        return VectorCapability::isSupported() ? new NativeVectorRetrieval() : new BruteForceRetrieval();
    }

    /**
     * Label -> Beschreibung (ChatProfile::$sitemapGroups[]['description']) fuer alle benannten
     * Gruppen dieses Profils - der KI als Zusatzkontext mitgegeben (siehe PromptBuilder sowie
     * die je Provider dupizierte [Bereich: ...]-Praefix-Logik in Gemini-/Cloudflare-/
     * OpenAiCompatibleService).
     *
     * @return array<string, string>
     */
    private function buildSourceLabelDescriptions(?ChatProfile $profile): array
    {
        if (null === $profile) {
            return [];
        }

        $map = [];
        foreach ($profile->sitemapGroups as $group) {
            $label = $group['label'];
            $description = $group['description'];
            if ('' !== $label && '' !== $description) {
                $map[$label] = $description;
            }
        }

        return $map;
    }

    /**
     * Menge der Labels, deren Sitemap-Gruppe als "aktuelle/zeitkritische Inhalte" markiert ist
     * (ChatProfile::$sitemapGroups[]['is_timely'], z.B. eine News-Gruppe) - genutzt fuers
     * Score-Boosting bei erkannter Aktualitaets-Anfrage (looksLikeRecencyQuery()).
     *
     * @return array<string, true>
     */
    private function buildTimelyLabelSet(?ChatProfile $profile): array
    {
        if (null === $profile) {
            return [];
        }

        $set = [];
        foreach ($profile->sitemapGroups as $group) {
            $label = $group['label'];
            if ('' !== $label && $group['is_timely']) {
                $set[$label] = true;
            }
        }

        return $set;
    }

    /**
     * Grobe Heuristik: fragt der Nutzer erkennbar nach dem aktuellen/neuesten Stand? Bewusst
     * konservativ (nur eindeutige Signalwoerter) - ein Fehlalarm hebt hoechstens den Score
     * ohnehin schon passender News-Treffer leicht an, kein hartes Ausschlusskriterium.
     */
    private function looksLikeRecencyQuery(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ('' === $normalized) {
            return false;
        }

        $signalWords = [
            'aktuell', 'aktuelle', 'aktuellen', 'aktuelles', 'neueste', 'neuesten', 'neustes',
            'neuigkeiten', 'neuigkeit', 'zuletzt', 'kürzlich', 'gerade eben', 'jüngst', 'jüngste',
            'diese woche', 'diesen monat', 'heute', 'news',
        ];

        foreach ($signalWords as $word) {
            if (str_contains($normalized, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}> $results
     * @return array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}>
     */
    private function trimLowSignalResults(array $results, int $limit): array
    {
        if ($results === []) {
            return [];
        }

        $maxResults = max(1, $limit);
        if (count($results) <= $maxResults) {
            return $results;
        }

        $topScore = $results[0]['similarity'];
        $minSignal = max(0.12, $topScore * 0.5);
        $kept = [];

        foreach ($results as $result) {
            $score = $result['similarity'];
            if ($score < $minSignal && $kept !== []) {
                continue;
            }

            $kept[] = $result;
            if (count($kept) >= $maxResults) {
                break;
            }
        }

        // Die Schleife haengt beim ersten Durchlauf immer ein Element an (der $kept !== []-Check
        // greift erst ab der zweiten Iteration), $kept ist danach also garantiert nicht leer.
        return $kept;
    }

    /**
     * @param array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}> $context
     */
    private function hasSufficientAnswerContext(array $context, string $message, string $scope): bool
    {
        // Developer chat can always answer from the model's own knowledge and
        // system tools, even with an empty/not-yet-indexed addon/github docs index.
        if ($scope === 'developer') {
            return true;
        }

        if ($context === []) {
            return false;
        }

        $topSimilarity = (float) ($context[0]['similarity'] ?? 0.0);
        if ($topSimilarity >= 0.42) {
            return true;
        }

        $tokens = $this->extractRelevantTokens($message);
        if ($tokens === []) {
            return $topSimilarity >= 0.30;
        }

        $bestCoverage = 0.0;
        foreach ($context as $item) {
            $text = mb_strtolower(trim($item['title'] . ' ' . $item['content'] . ' ' . $item['url']));
            if ($text === '') {
                continue;
            }

            $matchedTokens = 0;
            foreach ($tokens as $token) {
                if ($token !== '' && $this->tokenMatchesText($text, $token)) {
                    $matchedTokens++;
                }
            }

            $coverage = $matchedTokens / count($tokens);
            if ($coverage > $bestCoverage) {
                $bestCoverage = $coverage;
            }
        }

        if ($bestCoverage >= 0.75 && $topSimilarity >= 0.20) {
            return true;
        }

        if ($bestCoverage >= 0.50 && $topSimilarity >= 0.28) {
            return true;
        }

        return false;
    }

    private function buildInsufficientContextAnswer(string $scope): string
    {
        if ($scope === 'developer') {
            return 'Dazu habe ich in den aktuell indizierten Entwickler- und Addon-Inhalten keine verlässlichen Informationen. Bitte präzisiere die Frage oder erweitere zuerst den Index.';
        }

        return 'Dazu habe ich in den aktuell indizierten Inhalten keine verlässlichen Informationen. Ich möchte hier nichts erfinden. Bitte formuliere die Frage genauer oder ergänze die Information im Index.';
    }

    /**
     * @return list<string>
     */
    private function getEnabledFrontendProviderSourceTypes(): array
    {
        $registry = new ContentProviderRegistry();
        $providers = $registry->getEnabledProviders(rex_addon::get('ai_chat'));

        $types = [];
        foreach ($providers as $provider) {
            foreach ($provider->getSupportedSourceTypes() as $sourceType) {
                $sourceType = trim((string) $sourceType);
                if ($sourceType === '') {
                    continue;
                }

                $types[] = $sourceType;
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($types));

        return $unique;
    }

    /**
     * Ein Profil kann YForm-Tabellen und/oder Medienpool-PDFs exklusiv fuer sich indexieren
     * (ChatProfile::$yformProfileIds/$pdfMediaIds/$pdfCategoryIds), unabhaengig davon, ob der
     * jeweilige Content-Provider auch global fuer den Shared Pool aktiviert ist
     * (getEnabledFrontendProviderSourceTypes() liest nur diese globale Einstellung). Ohne diese
     * Ergaenzung waeren exklusiv indexierte Inhalte fuer die Live-Suche (search()) unsichtbar -
     * source_type IN (...) wuerde sie aus dem WHERE herausfiltern, obwohl sie erfolgreich
     * indexiert wurden und ueber den Chat/RAG-Pfad (ohne diese Einschraenkung) bereits gefunden
     * werden.
     *
     * @return list<string>
     */
    private function getProfileExclusiveSourceTypes(?ChatProfile $profile): array
    {
        if (null === $profile) {
            return [];
        }

        $types = [];
        foreach ($profile->yformProfileIds as $yformProfileId) {
            $types[] = YformProfiles::sourceTypeForProfile($yformProfileId);
        }
        if ([] !== $profile->pdfMediaIds || [] !== $profile->pdfCategoryIds) {
            $types[] = 'mediapool_pdf';
        }

        return $types;
    }

    /**
     * @param array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}> $context
     * @return array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}>
     */
    private function ensureForcalContextForIntent(array $context, string $message, string $scope, int $maxContext): array
    {
        if ($scope !== 'frontend' || !$this->isForcalHybridEnabled()) {
            return $context;
        }

        if (!$this->hasForcalIntent($message)) {
            return $context;
        }

        foreach ($context as $item) {
            if ($item['source_type'] === 'forcal_entry') {
                return $context;
            }
        }

        $keywordMatches = $this->findForcalKeywordMatches($message, 2);
        if ($keywordMatches === []) {
            return $context;
        }

        $seen = [];
        $merged = [];

        foreach ($keywordMatches as $item) {
            $key = $item['source_type'] . '|' . $item['source_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
        }

        foreach ($context as $item) {
            $key = $item['source_type'] . '|' . $item['source_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
        }

        $maxContext = max(1, $maxContext);

        return array_slice($merged, 0, $maxContext);
    }

    private function hasForcalIntent(string $message): bool
    {
        $query = mb_strtolower(trim($message));
        if ($query === '') {
            return false;
        }

        foreach ($this->getForcalIntentKeywords() as $keyword) {
            $needle = mb_strtolower(trim($keyword));
            if ($needle === '') {
                continue;
            }

            if (preg_match('/\s/u', $needle) === 1) {
                if (str_contains($query, $needle)) {
                    return true;
                }
                continue;
            }

            if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getForcalIntentKeywords(): array
    {
        $configured = rex_addon::get('ai_chat')->getConfig('forcal_intent_keywords', '');

        $raw = '';
        if (is_array($configured)) {
            $raw = implode("\n", array_map(static fn($item): string => is_string($item) ? $item : '', $configured));
        } elseif (is_string($configured)) {
            $raw = $configured;
        }

        $parts = preg_split('/[\r\n,;]+/', $raw);
        $keywords = [];
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $keyword = trim((string) $part);
                if ($keyword !== '') {
                    $keywords[] = $keyword;
                }
            }
        }

        if ($keywords === []) {
            $keywords = ['termin', 'termine', 'kalender', 'event', 'veranstaltung', 'demnächst', 'nächste', 'letzte', 'zuletzt', 'vergangen'];
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($keywords));

        return $unique;
    }

    /**
     * @return array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}>
     */
    private function findForcalKeywordMatches(string $message, int $limit = 2): array
    {
        $terms = $this->extractSearchTerms($message);
        if ($terms === []) {
            $needle = trim($message);
            if ($needle !== '') {
                $terms = [$needle];
            }
        }

        if ($terms === []) {
            return [];
        }

        $sql = rex_sql::factory();
        $table = rex::getTable('ai_chat_index');

        $clauses = [];
        $params = [];
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $clauses[] = '(title LIKE ? OR content LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $where = implode(' OR ', $clauses);
        $rows = $sql->getArray(
            'SELECT source_type, source_id, title, content, url, updatedate
             FROM ' . $table . '
             WHERE source_type = ? AND (' . $where . ')
             ORDER BY updatedate DESC, id DESC
             LIMIT 20',
            array_merge(['forcal_entry'], $params),
        );

        $matches = [];
        $seen = [];
        foreach ($rows as $row) {
            $sourceType = (string) ($row['source_type'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            if ($sourceType === '' || $sourceId === '') {
                continue;
            }

            $key = $sourceType . '|' . $sourceId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $content = (string) ($row['content'] ?? '');
            $nextSummary = $this->getForcalNextOccurrenceSummaryBySourceId($sourceId, $this->hasForcalPastIntent($message));
            if ($nextSummary !== '') {
                $content = trim($content . "\n\n" . $nextSummary);
            }

            $matches[] = [
                'content' => $content,
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? 'forcal Termin'),
                'similarity' => 1.0,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @param array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}> $context
     * @return array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}>
     */
    private function ensureProviderContextByKeyword(array $context, string $message, string $scope, int $maxContext): array
    {
        if ($scope !== 'frontend') {
            return $context;
        }

        $providerSourceTypes = array_values(array_filter(
            $this->getEnabledFrontendProviderSourceTypes(),
            static fn (string $type): bool => $type !== 'forcal_entry',
        ));

        if ($providerSourceTypes === []) {
            return $context;
        }

        foreach ($context as $item) {
            if (in_array($item['source_type'], $providerSourceTypes, true)) {
                return $context;
            }
        }

        $keywordMatches = $this->findProviderKeywordMatches($message, $providerSourceTypes, 2);
        if ($keywordMatches === []) {
            return $context;
        }

        $seen = [];
        $merged = [];

        foreach ($keywordMatches as $item) {
            $key = $item['source_type'] . '|' . $item['source_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
        }

        foreach ($context as $item) {
            $key = $item['source_type'] . '|' . $item['source_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $item;
        }

        $maxContext = max(1, $maxContext);

        return array_slice($merged, 0, $maxContext);
    }

    /**
     * @param list<string> $sourceTypes
     * @return array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}>
     */
    private function findProviderKeywordMatches(string $message, array $sourceTypes, int $limit = 2): array
    {
        if ($sourceTypes === []) {
            return [];
        }

        $terms = $this->extractSearchTerms($message);
        if ($terms === []) {
            $needle = trim($message);
            if ($needle !== '') {
                $terms = [$needle];
            }
        }

        if ($terms === []) {
            return [];
        }

        $sql = rex_sql::factory();
        $table = rex::getTable('ai_chat_index');

        $typePlaceholders = implode(', ', array_fill(0, count($sourceTypes), '?'));
        $clauses = [];
        $params = $sourceTypes;

        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $clauses[] = '(title LIKE ? OR content LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $where = implode(' OR ', $clauses);
        $rows = $sql->getArray(
            'SELECT source_type, source_id, title, content, url, updatedate
             FROM ' . $table . '
             WHERE source_type IN (' . $typePlaceholders . ') AND (' . $where . ')
             ORDER BY updatedate DESC, id DESC
             LIMIT 20',
            $params,
        );

        $matches = [];
        $seen = [];
        foreach ($rows as $row) {
            $sourceType = (string) ($row['source_type'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            if ($sourceType === '' || $sourceId === '') {
                continue;
            }

            $key = $sourceType . '|' . $sourceId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $matches[] = [
                'content' => (string) ($row['content'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? 'Inhalt'),
                'similarity' => 1.0,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Duenne Wrapper um VectorMath - bleiben hier fuer den FAQ-Cache-Treffer-Abgleich
     * (findCachedAnswer()), der unabhaengig von der gewaehlten Retrieval-Strategie immer
     * brute-force bleibt (siehe TODO.md). Retrieval selbst (findSimilarContent()) nutzt
     * VectorMath direkt ueber BruteForceRetrieval/NativeVectorRetrieval.
     *
     * @param float[] $vector
     */
    private function vectorMagnitude(array $vector): float
    {
        return VectorMath::magnitude($vector);
    }

    /**
     * @param float[] $vec1
     * @param float[] $vec2
     */
    private function cosineSimilarity(array $vec1, array $vec2, float $magnitude1, ?float $magnitude2 = null): float
    {
        return VectorMath::cosineSimilarity($vec1, $vec2, $magnitude1, $magnitude2);
    }

    /**
     * @param float[] $userEmbedding
     */
    private function findCachedAnswer(array $userEmbedding, string $scope, ?string $currentQuestion = null): ?string
    {
        $normalizedCurrentQuestion = $this->normalizeQuestionForCache((string) ($currentQuestion ?? ''));
        $sql = rex_sql::factory();

        if ($currentQuestion !== null && $currentQuestion !== '') {
            $exactMatch = $sql->getArray(
                'SELECT answer FROM ' . rex::getTable('ai_chat_cache') . ' WHERE scope = ? AND question = ? ORDER BY id DESC LIMIT 1',
                [$scope, $currentQuestion],
            );
            if ($exactMatch !== []) {
                return (string) ($exactMatch[0]['answer'] ?? '');
            }
        }

        $candidateLimit = $this->getCacheCandidateLimit();
        $queryMagnitude = $this->vectorMagnitude($userEmbedding);
        $sql->setQuery(
            'SELECT question, answer, embedding, embedding_norm FROM ' . rex::getTable('ai_chat_cache') . ' WHERE scope = ? ORDER BY created_at DESC, id DESC LIMIT ' . $candidateLimit,
            [$scope],
        );

        foreach ($sql as $row) {
            $cachedQuestion = (string) $row->getValue('question');
            if ($normalizedCurrentQuestion !== '' && $this->questionsAreEquivalentForCache((string) ($currentQuestion ?? ''), $cachedQuestion, $normalizedCurrentQuestion)) {
                return (string) $row->getValue('answer');
            }

            /** @var mixed $decoded */
            $decoded = json_decode((string) $row->getValue('embedding'), true);
            if (!is_array($decoded)) {
                continue;
            }

            $storedMagnitude = $row->getValue('embedding_norm');
            $similarity = $this->cosineSimilarity(
                $userEmbedding,
                $decoded,
                $queryMagnitude,
                is_numeric($storedMagnitude) ? (float) $storedMagnitude : null,
            );
            $threshold = (float) rex_addon::get('ai_chat')->getConfig('cache_similarity', 0.95);
            if ($similarity >= $threshold) {
                return (string) $row->getValue('answer');
            }
        }

        return null;
    }

    /**
     * @param float[] $embedding
     */
    private function cacheAnswer(string $question, array $embedding, string $answer, string $scope): void
    {
        $normalizedQuestion = $this->normalizeQuestionForCache($question);
        if ($normalizedQuestion !== '') {
            $sql = rex_sql::factory();
            $existing = $sql->getArray(
                'SELECT question FROM ' . rex::getTable('ai_chat_cache') . ' WHERE scope = :scope ORDER BY created_at DESC, id DESC LIMIT ' . $this->getCacheCandidateLimit(),
                ['scope' => $scope],
            );

            foreach ($existing as $row) {
                $cachedQuestion = (string) ($row['question'] ?? '');
                if ($cachedQuestion === '') {
                    continue;
                }

                if ($this->questionsAreEquivalentForCache($question, $cachedQuestion, $normalizedQuestion)) {
                    return;
                }
            }
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_chat_cache'));
        $sql->setValue('question', $question);
        $sql->setValue('embedding', json_encode($embedding));
        $sql->setValue('embedding_norm', $this->vectorMagnitude($embedding));
        $sql->setValue('answer', $answer);
        $sql->setValue('scope', $scope);
        $sql->setValue('created_at', date('Y-m-d H:i:s'));
        $sql->insert();
    }

    private function normalizeQuestionForCache(string $question): string
    {
        $normalized = strtolower(trim($question));
        $normalized = preg_replace('/&nbsp;|\s+/u', ' ', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $normalized);
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $normalized));

        return (string) $normalized;
    }

    private function questionsAreEquivalentForCache(string $questionA, string $questionB, ?string $normalizedQuestionA = null): bool
    {
        $normalizedA = $normalizedQuestionA ?? $this->normalizeQuestionForCache($questionA);
        $normalizedB = $this->normalizeQuestionForCache($questionB);

        if ($normalizedA === '' || $normalizedB === '') {
            return false;
        }

        if ($normalizedA === $normalizedB) {
            return true;
        }

        $tokensA = preg_split('/\s+/u', $normalizedA, -1, PREG_SPLIT_NO_EMPTY);
        $tokensB = preg_split('/\s+/u', $normalizedB, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($tokensA) || !is_array($tokensB)) {
            return false;
        }

        $tokenSetA = array_values(array_unique($tokensA));
        $tokenSetB = array_values(array_unique($tokensB));
        $intersection = array_intersect($tokenSetA, $tokenSetB);
        // $tokenSetA ist nie leer (preg_split(...PREG_SPLIT_NO_EMPTY) liefert hier immer eine
        // non-empty-list), array_merge/array_unique koennen $union also nie auf [] reduzieren.
        $union = array_values(array_unique(array_merge($tokenSetA, $tokenSetB)));

        $similarity = count($intersection) / count($union);

        return $similarity >= 0.7;
    }

    private function parseMarkdown(string $markdown): string
    {
        $markdown = $this->normalizeEscapedLineBreaks($markdown);
        $markdown = $this->linkifyEmailAddresses($markdown);
        $parsedown = new \Parsedown();
        $parsedown->setSafeMode(true);
        $html = (string) $parsedown->text($markdown);
        $sourcesTitle = (string) rex_addon::get('ai_chat')->getConfig('sources_title', 'Links:');
        $html = str_replace('<p><strong>' . rex_escape($sourcesTitle) . ':</strong></p>', '<hr><p><strong>' . rex_escape($sourcesTitle) . ':</strong></p>', $html);

        return str_replace('<a href="', '<a target="_blank" rel="noopener noreferrer" href="', $html);
    }

    private function linkifyEmailAddresses(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\w@.\/-])([a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+)/i',
            static fn(array $match): string => '[' . $match[1] . '](mailto:' . $match[1] . ')',
            $text
        );
    }

    private function normalizeAnswerMarkdown(string $answer): string
    {
        // Einige Provider liefern escaped Newlines ("\\n") statt echter Umbrüche.
        // Für sauberes Markdown/Parsedown hier in echte Zeilenumbrüche wandeln.
        $answer = $this->normalizeEscapedLineBreaks($answer);
        $answer = $this->normalizeUtf8Text($answer);

        // Prevent Parsedown from interpreting date-like bullets (e.g. "- 01. August")
        // as nested ordered lists with empty bullet wrappers.
        $dateBulletFixed = preg_replace('/^(\s*[-*]\s+)(\d{1,2})\.(\s+)/m', '$1$2\\.$3', $answer);
        if (is_string($dateBulletFixed)) {
            $answer = $dateBulletFixed;
        }

        // Defense-in-depth zusaetzlich zur Prompt-Anweisung (PromptBuilder::markdownFormattingInstruction()):
        // LLMs trennen Listen von vorangehendem Fliesstext oft nur mit einem einzelnen
        // Zeilenumbruch statt einer Leerzeile - nach CommonMark/Parsedown ist das eine reine
        // Fortsetzung desselben Absatzes ("lazy continuation"), die Liste wird dann nie als
        // <ol>/<ul> erkannt, sondern zu Fliesstext mit sichtbaren "1." / "-" verschmolzen. Vor
        // jeder Listenzeile, die nicht schon durch eine Leerzeile abgetrennt ist, eine Leerzeile
        // einfuegen - trifft auch aufeinanderfolgende Listenpunkte (harmlos, ergibt hoechstens
        // eine "loose list" mit etwas mehr Absatz je Punkt statt eines Formatierungsfehlers).
        $listBlockFixed = preg_replace('/(?<=\S)\n([ \t]{0,3}(?:\d{1,3}\.|[-*])\s)/', "\n\n$1", $answer);
        if (is_string($listBlockFixed)) {
            $answer = $listBlockFixed;
        }

        $sourcesTitle = (string) rex_addon::get('ai_chat')->getConfig('sources_title', 'Links:');
        $titlePattern = preg_quote($sourcesTitle, '/');

        // Keep sources heading out of previous list/paragraph flow.
        $inlineSourcesFixed = preg_replace('/([^\n])\s+(\*\*' . $titlePattern . '\*\*)/u', '$1\n\n$2', $answer);
        if (is_string($inlineSourcesFixed)) {
            $answer = $inlineSourcesFixed;
        }

        $singleBreakSourcesFixed = preg_replace('/([^\n])\n(\*\*' . $titlePattern . '\*\*)/u', '$1\n\n$2', $answer);
        if (is_string($singleBreakSourcesFixed)) {
            $answer = $singleBreakSourcesFixed;
        }

        $sourceSeparatorFixed = preg_replace('/\n{2}(\*\*' . $titlePattern . '\*\*)/u', "\n\n---\n\n$1", $answer, 1);
        if (is_string($sourceSeparatorFixed)) {
            $answer = $sourceSeparatorFixed;
        }

        return $answer;
    }

    private function normalizeEscapedLineBreaks(string $text): string
    {
        $text = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $text);

        $patterns = [
            '/\\\\r\\\\n/u' => "\n",
            '/\\\\n/u' => "\n",
            '/\\\\r/u' => "\n",
            '/\\\\+n/u' => "\n",
            '/\\\\+r/u' => "\n",
        ];

        foreach ($patterns as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $text);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        return $text;
    }

    private function removeSourcesSection(string $answer): string
    {
        $answer = $this->normalizeUtf8Text($answer);

        $sourcesTitle = (string) rex_addon::get('ai_chat')->getConfig('sources_title', 'Links:');
        $titlePattern = preg_quote($sourcesTitle, '/');

        $pattern = '/\n{2}\*\*' . $titlePattern . '\*\*\n(?:- \[[^\]]+\]\([^\)]+\)\n?)+$/';
        $stripped = preg_replace($pattern, '', $answer);

        if (is_string($stripped)) {
            $answer = $stripped;
        }

        // Safety net: remove trailing markdown citation blocks even if title differs.
        $fallbackPattern = '/\n{2}(?:\*\*[^\n]+\*\*\n)?(?:- \[[^\]]+\]\([^\)]+\)\n?){2,}$/';
        $strippedFallback = preg_replace($fallbackPattern, '', $answer);

        return is_string($strippedFallback) ? rtrim($strippedFallback) : rtrim($answer);
    }

    private function isFaqPrecacheEnabled(): bool
    {
        $raw = rex_addon::get('ai_chat')->getConfig('faq_precache_enabled', null);

        if (is_int($raw)) {
            return $raw === 1;
        }

        if (is_string($raw)) {
            $normalized = trim($raw);

            return $normalized === '1' || $normalized === '|1|';
        }

        return $raw === true;
    }

    /**
     * @return list<string>
     */
    private function getFaqPrecacheQuestions(): array
    {
        $raw = rex_addon::get('ai_chat')->getConfig('faq_precache_questions', '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines)) {
            return [];
        }

        $questions = [];
        foreach ($lines as $line) {
            $question = trim((string) $line);
            if ($question === '' || str_starts_with($question, '#')) {
                continue;
            }

            $questions[] = $question;
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($questions));

        return $unique;
    }

    private function hasExactCachedQuestion(string $question, string $scope): bool
    {
        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT id FROM ' . rex::getTable('ai_chat_cache') . ' WHERE scope = :scope AND question = :question LIMIT 1',
            [
                'scope' => $scope,
                'question' => $question,
            ],
        );

        return $rows !== [];
    }

    private function normalizeUtf8Text(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        return $text;
    }

    /**
     * @param array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}> $context
     */
    private function appendSourcesFromContext(string $answer, array $context, string $message, string $scope, bool $showSources): string
    {
        if (!$showSources) {
            return $answer;
        }

        $sources = $this->collectDisplaySources($context, $message, $scope);
        if ($sources === []) {
            return $answer;
        }

        $sourcesTitle = (string) rex_addon::get('ai_chat')->getConfig('sources_title', 'Links:');
        $answer .= "\n\n**" . $sourcesTitle . "**\n";
        foreach ($sources as $url => $title) {
            $answer .= "- [$title]($url)\n";
        }

        return $answer;
    }

    /**
     * @param array<int, array{content: string, url: string, title: string, similarity: float, source_type: string, source_id: string}> $context
     * @return array<string, string>
     */
    private function collectDisplaySources(array $context, string $message, string $scope): array
    {
        if ($context === []) {
            return [];
        }

        $sorted = $context;
        usort($sorted, static fn(array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        // Unterhalb dieser Schwelle ist der beste Treffer so schwach, dass ein Link
        // eher irreführend als hilfreich wäre (z.B. thematisch zufällige Seite bei
        // einer Anfrage, zu der es keinen wirklich passenden Inhalt gibt).
        $topSimilarity = $sorted[0]['similarity'];
        if ($topSimilarity < 0.20) {
            return [];
        }

        $messageTokens = $this->extractRelevantTokens($message);
        $relevantSorted = [];
        foreach ($sorted as $item) {
            if ($messageTokens === []) {
                $relevantSorted[] = $item;
                continue;
            }

            $text = strtolower($item['title'] . ' ' . $item['content'] . ' ' . $item['url']);
            $matches = 0;
            foreach ($messageTokens as $token) {
                if ($token === '') {
                    continue;
                }
                if ($this->tokenMatchesText($text, $token)) {
                    $matches++;
                }
            }

            if ($matches > 0 || $item['source_type'] === 'forcal_entry') {
                $relevantSorted[] = $item;
            }
        }

        if ($relevantSorted === []) {
            // Kein einziger Kandidat teilt ein Wort mit der Frage: nur bei wirklich hoher
            // semantischer Ähnlichkeit trotzdem einen Link anbieten, sonst lieber keinen.
            if ($topSimilarity < 0.35) {
                return [];
            }

            $relevantSorted = $sorted;
        }

        $sorted = $relevantSorted;
        $maxSources = 3;
        $selected = [];

        if ($scope === 'frontend' && $this->hasForcalIntent($message)) {
            foreach ($sorted as $item) {
                if ($item['source_type'] !== 'forcal_entry') {
                    continue;
                }

                $selected[] = $item;
                if (count($selected) >= $maxSources) {
                    break;
                }
            }
        }

        if ($selected === []) {
            $topSimilarity = $sorted[0]['similarity'];
            $minSimilarity = max(0.18, $topSimilarity * 0.72);

            foreach ($sorted as $item) {
                if (count($selected) >= $maxSources) {
                    break;
                }

                $similarity = $item['similarity'];
                if ($similarity < $minSimilarity && count($selected) > 0) {
                    continue;
                }

                $selected[] = $item;
            }
        }

        // $selected ist an dieser Stelle nie leer: der Block oben haengt bei nicht-leerem
        // $sorted (garantiert, siehe oben) beim ersten Schleifendurchlauf immer ein Element an.
        //
        // $selected kann mehrere Eintraege fuer DIESELBE Seite enthalten: (a) mehrere
        // Embedding-Chunks desselben Artikels koennen unabhaengig voneinander unter den
        // Top-Treffern landen, oder (b) dieselbe Seite wurde ueber zwei Indexierungs-Quellen
        // gleichzeitig erfasst (z.B. "Struktur" UND "Sitemap" beide aktiv, siehe Einstellungen
        // -> Indexierung) und landet dadurch mit LEICHT UNTERSCHIEDLICHER URL (z.B. einmal
        // yrewrite-Pfad, einmal Sitemap-URL) als zwei separate Zeilen im Index. Ohne die
        // Normalisierung/Dedupe unten erschien dieselbe Seite dann als 2-3 fast identisch
        // aussehende "Quellen"-Links hintereinander (siehe Nutzer-Report: dreimal derselbe
        // Linktext "KLXM macht Weihnachtsferien").
        $sources = [];
        $seenTitles = [];
        foreach ($selected as $item) {
            $url = trim($item['url']);
            $title = trim($item['title']);
            if ($url === '' || $title === '') {
                continue;
            }

            // Interne Platzhalter-Schemas (z.B. "yform://tabelle/123/slug" oder "forcal://entry/123")
            // sind Fallback-Werte für nicht konfigurierte URL-Zuordnungen (siehe YForm-Mappings-Seite)
            // und wurden nie in eine echte, aufrufbare Frontend-URL übersetzt. Als klickbarer Link
            // sind sie für Website-Besucher immer kaputt/bedeutungslos - lieber komplett weglassen.
            if (preg_match('/^https?:\/\//i', $url) !== 1) {
                continue;
            }

            // URL normalisieren (Query-String/Fragment/trailing Slash weg, lowercase Host) - fängt
            // Duplikate ab, die sich nur in ?utm=..., #anchor oder einem trailing "/" unterscheiden.
            $normalizedUrl = $this->normalizeUrlForDeduplication($url);

            // Zusätzlich per (normalisiertem) Titel entdoppeln: zwei Zeilen mit erkennbar
            // demselben Seitentitel aber technisch unterschiedlicher URL (Fall b oben) sind für
            // den Besucher trotzdem "derselbe Link" - lieber den ähnlichsten (zuerst gesehenen,
            // da $selected bereits nach Similarity sortiert ist) statt beide zu zeigen.
            $normalizedTitle = mb_strtolower(trim($title));
            if (isset($seenTitles[$normalizedTitle]) || isset($sources[$normalizedUrl])) {
                continue;
            }
            $seenTitles[$normalizedTitle] = true;

            $sources[$normalizedUrl] = ['url' => $url, 'title' => $title];
        }

        return array_combine(
            array_map(static fn(array $s): string => $s['url'], $sources),
            array_map(static fn(array $s): string => $s['title'], $sources),
        );
    }

    /**
     * Normalisiert eine URL für den Duplikat-Vergleich in collectDisplaySources() - NICHT für
     * die Anzeige/den Klick selbst (dafür bleibt die Original-URL erhalten). Entfernt
     * Query-String, Fragment und einen trailing "/", vereinheitlicht Schema/Host auf
     * Kleinschreibung - fängt damit z.B. "https://x.de/seite/" vs. "https://X.de/seite" als
     * dieselbe Seite ab, ohne eine echte URL-Normalisierungs-Bibliothek zu benötigen.
     */
    private function normalizeUrlForDeduplication(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return mb_strtolower(trim($url));
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $scheme . '://' . $host . $path;
    }

    /**
     * Prüft, ob $token in $text vorkommt – exakt oder (bei Wörtern ab 5 Zeichen, z.B. Namen)
     * tolerant gegenüber kleinen Tippfehlern per Levenshtein-Distanz. Verhindert, dass ein
     * einzelner Buchstabendreher (z.B. "Pattrick" statt "Patrick") die Kontext-Erkennung komplett
     * verfehlen lässt, obwohl inhaltlich derselbe Name gemeint ist.
     */
    private function tokenMatchesText(string $text, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        if (str_contains($text, $token)) {
            return true;
        }

        $tokenLength = mb_strlen($token, 'UTF-8');
        if ($tokenLength < 5) {
            return false;
        }

        $maxDistance = $tokenLength >= 8 ? 2 : 1;

        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
        foreach ($matches[0] as $word) {
            if (abs(mb_strlen($word, 'UTF-8') - $tokenLength) > $maxDistance) {
                continue;
            }
            if (levenshtein($token, $word) <= $maxDistance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bewusst umfangreiche Liste kurzer, hochfrequenter Funktionswörter (Artikel, Pronomen,
     * Präpositionen, Konjunktionen, Hilfsverb-Formen). Ohne diese teilen fast ALLE Nachrichten
     * ("das", "ist", "der", "hast" ...) irgendein Wort mit praktisch jedem indexierten Inhalt,
     * wodurch sowohl der Relevanz-Check als auch SQL-LIKE-Keyword-Suchen faktisch wirkungslos
     * werden und thematisch völlig unpassende Inhalte als "relevant" durchrutschen.
     *
     * @return list<string>
     */
    private function getGermanStopwords(): array
    {
        return [
            'und', 'oder', 'mit', 'dass', 'diese', 'dieser', 'diesen', 'dieses', 'diesem',
            'über', 'dazu', 'dann', 'mehr', 'wird', 'sind', 'werden', 'wurde', 'wurden',
            'der', 'die', 'das', 'des', 'dem', 'den', 'ein', 'eine', 'einer', 'einen', 'einem', 'eines',
            'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr', 'mich', 'dich', 'sich', 'uns', 'euch',
            'ist', 'war', 'waren', 'bin', 'bist', 'sei', 'seid', 'hat', 'hast', 'habe', 'habt', 'haben', 'hatte', 'hatten',
            'kann', 'kannst', 'können', 'könnte', 'soll', 'sollst', 'sollen', 'sollte', 'muss', 'musst', 'müssen', 'will', 'willst', 'wollen',
            'in', 'an', 'auf', 'bei', 'nach', 'von', 'vom', 'zu', 'zum', 'zur', 'für', 'aus', 'bis', 'als', 'wie', 'wenn', 'weil', 'ob',
            'nicht', 'kein', 'keine', 'auch', 'nur', 'noch', 'schon', 'sehr', 'so', 'aber', 'doch', 'also',
        ];
    }

    /**
     * @return list<string>
     */
    private function extractRelevantTokens(string $message): array
    {
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', strtolower(trim($message))) ?? '';
        $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopwords = $this->getGermanStopwords();
        $filtered = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') <= 3) {
                continue;
            }
            if (in_array($token, $stopwords, true)) {
                continue;
            }
            $filtered[] = $token;
        }

        return array_values(array_unique($filtered));
    }

    private function isShowSourcesEnabled(): bool
    {
        $raw = rex_addon::get('ai_chat')->getConfig('show_sources', null);
        if (is_int($raw)) {
            return $raw === 1;
        }

        if (is_string($raw)) {
            $normalized = trim($raw);

            return $normalized === '1' || $normalized === '|1|';
        }

        return $raw === true;
    }

    private function validateOrigin(): void
    {
        if (rex::getUser()) {
            return;
        }

        $server = rtrim(rex::getServer(), '/');
        if ($server === '') {
            return;
        }

        $parsed = parse_url($server);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return;
        }

        $serverScheme = strtolower((string) ($parsed['scheme'] ?? 'https'));
        $serverHost = strtolower((string) $parsed['host']);
        $serverPort = isset($parsed['port']) ? (int) $parsed['port'] : $this->defaultPort($serverScheme);

        $origin = rex_server('HTTP_ORIGIN', 'string', '');
        $referer = rex_server('HTTP_REFERER', 'string', '');

        if ($origin !== '') {
            if (!$this->isSameOrigin($origin, $serverScheme, $serverHost, $serverPort)) {
                throw new \Exception('Forbidden');
            }
            return;
        }

        if ($referer !== '') {
            if (!$this->isSameOrigin($referer, $serverScheme, $serverHost, $serverPort)) {
                throw new \Exception('Forbidden');
            }
            return;
        }

        throw new \Exception('Forbidden');
    }

    private function isStatsLoggingEnabled(): bool
    {
        return (bool) rex_addon::get('ai_chat')->getConfig('stats_logging_enabled', true);
    }

    private function recordUsageStat(string $mode, string $scope, string $query, string $status, int $hitCount = 0, ?int $profileId = null): void
    {
        $normalized = trim($this->normalizeStatQuery($query));
        if ($normalized === '') {
            return;
        }

        if (mb_strlen($normalized, 'UTF-8') < 3) {
            return;
        }

        if ($mode === 'search' && $this->isLikelyPartialTyping($scope, $normalized)) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('ai_chat_stats'));
        $sql->setValue('mode', $mode);
        $sql->setValue('scope', $scope);
        $sql->setValue('status', $status);
        $sql->setValue('query', $normalized);
        $sql->setValue('normalized_query', $normalized);
        $sql->setValue('hit_count', max(0, (int) $hitCount));
        $sql->setValue('profile_id', $profileId);
        $sql->setValue('created_at', date('Y-m-d H:i:s'));
        $sql->insert();
    }

    private function isLikelyPartialTyping(string $scope, string $query): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $key = 'ai_chat_last_stat_' . $scope;
        $state = $_SESSION[$key] ?? ['query' => '', 'time' => 0.0];
        $now = microtime(true);
        $previous = (string) ($state['query'] ?? '');
        $lastTime = (float) ($state['time'] ?? 0.0);

        if ($previous !== '' && ($now - $lastTime) < 2.5) {
            $prefixMatch = str_starts_with($query, $previous) || str_starts_with($previous, $query);
            if ($prefixMatch) {
                $_SESSION[$key] = ['query' => $query, 'time' => $now];
                return true;
            }
        }

        $_SESSION[$key] = ['query' => $query, 'time' => $now];
        return false;
    }

    private function normalizeStatQuery(string $query): string
    {
        $cleaned = preg_replace('/\s+/u', ' ', trim((string) $query));
        $cleaned = preg_replace('/\s+(\?|\!|\.|,|;|:)/u', '$1', (string) $cleaned);
        $cleaned = trim((string) $cleaned);

        return $cleaned === '' ? '' : $cleaned;
    }

    private function checkRateLimit(): void
    {
        if (rex::getUser() && rex::getUser()->isAdmin()) {
            return;
        }

        $limit = (int) rex_addon::get('ai_chat')->getConfig('rate_limit', 10);
        if ($limit <= 0) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $sessionId = (string) session_id();
        $ip = rex_server('REMOTE_ADDR', 'string', '0.0.0.0');

        $sql = rex_sql::factory();
        $table = rex::getTable('ai_chat_ratelimit');

        $oneMinuteAgo = date('Y-m-d H:i:s', time() - 60);
        $sql->setQuery('SELECT COUNT(*) as count FROM ' . $table . ' WHERE ip = ? AND session_id = ? AND created_at >= ?', [$ip, $sessionId, $oneMinuteAgo]);
        $count = (int) $sql->getValue('count');

        if ($count >= $limit) {
            throw new \Exception('Zu viele Anfragen. Bitte warten Sie einen Moment.');
        }

        $sql->setTable($table);
        $sql->setValue('ip', $ip);
        $sql->setValue('session_id', $sessionId);
        $sql->setValue('created_at', date('Y-m-d H:i:s'));
        $sql->insert();

        if (rand(1, 20) === 1) {
            $sql->setQuery('DELETE FROM ' . $table . ' WHERE created_at < ?', [$oneMinuteAgo]);
        }
    }

    private function isSameOrigin(string $value, string $serverScheme, string $serverHost, int $serverPort): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : $this->defaultPort($scheme);

        return $scheme === $serverScheme && $host === $serverHost && $port === $serverPort;
    }

    private function defaultPort(string $scheme): int
    {
        return $scheme === 'http' ? 80 : 443;
    }

    private function getRagCandidateLimit(int $limit, bool $hasUrlContext): int
    {
        $default = $hasUrlContext ? 800 : 1500;
        $configured = (int) rex_addon::get('ai_chat')->getConfig('rag_candidate_limit', $default);
        $min = max(20, $limit);
        // Bewusst großzügig: Ein zu niedriges Limit schneidet relevante, aber selten
        // aktualisierte Inhalte (z.B. eine Kontaktseite) vor dem Ähnlichkeitsvergleich
        // komplett ab, was zu scheinbar zufälligen Treffern führt.
        $max = $hasUrlContext ? 2500 : 6000;

        return max($min, min($configured, $max));
    }

    private function getCacheCandidateLimit(): int
    {
        $configured = (int) rex_addon::get('ai_chat')->getConfig('cache_candidate_limit', 300);

        return max(50, min($configured, 2000));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function search(string $query, array $input, string $scope, ?ChatProfile $profile = null, ?AiServiceInterface $aiService = null): array
    {
        $needle = trim($query);
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(5, min($limit, 50));
        $terms = $this->extractSearchTerms($needle);

        $allowedSourceTypes = [];
        if ($scope === 'frontend') {
            $allowedSourceTypes = array_values(array_unique(array_merge(
                ['article', 'sitemap_url'],
                $this->getEnabledFrontendProviderSourceTypes(),
                $this->getProfileExclusiveSourceTypes($profile),
            )));
        } elseif ($scope === 'developer') {
            $allowedSourceTypes = ['addon_docs', 'github_docs'];
        }

        if ($allowedSourceTypes === []) {
            rex_logger::logError(E_USER_WARNING, 'AiChat Search: Unknown scope "' . $scope . '". Returning empty result.', __FILE__, __LINE__);
            return [
                'mode' => 'search',
                'query' => $needle,
                'hits' => [],
                'filters' => [
                    'source_types' => [],
                    'labels' => [],
                ],
                'summary' => null,
                'summary_available' => false,
            ];
        }

        $selectedTypes = $this->extractSourceTypes($input);

        if ($needle === '') {
            return [
                'mode' => 'search',
                'query' => '',
                'hits' => [],
                'filters' => [
                    'source_types' => [],
                    'labels' => [],
                ],
                'summary' => null,
                'summary_available' => false,
            ];
        }

        $sql = rex_sql::factory();
        $table = rex::getTable('ai_chat_index');
        $typePlaceholders = implode(', ', array_fill(0, count($allowedSourceTypes), '?'));
        $params = $allowedSourceTypes;
        $textClauses = [];

        if ($terms === []) {
            $like = '%' . $needle . '%';
            $textClauses[] = '(title LIKE ? OR content LIKE ? OR url LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        } else {
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                $textClauses[] = '(title LIKE ? OR content LIKE ? OR url LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        $textWhere = implode(' OR ', $textClauses);

        // Selbes Profil-Scope-Prinzip wie findSimilarContent() (siehe dortiger
        // Kommentar): ohne das wuerde ein Profil mit use_shared_scope=0
        // (isolierter Wissensstand) trotzdem Treffer aus dem Shared Pool UND aus
        // fremden Profilen sehen, sobald der source_type passt - die Live-Suche
        // kannte bisher gar keine Profil-Grenze.
        $profileWhere = '';
        if (null !== $profile) {
            if ($profile->useSharedScope) {
                $profileWhere = ' AND (profile_id = ? OR profile_id IS NULL)';
                $params[] = $profile->id;
            } else {
                $profileWhere = ' AND profile_id = ?';
                $params[] = $profile->id;
            }
        }

        $rows = $sql->getArray(
            'SELECT id, source_type, title, content, url, updatedate
                                        , source_id, source_label
             FROM ' . $table . '
             WHERE source_type IN (' . $typePlaceholders . ')
               AND (' . $textWhere . ')' . $profileWhere . '
             ORDER BY updatedate DESC, id DESC
             LIMIT 300',
            $params,
        );

        $addon = rex_addon::get('ai_chat');
        $providerRegistry = new ContentProviderRegistry();

        $facets = [];
        $facetSourceMap = [];
        $labelFacets = [];
        $labelFacetSourceMap = [];
        $selectedLabels = $this->extractSourceLabels($input);
        $candidatesBySource = [];

        foreach ($rows as $row) {
            $sourceType = (string) ($row['source_type'] ?? 'unknown');
            if ($sourceType === '') {
                $sourceType = 'unknown';
            }

            $sourceId = (string) ($row['source_id'] ?? '');
            if ($sourceId === '') {
                $sourceId = (string) ($row['id'] ?? '');
            }
            $sourceKey = $sourceType . '|' . $sourceId;
            $sourceLabel = trim((string) ($row['source_label'] ?? ''));

            if (!isset($facetSourceMap[$sourceType])) {
                $facetSourceMap[$sourceType] = [];
            }
            if (!isset($facetSourceMap[$sourceType][$sourceKey])) {
                $facetSourceMap[$sourceType][$sourceKey] = true;
                if (!isset($facets[$sourceType])) {
                    $facets[$sourceType] = 0;
                }
                $facets[$sourceType]++;
            }

            // Eigene Facette fuer benannte Sitemap-Gruppen (ChatProfile::$sitemapGroups,
            // z.B. "News") - unabhaengig vom source_type-Filter oben, damit "News" auch dann
            // waehlbar bleibt, wenn mehrere Quellenarten unter demselben Label auftauchen.
            if ('' !== $sourceLabel) {
                if (!isset($labelFacetSourceMap[$sourceLabel])) {
                    $labelFacetSourceMap[$sourceLabel] = [];
                }
                if (!isset($labelFacetSourceMap[$sourceLabel][$sourceKey])) {
                    $labelFacetSourceMap[$sourceLabel][$sourceKey] = true;
                    if (!isset($labelFacets[$sourceLabel])) {
                        $labelFacets[$sourceLabel] = 0;
                    }
                    $labelFacets[$sourceLabel]++;
                }
            }

            if ($selectedTypes !== [] && !in_array($sourceType, $selectedTypes, true)) {
                continue;
            }

            if ($selectedLabels !== [] && !in_array($sourceLabel, $selectedLabels, true)) {
                continue;
            }

            $title = (string) ($row['title'] ?? 'Ohne Titel');
            $content = (string) ($row['content'] ?? '');
            $url = (string) ($row['url'] ?? '');

            $score = $this->scoreSearchHit($needle, $title, $content, $url, $terms);

            if ($score <= 0.0) {
                continue;
            }

            $candidate = [
                'id' => (string) ($row['id'] ?? ''),
                'type' => $sourceType,
                'type_label' => $this->getSourceTypeLabel($sourceType),
                'icon_svg' => $providerRegistry->getSearchIconSvgForSourceType($addon, $sourceType),
                'title' => $title,
                'url' => $url,
                'snippet' => $this->createSnippet($content, $needle, $terms),
                'updatedate' => (string) ($row['updatedate'] ?? ''),
                'score' => $score,
                'source_id' => $sourceId,
                'label' => '' !== $sourceLabel ? $sourceLabel : null,
            ];

            if (trim($candidate['icon_svg']) === '') {
                $candidate['icon_svg'] = $this->getDefaultSourceTypeIconSvg($sourceType);
            }

            if (!isset($candidatesBySource[$sourceKey])) {
                $candidatesBySource[$sourceKey] = $candidate;
                continue;
            }

            $existing = $candidatesBySource[$sourceKey];
            $replace = false;
            if ($candidate['score'] > $existing['score']) {
                $replace = true;
            } elseif (
                $candidate['score'] === $existing['score']
                && strcmp($candidate['updatedate'], $existing['updatedate']) > 0
            ) {
                $replace = true;
            }

            if ($replace) {
                $candidatesBySource[$sourceKey] = $candidate;
            }
        }

        $candidates = array_values($candidatesBySource);

        usort($candidates, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strcmp($b['updatedate'], $a['updatedate']);
            }

            return $b['score'] <=> $a['score'];
        });

        // "Alle" (kein Bereich-Filter aktiv): ohne Durchmischung wuerde ein grosser Bereich
        // (z.B. eine umfangreiche allgemeine Sitemap) einen kleineren, thematisch aber
        // relevanten Bereich (z.B. "News") im Ranking komplett verdraengen koennen. Bei einem
        // expliziten Label-Filter ist die Kandidatenliste ohnehin schon auf die gewaehlten
        // Bereiche eingeschraenkt - dort bleibt die reine Score-Sortierung unveraendert.
        $hits = $selectedLabels === []
            ? $this->diversifyCandidatesByLabel($candidates, $limit)
            : array_slice($candidates, 0, $limit);

        foreach ($hits as $index => $hit) {
            if (($hit['type'] ?? '') !== 'forcal_entry') {
                continue;
            }

            $nextSummary = $this->getForcalNextOccurrenceSummaryBySourceId((string) ($hit['source_id'] ?? ''), $this->hasForcalPastIntent($query));
            if ($nextSummary !== '') {
                $hits[$index]['snippet'] = $nextSummary;
            }
        }

        $filterItems = [];
        ksort($facets);
        foreach ($facets as $type => $count) {
            $filterItems[] = [
                'value' => $type,
                'label' => $this->getSourceTypeLabel($type),
                'count' => $count,
                'active' => $selectedTypes === [] || in_array($type, $selectedTypes, true),
            ];
        }

        $labelDescriptions = $this->buildSourceLabelDescriptions($profile);
        $labelItems = [];
        ksort($labelFacets);
        foreach ($labelFacets as $label => $count) {
            $labelItems[] = [
                'value' => $label,
                'label' => $label,
                'count' => $count,
                'active' => $selectedLabels === [] || in_array($label, $selectedLabels, true),
                'description' => $labelDescriptions[$label] ?? null,
            ];
        }

        // KI-Zusammenstellung ueber die "Alle"-Ansicht: NICHT mehr hier blockierend erzeugt (das
        // liess JEDE Suche auf einen vollen KI-Aufruf warten, bevor auch nur die Trefferliste
        // ankam - spuerbar langsamer, fuehlte sich nicht mehr wie Live-Suche an). Stattdessen nur
        // eine guenstige Eignungspruefung; ai-search.js zeigt die Treffer sofort und holt die
        // Zusammenstellung ueber einen separaten, asynchronen mode=search_summary-Folgeaufruf
        // nach - exakt dasselbe Muster wie shouldAskChat()/fetchChatAnswer() fuer erkannte Fragen.
        $summaryAvailable = $selectedLabels === []
            && $this->isSearchAiSummaryEnabled()
            && $this->countDistinctHitLabels($hits) >= 2;

        return [
            'mode' => 'search',
            'query' => $needle,
            'hits' => $hits,
            'filters' => [
                'source_types' => $filterItems,
                // Nur befuellt, wenn mindestens ein Treffer aus einer benannten Sitemap-Gruppe
                // stammt (ChatProfile::$sitemapGroups) - sonst leer, kein zusaetzlicher, immer
                // leerer Filter-"Reiter" in der Such-UI.
                'labels' => $labelItems,
            ],
            'summary' => null,
            'summary_available' => $summaryAvailable,
        ];
    }

    /**
     * @param list<array<string, mixed>> $hits
     */
    private function countDistinctHitLabels(array $hits): int
    {
        $labels = [];
        foreach ($hits as $hit) {
            $labels[(string) ($hit['label'] ?? '')] = true;
        }

        return count($labels);
    }

    /**
     * Durchmischt score-sortierte Suchkandidaten nach source_label, damit ein grosser Bereich
     * (z.B. "Allgemein") einen kleineren (z.B. "News") in der "Alle"-Ansicht nicht komplett
     * verdraengt - reines Round-Robin ueber die Bereiche (inkl. eines "kein Label"-Eimers fuer
     * den Shared Pool), Reihenfolge INNERHALB eines Bereichs bleibt score-sortiert.
     *
     * @param list<array<string, mixed>> $candidates Bereits nach Score sortiert (siehe usort oben)
     * @return list<array<string, mixed>>
     */
    private function diversifyCandidatesByLabel(array $candidates, int $limit): array
    {
        if ($candidates === []) {
            return [];
        }

        $buckets = [];
        $bucketOrder = [];
        foreach ($candidates as $candidate) {
            $bucketKey = (string) ($candidate['label'] ?? '');
            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [];
                $bucketOrder[] = $bucketKey;
            }
            $buckets[$bucketKey][] = $candidate;
        }

        // Nur EIN Bereich (oder nur der Shared Pool) vorhanden - Durchmischung haette keinen
        // Effekt ausser unnoetiger Komplexitaet, einfach die bestehende Score-Reihenfolge nutzen.
        if (count($bucketOrder) <= 1) {
            return array_slice($candidates, 0, $limit);
        }

        $hits = [];
        $cursor = array_fill_keys($bucketOrder, 0);
        while (count($hits) < $limit) {
            $addedAny = false;
            foreach ($bucketOrder as $bucketKey) {
                if (count($hits) >= $limit) {
                    break;
                }
                $position = $cursor[$bucketKey];
                if (!isset($buckets[$bucketKey][$position])) {
                    continue;
                }
                $hits[] = $buckets[$bucketKey][$position];
                $cursor[$bucketKey]++;
                $addedAny = true;
            }
            if (!$addedAny) {
                break;
            }
        }

        return $hits;
    }

    private function isSearchAiSummaryEnabled(): bool
    {
        $raw = rex_addon::get('ai_chat')->getConfig('search_ai_summary_enabled', null);

        if (is_string($raw)) {
            return trim($raw) === '1';
        }

        return false;
    }

    /**
     * Kurze KI-generierte Zusammenstellung der relevantesten Punkte je Bereich, oberhalb der
     * regulaeren Trefferliste angezeigt (siehe assets/ai-search.js). Bewusst best-effort: ein
     * Fehler hier darf die eigentliche (bereits fertige) Suche nicht scheitern lassen.
     *
     * @param list<array<string, mixed>> $hits
     */
    private function buildSearchSummary(string $query, array $hits, AiServiceInterface $aiService): ?string
    {
        if ($hits === []) {
            return null;
        }

        $labelsPresent = [];
        foreach ($hits as $hit) {
            $labelsPresent[(string) ($hit['label'] ?? '')] = true;
        }

        // Nur bei tatsaechlich mehreren Bereichen unter den Treffern sinnvoll - sonst fasst die
        // Zusammenstellung nur einen einzelnen Bereich zusammen, was die Trefferliste bereits
        // leistet.
        if (count($labelsPresent) < 2) {
            return null;
        }

        $context = [];
        foreach (array_slice($hits, 0, 8) as $hit) {
            $label = (string) ($hit['label'] ?? '');
            $context[] = [
                'content' => trim((string) ($hit['title'] ?? '') . ': ' . (string) ($hit['snippet'] ?? '')),
                'url' => (string) ($hit['url'] ?? ''),
                'title' => (string) ($hit['title'] ?? ''),
                'source_label' => '' !== $label ? $label : null,
            ];
        }

        $prompt = 'Fasse in maximal 3-4 kurzen Saetzen zusammen, was zur Suchanfrage "' . $query . '" in den verschiedenen Bereichen zu finden ist. '
            . 'Nenne dabei erkennbar den jeweiligen Bereich (z.B. "In Allgemein ...", "Unter News ..."). Keine Einleitung, keine Liste, reiner Fliesstext.';

        try {
            $summary = trim($aiService->generateAnswer($prompt, $context, 'frontend'));
        } catch (\Exception $e) {
            rex_logger::logException($e);

            return null;
        }

        if ('' === $summary) {
            return null;
        }

        // Wie 'answer' bei mode=chat bereits fertig zu sicherem HTML gerendert (siehe
        // process()/$answerHtml) - ai-search.js setzt beides gleichermassen direkt per
        // innerHTML, ohne selbst nochmal zu escapen.
        return $this->parseMarkdown($this->normalizeAnswerMarkdown($summary));
    }

    private function getDefaultSourceTypeIconSvg(string $sourceType): string
    {
        $sourceType = trim($sourceType);

        if ($sourceType === 'article' || $sourceType === 'sitemap_url') {
            return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 3a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10.5a2 2 0 0 0 1.414-.586l3.5-3.5A2 2 0 0 0 21 15.5V5a2 2 0 0 0-2-2H5Zm0 2h14v10h-3.5a1.5 1.5 0 0 0-1.5 1.5V20H5V5Zm2 3h8a1 1 0 1 1 0 2H7a1 1 0 1 1 0-2Zm0 4h8a1 1 0 1 1 0 2H7a1 1 0 1 1 0-2Z"/></svg>';
        }

        if ($sourceType === 'addon_docs') {
            return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 4.5A2.5 2.5 0 0 1 6.5 2h11A2.5 2.5 0 0 1 20 4.5v15a1 1 0 0 1-1.447.894L15 18.618l-3.553 1.776a1 1 0 0 1-.894 0L7 18.618l-3.553 1.776A1 1 0 0 1 2 19.5v-15ZM6.5 4a.5.5 0 0 0-.5.5v13.382l1.553-.776a1 1 0 0 1 .894 0L11 18.382l2.553-1.276a1 1 0 0 1 .894 0L17 18.382V4.5a.5.5 0 0 0-.5-.5h-10Zm2.5 3h5a1 1 0 1 1 0 2H9a1 1 0 1 1 0-2Zm0 4h6a1 1 0 1 1 0 2H9a1 1 0 1 1 0-2Z"/></svg>';
        }

        if ($sourceType === 'github_docs') {
            return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.162 19.487c.5.092.682-.217.682-.482 0-.237-.009-.867-.014-1.701-2.776.603-3.363-1.338-3.363-1.338-.454-1.154-1.11-1.462-1.11-1.462-.908-.62.069-.607.069-.607 1.003.07 1.53 1.03 1.53 1.03.892 1.53 2.341 1.088 2.91.832.091-.647.349-1.088.635-1.338-2.217-.252-4.551-1.109-4.551-4.937 0-1.09.39-1.983 1.029-2.681-.103-.253-.446-1.272.098-2.65 0 0 .84-.269 2.75 1.024A9.564 9.564 0 0 1 12 6.844a9.56 9.56 0 0 1 2.504.337c1.909-1.293 2.748-1.024 2.748-1.024.546 1.378.203 2.397.1 2.65.64.698 1.028 1.591 1.028 2.681 0 3.837-2.338 4.682-4.562 4.93.359.309.678.918.678 1.85 0 1.336-.012 2.415-.012 2.744 0 .267.18.579.688.481A10.002 10.002 0 0 0 12 2Z"/></svg>';
        }

        if ($sourceType === 'forcal_entry') {
            return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm13 8H4v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9ZM5 6a1 1 0 0 0-1 1v1h16V7a1 1 0 0 0-1-1H5Z"/></svg>';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function extractSourceTypes(array $input): array
    {
        $fromRoot = $input['source_types'] ?? null;
        $fromFilters = is_array($input['filters'] ?? null) ? (($input['filters'])['source_types'] ?? null) : null;
        $raw = is_array($fromRoot) ? $fromRoot : (is_array($fromFilters) ? $fromFilters : []);

        $types = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $types[] = trim($item);
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($types));

        return $unique;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function extractSourceLabels(array $input): array
    {
        $fromRoot = $input['source_labels'] ?? null;
        $fromFilters = is_array($input['filters'] ?? null) ? (($input['filters'])['labels'] ?? null) : null;
        $raw = is_array($fromRoot) ? $fromRoot : (is_array($fromFilters) ? $fromFilters : []);

        $labels = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $labels[] = trim($item);
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($labels));

        return $unique;
    }

    /**
     * @param list<string> $terms
     */
    private function scoreSearchHit(string $needle, string $title, string $content, string $url, array $terms = []): float
    {
        $queryLower = mb_strtolower(trim((string) $needle));
        $queryLower = preg_replace('/[\?\!\.,;:\(\)\[\]{}]+$/u', '', $queryLower) ?? $queryLower;
        $titleLower = mb_strtolower($title);
        $contentLower = mb_strtolower($content);
        $urlLower = mb_strtolower($url);

        $score = 0.0;
        if ($queryLower !== '' && str_contains($titleLower, $queryLower)) {
            $score += 8.0;
        }
        if ($queryLower !== '' && str_contains($urlLower, $queryLower)) {
            $score += 4.0;
        }
        if ($queryLower !== '' && str_contains($contentLower, $queryLower)) {
            $score += 2.0;
        }

        $queryParts = $terms;
        if ($queryParts === []) {
            $split = preg_split('/\s+/', $queryLower, -1, PREG_SPLIT_NO_EMPTY);
            $queryParts = is_array($split) ? $split : [];
        }

        $tokenMatches = 0;
        foreach ($queryParts as $part) {
            $term = mb_strtolower(trim((string) $part));
            if ($term === '') {
                continue;
            }

            if (str_contains($titleLower, $term)) {
                $score += 1.5;
                $tokenMatches++;
            }
            if (str_contains($contentLower, $term)) {
                $score += 0.5;
                $tokenMatches++;
            }
            if (str_contains($urlLower, $term)) {
                $score += 0.8;
                $tokenMatches++;
            }
        }

        if ($queryParts !== [] && $tokenMatches > 0) {
            $coverage = $tokenMatches / count($queryParts);
            $score += min(3.0, $coverage * 3.0);
        }

        if ($queryLower !== '' && str_contains($titleLower, $queryLower) && preg_match('/\s/u', $queryLower) === 1) {
            $score += 1.0;
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function extractSearchTerms(string $needle): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($needle));
        if (!is_array($parts)) {
            return [];
        }

        $stopwords = $this->getGermanStopwords();
        $terms = [];
        foreach ($parts as $part) {
            $term = trim($part);
            if ($term === '' || mb_strlen($term) <= 3) {
                continue;
            }
            if (in_array($term, $stopwords, true)) {
                continue;
            }

            $terms[] = $term;
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($terms));

        return $unique;
    }

    /**
     * @param list<string> $terms
     */
    private function createSnippet(string $content, string $needle, array $terms = []): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');
        if ($plain === '') {
            return '';
        }

        $searchTerms = $terms;
        if ($searchTerms === []) {
            $searchTerms = $this->extractSearchTerms($needle);
        }
        if ($searchTerms === [] && trim($needle) !== '') {
            $searchTerms = [trim($needle)];
        }

        if ($this->isMultiContextSnippetEnabled()) {
            $multi = $this->createMultiContextSnippet($plain, $searchTerms);
            if ($multi !== '') {
                return $multi;
            }
        }

        $needleLower = mb_strtolower($needle);
        $plainLower = mb_strtolower($plain);
        $position = mb_stripos($plainLower, $needleLower);

        if ($position === false) {
            return $this->highlightSnippetSegment(mb_substr($plain, 0, 180), $searchTerms);
        }

        $start = max(0, (int) $position - 70);
        $snippet = $this->highlightSnippetSegment(mb_substr($plain, $start, 220), $searchTerms);

        if ($start > 0) {
            $snippet = '... ' . $snippet;
        }

        return $snippet;
    }

    /**
     * @param list<string> $terms
     */
    private function createMultiContextSnippet(string $plain, array $terms): string
    {
        $contexts = [];
        $seen = [];

        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            $position = mb_stripos(mb_strtolower($plain), mb_strtolower($term));
            if ($position === false) {
                continue;
            }

            $start = max(0, (int) $position - 45);
            $chunkText = mb_substr($plain, $start, 140);

            $key = mb_strtolower($chunkText);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $chunk = $this->highlightSnippetSegment($chunkText, $terms);
            if ($start > 0) {
                $chunk = '... ' . $chunk;
            }
            $contexts[] = $chunk;

            if (count($contexts) >= 3) {
                break;
            }
        }

        if ($contexts === []) {
            return '';
        }

        return implode(' | ', $contexts);
    }

    /**
     * Escaped den rohen Text-Abschnitt fuer die HTML-Ausgabe und umschliesst dabei
     * jedes Vorkommen eines der $terms mit <mark>...</mark> (case-insensitive,
     * ueberlappende Treffer werden nur einmal markiert). <mark> ist das einzige Tag,
     * das hier je erzeugt wird - alles andere laeuft durch htmlspecialchars(), damit
     * der Client das Ergebnis gefahrlos per innerHTML einsetzen kann (siehe
     * assets/ai-search.js) statt es wie bisher als reinen Text zu behandeln.
     *
     * @param list<string> $terms
     */
    private function highlightSnippetSegment(string $text, array $terms): string
    {
        if ('' === $text) {
            return '';
        }

        $textLower = mb_strtolower($text);
        $textLength = mb_strlen($text);

        /** @var list<array{0: int, 1: int}> $ranges */
        $ranges = [];
        foreach ($terms as $term) {
            $term = trim((string) $term);
            if ('' === $term) {
                continue;
            }

            $termLength = mb_strlen($term);
            $termLower = mb_strtolower($term);
            $offset = 0;
            while ($offset < $textLength) {
                $position = mb_stripos($textLower, $termLower, $offset);
                if (false === $position) {
                    break;
                }
                $ranges[] = [$position, $termLength];
                $offset = $position + $termLength;
            }
        }

        if ([] === $ranges) {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $html = '';
        $cursor = 0;
        foreach ($ranges as [$start, $length]) {
            if ($start < $cursor) {
                // Ueberlappt einen bereits markierten Bereich - überspringen.
                continue;
            }

            $html .= htmlspecialchars(mb_substr($text, $cursor, $start - $cursor), ENT_QUOTES, 'UTF-8');
            $html .= '<mark>' . htmlspecialchars(mb_substr($text, $start, $length), ENT_QUOTES, 'UTF-8') . '</mark>';
            $cursor = $start + $length;
        }
        $html .= htmlspecialchars(mb_substr($text, $cursor), ENT_QUOTES, 'UTF-8');

        return $html;
    }

    private function isMultiContextSnippetEnabled(): bool
    {
        $raw = rex_addon::get('ai_chat')->getConfig('search_multi_context_snippets', null);

        if (is_int($raw)) {
            return $raw === 1;
        }

        if (is_string($raw)) {
            $normalized = trim($raw);

            return $normalized === '1' || $normalized === '|1|';
        }

        return $raw === true;
    }

    private function getSourceTypeLabel(string $sourceType): string
    {
        $addon = rex_addon::get('ai_chat');
        $registry = new ContentProviderRegistry();

        $labels = $registry->getSourceTypeLabels($addon);
        if (isset($labels[$sourceType]) && $labels[$sourceType] !== '') {
            return $labels[$sourceType];
        }

        $labels = $this->getSourceTypeLabels();

        if (isset($labels[$sourceType]) && $labels[$sourceType] !== '') {
            return $labels[$sourceType];
        }

        return $sourceType;
    }

    /**
     * @return array<string, string>
     */
    private function getSourceTypeLabels(): array
    {
        $defaults = [
            'sitemap_url' => 'Seiten',
            'article' => 'Artikel der Website',
            'forcal_entry' => 'Termine',
            'addon_docs' => 'AddOn Dokumentation',
            'github_docs' => 'GitHub Dokumentation',
        ];

        $providerLabels = (new ContentProviderRegistry())->getSourceTypeLabels(rex_addon::get('ai_chat'));
        if ($providerLabels !== []) {
            $defaults = array_merge($defaults, $providerLabels);
        }

        $raw = (string) rex_addon::get('ai_chat')->getConfig('search_source_type_labels', '');
        if (trim($raw) === '') {
            return $defaults;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        if (!is_array($lines)) {
            return $defaults;
        }

        $labels = $defaults;
        foreach ($lines as $line) {
            $entry = trim($line);
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }

            $parts = explode('=', $entry, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '' || $value === '') {
                continue;
            }

            $labels[$key] = $value;
        }

        return $labels;
    }

    /**
     * @param array<int, array<string, mixed>> $context
     */
    private function getProviderInstructionsForContext(array $context): string
    {
        $sourceTypes = [];
        foreach ($context as $item) {
            $sourceType = trim((string) ($item['source_type'] ?? ''));
            if ($sourceType !== '') {
                $sourceTypes[] = $sourceType;
            }
        }

        if ($sourceTypes === []) {
            return '';
        }

        /** @var list<string> $sourceTypes */
        $sourceTypes = array_values(array_unique($sourceTypes));

        $registry = new ContentProviderRegistry();
        $instructions = $registry->getPromptInstructionsForSourceTypes(rex_addon::get('ai_chat'), $sourceTypes);
        if ($instructions === []) {
            return '';
        }

        $lines = [];
        foreach ($instructions as $instruction) {
            $lines[] = '- ' . $instruction;
        }

        return implode("\n", $lines);
    }

    private function isForcalHybridEnabled(): bool
    {
        if (!rex_addon::exists('forcal')) {
            return false;
        }

        if (!rex_addon::get('forcal')->isAvailable()) {
            return false;
        }

        $configured = rex_addon::get('ai_chat')->getConfig('index_content_providers');

        $enabledKeys = [];
        if (is_array($configured)) {
            $enabledKeys = array_values(array_filter(array_map('strval', $configured)));
        } elseif (is_string($configured) && trim($configured) !== '') {
            if (str_contains($configured, '|')) {
                $enabledKeys = array_values(array_filter(explode('|', $configured)));
            } else {
                $parts = preg_split('/[\s,]+/', $configured);
                $enabledKeys = array_values(array_filter(array_map('strval', is_array($parts) ? $parts : [])));
            }
        }

        return in_array('forcal', $enabledKeys, true);
    }

    private function getForcalNextOccurrenceSummaryBySourceId(string $sourceId, bool $wantPast = false): string
    {
        $entryId = (int) preg_replace('/[^0-9].*$/', '', $sourceId);
        if ($entryId <= 0) {
            return '';
        }

        return $this->getForcalOccurrencesSummary($entryId, 3, $wantPast);
    }

    /**
     * Erkennt, ob die Nutzerfrage nach einem VERGANGENEN Termin fragt (z.B. "wann WAR das
     * LETZTE Turnier", "welcher Termin fand ZULETZT statt"), statt nach dem nächsten/kommenden
     * (Standardfall). Ohne diese Unterscheidung wurde bisher IMMER der nächste zukünftige Termin
     * genannt, selbst wenn explizit nach der Vergangenheit gefragt wurde.
     */
    private function hasForcalPastIntent(string $message): bool
    {
        $query = mb_strtolower(trim($message));
        if ($query === '') {
            return false;
        }

        $pastKeywords = [
            'letzte', 'letzter', 'letztes', 'letzten',
            'zuletzt', 'vergangen', 'vergangene', 'vergangener', 'vergangenheit',
            'bisherige', 'bisherigen', 'vorherige', 'vorherigen',
            'fand statt', 'stattgefunden', 'gab es', 'war das', 'war der', 'war die',
        ];

        foreach ($pastKeywords as $keyword) {
            if (preg_match('/\s/u', $keyword) === 1) {
                if (str_contains($query, $keyword)) {
                    return true;
                }
                continue;
            }

            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/u', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    private function getForcalOccurrencesSummary(int $entryId, int $maxItems = 3, bool $wantPast = false): string
    {
        if (!$this->isForcalHybridEnabled()) {
            return '';
        }

        $maxItems = max(1, min($maxItems, 10));

        $factoryClass = 'forCal\\Factory\\forCalEventsFactory';
        if (!class_exists($factoryClass)) {
            return '';
        }

        try {
            /** @var mixed $factory */
            $factory = $factoryClass::create();
            if (is_object($factory) && method_exists($factory, 'withUserPermissions')) {
                $factory = $factory->withUserPermissions(false);
            }

            if (!is_object($factory) || !method_exists($factory, 'getEntryById')) {
                return '';
            }

            /** @var mixed $entry */
            $entry = $factory->getEntryById($entryId);
            if (!is_array($entry) || $entry === []) {
                return '';
            }

            $occurrences = $this->extractForcalOccurrences($entry, $maxItems, $wantPast);
            $singleLabel = $wantPast ? 'Letzter bekannter Termin: ' : 'Nächster Termin: ';
            $listLabel = $wantPast ? 'Letzte Termine: ' : 'Nächste Termine: ';

            if ($occurrences === []) {
                $fallbackStart = trim((string) ($entry['entry_start_date'] ?? ($entry['start_date'] ?? '')));
                $fallbackEnd = trim((string) ($entry['entry_end_date'] ?? ($entry['end_date'] ?? '')));
                $fallbackTs = $fallbackStart !== '' ? strtotime($fallbackStart) : false;

                // Nur als Fallback nutzen, wenn dieses einzelne bekannte Datum tatsächlich zur
                // gefragten Richtung (Vergangenheit/Zukunft) passt - sonst lieber "keine Angabe"
                // statt ein zukünftiges Datum fälschlich als "letzter Termin" zu präsentieren.
                if ($fallbackTs === false) {
                    return '';
                }
                if ($wantPast && $fallbackTs >= time()) {
                    return '';
                }
                if (!$wantPast && $fallbackTs < time()) {
                    return '';
                }

                $formatted = $this->formatForcalDateRange($fallbackStart, $fallbackEnd);
                if ($formatted === '') {
                    return '';
                }

                return $singleLabel . $formatted . '.';
            }

            if (count($occurrences) === 1) {
                return $singleLabel . $occurrences[0] . '.';
            }

            $list = [];
            foreach ($occurrences as $i => $occurrence) {
                $list[] = ($i + 1) . ') ' . $occurrence;
            }

            return $listLabel . implode(' | ', $list) . '.';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private function extractForcalOccurrences(array $entry, int $maxItems, bool $wantPast = false): array
    {
        $maxItems = max(1, min($maxItems, 10));
        $found = [];
        $nowTs = time();

        if (isset($entry['dates']) && is_array($entry['dates'])) {
            foreach ($entry['dates'] as $occurrence) {
                if (!is_array($occurrence)) {
                    continue;
                }

                $start = trim((string) ($occurrence['entry_start_date'] ?? ''));
                if ($start === '') {
                    $start = trim((string) ($occurrence['start_date'] ?? ''));
                }
                if ($start === '') {
                    continue;
                }

                $startTs = strtotime($start);
                if ($startTs === false) {
                    continue;
                }
                if ($wantPast ? $startTs >= $nowTs : $startTs < $nowTs) {
                    continue;
                }

                $end = trim((string) ($occurrence['entry_end_date'] ?? ''));
                if ($end === '') {
                    $end = trim((string) ($occurrence['end_date'] ?? ''));
                }

                $formatted = $this->formatForcalDateRange($start, $end);
                if ($formatted === '') {
                    continue;
                }

                $key = $startTs . '|' . $formatted;
                $found[$key] = [
                    'ts' => $startTs,
                    'text' => $formatted,
                ];
            }
        }

        if ($found === []) {
            return [];
        }

        // Zukunft: chronologisch (nächster zuerst). Vergangenheit: umgekehrt chronologisch
        // (zuletzt stattgefundener Termin zuerst), damit "wann war der letzte Termin" den
        // wirklich jüngsten vergangenen Termin an erster Stelle nennt.
        usort($found, static function (array $a, array $b) use ($wantPast): int {
            $tsA = $a['ts'];
            $tsB = $b['ts'];

            return $wantPast ? ($tsB <=> $tsA) : ($tsA <=> $tsB);
        });

        $result = [];
        foreach ($found as $item) {
            $result[] = $item['text'];
            if (count($result) >= $maxItems) {
                break;
            }
        }

        return $result;
    }

    private function formatForcalDateRange(string $start, string $end): string
    {
        try {
            $startDt = new \DateTimeImmutable($start);
        } catch (\Throwable) {
            return '';
        }

        $startText = $startDt->format('d.m.Y H:i');
        $endText = '';

        if ($end !== '') {
            try {
                $endDt = new \DateTimeImmutable($end);
                $endText = $endDt->format('d.m.Y H:i');
            } catch (\Throwable) {
                $endText = '';
            }
        }

        if ($endText !== '') {
            return $startText . ' bis ' . $endText;
        }

        return $startText;
    }

    private function removeUnwantedGreetingPrefix(string $answer, string $scope): string
    {
        if ($scope !== 'frontend') {
            return $answer;
        }

        $patterns = [
            '/^\s*(?:Hallo|Hi)\s*[,!:;]?\s*wie\s*hei(?:ß|ss)t\s*du(?:\s*,\s*bitte)?\??\s*/iu',
            '/^\s*(?:Hallo|Hi)\s*[,!:;]?\s*ich\s*brauche\s*(?:deinen|ihren)\s*namen[^\n.!?]*[.!?]?\s*wie\s*hei(?:ß|ss)t\s*du(?:\s*,\s*bitte)?\??\s*/iu',
            '/^\s*wie\s*hei(?:ß|ss)t\s*du(?:\s*,\s*bitte)?\??\s*/iu',
            '/^\s*(?:Hallo|Hi)\s*[,!:;]?\s*wer\s*bist\s*du\??\s*/iu',
            '/^\s*wer\s*bist\s*du\??\s*/iu',
        ];

        $cleaned = $answer;
        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '', $cleaned, 1);
            if (is_string($replaced)) {
                $cleaned = $replaced;
            }
        }

        // Safety net: remove residual "name request" phrases even when they are not strict prefixes.
        $globalPatterns = [
            '/\bwie\s*hei(?:ß|ss)t\s*du(?:\s*,\s*bitte)?\??/iu',
            '/\bich\s*brauche\s*(?:deinen|ihren)\s*namen[^\n.!?]*[.!?]?/iu',
            '/\bwer\s*bist\s*du\??/iu',
        ];
        foreach ($globalPatterns as $pattern) {
            $replaced = preg_replace($pattern, '', $cleaned);
            if (is_string($replaced)) {
                $cleaned = $replaced;
            }
        }

        // NUR horizontalen Whitespace zusammenfassen (Leerzeichen/Tabs, die eine entfernte
        // Phrase hinterlassen hat) - NICHT \s{2,}, das wuerde auch Zeilenumbrueche treffen und
        // damit jede Leerzeile (Absatz-/Block-Trennung) im kompletten Answer zu einem einzelnen
        // Leerzeichen zusammenfalten. Traf u.a. per Trigger angehaengten Markdown-Inhalt (siehe
        // checkTriggers() weiter oben): dessen Ueberschriften/Tabellen verschmolzen dadurch mit
        // dem Fliesstext zu einem einzigen Absatz, Parsedown erkannte weder "##"-Ueberschrift
        // noch "| Tabelle |" mehr als eigenen Block und liess die Markdown-Syntax als sichtbaren
        // Rohtext stehen (siehe GitHub/Oli: Trigger-Antwort mit Oeffnungszeiten-Tabelle).
        $cleaned = preg_replace('/[ \t]{2,}/u', ' ', $cleaned) ?: $cleaned;

        return ltrim($cleaned);
    }

    /**
     * Erkennt offensichtlich sinnlose Sucheingaben (leer/zu kurz, keine Buchstaben, stark
     * wiederholte Zeichen, extrem geringe Zeichenvielfalt bei ausreichender Länge), BEVOR bei
     * treffer-loser Suche ein KI-Fallback (siehe process()) angestoßen würde - eine
     * Zeichenkette wie "asdasdasd" oder "?????" soll nicht erst die KI belasten, um dann
     * ohnehin nur eine Rückfrage/keine sinnvolle Antwort zu produzieren. Bewusst konservativ
     * (lieber ein falsches "nicht nonsense" als eine echte, nur kurze Anfrage zu blockieren -
     * "auto" oder "kfz" haben trotz Kürze genug Zeichenvielfalt, um hier durchzukommen).
     */
    private function looksLikeNonsenseQuery(string $message): bool
    {
        $text = trim($message);
        if ('' === $text || mb_strlen($text) < 2) {
            return true;
        }

        if (0 === preg_match('/\p{L}/u', $text)) {
            // Kein einziger Buchstabe (nur Ziffern/Sonderzeichen) - z.B. "12345" oder "???".
            return true;
        }

        if (preg_match('/^(.)\1{4,}$/u', $text) === 1) {
            // Ein einzelnes Zeichen mind. 5x wiederholt, sonst nichts - z.B. "aaaaaa".
            return true;
        }

        $normalized = preg_replace('/\s+/u', '', mb_strtolower($text));
        $normalized = is_string($normalized) ? $normalized : $text;
        $length = mb_strlen($normalized);
        if ($length >= 8) {
            $chars = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
            $distinctChars = is_array($chars) ? count(array_unique($chars)) : $length;
            if ($distinctChars <= 3) {
                // z.B. "asdasdasdasd" (nur 3 unterschiedliche Zeichen bei 12 Zeichen Länge).
                return true;
            }
        }

        return false;
    }

    /**
     * Begrenzt die Nachrichtenlänge konfigurierbar getrennt nach Frontend- und Backend-/Developer-Chat
     * (Backend darf großzügiger sein, da dort z.B. Code/Logs eingefügt werden). 0 = keine Begrenzung.
     *
     * @return array{blocked: bool, message: string}
     */
    private function evaluateMessageLengthGuard(string $message, string $scope): array
    {
        $isBackendScope = $scope === 'developer';
        $configKey = $isBackendScope ? 'max_message_length_backend' : 'max_message_length_frontend';
        $defaultLimit = $isBackendScope ? 20000 : 2000;
        $limit = (int) rex_addon::get('ai_chat')->getConfig($configKey, $defaultLimit);

        if ($limit <= 0) {
            return ['blocked' => false, 'message' => ''];
        }

        $length = mb_strlen($message, 'UTF-8');
        if ($length <= $limit) {
            return ['blocked' => false, 'message' => ''];
        }

        return [
            'blocked' => true,
            'message' => sprintf('Deine Nachricht ist zu lang (%d von maximal %d Zeichen). Bitte kürze sie und versuche es erneut.', $length, $limit),
        ];
    }

    /**
     * Blockiert Nachrichten mit Skript-/HTML-Einschleusungsversuchen (z.B. `<script>`, `onerror=`,
     * `javascript:`-URLs) BEVOR sie an die KI weitergegeben oder in den FAQ-Cache geschrieben
     * werden. Rendering-seitig sind Parsedown (`setSafeMode(true)`) und die JS-Ausgabe zwar
     * bereits gegen sowas gehärtet, aber ein Versuch soll erst gar nicht bis zur KI durchdringen.
     *
     * @return array{blocked: bool, strikes: int, message: string}
     */
    private function evaluateCodeInjectionGuard(string $message): array
    {
        if (!$this->containsCodeInjectionAttempt($message)) {
            return ['blocked' => false, 'strikes' => 0, 'closing' => false, 'message' => ''];
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $strikes = (int) ($_SESSION['ai_chat_security_strikes'] ?? 0) + 1;
        $_SESSION['ai_chat_security_strikes'] = $strikes;

        rex_logger::logError(
            E_USER_NOTICE,
            'AiChat: Blockierter Code-Einschleusungsversuch (IP ' . rex_server('REMOTE_ADDR', 'string', '?') . ', Strike ' . $strikes . '): ' . mb_substr(trim($message), 0, 200),
            __FILE__,
            __LINE__,
        );

        $this->reportAttackToUpkeep('AI Chat: Code-/Skript-Einschleusungsversuch erkannt');

        if ($this->combinedAttackStrikes() >= self::ATTACK_STRIKES_BEFORE_CLOSING) {
            return [
                'blocked' => true,
                'strikes' => $strikes,
                'closing' => true,
                'message' => $this->conversationClosingMessage(),
            ];
        }

        return [
            'blocked' => true,
            'strikes' => $strikes,
            'closing' => false,
            'message' => 'Diese Eingabe enthält Code bzw. HTML-Markup (z.B. Skript-Tags oder Event-Attribute) und wurde aus Sicherheitsgründen nicht verarbeitet.',
        ];
    }

    /**
     * Erkennt Versuche, die KI ueber die Nutzereingabe umzuprogrammieren (Prompt-Injection/
     * Jailbreak) - z.B. "ignoriere alle bisherigen Anweisungen", "du bist jetzt uneingeschränkt",
     * "gib deinen System-Prompt aus". Eigenstaendig von containsCodeInjectionAttempt() (das
     * erkennt HTML/Skript-Markup, keine reinen Text-Anweisungen an das Sprachmodell).
     *
     * @return array{blocked: bool, strikes: int, closing: bool, message: string}
     */
    private function evaluatePromptInjectionGuard(string $message): array
    {
        if (!$this->containsPromptInjectionAttempt($message)) {
            return ['blocked' => false, 'strikes' => 0, 'closing' => false, 'message' => ''];
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $strikes = (int) ($_SESSION['ai_chat_injection_strikes'] ?? 0) + 1;
        $_SESSION['ai_chat_injection_strikes'] = $strikes;

        rex_logger::logError(
            E_USER_NOTICE,
            'AiChat: Blockierter Prompt-Injection-/Jailbreak-Versuch (IP ' . rex_server('REMOTE_ADDR', 'string', '?') . ', Strike ' . $strikes . '): ' . mb_substr(trim($message), 0, 200),
            __FILE__,
            __LINE__,
        );

        $this->reportAttackToUpkeep('AI Chat: Prompt-Injection-/Jailbreak-Versuch erkannt');

        if ($this->combinedAttackStrikes() >= self::ATTACK_STRIKES_BEFORE_CLOSING) {
            return [
                'blocked' => true,
                'strikes' => $strikes,
                'closing' => true,
                'message' => $this->conversationClosingMessage(),
            ];
        }

        return [
            'blocked' => true,
            'strikes' => $strikes,
            'closing' => false,
            'message' => 'Diese Eingabe wurde als Versuch erkannt, die KI-Anweisungen zu umgehen oder offenzulegen, und wurde nicht verarbeitet.',
        ];
    }

    private function containsPromptInjectionAttempt(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        $patterns = [
            // Anweisungen ignorieren/ueberschreiben
            '/ignor(e|iere)\s+(all|alle|previous|bisherige[n]?|vorherige[n]?|obige[n]?)\s+(instructions?|anweisungen?|regeln?)/iu',
            '/disregard\s+(all\s+)?(previous|prior|above)\s+(instructions?|rules?)/iu',
            '/vergiss\s+(alle\s+)?(bisherige[n]?|vorherige[n]?)\s+(anweisungen?|regeln?)/iu',
            // Rollen-/Modus-Uebernahme
            '/you\s+are\s+now\s+(an?\s+)?(unrestricted|uncensored|jailbroken|DAN)/iu',
            '/du\s+bist\s+jetzt\s+(ein[e]?\s+)?(uneingeschränkt|unzensiert)/iu',
            '/\bDAN\s+mode\b/i',
            '/developer\s+mode\s+(enabled|aktiviert)/iu',
            '/act\s+as\s+(an?\s+)?(unrestricted|different)\s+ai/iu',
            '/pretend\s+(you\s+have\s+no|there\s+are\s+no)\s+(restrictions?|rules?|guidelines?)/iu',
            // System-Prompt offenlegen
            '/(reveal|show|print|output|gib.*aus)\s+.*(system\s*[- ]?prompt|system(anweisung|nachricht)|deine\s+anweisungen|your\s+instructions)/iu',
            '/wiederhole\s+.*(system\s*prompt|deine\s+anweisungen)\s+(wortwörtlich|verbatim)/iu',
            '/repeat\s+.*(system\s*prompt|your\s+instructions)\s+verbatim/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Summe der Angriffs-bezogenen Strikes dieser Session (Code-Injection + Prompt-Injection) -
     * NICHT Spam/Datenschutz, die haben eigene, mildere Strike-Zaehler ohne Upkeep-Meldung und
     * ohne Gespraechs-Abbruch (ein versehentlich eingefügter Datenschutz-relevanter Text ist
     * kein Angriff).
     */
    private function combinedAttackStrikes(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        return (int) ($_SESSION['ai_chat_security_strikes'] ?? 0) + (int) ($_SESSION['ai_chat_injection_strikes'] ?? 0);
    }

    /**
     * Freundlicher Gespraechsabschluss statt eines weiteren harten Blocks, sobald wiederholt
     * Angriffsversuche erkannt wurden (siehe ATTACK_STRIKES_BEFORE_CLOSING) - der Nutzer merkt,
     * dass das Gespraech vorbei ist, ohne eine unfreundliche Fehlermeldung zu bekommen.
     */
    private function conversationClosingMessage(): string
    {
        return 'Ich beende dieses Gespräch an dieser Stelle lieber - mehrere Eingaben wirkten wie Versuche, mich zu manipulieren. Wenn das ein Missverständnis war, starten Sie gerne einen neuen Chat.';
    }

    /**
     * Meldet einen erkannten Angriffsversuch optional an das (separat installierte, hier
     * bewusst nicht veränderte) upkeep-Addon zur milden, temporären IP-Sperre - rein additiv
     * über dessen öffentliche API, kein Eingriff in upkeep selbst. Ohne installiertes/
     * verfügbares upkeep ist dies ein No-Op, kein Fehler.
     */
    private function reportAttackToUpkeep(string $reason): void
    {
        if (!rex_addon::get('upkeep')->isAvailable() || !class_exists(\KLXM\Upkeep\IntrusionPrevention::class)) {
            return;
        }

        $ip = rex_server('REMOTE_ADDR', 'string', '');
        if ('' === $ip) {
            return;
        }

        try {
            // '1h' = bewusst eine milde, temporaere Sperre ("soft Sperre") statt 'permanent' -
            // ein einzelner Chat-Widget-Fund soll niemanden dauerhaft aussperren.
            \KLXM\Upkeep\IntrusionPrevention::blockIpManually($ip, '1h', $reason);
        } catch (\Throwable $e) {
            rex_logger::logException($e);
        }
    }

    private function containsCodeInjectionAttempt(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        $patterns = [
            '/<\s*script\b/i',
            '/<\s*\/\s*script\s*>/i',
            '/<\s*iframe\b/i',
            '/<\s*object\b/i',
            '/<\s*embed\b/i',
            '/<\s*svg\b/i',
            '/<\s*img\b[^>]*\bon[a-z]+\s*=/i',
            '/javascript\s*:/i',
            '/vbscript\s*:/i',
            '/data\s*:\s*text\/html/i',
            '/\bon[a-z]+\s*=\s*["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Blockiert typische Spam-/Werbe-Anfragen (Pharma-Spam, SEO-/Backlink-Spam,
     * Casino/Glücksspiel, Kredit-/Finanzbetrug, Krypto-Versprechen, Erwachseneninhalte,
     * sowie Nachrichten mit auffällig vielen Links) BEVOR sie an die KI weitergegeben oder
     * in den FAQ-Cache geschrieben werden.
     *
     * @return array{blocked: bool, strikes: int, message: string}
     */
    private function evaluateSpamGuard(string $message): array
    {
        if (!$this->containsSpamContent($message)) {
            return ['blocked' => false, 'strikes' => 0, 'message' => ''];
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $strikes = (int) ($_SESSION['ai_chat_spam_strikes'] ?? 0) + 1;
        $_SESSION['ai_chat_spam_strikes'] = $strikes;

        rex_logger::logError(
            E_USER_NOTICE,
            'AiChat: Blockierte Spam-/Werbeanfrage (IP ' . rex_server('REMOTE_ADDR', 'string', '?') . ', Strike ' . $strikes . '): ' . mb_substr(trim($message), 0, 200),
            __FILE__,
            __LINE__,
        );

        return [
            'blocked' => true,
            'strikes' => $strikes,
            'message' => 'Diese Anfrage wurde als Spam/Werbung erkannt und nicht verarbeitet.',
        ];
    }

    private function containsSpamContent(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        // Auffällig viele Links in einer einzelnen Nachricht sind ein starkes, wortunabhängiges
        // Spam-Indiz (typisches Muster von Link-Spam-Bots).
        $urlCount = preg_match_all('#https?://#i', $text);
        if (is_int($urlCount) && $urlCount >= 3) {
            return true;
        }

        // Eingebaute Grundliste typischer Spam-/Werbe-Begriffe (Pharma, SEO/Backlinks, Casino,
        // Kredit-/Finanzbetrug, Krypto, Erwachseneninhalte). Bewusst als Phrasen statt einzelner
        // Wörter, um False Positives bei harmlosen Anfragen zu vermeiden.
        $builtinBadwords = [
            'viagra', 'cialis', 'levitra', 'kamagra',
            'backlink kaufen', 'buy backlinks', 'seo services', 'seo-dienstleistung',
            'guest post', 'link building', 'google ranking verbessern',
            'bitcoin investment', 'crypto signals', 'forex signals', 'guaranteed profit',
            'guaranteed returns', 'get rich quick', 'schnell reich werden', 'passives einkommen garantiert',
            'online casino', 'free spins', 'casino bonus', 'jackpot gewinnen',
            'payday loan', 'instant loan approval', 'kredit ohne schufa', 'credit repair',
            'porn', 'xxx video', 'adult dating', 'sexcam',
            'you have won', 'sie haben gewonnen', 'claim your prize', 'preis abholen',
        ];

        $badwords = array_values(array_unique(array_merge($builtinBadwords, $this->getConfiguredSpamBadwords())));

        foreach ($badwords as $badword) {
            if ($badword === '') {
                continue;
            }

            if (mb_stripos($text, $badword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getConfiguredSpamBadwords(): array
    {
        $configured = rex_addon::get('ai_chat')->getConfig('spam_badwords', '');

        $raw = '';
        if (is_array($configured)) {
            $raw = implode("\n", array_map(static fn($item): string => is_string($item) ? $item : '', $configured));
        } elseif (is_string($configured)) {
            $raw = $configured;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,;]+/', $raw);
        if (!is_array($parts)) {
            return [];
        }

        $words = [];
        foreach ($parts as $part) {
            $word = mb_strtolower(trim($part));
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * @return array{blocked: bool, disabled: bool, strikes: int, message: string}
     */
    private function evaluatePrivacyGuard(string $message, string $mode): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $strikes = (int) ($_SESSION['ai_chat_privacy_strikes'] ?? 0);

        if ($this->containsSensitiveInput($message)) {
            $strikes++;
            $_SESSION['ai_chat_privacy_strikes'] = $strikes;

            return [
                'blocked' => true,
                'disabled' => false,
                'strikes' => $strikes,
                'message' => 'Bitte keine personenbezogenen oder vertraulichen Daten eingeben (z.B. Konto-/Kartendaten, Passwörter, E-Mail-Adressen). Diese Anfrage wurde nicht an die KI weitergegeben.',
            ];
        }

        return [
            'blocked' => false,
            'disabled' => false,
            'strikes' => $strikes,
            'message' => '',
        ];
    }

    private function containsSensitiveInput(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        if ($this->containsNonWhitelistedEmail($text)) {
            return true;
        }

        $upper = mb_strtoupper($text);
        if (preg_match('/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}\b/u', $upper) === 1) {
            return true;
        }

        if ($this->containsLikelyCreditCardNumber($text)) {
            return true;
        }

        $hasSensitiveKeyword = preg_match('/\b(iban|kontonummer|konto|kreditkarte|kartennummer|passwort|pin|tan|cvv|cvc|personalausweis|ausweisnummer|sozialversicherungsnummer|steuer\s*id|steueridentifikationsnummer)\b/iu', $text) === 1;
        $hasLongNumeric = preg_match('/\b\d{8,}\b/u', $text) === 1;

        return $hasSensitiveKeyword && $hasLongNumeric;
    }

    private function containsNonWhitelistedEmail(string $text): bool
    {
        $matched = preg_match_all('/\b([A-Z0-9._%+-]+)@([A-Z0-9.-]+\.[A-Z]{2,})\b/i', $text, $matches);
        if (!is_int($matched) || $matched <= 0) {
            return false;
        }

        $allowedDomains = $this->getPrivacyEmailDomainWhitelist();
        if ($allowedDomains === []) {
            return true;
        }

        $domains = $matches[2];

        foreach ($domains as $domainRaw) {
            $domain = mb_strtolower(trim($domainRaw));
            if ($domain === '') {
                return true;
            }

            if (!in_array($domain, $allowedDomains, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getPrivacyEmailDomainWhitelist(): array
    {
        $configured = rex_addon::get('ai_chat')->getConfig('privacy_email_domain_whitelist', '');

        $raw = '';
        if (is_array($configured)) {
            $raw = implode("\n", array_map(static fn($item): string => is_string($item) ? $item : '', $configured));
        } elseif (is_string($configured)) {
            $raw = $configured;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        if ((str_starts_with($raw, '[') && str_ends_with($raw, ']')) || (str_starts_with($raw, '"') && str_ends_with($raw, '"'))) {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = implode("\n", array_map(static fn($item): string => is_string($item) ? $item : '', $decoded));
            } elseif (is_string($decoded)) {
                $raw = $decoded;
            }
        }

        $parts = preg_split('/[\r\n,;]+/', $raw);
        if (!is_array($parts)) {
            return [];
        }

        $domains = [];
        foreach ($parts as $part) {
            $domain = mb_strtolower(trim($part));
            if ($domain === '') {
                continue;
            }

            if (
                (str_starts_with($domain, '"') && str_ends_with($domain, '"'))
                || (str_starts_with($domain, "'") && str_ends_with($domain, "'"))
            ) {
                $domain = trim($domain, "\"'");
            }

            if (str_starts_with($domain, '@')) {
                $domain = ltrim($domain, '@');
            }

            if ($domain === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1) {
                continue;
            }

            $domains[] = $domain;
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($domains));

        return $unique;
    }

    /**
     * @return array<string, string>
     */
    private function getDeveloperPersonalization(): array
    {
        $personalization = [
            'mode' => 'informal',
        ];

        $backendUser = self::getAuthenticatedBackendUser();
        if (!$backendUser) {
            return $personalization;
        }

        $userName = trim((string) $backendUser->getName());
        if ($userName !== '') {
            $personalization['name'] = $userName;

            return $personalization;
        }

        $login = trim((string) $backendUser->getLogin());
        if ($login !== '') {
            $personalization['name'] = $login;
        }

        return $personalization;
    }

    private function containsLikelyCreditCardNumber(string $text): bool
    {
        $matchResult = preg_match_all('/(?:\d[\s-]?){13,19}/', $text, $matches);
        if (!is_int($matchResult) || $matchResult <= 0) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $digits = preg_replace('/\D+/', '', $candidate);
            if (!is_string($digits)) {
                continue;
            }

            $length = strlen($digits);
            if ($length < 13 || $length > 19) {
                continue;
            }

            if ($this->passesLuhn($digits)) {
                return true;
            }
        }

        return false;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $alternate = false;

        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $digit = (int) $digits[$i];

            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $alternate = !$alternate;
        }

        return $sum > 0 && $sum % 10 === 0;
    }
}
