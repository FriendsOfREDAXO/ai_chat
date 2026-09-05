<?php

namespace FriendsOfRedaxo\AiChat\Service;

use FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderRegistry;
use FriendsOfRedaxo\AiChat\ContentProvider\MediaPoolContentProvider;
use FriendsOfRedaxo\AiChat\ContentProvider\YformContentProvider;
use FriendsOfRedaxo\AiChat\ContentProvider\YformProfiles;
use FriendsOfRedaxo\AiChat\Db\VectorCapability;
use FriendsOfRedaxo\AiChat\Db\VectorIndexInstaller;
use FriendsOfRedaxo\AiChat\Profile\ChatProfile;
use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;
use rex_article;
use rex_article_slice;
use rex_sql;
use rex_clang;

class IndexerService
{
    private AiServiceInterface $aiService;
    private ContentProviderRegistry $providerRegistry;

    public function __construct()
    {
        $this->aiService = AiServiceFactory::create();
        $this->providerRegistry = new ContentProviderRegistry();
    }

    public function clearIndex(): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery('TRUNCATE TABLE ' . \rex::getTable('ai_chat_index'));
        $sql->setQuery('TRUNCATE TABLE ' . \rex::getTable('ai_chat_cache'));
    }

    public function clearCache(): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery('TRUNCATE TABLE ' . \rex::getTable('ai_chat_cache'));
    }

    /**
     * Seit Phase 6 (kein globaler Shared Pool mehr) gibt es keine globale Struktur-/
     * Sitemap-/PDF-Indexierung und keine AddOn-/GitHub-Docs-Quelle im Kern mehr - jedes
     * Profil waehlt ausschliesslich eigene Quellen (siehe collectProfileTasks()).
     * Drittanbieter-Provider ueber die AI_CHAT_CONTENT_PROVIDERS-Extension-Point (z.B.
     * "forcal") bleiben als einzige globale, profil-unabhaengige Quelle bestehen - ihre
     * Aktivierung ist bewusst weiterhin unveraendert (siehe ContentProviderRegistry).
     *
     * @return list<array<string, mixed>>
     */
    public function collectTasks(): array
    {
        $tasks = [];
        $addon = \rex_addon::get('ai_chat');

        foreach ($this->providerRegistry->getEnabledProviders($addon) as $provider) {
            try {
                $tasks = array_merge($tasks, $provider->collectTasks());
            } catch (\Throwable $e) {
                \rex_logger::logException($e);
            }
        }

        $tasks = array_merge($tasks, $this->collectProfileTasks());

        \rex_logger::factory()->info('AiChat Indexer: Collected {count} tasks.', ['count' => count($tasks)]);

        return $tasks;
    }

    /**
     * Sammelt Tasks für profil-eigene Quellen (siehe ChatProfile::$yformProfileIds/
     * $sitemapGroups/$mountpointGroups/$pdfMediaIds/$pdfCategoryIds) - seit Phase 6 die
     * EINZIGE Quelle profilgebundener Tasks, alle gleichzeitig kombinierbar. Jeder Task
     * bekommt 'chat_profile_id' (ID aus
     * `ai_chat_profile`, NICHT zu verwechseln mit dem YForm-Mapping-'profile_id'-
     * Schlüssel, den YformContentProvider/processTask() für ihre eigenen,
     * string-basierten Profile verwenden), damit processTask() den fertigen
     * Chunk exklusiv diesem Profil zuordnen kann.
     *
     * @return list<array<string, mixed>>
     */
    private function collectProfileTasks(): array
    {
        $tasks = [];
        $addon = \rex_addon::get('ai_chat');

        foreach ((new ProfileRepository())->getEnabled() as $profile) {
            if ([] !== $profile->yformProfileIds) {
                $yformProvider = new YformContentProvider();
                foreach ($yformProvider->collectTasksForKeys($profile->yformProfileIds) as $task) {
                    $task['chat_profile_id'] = $profile->id;
                    $tasks[] = $task;
                }
            }

            if ([] !== $profile->pdfMediaIds || [] !== $profile->pdfCategoryIds) {
                $mediaProvider = new MediaPoolContentProvider();
                $filenames = $profile->pdfMediaIds;
                if ([] !== $profile->pdfCategoryIds) {
                    $filenames = array_merge($filenames, $mediaProvider->resolveFilenamesForCategories($profile->pdfCategoryIds));
                }

                if ([] !== $filenames) {
                    foreach ($mediaProvider->collectTasksForKeys(array_values(array_unique($filenames))) as $task) {
                        $task['chat_profile_id'] = $profile->id;
                        $tasks[] = $task;
                    }
                }
            }

            if ([] !== $profile->sitemapGroups) {
                foreach ($profile->sitemapGroups as $group) {
                    $groupUrls = [];
                    foreach ($group['urls'] as $sitemapUrl) {
                        $groupUrls = array_merge($groupUrls, $this->fetchSitemapUrls($sitemapUrl));
                    }
                    // '' (unbenannte Gruppe) wird NICHT als source_label gespeichert (bleibt
                    // NULL) - konsistent mit "keine Gruppe" fuer bereits migrierte Alt-Profile
                    // (siehe install.php-Migration) und mit dem Shared Pool.
                    $label = '' !== $group['label'] ? $group['label'] : null;
                    foreach (array_unique($groupUrls) as $url) {
                        $tasks[] = [
                            'type' => 'url',
                            'url' => $url,
                            'chat_profile_id' => $profile->id,
                            'source_label' => $label,
                        ];
                    }
                }
            }

            // Mehrere benannte Struktur-Bereiche, seit Phase 6 gleichzeitig mit den
            // Sitemap-Gruppen oben nutzbar (kein extraSource-Entweder-Oder mehr).
            foreach ($profile->mountpointGroups as $group) {
                $label = '' !== $group['label'] ? $group['label'] : null;
                foreach (rex_clang::getAllIds() as $clangId) {
                    foreach ($this->collectArticlesUnderCategory($group['category_id'], $clangId) as $articleId) {
                        $tasks[] = [
                            'type' => 'article',
                            'id' => $articleId,
                            'clang' => $clangId,
                            'chat_profile_id' => $profile->id,
                            'source_label' => $label,
                        ];
                    }
                }
            }
        }

        return $tasks;
    }

    /**
     * Alle Artikel-IDs unterhalb (inkl.) einer Kategorie, für eine bestimmte
     * Sprache - Grundlage für einen Eintrag in `$profile->mountpointGroups`.
     * Nutzt dieselbe rekursive Kategorie-Baum-Logik wie die bestehenden
     * Kategorie-Ausschlüsse (getCategoryIdsRecursive()).
     *
     * @return list<int>
     */
    private function collectArticlesUnderCategory(int $categoryId, int $clangId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $categoryIds = $this->getCategoryIdsRecursive($categoryId);

        $sql = rex_sql::factory();
        $placeholders = implode(', ', array_fill(0, count($categoryIds), '?'));
        $sql->setQuery(
            'SELECT id FROM ' . \rex::getTable('article') . ' WHERE clang_id = ? AND (id IN (' . $placeholders . ') OR parent_id IN (' . $placeholders . '))',
            array_merge([$clangId], $categoryIds, $categoryIds),
        );

        $articleIds = [];
        foreach ($sql as $row) {
            $articleIds[] = (int) $row->getValue('id');
        }

        return array_values(array_unique($articleIds));
    }

    /**
     * @return array{processed: int, skipped: int, errors: int, total: int, chunks: int, cancelled: bool, error_log: list<array{label: string, error: string}>}
     */
    public function sync(int $maxItems = 0, ?callable $onProgress = null, ?callable $shouldStop = null): array
    {
        $tasks = $this->collectTasks();
        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0, 'total' => count($tasks), 'chunks' => 0, 'cancelled' => false, 'error_log' => []];

        // Get all existing source_ids and their updatedates from DB - inkl. profile_id im
        // Dedup-Schluessel (siehe Kommentar bei $key unten): ohne das wuerde ein Chunk, der
        // sowohl im Shared Pool (profile_id NULL) als auch exklusiv fuer ein Profil indexiert
        // ist, beim inkrementellen Sync zufaellig eine der beiden Varianten verlieren.
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT source_type, source_id, profile_id, MAX(updatedate) as last_update FROM ' . \rex::getTable('ai_chat_index') . ' GROUP BY source_type, source_id, profile_id');
        $existing = [];
        foreach ($sql as $row) {
            $existingProfileId = $row->getValue('profile_id');
            $key = $row->getValue('source_type') . '|' . $row->getValue('source_id') . '|' . (null !== $existingProfileId ? (string) $existingProfileId : '');
            $existing[$key] = $row->getDateTimeValue('last_update');
        }

        foreach ($tasks as $task) {
            if ($shouldStop !== null && $shouldStop()) {
                $stats['cancelled'] = true;
                break;
            }

            if ($onProgress !== null) {
                $onProgress([
                    'processed' => $stats['processed'],
                    'total' => $stats['total'],
                    'chunks' => $stats['chunks'],
                    'errors' => $stats['errors'],
                    'current_label' => self::describeTask($task),
                ]);
            }

            if ($maxItems > 0 && $stats['processed'] >= $maxItems) {
                $stats['skipped'] += 1; // count remaining as skipped
                continue;
            }
            try {
                $shouldUpdate = false;
                $sourceType = '';
                $sourceId = '';
                $currentUpdateDate = 0;

                if ($task['type'] === 'article') {
                    $sourceType = 'article';
                    $sourceId = $task['id'] . '-' . $task['clang'];
                    $article = rex_article::get($task['id'], $task['clang']);
                    if (!$article) continue;
                    $currentUpdateDate = $article->getUpdateDate();
                } elseif ($task['type'] === 'url') {
                    $sourceType = 'sitemap_url';
                    $sourceId = $task['url'];
                    // URLs are harder to check for update without fetching.
                    // For now, we assume if it's in the index, it's fine.
                    // Or we could always update URLs? 
                    // Let's check if it exists in DB. If yes, we skip for performance, 
                    // unless it's a "force" or "clear" run.
                    $currentUpdateDate = 0; // We don't have it easily
                } elseif ($task['type'] === 'provider_item') {
                    $sourceType = (string) ($task['source_type'] ?? 'provider_item');
                    $sourceId = (string) ($task['source_id'] ?? '');
                    $currentUpdateDate = (int) ($task['updatedate_ts'] ?? 0);

                    if ($sourceId === '') {
                        $stats['skipped']++;
                        continue;
                    }
                }

                // profile_id gehoert zum Dedup-Schluessel (siehe Kommentar an der
                // $existing-Query oben) - derselbe (source_type, source_id) kann als
                // Shared-Pool-Zeile (profile_id NULL) UND als exklusive Profil-Zeile
                // gleichzeitig existieren (ueberlappender Mountpoint/YForm-Quelle).
                $taskProfileId = isset($task['chat_profile_id']) ? (int) $task['chat_profile_id'] : null;
                $key = $sourceType . '|' . $sourceId . '|' . (null !== $taskProfileId ? (string) $taskProfileId : '');

                if (!isset($existing[$key])) {
                    $shouldUpdate = true;
                } else {
                    $lastUpdate = $existing[$key];
                    // Compare timestamps
                    if ($currentUpdateDate > $lastUpdate) {
                        $shouldUpdate = true;
                    }
                }

                if ($shouldUpdate) {
                    // Delete existing entries for this source (nur fuer denselben
                    // profile_id - rex_sql::setWhere() bindet null als Parameter, was in
                    // SQL "profile_id = NULL" ergibt und wegen der Drei-Werte-Logik NIE
                    // matcht, deshalb hier bewusst raw SQL mit "IS NULL" statt setWhere()).
                    $del = rex_sql::factory();
                    if (null === $taskProfileId) {
                        $del->setQuery(
                            'DELETE FROM ' . \rex::getTable('ai_chat_index') . ' WHERE source_type = ? AND source_id = ? AND profile_id IS NULL',
                            [$sourceType, $sourceId],
                        );
                    } else {
                        $del->setQuery(
                            'DELETE FROM ' . \rex::getTable('ai_chat_index') . ' WHERE source_type = ? AND source_id = ? AND profile_id = ?',
                            [$sourceType, $sourceId, $taskProfileId],
                        );
                    }

                    // Process (Insert new)
                    $result = $this->processTask($task);
                    $stats['chunks'] += $result['chunks'];
                    if ($result['error'] !== null) {
                        $stats['errors']++;
                        $stats['error_log'][] = ['label' => self::describeTask($task), 'error' => (string) $result['error']];
                    }
                    $stats['processed']++;
                } else {
                    $stats['skipped']++;
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                $stats['error_log'][] = ['label' => self::describeTask($task), 'error' => $e->getMessage()];
                \rex_logger::logException($e);
            }
        }

        if ($onProgress !== null) {
            $onProgress([
                'processed' => $stats['processed'],
                'total' => $stats['total'],
                'chunks' => $stats['chunks'],
                'errors' => $stats['errors'],
                'current_label' => null,
            ]);
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{title: string, chunks: int, error: string|null}
     */
    public function processTask(array $task): array
    {
        $result = ['title' => '', 'chunks' => 0, 'error' => null];

        try {
            $type = $task['type'] ?? '';
            // Chunk exklusiv einem ai_chat-Profil zuordnen (siehe ChatProfile) - NICHT
            // zu verwechseln mit dem gleichnamigen, string-basierten YForm-Mapping-
            // 'profile_id'-Schlüssel weiter unten im provider_item-Zweig.
            $chatProfileId = isset($task['chat_profile_id']) ? (int) $task['chat_profile_id'] : null;

            if ($type === 'article') {
                $article = rex_article::get($task['id'], $task['clang']);
                if ($article instanceof rex_article) {
                    $result['title'] = $article->getName();
                    $result['chunks'] = $this->indexArticle($article, $task['clang'], $chatProfileId);
                    if ($result['chunks'] === 0) {
                         // Check if it was empty or failed
                         // indexArticle should throw if it really fails
                    }
                }
            } elseif ($type === 'url') {
                $result['title'] = $task['url'];
                $sourceLabel = isset($task['source_label']) && is_string($task['source_label']) && '' !== $task['source_label'] ? $task['source_label'] : null;
                $result['chunks'] = $this->indexUrl($task['url'], $chatProfileId, $sourceLabel);
            } elseif ($type === 'provider_item') {
                $result['title'] = (string) ($task['title'] ?? ((string) ($task['source_id'] ?? 'Provider-Element')));
                $result['chunks'] = $this->indexProviderTask($task, $chatProfileId);
            } elseif ($type === '') {
                // Kein "type" im übergebenen Task-Array - z.B. wenn dieser Endpunkt
                // ohne den erwarteten task[...]-POST-Body aufgerufen wird (manuelles
                // Testen der URL, defekter Client). Ohne diesen Zweig würden alle
                // obigen Vergleiche stillschweigend eine "Undefined array key"-
                // Warnung werfen, die REDAXO im Debug-Modus direkt in die Response
                // schreibt und damit die JSON-Antwort dieses Endpunkts kaputt macht
                // (sichtbar am Client als kryptischer fetch()-Fehler statt als
                // sauberer JSON-Error).
                $result['error'] = 'Ungültige oder fehlende Aufgabe (kein "type" übergeben).';
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            \rex_logger::logException($e);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function indexProviderTask(array $task, ?int $chatProfileId = null): int
    {
        $providerKey = (string) ($task['provider'] ?? '');
        if ($providerKey === '') {
            return 0;
        }

        $provider = $this->providerRegistry->getProvider($providerKey);
        if ($provider === null || !$provider->isAvailable()) {
            return 0;
        }

        $document = $provider->prepareDocument($task);
        if (!is_array($document)) {
            return 0;
        }

        return $this->indexPreparedDocument($document, $chatProfileId);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function indexPreparedDocument(array $document, ?int $chatProfileId = null): int
    {
        $sourceType = (string) ($document['source_type'] ?? 'provider_item');
        $sourceId = (string) ($document['source_id'] ?? '');
        $title = trim((string) ($document['title'] ?? 'Provider-Eintrag'));
        $content = trim((string) ($document['content'] ?? ''));
        $url = trim((string) ($document['url'] ?? ''));

        if ($sourceId === '' || $content === '') {
            return 0;
        }

        $cleanText = $this->cleanText($content);
        if ($cleanText === '') {
            return 0;
        }

        $chunks = $this->chunkText($cleanText);
        if ($chunks === []) {
            return 0;
        }

        $semanticChunks = [];
        foreach ($chunks as $chunk) {
            $semanticChunks[] = $this->prepareEmbeddingText($chunk, $title, $url, $sourceType);
        }
        $embeddings = $this->aiService->getEmbeddings($semanticChunks);

        $chunkCount = 0;
        foreach ($chunks as $i => $chunk) {
            $embedding = $embeddings[$i] ?? null;
            if ($embedding === null) {
                continue;
            }

            $sql = rex_sql::factory();
            $sql->setTable(\rex::getTable('ai_chat_index'));
            $sql->setValue('source_type', $sourceType);
            $sql->setValue('source_id', $sourceId);
            // Kein Chunk-Index-Suffix am Titel (anders als frueher): search()/findSimilarContent()
            // dedupen ohnehin nach source_type+source_id auf EINE Zeile pro Quelle - der Suffix
            // hatte keinen funktionalen Zweck mehr, landete aber sichtbar in der Such-UI
            // ("Titel (2)" wirkt wie ein Notification-Badge, nicht wie ein Chunk-Index).
            $sql->setValue('title', $title);
            $sql->setValue('content', $chunk);
            $this->setEmbeddingColumns($sql, $embedding);
            $sql->setValue('url', $url);
            $sql->setValue('profile_id', $chatProfileId);
            $sql->setDateTimeValue('updatedate', time());
            $sql->insert();

            ++$chunkCount;
        }

        return $chunkCount;
    }


    /**
     * Runs a complete reindex (Index leeren -> Aufgaben sammeln -> jede Aufgabe
     * verarbeiten) in einem Rutsch. Genutzt vom
     * Konsolenbefehl und vom Hintergrund-Worker (Api\ReindexWorker) - beide
     * brauchen dieselbe vollständige Pipeline wie der browsergesteuerte
     * AJAX-Ablauf, nur ohne dass der Browser jede einzelne Aufgabe selbst anstößt.
     *
     * $onProgress wird vor jeder Aufgabe aufgerufen (nicht nur danach), damit ein
     * Fortschritts-Log auch bei einer sehr langsamen/hängenden Einzelaufgabe zeigt,
     * WAS gerade läuft, statt bis zum nächsten Abschluss stumm zu bleiben.
     *
     * @param callable(array<string, mixed>): void|null $onProgress
     * @param callable(): bool|null $shouldStop Kooperative Abbruch-Prüfung, wird zwischen den Aufgaben aufgerufen.
     * @return array{processed: int, total: int, chunks: int, errors: int, cancelled: bool, error_log: list<array{label: string, error: string}>}
     */
    public function runFull(?callable $onProgress = null, ?callable $shouldStop = null): array
    {
        $this->clearIndex();

        $tasks = $this->collectTasks();
        $total = count($tasks);
        $processed = 0;
        $chunks = 0;
        $errors = 0;
        $errorLog = [];
        $cancelled = false;

        foreach ($tasks as $task) {
            if ($shouldStop !== null && $shouldStop()) {
                $cancelled = true;
                break;
            }

            $label = self::describeTask($task);
            if ($onProgress !== null) {
                $onProgress([
                    'processed' => $processed,
                    'total' => $total,
                    'chunks' => $chunks,
                    'errors' => $errors,
                    'current_label' => $label,
                ]);
            }

            $result = $this->processTask($task);
            $chunks += $result['chunks'];
            if ($result['error'] !== null) {
                ++$errors;
                $errorLog[] = ['label' => $label, 'error' => (string) $result['error']];
            }
            ++$processed;
        }

        if ($onProgress !== null) {
            $onProgress([
                'processed' => $processed,
                'total' => $total,
                'chunks' => $chunks,
                'errors' => $errors,
                'current_label' => null,
            ]);
        }

        return [
            'processed' => $processed,
            'total' => $total,
            'chunks' => $chunks,
            'errors' => $errors,
            'cancelled' => $cancelled,
            'error_log' => $errorLog,
        ];
    }

    /**
     * @param array<string, mixed> $task
     */
    private static function describeTask(array $task): string
    {
        $type = (string) ($task['type'] ?? '');

        return match ($type) {
            'article' => 'Artikel ' . ($task['id'] ?? '?') . ' (Sprache ' . ($task['clang'] ?? '?') . ')',
            'url' => 'Sitemap: ' . ($task['url'] ?? '?'),
            'provider_item' => 'Provider ' . ($task['provider'] ?? '?') . ': ' . ($task['title'] ?? $task['source_id'] ?? '?'),
            default => '' !== $type ? $type : 'Element',
        };
    }

    private function indexUrl(string $url, ?int $chatProfileId = null, ?string $sourceLabel = null): int
    {
        $chunkCount = 0;
        try {
            $socket = \rex_socket::factoryUrl($url);
            $response = $socket->doGet();
            
            if (!$response->isOk()) return 0;
            
            $html = $response->getBody();
            if (empty($html)) return 0;

            // Simple HTML to text
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

            // Try to find title
            $title = '';
            $titleTags = $dom->getElementsByTagName('title');
            if ($titleTags->length > 0) {
                $titleNode = $titleTags->item(0);
                $title = $titleNode ? $titleNode->textContent : '';
            }

            // Kein konfigurierbarer Haupt-/Ausschluss-Selektor mehr (index_http_selector/
            // index_http_exclude_selectors - beide ohne UI seit Phase 6, siehe
            // settings.indexing.php): immer den kompletten <body> extrahieren, cleanText()
            // filtert Navigation/Footer/Skripte etc. bereits generisch heraus.
            $body = $dom->getElementsByTagName('body')->item(0);
            $sourceHtml = $body ? (string) $dom->saveHTML($body) : (string) $html;

            $cleanText = $this->cleanText($sourceHtml);

            if (empty($cleanText)) return 0;

            // Metadata prefix
            $prefix = $title ? "Seitentitel: $title. URL: $url\n" : "URL: $url\n";
            
            $chunks = $this->chunkText($cleanText);

            if ($chunks !== []) {
                $fullChunks = [];
                $semanticChunks = [];
                foreach ($chunks as $chunk) {
                    $fullChunk = $prefix . $chunk;
                    $fullChunks[] = $fullChunk;
                    $semanticChunks[] = $this->prepareEmbeddingText($fullChunk, $title ?: $url, $url, 'sitemap_url');
                }
                $embeddings = $this->aiService->getEmbeddings($semanticChunks);

                foreach ($fullChunks as $i => $fullChunk) {
                    $embedding = $embeddings[$i] ?? null;
                    if ($embedding === null) {
                        continue;
                    }

                    $sql = rex_sql::factory();
                    $sql->setTable(\rex::getTable('ai_chat_index'));
                    $sql->setValue('source_type', 'sitemap_url');
                    $sql->setValue('source_id', $url);
                    // Kein Chunk-Index-Suffix - siehe Kommentar bei processTask() weiter oben.
                    $sql->setValue('title', $title ?: $url);
                    $sql->setValue('content', $fullChunk);
                    $this->setEmbeddingColumns($sql, $embedding);
                    $sql->setValue('url', $url);
                    $sql->setValue('profile_id', $chatProfileId);
                    $sql->setValue('source_label', $sourceLabel);
                    $sql->setDateTimeValue('updatedate', time());
                    $sql->insert();
                    ++$chunkCount;
                }
            }
        } catch (\Exception $e) {
            \rex_logger::logException($e);
            throw $e;
        }
        return $chunkCount;
    }

    /**
     * @return list<string>
     */
    private function fetchSitemapUrls(string $sitemapUrl): array
    {
        $urls = [];
        try {
            $socket = \rex_socket::factoryUrl($sitemapUrl);
            $response = $socket->doGet();
            if (!$response->isOk()) return [];
            
            $xml = $response->getBody();
            $sitemap = new \SimpleXMLElement($xml);
            
            foreach ($sitemap->url as $url) {
                $urls[] = (string) $url->loc;
            }
            
            // Handle sitemap index
            if (isset($sitemap->sitemap)) {
                foreach ($sitemap->sitemap as $subSitemap) {
                    $urls = array_merge($urls, $this->fetchSitemapUrls((string) $subSitemap->loc));
                }
            }
        } catch (\Exception $e) {
            \rex_logger::logError(E_USER_WARNING, 'AiChat: Failed to fetch sitemap ' . $sitemapUrl . ': ' . $e->getMessage(), __FILE__, __LINE__);
        }
        /** @var list<string> $uniqueUrls */
        $uniqueUrls = array_values(array_unique($urls));

        return $uniqueUrls;
    }

    /**
     * Returns the given category ID plus all descendant category IDs recursively.
     *
     * @return int[]
     */
    private function getCategoryIdsRecursive(int $categoryId): array
    {
        $ids = [$categoryId];
        $cat = \rex_category::get($categoryId);
        if (!$cat) {
            return $ids;
        }
        foreach ($cat->getChildren() as $child) {
            $ids = array_merge($ids, $this->getCategoryIdsRecursive($child->getId()));
        }
        return $ids;
    }

    public function deleteArticleFromIndex(int $articleId, int $clangId): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(\rex::getTable('ai_chat_index'));
        $sql->setWhere(['source_type' => 'article', 'source_id' => $articleId . '-' . $clangId]);
        $sql->delete();
    }

    public function updateArticleIndex(int $articleId, int $clangId): void
    {
        // Loescht alle Varianten dieses Artikels (je Profil, das ihn exklusiv via
        // Mountpoint fuehrt) - unten wird der komplette, korrekte Satz an Zeilen neu
        // aufgebaut, kein partielles Update. Seit Phase 6 (kein globaler Shared Pool
        // mehr) gibt es keinen profile_id=NULL-Zweig mehr: ein Artikel wird NUR indiziert,
        // wenn mindestens ein Profil ihn ueber $profile->mountpointGroups fuehrt.
        $this->deleteArticleFromIndex($articleId, $clangId);

        $article = rex_article::get($articleId, $clangId);
        if (!$article) {
            return;
        }

        // Keine article_status-/Exclude-Pruefung hier, analog zu collectProfileTasks() -
        // ein Mountpoint ist eine bewusste, enge Auswahl, die unabhaengig vom globalen
        // Online/Offline-Filter indexiert wird.
        foreach ($this->resolveChatProfileIdsForMountpoint($article->getCategoryId()) as $mountpointProfileId) {
            $this->indexArticle($article, $clangId, $mountpointProfileId);
        }
    }

    /**
     * Welche aktivierten Profile fuehren die gegebene Kategorie (bzw. eine ihrer
     * Elternkategorien) als eigenen Mountpoint - fuer die event-getriebene
     * Einzelartikel-Neuindizierung in updateArticleIndex().
     *
     * @return list<int>
     */
    private function resolveChatProfileIdsForMountpoint(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $profileIds = [];
        foreach ((new ProfileRepository())->getEnabled() as $profile) {
            foreach ($profile->mountpointGroups as $group) {
                if (in_array($categoryId, $this->getCategoryIdsRecursive($group['category_id']), true)) {
                    $profileIds[] = $profile->id;
                    break;
                }
            }
        }

        return $profileIds;
    }

    /**
     * Reindiziert (bzw. entfernt) alle direkten Artikel einer Kategorie, z.B. bei CAT_STATUS.
     * $forceOffline = true entfernt die Artikel unabhängig von ihrem eigenen Status-Flag
     * (die Kategorie selbst wurde offline geschaltet); ansonsten wird pro Artikel ganz normal
     * über updateArticleIndex() neu entschieden.
     */
    public function refreshCategoryArticles(int $categoryId, ?bool $forceOffline = null): void
    {
        if ($categoryId <= 0) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id, clang_id FROM ' . \rex::getTable('article') . ' WHERE parent_id = ?', [$categoryId]);

        foreach ($sql as $row) {
            $articleId = (int) $row->getValue('id');
            $clangId = (int) $row->getValue('clang_id');

            if ($forceOffline === true) {
                $this->deleteArticleFromIndex($articleId, $clangId);
                continue;
            }

            $this->updateArticleIndex($articleId, $clangId);
        }
    }

    /**
     * Aktualisiert genau einen YForm-Datensatz in allen konfigurierten Mappings dieser Tabelle.
     */
    public function refreshYformRecord(string $tableName, int $recordId): void
    {
        $tableName = trim($tableName);
        if ($tableName === '' || $recordId <= 0) {
            return;
        }

        $addon = \rex_addon::get('ai_chat');
        $profiles = YformProfiles::getByTable($addon, $tableName);
        if ($profiles === []) {
            return;
        }

        foreach ($profiles as $profile) {
            $profileId = (string) ($profile['id'] ?? '');
            if ($profileId === '') {
                continue;
            }

            $this->deleteBySource(YformProfiles::sourceTypeForProfile($profileId), $this->buildYformSourceId($profileId, $recordId));
            $this->processTask([
                'type' => 'provider_item',
                'provider' => 'yform',
                'profile_id' => $profileId,
                'table_name' => $tableName,
                'record_id' => $recordId,
            ]);
        }
    }

    /**
     * Entfernt einen YForm-Datensatz aus allen konfigurierten Mappings dieser Tabelle.
     */
    public function deleteYformRecord(string $tableName, int $recordId): void
    {
        $tableName = trim($tableName);
        if ($tableName === '' || $recordId <= 0) {
            return;
        }

        $addon = \rex_addon::get('ai_chat');
        $profiles = YformProfiles::getByTable($addon, $tableName);
        if ($profiles === []) {
            return;
        }

        foreach ($profiles as $profile) {
            $profileId = (string) ($profile['id'] ?? '');
            if ($profileId === '') {
                continue;
            }

            $this->deleteBySource(YformProfiles::sourceTypeForProfile($profileId), $this->buildYformSourceId($profileId, $recordId));
        }
    }

    private function deleteBySource(string $sourceType, string $sourceId): void
    {
        if ($sourceType === '' || $sourceId === '') {
            return;
        }

        $del = rex_sql::factory();
        $del->setTable(\rex::getTable('ai_chat_index'));
        $del->setWhere(['source_type' => $sourceType, 'source_id' => $sourceId]);
        $del->delete();
    }

    private function buildYformSourceId(string $profileId, int $recordId): string
    {
        $profileId = trim(mb_strtolower($profileId));
        $profileId = preg_replace('/[^a-z0-9]+/i', '_', $profileId) ?? $profileId;

        return trim($profileId, '_') . ':' . $recordId;
    }

    public function indexArticle(rex_article $article, int $clangId, ?int $chatProfileId = null): int
    {
        if ($this->isExcludedByYrewriteSeo($article)) {
            return 0;
        }

        $chunkCount = 0;

        // Kein HTTP-Crawl-Modus mehr (index_method - ohne UI seit Phase 6, siehe
        // settings.indexing.php): Artikel werden immer intern gerendert statt ihre
        // eigene Live-URL per HTTP abzurufen.
        //
        // Simulate Frontend Environment for correct module output. rex::isBackend()/
        // isFrontend() lesen intern die Property "redaxo" (siehe core/boot.php:
        // rex::setProperty('redaxo', $REX['REDAXO'])) - NICHT "is_backend". Ein Modul,
        // das per rex::isBackend() zwischen echtem Frontend-Output und einer
        // Editor-Vorschau (Grid-/Container-Bearbeitungs-Overlay, "Zurück"-Devlink, o.ae.)
        // unterscheidet, hat bei falscher Property also weiterhin "Backend" gesehen und
        // seine Editor-Ausgabe in den Index geschrieben (siehe GitHub-Issue-Report von Oli:
        // sichtbare Grid-/Container-Einstellungen im Suchergebnis-Snippet).
        $isBackend = \rex::isBackend();
        $originalArticleId = \rex::getProperty('article_id');
        $originalClang = \rex::getProperty('clang');

        if ($isBackend) {
            \rex::setProperty('redaxo', false);
        }

        // Set context for modules that rely on rex_article::getCurrent() or global properties
        \rex::setProperty('article_id', $article->getId());
        \rex::setProperty('clang', $clangId);

        try {
            $content = new \rex_article_content($article->getId(), $clangId);
            $text = $content->getArticle();
        } finally {
            // Restore Environment
            if ($isBackend) {
                \rex::setProperty('redaxo', true);
            }
            \rex::setProperty('article_id', $originalArticleId);
            \rex::setProperty('clang', $originalClang);
        }

        $cleanText = $this->cleanText($text);

        if (empty($cleanText)) return 0;

        $chunks = $this->chunkText($cleanText);
        if ($chunks === []) {
            return 0;
        }

        $semanticChunks = [];
        foreach ($chunks as $chunk) {
            $semanticChunks[] = $this->prepareEmbeddingText($chunk, $article->getName(), $article->getUrl(), 'article', $article);
        }
        $embeddings = $this->aiService->getEmbeddings($semanticChunks);

        foreach ($chunks as $i => $chunk) {
            $embedding = $embeddings[$i] ?? null;
            if ($embedding === null) {
                continue;
            }

            $sql = rex_sql::factory();
            $sql->setTable(\rex::getTable('ai_chat_index'));
            $sql->setValue('source_type', 'article');
            $sql->setValue('source_id', $article->getId() . '-' . $clangId);
            $sql->setValue('title', $article->getName());
            $sql->setValue('content', $chunk);
            $this->setEmbeddingColumns($sql, $embedding);
            $sql->setValue('url', $article->getUrl());
            $sql->setValue('profile_id', $chatProfileId);
            $sql->setValue('clang_id', $clangId);
            $sql->setDateTimeValue('updatedate', time());
            $sql->insert();
            ++$chunkCount;
        }
        return $chunkCount;
    }

    /**
     * Cleans HTML/Text from noisy elements like scripts, styles, navigation, etc.
     */
    private function cleanText(string $text): string
    {
        // 0. JSON-LD (schema.org) VOR dem Entfernen der <script>-Blöcke auswerten. Strukturierte
        // Daten können nicht wie Fließtext "verschmelzen", da jede Person/Rolle ein eigenes,
        // in sich abgeschlossenes JSON-Objekt ist - deutlich robuster als Prosa-Parsing.
        $jsonLdFacts = $this->extractJsonLdFacts($text);

        // 1. Remove obvious non-content blocks (with closing tag)
        $excludeTags = [
            'script', 'style', 'noscript', 'iframe', 'svg', 'canvas', 
            'video', 'audio', 'header', 'footer', 'nav', 'form', 'button'
        ];
        
        foreach ($excludeTags as $tag) {
            $text = preg_replace('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '>/is', '', $text) ?? '';
        }

        // 2. Remove HTML comments
        $text = preg_replace('/<!--(.*?)-->/is', '', $text) ?? '';

        // 2b. Zeilenumbrüche an Blockgrenzen einfügen, BEVOR strip_tags() alle Tags entfernt.
        // Ohne das landen z.B. zwei Tabellenzeilen oder Listenpunkte mit unterschiedlichen
        // Fakten (z.B. "zuständig für X" / "ist allgemein Y") ohne jede Trennung direkt
        // hintereinander im Index – das begünstigt, dass die KI daraus eine falsche,
        // so nicht existierende Kombination ("X-Y") bildet.
        $blockClosingTags = [
            'p', 'div', 'li', 'tr', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'dt', 'dd', 'blockquote', 'section', 'article', 'ul', 'ol', 'table', 'address',
        ];
        foreach ($blockClosingTags as $tag) {
            $text = preg_replace('/<\/' . $tag . '\s*>/i', "\n", $text) ?? '';
        }
        // \b[^>]* statt \s*\/? , damit auch <br class="..."> / <br data-foo> (z.B. responsive
        // Utility-Klassen) erkannt werden, nicht nur das reine <br>/<br/>/<br />.
        $text = preg_replace('/<(br|hr)\b[^>]*>/i', "\n", $text) ?? '';

        // 2c. Sicherheitsnetz für ALLE übrigen (inline/unbekannten) schließenden Tags: z.B.
        // "<span>Friedrich-Alfred-Allee 10</span><span>47055 Duisburg</span>" hätte ohne
        // Trennzeichen im Quelltext sonst zu "1047055" verschmolzen (PLZ direkt an Hausnummer
        // angehängt). Ein schließender Tag markiert IMMER eine Elementgrenze, daher hier
        // grundsätzlich mindestens ein Leerzeichen einfügen, egal um welches Element es geht.
        $text = preg_replace('/<\/[a-z][a-z0-9-]*\s*>/i', ' ', $text) ?? '';

        // 3. Strip remaining tags
        $text = strip_tags($text);

        // 4. Decode entities (so the AI gets real characters)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 4b. Sicherheitsnetz gegen CSS-Rauschen, das trotz Tag-Entfernung im Text landet (z.B.
        // wenn ein Modul ein unvollständiges/verschachteltes <style>-Fragment ausliefert und
        // Schritt 1 dadurch nicht das komplette Fragment matcht). Entfernt Zeilen, die eindeutig
        // wie CSS aussehen, bevor sie als Fließtext indexiert werden.
        $text = $this->stripResidualCssNoise($text);

        // 4c. Sicherheitsnetz gegen "Ähnliche Beiträge"/"Links:"-Widgets ohne passendes
        // semantisches Tag (weder <nav> noch <aside>, siehe Schritt 1 oben) - z.B. eine
        // Blog-Teaser-Liste am Artikelende. Ohne das landet so ein Widget unveraendert als
        // Fliesstext im Index UND wird von der KI woertlich in die Antwort uebernommen
        // (realer Fall: eine "Links:"-Ueberschrift plus mehrere kurze Artikeltitel wurde als
        // vermeintliche Antwort auf eine unabhaengige Frage wiedergegeben).
        $text = $this->stripRelatedContentWidgets($text);

        // 5. Normalize whitespace: horizontale Whitespaces zusammenfassen, Zeilenumbrüche
        // (Fakt-/Absatzgrenzen) aber bewusst erhalten statt sie mit wegzunormalisieren.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? '';
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? '';

        $cleaned = trim($text);

        // JSON-LD-Fakten als eigenen, klar abgegrenzten Absatz voranstellen.
        if ($jsonLdFacts !== '') {
            return $cleaned !== '' ? $jsonLdFacts . "\n\n" . $cleaned : $jsonLdFacts;
        }

        return $cleaned;
    }

    /**
     * Entfernt Zeilen, die eindeutig CSS-Syntax sind (Selektor+Klammer, Property:Value;,
     * einzelne Klammern, CSS-Kommentare). Greift als Sicherheitsnetz, wenn ein <style>-Block
     * aus Schritt 1 nicht vollständig entfernt wurde (z.B. bei fehlerhaft verschachtelten oder
     * unabgeschlossenen Style-Fragmenten in Modul-Templates).
     */
    private function stripResidualCssNoise(string $text): string
    {
        $lines = explode("\n", $text);
        $filtered = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $filtered[] = $line;
                continue;
            }

            // Einzelne Klammern oder eine Selektor-Zeile, die mit "{" endet,
            // z.B. "ul li.uk-parent .clicky, ul li.uk-parent .clicky1 {".
            if (
                preg_match('/^[{}]$/', $trimmed)
                || preg_match('/^[.#]?[a-z0-9_\-\s>,.:\[\]="\'*]+\{$/i', $trimmed)
            ) {
                continue;
            }

            // CSS-Kommentare, z.B. "/* border: 1px solid #fff; */".
            if (preg_match('/^\/\*.*\*\/$/', $trimmed)) {
                continue;
            }

            // CSS-Deklarationszeilen, z.B. "padding: 5px;", "-webkit-transition: all 0.5s ease;",
            // "cursor: pointer;" oder "clear: right; }" (mit optionaler schließender Klammer).
            if (preg_match('/^-?[a-z\-]+\s*:\s*[^;{}]*;\s*\}?\s*$/i', $trimmed)) {
                continue;
            }

            // Reiner Wert-Rest ohne Property-Namen, z.B. "#fff;" oder "#fff; }".
            if (preg_match('/^#[0-9a-f]{3,8};\s*\}?\s*$/i', $trimmed)) {
                continue;
            }

            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }

    /**
     * Entfernt "Ähnliche Beiträge"/"Das könnte Sie auch interessieren"-Widgets, die ohne ein
     * von cleanText() bereits erkanntes semantisches Tag (nav/aside/footer) auskommen - z.B.
     * eine simple Teaser-Liste direkt im Artikel-Markup. Erkennungsmuster: eine kurze
     * Ueberschriftszeile aus einer festen Liste typischer Formulierungen, gefolgt von mehreren
     * kurzen, satzzeichenlosen "Titel-Zeilen" (wie sie Artikel-/Linktitel typischerweise sind).
     * Bewusst als Nachbearbeitung auf Zeilenebene statt als HTML-Selektor, weil das Markup
     * dieser Widgets von Website zu Website völlig unterschiedlich aufgebaut ist - nur das
     * resultierende Textmuster ist einigermaßen konstant.
     */
    private function stripRelatedContentWidgets(string $text): string
    {
        $headingPattern = '/^(links|lesetipps?|weiterlesen|mehr zum thema|weitere artikel|verwandte artikel|ähnliche (artikel|beiträge)|das könnte (sie|dich) auch interessieren)\s*:?\s*$/iu';

        $lines = explode("\n", $text);
        $filtered = [];
        $count = count($lines);

        for ($i = 0; $i < $count; ++$i) {
            $trimmed = trim($lines[$i]);

            if ('' === $trimmed || !preg_match($headingPattern, $trimmed)) {
                $filtered[] = $lines[$i];
                continue;
            }

            // Ueberschrift gefunden - pruefen, ob unmittelbar danach (ueberspringt genau eine
            // Leerzeile, wie sie durch die Block-Umwandlung in Schritt 2b typischerweise
            // entsteht) mehrere kurze, titelartige Zeilen folgen. Ohne diese Bestaetigung bleibt
            // die Ueberschrift stehen - sonst wuerde z.B. ein legitimer Absatz, der zufaellig
            // mit "Weiterlesen:" endet, mitsamt seinem folgenden Fliesstext geloescht.
            $lookahead = $i + 1;
            if ($lookahead < $count && '' === trim($lines[$lookahead])) {
                ++$lookahead;
            }

            $titleLines = 0;
            $scan = $lookahead;
            while ($scan < $count && $titleLines < 10) {
                $candidate = trim($lines[$scan]);
                if ('' === $candidate) {
                    break;
                }
                // Eine "Titel-Zeile" ist kurz und endet nicht wie ein normaler Satz (Punkt/!/?)-
                // echter Fliesstext nach einer Ueberschrift sieht anders aus.
                if (mb_strlen($candidate) > 100 || preg_match('/[.!?]$/u', $candidate)) {
                    break;
                }
                ++$titleLines;
                ++$scan;
            }

            if ($titleLines < 2) {
                // Kein erkennbares Widget-Muster - Ueberschrift ist vermutlich echter Inhalt.
                $filtered[] = $lines[$i];
                continue;
            }

            // Ueberschrift + alle erkannten Titel-Zeilen ueberspringen.
            $i = $scan - 1;
        }

        return implode("\n", $filtered);
    }

    /**
     * Extrahiert JSON-LD (schema.org) Structured Data aus <script type="application/ld+json">
     * und wandelt relevante @type-Objekte (Person, Organization, ContactPoint, ...) in klare
     * "Name – Attribut: Wert"-Zeilen um.
     *
     * @return string Leerer String, wenn kein verwertbares JSON-LD gefunden wurde.
     */
    private function extractJsonLdFacts(string $html): string
    {
        if (stripos($html, 'application/ld+json') === false) {
            return '';
        }

        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return '';
        }

        $facts = [];
        foreach ($matches[1] as $rawJson) {
            $rawJson = trim($rawJson);
            // Sicherheitsnetz gegen übergroße/entartete JSON-LD-Blöcke bei extern gecrawlten Seiten.
            if ($rawJson === '' || mb_strlen($rawJson) > 200000) {
                continue;
            }

            $decoded = json_decode(html_entity_decode($rawJson, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 32);
            if (!is_array($decoded)) {
                continue;
            }

            $nodes = $decoded['@graph'] ?? $decoded;
            if (!is_array($nodes)) {
                continue;
            }
            if (isset($nodes['@type'])) {
                $nodes = [$nodes];
            }

            foreach ($nodes as $node) {
                if (is_array($node)) {
                    $facts = array_merge($facts, $this->jsonLdNodeToFacts($node));
                }
            }
        }

        $facts = array_values(array_unique(array_filter($facts, static fn ($f): bool => trim((string) $f) !== '')));
        if ($facts === []) {
            return '';
        }

        return "Strukturierte Fakten (JSON-LD):\n" . implode("\n", $facts);
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private function jsonLdNodeToFacts(array $node, int $depth = 0): array
    {
        // Schutz vor extrem tief verschachteltem/rekursivem JSON-LD aus externen Quellen.
        if ($depth > 4) {
            return [];
        }

        $type = $node['@type'] ?? '';
        $type = is_array($type) ? implode(',', array_map('strval', $type)) : (string) $type;

        $isRelevantType = $type !== '' && preg_match('/person|organization|contactpoint|localbusiness/i', $type) === 1;

        $facts = [];

        if ($isRelevantType) {
            $name = $this->jsonLdScalar($node['name'] ?? null);
            $label = $name !== '' ? $name : trim($type);

            $attributes = [];
            $jobTitle = $this->jsonLdScalar($node['jobTitle'] ?? null);
            if ($jobTitle !== '') {
                $attributes[] = 'Rolle/Titel: ' . $jobTitle;
            }

            $contactType = $this->jsonLdScalar($node['contactType'] ?? null);
            if ($contactType !== '') {
                $attributes[] = 'Zuständigkeit: ' . $contactType;
            }

            $areaServed = $this->jsonLdScalar($node['areaServed'] ?? null);
            if ($areaServed !== '') {
                $attributes[] = 'Bereich: ' . $areaServed;
            }

            if (isset($node['worksFor']) && is_array($node['worksFor'])) {
                $worksFor = $this->jsonLdScalar($node['worksFor']['name'] ?? null);
                if ($worksFor !== '') {
                    $attributes[] = 'Organisation: ' . $worksFor;
                }
            }

            foreach (['telephone', 'email', 'url'] as $simpleKey) {
                $value = $this->jsonLdScalar($node[$simpleKey] ?? null);
                if ($value !== '') {
                    $attributes[] = ucfirst($simpleKey) . ': ' . $value;
                }
            }

            $openingHoursText = $this->jsonLdOpeningHoursText($node);
            if ('' !== $openingHoursText) {
                $attributes[] = 'Öffnungszeiten: ' . $openingHoursText;
            }

            if ($label !== '' && $attributes !== []) {
                $facts[] = trim($label . ' – ' . implode('; ', $attributes));
            }
        }

        // BreadcrumbList: die itemListElement-Eintraege sind nach "position" sortiert, aber
        // im JSON nicht zwingend in dieser Reihenfolge aufgelistet - deshalb per position
        // einsortieren statt die Array-Reihenfolge zu uebernehmen. Nur ab zwei Ebenen ein
        // eigenes Fakt wert (eine einzelne Ebene traegt keine Hierarchie-Information).
        if (preg_match('/breadcrumblist/i', $type) === 1) {
            $items = is_array($node['itemListElement'] ?? null) ? $node['itemListElement'] : [];
            $ordered = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $position = (int) ($item['position'] ?? 0);
                $itemNode = is_array($item['item'] ?? null) ? $item['item'] : $item;
                $name = $this->jsonLdScalar($itemNode['name'] ?? null);
                if ('' !== $name) {
                    $ordered[$position] = $name;
                }
            }
            ksort($ordered);
            if (count($ordered) > 1) {
                $facts[] = 'Kategorie-Pfad: ' . implode(' > ', $ordered);
            }
        }

        // FAQPage: jede Frage/Antwort als eigenes Fakt, damit eine spaetere Aehnlichkeitssuche
        // gezielt die passende Frage treffen kann statt eines einzigen grossen FAQ-Blocks.
        if (preg_match('/faqpage/i', $type) === 1) {
            $questions = is_array($node['mainEntity'] ?? null) ? $node['mainEntity'] : [];
            if (isset($questions['@type'])) {
                $questions = [$questions];
            }
            foreach ($questions as $question) {
                if (!is_array($question)) {
                    continue;
                }
                $questionText = $this->jsonLdScalar($question['name'] ?? null);
                $answerNode = $question['acceptedAnswer'] ?? null;
                $answerText = is_array($answerNode) ? $this->jsonLdScalar($answerNode['text'] ?? null) : '';
                $answerText = trim(strip_tags($answerText));
                if ('' !== $questionText && '' !== $answerText) {
                    $facts[] = 'FAQ: ' . $questionText . ' – ' . $answerText;
                }
            }
        }

        // contactPoint kann ein einzelnes Objekt oder eine Liste mehrerer ContactPoints sein -
        // jedes einzeln verarbeiten, damit z.B. zwei unterschiedliche Zuständigkeiten derselben
        // Person als getrennte Fakten erhalten bleiben statt zu einer Zeile zu verschmelzen.
        foreach (['contactPoint', 'employee', 'member', 'department', 'founder'] as $nestedKey) {
            if (!isset($node[$nestedKey]) || !is_array($node[$nestedKey])) {
                continue;
            }

            $items = $node[$nestedKey];
            if (isset($items['@type']) || !array_is_list($items)) {
                $items = [$items];
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    $facts = array_merge($facts, $this->jsonLdNodeToFacts($item, $depth + 1));
                }
            }
        }

        return $facts;
    }

    /**
     * @param mixed $value
     */
    private function jsonLdScalar($value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value) && isset($value['name']) && is_string($value['name'])) {
            return trim($value['name']);
        }

        return '';
    }

    /**
     * "openingHours" ist meist ein einfacher String/String-Array direkt am Node (z.B.
     * "Mo-Fr 09:00-18:00"), "openingHoursSpecification" ein strukturiertes Objekt/eine Liste
     * mit dayOfWeek/opens/closes - beide Formen kommen in freier Wildbahn vor, hier beide
     * abgedeckt statt sich auf eine Schreibweise zu verlassen.
     *
     * @param array<mixed> $node
     */
    private function jsonLdOpeningHoursText(array $node): string
    {
        $parts = [];

        $simple = $node['openingHours'] ?? null;
        if (is_string($simple) && '' !== trim($simple)) {
            $parts[] = trim($simple);
        } elseif (is_array($simple)) {
            foreach ($simple as $entry) {
                if (is_string($entry) && '' !== trim($entry)) {
                    $parts[] = trim($entry);
                }
            }
        }

        $specs = $node['openingHoursSpecification'] ?? null;
        if (is_array($specs)) {
            if (isset($specs['@type']) || !array_is_list($specs)) {
                $specs = [$specs];
            }
            foreach ($specs as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $days = $spec['dayOfWeek'] ?? null;
                $daysText = is_array($days) ? implode(', ', array_map(static fn ($d): string => (string) $d, $days)) : trim((string) ($days ?? ''));
                $opens = $this->jsonLdScalar($spec['opens'] ?? null);
                $closes = $this->jsonLdScalar($spec['closes'] ?? null);
                if ('' !== $daysText && '' !== $opens && '' !== $closes) {
                    $parts[] = $daysText . ' ' . $opens . '–' . $closes;
                }
            }
        }

        return implode('; ', array_values(array_unique($parts)));
    }

    /**
     * Schreibt das Embedding in beiden Formaten: die bestehende JSON-Spalte (immer, fuer
     * BruteForceRetrieval/den Cache-Treffer-Abgleich) und zusaetzlich die natives-Vektor-
     * Spalte, wenn MariaDB das unterstuetzt (siehe VectorCapability/NativeVectorRetrieval).
     * VectorCapability::ensureDimension() legt die Spalte/den Index beim allerersten Aufruf
     * an bzw. baut sie neu auf, wenn die Dimension seit dem letzten Lauf abweicht (anderes
     * Embedding-Modell gewaehlt) - kein Aufwand im Normalfall, in dem die Dimension schon
     * passt. rex_sql::setRawValue() statt setValue(), weil VEC_FromText(...) ein SQL-
     * Funktionsaufruf ist, kein literaler Spaltenwert; der JSON-Array-String besteht nur
     * aus vom Embedding-Provider gelieferten Zahlen, kein Injection-Risiko.
     *
     * @param float[] $embedding
     */
    private function setEmbeddingColumns(rex_sql $sql, array $embedding): void
    {
        $sql->setValue('embedding', json_encode($embedding));
        $sql->setValue('embedding_norm', $this->vectorMagnitude($embedding));

        $dimension = count($embedding);
        if ($dimension > 0 && VectorCapability::isSupported()) {
            VectorCapability::ensureDimension($dimension);
            $vectorLiteral = json_encode(array_values($embedding));
            if (false !== $vectorLiteral) {
                $sql->setRawValue(VectorIndexInstaller::columnName(), 'VEC_FromText(\'' . addslashes($vectorLiteral) . '\')');
            }
        }
    }

    /**
     * @param float[] $vector
     */
    private function vectorMagnitude(array $vector): float
    {
        $sumOfSquares = 0.0;
        foreach ($vector as $value) {
            $sumOfSquares += $value * $value;
        }

        return sqrt($sumOfSquares);
    }

    private function prepareEmbeddingText(string $text, string $title = '', string $url = '', string $sourceType = '', ?rex_article $article = null): string
    {
        $normalized = trim((string) $text);
        if ($normalized === '') {
            return '';
        }

        $meta = [];
        if ($title !== '') {
            $meta[] = 'Titel: ' . trim($title);
        }
        if ($url !== '') {
            $meta[] = 'URL: ' . trim($url);
        }
        if ($sourceType !== '') {
            $meta[] = 'Typ: ' . str_replace('_', ' ', $sourceType);
        }

        // Kategorie-/Struktur-Pfad: bei einem echten REDAXO-Artikel die tatsaechliche
        // Kategorie-Hierarchie (zuverlaessig), sonst - falls vorhanden - die Ordnerstruktur
        // aus der URL geraten (Sitemap-Inhalte/YForm-URLs haben keine REDAXO-Kategorie).
        // Beides fliesst NUR in den Embedding-Text, nicht in die gespeicherte "content"-Spalte
        // (siehe stripIndexMetadataPrefix()-Gegenstueck in ChatQueryService fuer die URL-Zeile
        // oben, die aus genau diesem Grund dort wieder entfernt wird).
        if ($this->isCategoryPathEnabled()) {
            $categoryPath = null !== $article
                ? $this->buildCategoryPathLabel($article->getCategoryId())
                : $this->buildUrlPathCategoryLabel($url);
            if ('' !== $categoryPath) {
                $meta[] = 'Kategorie: ' . $categoryPath;
            }
        }

        if (null !== $article) {
            $metainfoKeywords = $this->buildMetainfoKeywordsText($article);
            if ('' !== $metainfoKeywords) {
                $meta[] = 'Zusätzliche Keywords: ' . $metainfoKeywords;
            }
        }

        $contextHint = $this->getEmbeddingContextHint($sourceType);
        if ($contextHint !== '') {
            $meta[] = 'Kontext-Hinweis: ' . $contextHint;
        }

        foreach ($this->getEmbeddingFocusRules($sourceType) as $rule) {
            $label = $rule['label'];
            $pattern = $rule['pattern'];
            if ($pattern !== '' && preg_match($pattern, $normalized)) {
                $meta[] = 'Wichtige Fakten: ' . $label;
            }
        }

        if (preg_match('/(kontakt|ansprechpartner|telefon|e-mail|email|mail|adresse|hilfe|support|faq)/iu', $normalized) && !in_array('Wichtige Fakten: Ansprechpartner-/Kontaktinformationen', $meta, true)) {
            $meta[] = 'Wichtige Fakten: Ansprechpartner-/Kontaktinformationen';
        }

        $metaText = $meta !== [] ? implode("\n", array_values(array_unique($meta))) . "\n\nInhalt:\n" : '';

        return trim($metaText . $normalized);
    }

    private function isCategoryPathEnabled(): bool
    {
        return (bool) \rex_addon::get('ai_chat')->getConfig('embedding_category_path_enabled', true);
    }

    /**
     * Respektiert eine EXPLIZITE "noindex"-Markierung eines Artikels ueber yrewrites
     * Metainfo-Feld "yrewrite_index" (rex_yrewrite_seo::$meta_index_field), wenn der Schalter
     * "yrewrite-SEO-Einstellungen respektieren" aktiv ist (Standard: an). Wert `2` = explizit
     * "noindex, follow" (siehe rex_yrewrite_seo::getTags()) - das ist die einzige Stufe, die
     * hier geprueft wird. Der Online/Offline-Status (Wert `0`/leer = Standard, dort zusaetzlich
     * von isOnline() abhaengig) bleibt bewusst AUSSEN VOR: ein Mountpoint ist eine bewusste,
     * enge Auswahl, die unabhaengig vom Online/Offline-Filter indexiert wird (siehe
     * updateArticleIndex()) - dieses Verhalten soll sich durch diesen Schalter nicht aendern,
     * nur eine explizite Redakteurs-Entscheidung "diese Seite nicht indexieren" soll wirken.
     */
    private function isExcludedByYrewriteSeo(rex_article $article): bool
    {
        if (!\rex_addon::exists('yrewrite') || !\rex_addon::get('yrewrite')->isAvailable()) {
            return false;
        }

        if (!(bool) \rex_addon::get('ai_chat')->getConfig('index_respect_yrewrite_seo', true)) {
            return false;
        }

        return 2 === (int) $article->getValue('yrewrite_index');
    }

    /**
     * Echte REDAXO-Kategorie-Hierarchie eines Artikels als "A > B > C"-Pfad (Wurzel zuerst),
     * z.B. "Agentur > Leistungen > Webentwicklung" - zuverlässiger als das URL-Raten in
     * buildUrlPathCategoryLabel(), weil hier eine echte Struktur existiert statt einer
     * Heuristik. Tiefenbegrenzung als Schutz vor (praktisch nie vorkommenden) Zyklen.
     */
    private function buildCategoryPathLabel(int $categoryId): string
    {
        $names = [];
        $current = \rex_category::get($categoryId);
        $depth = 0;
        while (null !== $current && $depth < 10) {
            $name = trim($current->getName());
            if ('' !== $name) {
                $names[] = $name;
            }
            $current = $current->getParent();
            ++$depth;
        }

        return implode(' > ', array_reverse($names));
    }

    /**
     * Fallback fuer Inhalte ohne echte REDAXO-Kategorie (Sitemap-Seiten, YForm-/Provider-URLs):
     * die URL-Pfadsegmente als grobe Kategorie-Naeherung, z.B.
     * "/agentur/leistungen/webentwicklung/" -> "Agentur > Leistungen > Webentwicklung". Nur so
     * gut wie die tatsaechliche URL-Struktur der Seite - bei sprechenden Slugs hilfreich, bei
     * z.B. "/de/node/12345" liefert das nichts Sinnvolles und wird deshalb einzeln verworfen.
     */
    private function buildUrlPathCategoryLabel(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment));
        if ($segments === []) {
            return '';
        }

        // Letztes Segment nur verwerfen, wenn es erkennbar eine einzelne Detailseite ist statt
        // einer Kategorie-Ebene: REDAXO haengt bei Slug-Kollisionen automatisch "-<Zahl>" an
        // (z.B. ".../referenz/klxm-launcht-...-77"), und ein sehr langes, satzartiges Segment
        // ist ebenfalls eher ein Artikeltitel als ein Kategoriename (der Titel selbst steht
        // ohnehin schon in der separaten "Titel:"-Zeile, keine Dopplung noetig). Ein kurzes,
        // sprechendes letztes Segment wie "web"/"webentwicklung"/"kontakt" ist dagegen meist
        // die eigentliche Kategorie/Seite selbst und bleibt erhalten.
        $lastSegment = end($segments);
        $looksLikeDetailSlug = 1 === preg_match('/-\d+$/', $lastSegment) || mb_strlen($lastSegment) > 40;
        if ($looksLikeDetailSlug && count($segments) > 1) {
            array_pop($segments);
        }
        $segments = array_slice($segments, 0, 4);

        $labels = [];
        foreach ($segments as $segment) {
            $segment = preg_replace('/\.\w{2,5}$/', '', $segment) ?? $segment;
            $words = array_filter(preg_split('/[-_]+/', $segment) ?: [], static fn (string $word): bool => '' !== $word);
            if ($words === []) {
                continue;
            }
            // Rein numerische Segmente (IDs) tragen keine lesbare Bedeutung.
            $label = implode(' ', array_map('ucfirst', $words));
            if (!ctype_digit(str_replace(' ', '', $label))) {
                $labels[] = $label;
            }
        }

        return implode(' > ', $labels);
    }

    /**
     * @return list<string>
     */
    private function getConfiguredMetainfoFields(): array
    {
        $raw = trim((string) \rex_addon::get('ai_chat')->getConfig('embedding_metainfo_fields', ''));
        if ('' === $raw) {
            return [];
        }

        $fields = array_map('trim', explode(',', $raw));

        return array_values(array_filter($fields, static fn (string $field): bool => '' !== $field));
    }

    /**
     * Liest die in den Einstellungen ("Chunking & Cache") konfigurierten Metainfo-Felder eines
     * Artikels aus (z.B. eine eigene "Meta-Keywords"-Spalte) und reiht ihre Werte als
     * zusaetzlichen Kontext-Hinweis fuers Embedding ein - anders als JSON-LD (feste
     * schema.org-Struktur) sind Metainfo-Feldnamen frei pro Installation vergeben, deshalb
     * konfigurierbar statt fest verdrahtet.
     */
    private function buildMetainfoKeywordsText(rex_article $article): string
    {
        $fields = $this->getConfiguredMetainfoFields();
        if ($fields === []) {
            return '';
        }

        $values = [];
        foreach ($fields as $field) {
            $value = trim((string) $article->getValue($field));
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return implode(' | ', array_values(array_unique($values)));
    }

    private function getEmbeddingContextHint(string $sourceType = ''): string
    {
        $global = trim((string) \rex_addon::get('ai_chat')->getConfig('embedding_context_hint', ''));
        $sourceSpecific = trim((string) \rex_addon::get('ai_chat')->getConfig('embedding_context_hint_sources', ''));

        if ($sourceSpecific !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $sourceSpecific) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '' || !preg_match('/^([a-z0-9_]+)\s*=\s*(.+)$/i', $line, $matches)) {
                    continue;
                }

                $key = strtolower(trim($matches[1]));
                $value = trim($matches[2]);
                if ($key === strtolower((string) $sourceType) && $value !== '') {
                    return $value;
                }
            }
        }

        return $global;
    }

    /**
     * @return list<array{label: string, pattern: string}>
     */
    private function getEmbeddingFocusRules(string $sourceType = ''): array
    {
        $allRules = [];
        $globalRaw = (string) \rex_addon::get('ai_chat')->getConfig('embedding_focus_rules', '');
        if (trim($globalRaw) !== '') {
            $allRules = array_merge($allRules, $this->parseEmbeddingFocusRules($globalRaw));
        }

        $sourceSpecificRaw = (string) \rex_addon::get('ai_chat')->getConfig('embedding_focus_rules_sources', '');
        if ($sourceSpecificRaw !== '' && $sourceType !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $sourceSpecificRaw) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line === '' || !preg_match('/^([a-z0-9_]+)\s*=\s*(.+)$/i', $line, $matches)) {
                    continue;
                }

                if (strtolower(trim($matches[1])) !== strtolower($sourceType)) {
                    continue;
                }

                $allRules = array_merge($allRules, $this->parseEmbeddingFocusRules(trim($matches[2])));
                break;
            }
        }

        return $allRules;
    }

    /**
     * @return list<array{label: string, pattern: string}>
     */
    private function parseEmbeddingFocusRules(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $rules = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $label = 'Spezialthema';
            $terms = $line;

            if (preg_match('/^(.+?)\s*=\s*(.+)$/', $line, $matches)) {
                $label = trim($matches[1]);
                $terms = trim($matches[2]);
            } elseif (preg_match('/^(.+?)\s*\|\s*(.+)$/', $line, $matches)) {
                $firstPart = trim($matches[1]);
                $rest = trim($matches[2]);
                if ($firstPart !== '' && $rest !== '' && !preg_match('/\s*[,|]\s*/', $firstPart)) {
                    $label = $firstPart;
                    $terms = $rest;
                }
            }

            if ($label === '' || $terms === '') {
                continue;
            }

            $termList = preg_split('/\s*\|\s*|\s*,\s*/', $terms, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($termList === []) {
                continue;
            }

            $quotedTerms = [];
            foreach ($termList as $term) {
                $term = trim((string) $term);
                if ($term === '') {
                    continue;
                }
                $quotedTerms[] = preg_quote($term, '/');
            }

            if ($quotedTerms === []) {
                continue;
            }

            $rules[] = [
                'label' => $label,
                'pattern' => '/(?:' . implode('|', $quotedTerms) . ')/iu',
            ];
        }

        return $rules;
    }

    /**
     * Chunks text into overlapping segments for better RAG retrieval.
     *
     * @return string[]
     */
    private function chunkText(string $text, ?int $size = null, ?int $overlap = null): array
    {
        $addon = \rex_addon::get('ai_chat');
        $size = $size ?? max(200, (int) $addon->getConfig('chunk_size', 1000));
        $overlap = $overlap ?? max(0, (int) $addon->getConfig('chunk_overlap', 200));
        // Overlap darf nie so groß werden, dass er den Chunk faktisch verdoppelt.
        $overlap = min($overlap, (int) ($size / 2));

        $normalized = trim($text);
        if ($normalized === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n+/', $normalized) ?: [$normalized];
        $blocks = [];
        foreach ($paragraphs as $paragraph) {
            $block = trim((string) $paragraph);
            if ($block === '') {
                continue;
            }
            $blocks[] = $block;
        }

        if ($blocks === []) {
            return [$normalized];
        }

        $chunks = [];
        $current = '';
        foreach ($blocks as $block) {
            $candidate = $current === '' ? $block : $current . "\n\n" . $block;
            if (mb_strlen($candidate) <= $size) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/', $block) ?: [$block];
            $sentenceBuffer = '';
            foreach ($sentences as $sentence) {
                $sentence = trim((string) $sentence);
                if ($sentence === '') {
                    continue;
                }

                $sentenceCandidate = $sentenceBuffer === '' ? $sentence : $sentenceBuffer . ' ' . $sentence;
                if (mb_strlen($sentenceCandidate) <= $size) {
                    $sentenceBuffer = $sentenceCandidate;
                    continue;
                }

                if ($sentenceBuffer !== '') {
                    $chunks[] = $sentenceBuffer;
                }
                $sentenceBuffer = $sentence;
            }

            if ($sentenceBuffer !== '') {
                $current = $sentenceBuffer;
                continue;
            }

            $current = $block;
        }

        // $current ist an dieser Stelle nie leer: jeder Codepfad oben weist ihm vor
        // Verlassen der Schleife entweder einen nicht-leeren $block/$candidate/$sentenceBuffer zu.
        $chunks[] = $current;

        $finalChunks = [];
        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                $finalChunks[] = $chunk;
                continue;
            }

            $previous = $finalChunks[count($finalChunks) - 1] ?? '';
            if ($previous !== '' && mb_strlen($previous) < $size && mb_strlen($chunk) < $size && mb_strlen($previous . ' ' . $chunk) <= $size) {
                $finalChunks[count($finalChunks) - 1] = $previous . ' ' . $chunk;
                continue;
            }

            $finalChunks[] = $chunk;
        }

        return $overlap > 0 ? $this->applyChunkOverlap($finalChunks, $overlap) : $finalChunks;
    }

    /**
     * Prepends the tail of the previous chunk to each following chunk so that
     * facts split across a chunk boundary (e.g. contact name in one chunk,
     * phone number in the next) still appear together in at least one chunk.
     *
     * @param string[] $chunks
     * @return string[]
     */
    private function applyChunkOverlap(array $chunks, int $overlap): array
    {
        if (count($chunks) < 2) {
            return $chunks;
        }

        $result = [$chunks[0]];
        for ($i = 1, $count = count($chunks); $i < $count; ++$i) {
            $previous = $chunks[$i - 1];
            $tail = mb_substr($previous, -$overlap);

            // An einer Wortgrenze beginnen, damit kein Wort mitten durchgeschnitten wird.
            $spacePos = mb_strpos($tail, ' ');
            if ($spacePos !== false && $spacePos < mb_strlen($tail) - 1) {
                $tail = mb_substr($tail, $spacePos + 1);
            }

            $tail = trim($tail);
            $result[] = $tail !== '' ? $tail . "\n\n" . $chunks[$i] : $chunks[$i];
        }

        return $result;
    }
}
