<?php

namespace FriendsOfRedaxo\AiChat\Service;

use FriendsOfRedaxo\AiChat\ContentProvider\ContentProviderRegistry;
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
     * @return list<array<string, mixed>>
     */
    public function collectTasks(): array
    {
        $tasks = [];
        $addon = \rex_addon::get('ai_chat');

        $indexSource = $addon->getConfig('index_source', 'structure');
        $includeStructure = in_array($indexSource, ['structure', 'structure_sitemap'], true);
        $includeSitemap = in_array($indexSource, ['sitemap', 'structure_sitemap'], true);
        $sitemapOnlyMode = $includeSitemap && !$includeStructure;

        // 1. Collect Frontend Content (either via structure or sitemap)
        if ($includeSitemap) {
            $allUrls = [];
            foreach (self::parseSitemapUrls((string) $addon->getConfig('index_sitemap_url', '')) as $sitemapUrl) {
                $allUrls = array_merge($allUrls, $this->fetchSitemapUrls($sitemapUrl));
            }

            // Mehrere Sitemaps können sich überlappende Seiten liefern (z.B. eine
            // Haupt- und eine Bereichs-Sitemap) - einmal über alle hinweg dedupen,
            // statt jede URL pro Sitemap erneut zu indizieren.
            foreach (array_unique($allUrls) as $url) {
                $tasks[] = [
                    'type' => 'url',
                    'url'  => $url
                ];
            }
        }

        if ($includeStructure) {
            // Standard REDAXO structure collection
            // Default to true if not set (first install)
            if ((bool) $addon->getConfig('index_frontend', 1)) {
                $clangs = rex_clang::getAllIds();

                // Article status filter
                $articleStatus = $addon->getConfig('index_article_status', 'online');
                $where = match ($articleStatus) {
                    'all'     => '1=1',
                    'offline' => 'status = 0',
                    default   => 'status = 1',   // 'online'
                };

                // Exclude individual articles
                $excludeArticles = [];
                $excludeArticleConfig = $addon->getConfig('index_exclude_articles');
                if (!empty($excludeArticleConfig)) {
                    $excludeArticles = array_filter(array_map('intval', explode(',', $excludeArticleConfig)));
                }

                // Exclude categories (incl. all subcategories recursively)
                $excludeCategoryIds = [];
                $excludeCatConfig = $addon->getConfig('index_exclude_categories');
                if (!empty($excludeCatConfig)) {
                    $rootIds = [];
                    if (is_array($excludeCatConfig)) {
                        $rootIds = array_map('intval', $excludeCatConfig);
                    } else {
                        $split = preg_split('/[\s,|]+/', (string) $excludeCatConfig);
                        $rootIds = array_filter(array_map('intval', is_array($split) ? $split : []));
                    }

                    foreach ($rootIds as $catId) {
                        $excludeCategoryIds = array_merge($excludeCategoryIds, $this->getCategoryIdsRecursive($catId));
                    }
                    $excludeCategoryIds = array_unique($excludeCategoryIds);
                }

                // yrewrite steuert pro Artikel Indexierbarkeit/Sitemap-Sichtbarkeit über das
                // Metainfo-Feld 'yrewrite_index' (siehe rex_yrewrite_seo::sendSitemap()). Bei der
                // internen (nicht HTTP-Crawler-)Indexierung soll ein Artikel, den yrewrite selbst
                // aus Sitemap/Suchmaschinen-Index ausschließt, standardmäßig auch hier ausgeschlossen werden.
                $respectYrewriteSeo = (bool) $addon->getConfig('index_respect_yrewrite_seo', 1)
                    && \rex_addon::get('yrewrite')->isAvailable();

                foreach ($clangs as $clangId) {
                    $articleSql = rex_sql::factory();
                    $articleSql->setQuery(
                        'SELECT id, parent_id FROM ' . \rex::getTable('article') . ' WHERE ' . $where . ' AND clang_id = ?',
                        [$clangId]
                    );

                    foreach ($articleSql as $row) {
                        $id         = (int) $row->getValue('id');
                        $categoryId = (int) $row->getValue('parent_id');

                        // Skip excluded articles
                        if (in_array($id, $excludeArticles, true)) {
                            continue;
                        }

                        // Skip articles in excluded categories. Der Start-Artikel einer Kategorie
                        // hat parent_id = Elternkategorie, aber seine eigene Artikel-id === Kategorie-id
                        // (siehe rex_article::getCategoryId()/isStartArticle()) - deshalb muss zusätzlich
                        // die eigene id geprüft werden, sonst wird der Start-Artikel einer ausgeschlossenen
                        // Kategorie selbst nie ausgeschlossen.
                        if (!empty($excludeCategoryIds) && (in_array($categoryId, $excludeCategoryIds, true) || in_array($id, $excludeCategoryIds, true))) {
                            continue;
                        }

                        if ($respectYrewriteSeo && $this->isExcludedByYrewriteSeo($id, $clangId)) {
                            continue;
                        }

                        $tasks[] = [
                            'type'  => 'article',
                            'id'    => $id,
                            'clang' => $clangId,
                        ];
                    }
                }
            }
        }

        // 2. Collect Addon Docs (unabhängig von index_source, eigene Zusatzquelle)
        $addonMode = $addon->getConfig('index_addons_mode', 'all');

        if ($addonMode !== 'none') {
            $selectedAddons = [];
            if ($addonMode === 'selected') {
                $list = $addon->getConfig('index_addons_list');
                if (is_string($list)) {
                    // Handle pipe separated string |addon1|addon2|
                    $selectedAddons = array_filter(explode('|', $list));
                } elseif (is_array($list)) {
                    $selectedAddons = $list;
                }
            }

            $addons = \rex_addon::getAvailableAddons();
            foreach ($addons as $a) {
                $addonKey = $a->getPackageId();
                
                // Skip if mode is selected and addon is not in list
                if ($addonMode === 'selected' && !in_array($addonKey, $selectedAddons)) {
                    continue;
                }

                $path = $a->getPath();
                
                // Root files
                $files = ['README.md', 'README.de.md', 'API.md', 'CHANGELOG.md'];
                foreach ($files as $file) {
                    if (file_exists($path . $file)) {
                        $tasks[] = [
                            'type' => 'file',
                            'path' => $path . $file,
                            'addon' => $addonKey,
                            'relPath' => $file
                        ];
                    }
                }

                // Docs directory
                if (is_dir($path . 'docs')) {
                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path . 'docs'));
                    foreach ($iterator as $fileInfo) {
                        if ($fileInfo->isFile() && $fileInfo->getExtension() === 'md') {
                            $relativePath = str_replace($path, '', $fileInfo->getPathname());
                            $tasks[] = [
                                'type' => 'file',
                                'path' => $fileInfo->getPathname(),
                                'addon' => $addonKey,
                                'relPath' => $relativePath
                            ];
                        }
                    }
                }
            }
        }

        // 3. Collect GitHub Docs (unabhängig von index_source, eigene Zusatzquelle)
        $githubTasks = $this->collectGithubTasks();
        $tasks = array_merge($tasks, $githubTasks);

        // 4. Collect optional content providers (e.g. forcal)
        foreach ($this->providerRegistry->getEnabledProviders($addon) as $provider) {
            try {
                $tasks = array_merge($tasks, $provider->collectTasks());
            } catch (\Throwable $e) {
                \rex_logger::logException($e);
            }
        }

        // 5. Profil-eigene Quellen (YForm-Auswahl/Sitemap/Mountpoint je Profil) -
        // zusaetzlich zum obigen Shared Pool, mit chat_profile_id markiert.
        $tasks = array_merge($tasks, $this->collectProfileTasks());

        $modeLabel = $sitemapOnlyMode ? 'sitemap-plus-providers' : $indexSource;
        \rex_logger::factory()->info('AiChat Indexer: Collected {count} tasks ({mode}).', ['count' => count($tasks), 'mode' => $modeLabel]);

        return $tasks;
    }

    /**
     * Sammelt Tasks für profil-eigene Quellen (siehe ChatProfile::$yformProfileIds/
     * $indexSource/$sitemapUrls/$mountpointCategoryId) - zusätzlich zum globalen
     * Shared Pool oben. Jeder Task bekommt 'chat_profile_id' (ID aus
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

            if ('sitemap' === $profile->indexSource && [] !== $profile->sitemapGroups) {
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

            if ('mountpoint' === $profile->indexSource && null !== $profile->mountpointCategoryId && $profile->mountpointCategoryId > 0) {
                foreach (rex_clang::getAllIds() as $clangId) {
                    foreach ($this->collectArticlesUnderCategory($profile->mountpointCategoryId, $clangId) as $articleId) {
                        $tasks[] = [
                            'type' => 'article',
                            'id' => $articleId,
                            'clang' => $clangId,
                            'chat_profile_id' => $profile->id,
                        ];
                    }
                }
            }
        }

        return $tasks;
    }

    /**
     * Alle Artikel-IDs unterhalb (inkl.) einer Kategorie, für eine bestimmte
     * Sprache - Grundlage für ein Profil mit `index_source = mountpoint`.
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
     * @return array{processed: int, skipped: int, errors: int}
     */
    public function sync(int $maxItems = 0): array
    {
        $tasks = $this->collectTasks();
        $stats = ['processed' => 0, 'skipped' => 0, 'errors' => 0];
        
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
                } elseif ($task['type'] === 'file') {
                    $sourceType = 'addon_docs';
                    $sourceId = $task['addon'] . '/' . $task['relPath'];
                    if (!file_exists($task['path'])) continue;
                    $currentUpdateDate = filemtime($task['path']);
                } elseif ($task['type'] === 'github_file') {
                    $sourceType = 'github_docs';
                    $sourceId = 'github:' . $task['repo'] . '/' . $task['relPath'];
                    if (!file_exists($task['path'])) continue;
                    $currentUpdateDate = filemtime($task['path']);
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
                    $this->processTask($task);
                    $stats['processed']++;
                } else {
                    $stats['skipped']++;
                }

            } catch (\Exception $e) {
                $stats['errors']++;
                \rex_logger::logException($e);
            }
        }

        return $stats;
    }

    /**
     * @return array{status: string, message?: string, messages?: list<string>}
     */
    public function updateGithubSources(): array
    {
        $reposConfig = \rex_addon::get('ai_chat')->getConfig('github_repos');
        if (empty($reposConfig)) return ['status' => 'error', 'message' => 'No repos configured'];

        $repos = explode("\n", $reposConfig);
        $targetBaseDir = \rex_path::addonData('ai_chat', 'github_repos');
        
        if (!is_dir($targetBaseDir)) {
            \rex_dir::create($targetBaseDir);
        }

        $results = [];

        foreach ($repos as $repo) {
            $repo = trim($repo);
            if (empty($repo)) continue;
            
            try {
                $this->downloadRepo($repo, $targetBaseDir);
                $results[] = "Updated $repo";
            } catch (\Exception $e) {
                $results[] = "Failed $repo: " . $e->getMessage();
                \rex_logger::logException($e);
            }
        }
        
        return ['status' => 'success', 'messages' => $results];
    }

    private function downloadRepo(string $repo, string $baseDir): void
    {
        $token = \rex_addon::get('ai_chat')->getConfig('github_token');
        $zipPath = $baseDir . '/temp.zip';
        
        // Try main branch first
        $url = "https://github.com/$repo/archive/refs/heads/main.zip";
        if (!$this->downloadFile($url, $zipPath, $token)) {
            // Try master branch
            $url = "https://github.com/$repo/archive/refs/heads/master.zip";
            if (!$this->downloadFile($url, $zipPath, $token)) {
                throw new \Exception("Could not download ZIP for $repo (tried main and master)");
            }
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) === TRUE) {
            $extractPath = $baseDir . '/temp_extract_' . md5($repo);
            \rex_dir::create($extractPath);
            $zip->extractTo($extractPath);
            $zip->close();
            
            $folders = glob($extractPath . '/*', GLOB_ONLYDIR);
            $folders = is_array($folders) ? $folders : [];
            if (count($folders) > 0) {
                $innerFolder = $folders[0];
                $repoTargetDir = $baseDir . '/' . $repo;
                
                \rex_dir::delete($repoTargetDir);
                
                // Ensure parent dir exists (e.g. owner)
                $repoParts = explode('/', $repo);
                if (count($repoParts) === 2) {
                     \rex_dir::create($baseDir . '/' . $repoParts[0]);
                }

                // Use rex_dir::copy instead of rename
                \rex_dir::copy($innerFolder, $repoTargetDir);
            }
            
            \rex_dir::delete($extractPath);
            unlink($zipPath);
        } else {
            throw new \Exception("Failed to open ZIP for $repo");
        }
    }

    private function downloadFile(string $url, string $destination, ?string $token = null): bool
    {
        try {
            if ($url === '') {
                throw new \Exception('Download URL is empty');
            }

            if (!function_exists('curl_init')) {
                throw new \Exception('cURL extension is required');
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'REDAXO-AiChat');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            $headers = [
                'Accept: application/zip, application/octet-stream, */*',
                'Cache-Control: no-cache'
            ];
            if ($token) {
                $headers[] = 'Authorization: token ' . trim($token);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($content === false || $httpCode !== 200 || !empty($error)) {
                if ($httpCode === 403) {
                    throw new \Exception("GitHub API 403 Forbidden");
                }
                if ($httpCode === 404) {
                    return false; // Not found, try next branch
                }
                throw new \Exception("Download failed: HTTP $httpCode " . ($error ? "($error)" : ""));
            }

            return \rex_file::put($destination, (string) $content);
        } catch (\Exception $e) {
            \rex_logger::logException($e);
            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectGithubTasks(): array
    {
        $tasks = [];
        $reposConfig = \rex_addon::get('ai_chat')->getConfig('github_repos');
        
        if (empty($reposConfig)) {
            return [];
        }

        $repos = explode("\n", $reposConfig);
        $baseDir = \rex_path::addonData('ai_chat', 'github_repos');

        foreach ($repos as $repo) {
            $repo = trim($repo);
            if (empty($repo)) continue;

            $repoDir = $baseDir . '/' . $repo;
            if (!is_dir($repoDir)) continue;

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repoDir));
            foreach ($iterator as $fileInfo) {
                // Skip hidden files/dirs starting with .
                if (str_starts_with($fileInfo->getFilename(), '.')) continue;
                
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'md') {
                    $relativePath = str_replace($repoDir . '/', '', $fileInfo->getPathname());
                    
                    $tasks[] = [
                        'type' => 'github_file',
                        'repo' => $repo,
                        'path' => $fileInfo->getPathname(),
                        'relPath' => $relativePath,
                        'html_url' => "https://github.com/$repo/blob/main/" . $relativePath
                    ];
                }
            }
        }

        return $tasks;
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
            } elseif ($type === 'file') {
                $result['title'] = $task['addon'] . ': ' . $task['relPath'];
                $result['chunks'] = $this->indexFile($task['path'], $task['addon'], $task['relPath']);
            } elseif ($type === 'github_file') {
                $result['title'] = $task['repo'] . '/' . $task['relPath'];
                $result['chunks'] = $this->indexGithubFile($task);
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
     * @param array<string, mixed> $task
     */
    private function indexGithubFile(array $task): int
    {
        $chunkCount = 0;
        try {
            $content = \rex_file::get($task['path']);
            if (empty($content)) return 0;

            $cleanText = $this->cleanText($content);

            if (empty($cleanText)) return 0;

            $chunks = $this->chunkText($cleanText);

            if ($chunks !== []) {
                $semanticChunks = [];
                foreach ($chunks as $chunk) {
                    $semanticChunks[] = $this->prepareEmbeddingText($chunk, $task['relPath'] ?? '', $task['html_url'] ?? '', 'github_docs');
                }
                $embeddings = $this->aiService->getEmbeddings($semanticChunks);

                foreach ($chunks as $i => $chunk) {
                    $embedding = $embeddings[$i] ?? null;
                    if ($embedding === null) {
                        continue;
                    }

                    $sql = rex_sql::factory();
                    $sql->setTable(\rex::getTable('ai_chat_index'));
                    $sql->setValue('source_type', 'github_docs');
                    $sql->setValue('source_id', 'github:' . $task['repo'] . '/' . $task['relPath']);
                    // Kein Chunk-Index-Suffix - siehe Kommentar bei processTask() weiter oben.
                    $sql->setValue('title', $task['repo'] . ': ' . $task['relPath']);
                    $sql->setValue('content', $chunk);
                    $this->setEmbeddingColumns($sql, $embedding);
                    $sql->setValue('url', $task['html_url']);
                    $sql->setDateTimeValue('updatedate', time());
                    $sql->insert();
                    ++$chunkCount;
                }
            }
        } catch (\Exception $e) {
            // Re-throw or log and throw
            \rex_logger::logException($e);
            throw $e;
        }
        return $chunkCount;
    }

    /**
     * Runs a complete reindex (GitHub-Quellen aktualisieren -> Index leeren ->
     * Aufgaben sammeln -> jede Aufgabe verarbeiten) in einem Rutsch. Genutzt vom
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
        // Gleiche Bedingung wie im browsergesteuerten Ablauf (ai-chat-indexer.js
        // handleStart()): im reinen "sitemap"-Modus wird der GitHub-Sync bewusst
        // übersprungen, "structure_sitemap" und "structure" führen ihn weiterhin aus.
        if ('sitemap' !== \rex_addon::get('ai_chat')->getConfig('index_source', 'structure')) {
            $this->updateGithubSources();
        }
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
            $chunks += (int) ($result['chunks'] ?? 0);
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
            'file' => 'Addon-Dok: ' . ($task['addon'] ?? '?') . '/' . ($task['relPath'] ?? '?'),
            'github_file' => 'GitHub: ' . ($task['repo'] ?? '?') . '/' . ($task['relPath'] ?? '?'),
            'url' => 'Sitemap: ' . ($task['url'] ?? '?'),
            'provider_item' => 'Provider ' . ($task['provider'] ?? '?') . ': ' . ($task['title'] ?? $task['source_id'] ?? '?'),
            default => '' !== $type ? $type : 'Element',
        };
    }

    private function indexFile(string $filePath, string $addonKey, string $relativePath): int
    {
        $chunkCount = 0;
        $content = \rex_file::get($filePath);
        if (empty($content)) return 0;

        $cleanText = $this->cleanText($content);

        if (empty($cleanText)) return 0;

        $chunks = $this->chunkText($cleanText);
        if ($chunks === []) {
            return 0;
        }

        $semanticChunks = [];
        foreach ($chunks as $chunk) {
            $semanticChunks[] = $this->prepareEmbeddingText($chunk, $relativePath, '', 'addon_docs');
        }
        $embeddings = $this->aiService->getEmbeddings($semanticChunks);

        foreach ($chunks as $i => $chunk) {
            $embedding = $embeddings[$i] ?? null;
            if ($embedding === null) {
                continue;
            }

            $sql = rex_sql::factory();
            $sql->setTable(\rex::getTable('ai_chat_index'));
            $sql->setValue('source_type', 'addon_docs');
            $sql->setValue('source_id', $addonKey . '/' . $relativePath);
            // Kein Chunk-Index-Suffix - siehe Kommentar bei processTask() weiter oben.
            $sql->setValue('title', $addonKey . ': ' . $relativePath);
            $sql->setValue('content', $chunk);
            $this->setEmbeddingColumns($sql, $embedding);
            $sql->setDateTimeValue('updatedate', time());
            $sql->insert();
            ++$chunkCount;
        }
        return $chunkCount;
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

            // Ausschluss-Selektoren (z.B. Navigation, Off-Canvas-Menue, Cookie-Banner) VOR der
            // Haupt-Selektor-Extraktion aus dem DOM entfernen - cleanText() strippt zwar bereits
            // <nav>/<header>/<footer>/<form>/<button>, erkennt aber z.B. ein UIkit-Off-Canvas-Menue
            // (<div id="sidebar-navi" ...>) nicht, das kein <nav>-Tag ist. War dieselbe Einstellung
            // wie processTask()s eigener HTTP-Crawl-Zweig (index_http_exclude_selectors), dort
            // aber schon laenger verdrahtet - hier bisher schlicht nie ausgewertet.
            $excludeSelectors = \rex_addon::get('ai_chat')->getConfig('index_http_exclude_selectors', '');
            if ('' !== trim((string) $excludeSelectors)) {
                $excludeXpath = new \DOMXPath($dom);
                foreach ($this->splitSelectorList((string) $excludeSelectors) as $excludeSelector) {
                    $excludeNodes = $excludeXpath->query($this->cssSelectorToXpath($excludeSelector));
                    if (!$excludeNodes instanceof \DOMNodeList) {
                        continue;
                    }
                    // Rueckwaerts entfernen, da removeChild() die (live) NodeList sonst veraendert.
                    for ($ei = $excludeNodes->length - 1; $ei >= 0; --$ei) {
                        $excludeNode = $excludeNodes->item($ei);
                        if ($excludeNode instanceof \DOMNode && $excludeNode->parentNode !== null) {
                            $excludeNode->parentNode->removeChild($excludeNode);
                        }
                    }
                }
            }

            // Extract content source (HTML) - unterstuetzt wie processTask()s HTTP-Crawl-Zweig
            // mehrere kommagetrennte Selektoren UND mehrere Treffer pro Selektor (z.B. wenn eine
            // Seite den Hauptinhalt auf zwei ".content"-Bloecke aufteilt) statt nur den ersten
            // Treffer des ersten Selektors zu nehmen.
            $sourceHtml = '';
            $selectorConfig = (string) \rex_addon::get('ai_chat')->getConfig('index_http_selector', 'body');

            if ($selectorConfig !== 'body' && '' !== trim($selectorConfig)) {
                $xpath = new \DOMXPath($dom);
                foreach ($this->splitSelectorList($selectorConfig) as $mainSelector) {
                    $nodes = $xpath->query($this->cssSelectorToXpath($mainSelector));
                    if (!$nodes instanceof \DOMNodeList) {
                        continue;
                    }
                    foreach ($nodes as $node) {
                        if ($node instanceof \DOMNode) {
                            $sourceHtml .= (string) $dom->saveHTML($node);
                        }
                    }
                }
            }
            
            if (empty($sourceHtml)) {
                $body = $dom->getElementsByTagName('body')->item(0);
                $sourceHtml = $body ? (string) $dom->saveHTML($body) : (string) $html;
            }

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
     * `index_sitemap_url` erlaubt mehrere Sitemaps, eine pro Zeile (Backward-
     * kompatibel: ein einzelner alter Wert ohne Zeilenumbruch ist einfach eine
     * Liste mit einem Eintrag). Kommas werden zusätzlich als Trenner akzeptiert,
     * falls jemand die alte einzeilige Konfiguration um weitere URLs erweitert
     * hat, ohne dabei auf mehrere Zeilen umzusteigen.
     *
     * @return list<string>
     */
    public static function parseSitemapUrls(string $raw): array
    {
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $urls = array_filter(array_map('trim', $parts), static fn (string $url): bool => $url !== '');

        return array_values(array_unique($urls));
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
     * Prüft, ob yrewrite diesen Artikel per Metainfo-Feld 'yrewrite_index' aus der Sitemap
     * und dem Suchmaschinen-Index ausschließt (Werte -1 = NoIndex, 2 = NoIndex+Follow, sowie
     * 0 = "Status" bei offline geschaltetem Artikel). Spiegelt exakt die Bedingung aus
     * rex_yrewrite_seo::sendSitemap() wider.
     */
    private function isExcludedByYrewriteSeo(int $articleId, int $clangId): bool
    {
        if (!class_exists('rex_yrewrite_seo')) {
            return false;
        }

        $article = \rex_article::get($articleId, $clangId);
        if (!$article) {
            return false;
        }

        $index = $article->getValue(\rex_yrewrite_seo::$meta_index_field) ?? \rex_yrewrite_seo::$index_setting_default;

        $allowed = 1 == $index || ($article->isOnline() && 0 == $index);

        return !$allowed;
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

    private function isExcluded(int $articleId, int $clangId): bool
    {
        $addon = \rex_addon::get('ai_chat');

        // 1. Check if indexing is generally enabled. REDAXO-Checkboxen speichern einen
        // aktivierten Wert als "|1|" (Pipe-umschlossen); ein reiner '0'/0-Vergleich erkennt
        // ein deaktiviertes Haekchen (gespeichert als leer/null) daher NIE.
        if (!(bool) $addon->getConfig('index_frontend', 1)) {
            return true;
        }

        // 2. Check individual article exclusion
        $excludeArticleConfig = $addon->getConfig('index_exclude_articles');
        if (!empty($excludeArticleConfig)) {
            $excludeArticles = array_filter(array_map('intval', explode(',', $excludeArticleConfig)));
            if (in_array($articleId, $excludeArticles, true)) {
                return true;
            }
        }

        // 3. Check category exclusion
        $excludeCatConfig = $addon->getConfig('index_exclude_categories');
        if (!empty($excludeCatConfig)) {
            $rootIds = [];
            if (is_array($excludeCatConfig)) {
                $rootIds = array_map('intval', $excludeCatConfig);
            } else {
                    $split = preg_split('/[\s,|]+/', (string) $excludeCatConfig);
                    $rootIds = array_filter(array_map('intval', is_array($split) ? $split : []));
            }

            if (!empty($rootIds)) {
                $article = rex_article::get($articleId, $clangId);
                if ($article) {
                    $categoryId = $article->getCategoryId();
                    
                    // We check if the category or any of its parents is in the excluded list
                    // This is more efficient than building the whole recursive list of all child IDs
                    $checkCat = \rex_category::get($categoryId);
                    while ($checkCat) {
                        if (in_array($checkCat->getId(), $rootIds, true)) {
                            return true;
                        }
                        $checkCat = $checkCat->getParent();
                    }
                    
                    // Special case: check if category 0 (root) is excluded (unlikely but possible)
                    if ($categoryId === 0 && in_array(0, $rootIds, true)) {
                        return true;
                    }
                }
            }
        }

        // 4. Check yrewrite Sitemap/Robots exclusion (yrewrite_index Metainfo-Feld)
        if (
            ((bool) $addon->getConfig('index_respect_yrewrite_seo', 1))
            && \rex_addon::get('yrewrite')->isAvailable()
            && $this->isExcludedByYrewriteSeo($articleId, $clangId)
        ) {
            return true;
        }

        return false;
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
        // Loescht ALLE Varianten dieses Artikels (Shared Pool + jedes Profil, das ihn
        // exklusiv via Mountpoint fuehrt) - unten wird der komplette, korrekte Satz an
        // Zeilen neu aufgebaut, kein partielles Update.
        $this->deleteArticleFromIndex($articleId, $clangId);

        $article = rex_article::get($articleId, $clangId);
        if (!$article) {
            return;
        }

        // Shared Pool: globaler Ausschluss (index_exclude_categories/yrewrite-SEO) betrifft
        // nur diesen Zweig, nicht profil-eigene Mountpoints weiter unten - ein Profil hat den
        // Artikel bewusst separat ausgewaehlt.
        if (!$this->isExcluded($articleId, $clangId)) {
            $addon = \rex_addon::get('ai_chat');
            $articleStatus = $addon->getConfig('index_article_status', 'online');
            $shouldIndex = match ($articleStatus) {
                'all'     => true,
                'offline' => !$article->isOnline(),
                default   => $article->isOnline(),
            };

            if ($shouldIndex) {
                $this->indexArticle($article, $clangId);
            }
        }

        // Profil-eigene Mountpoints: vorher fiel ein Artikel, der exklusiv zu einem Profil-
        // Mountpoint gehoert, beim naechsten Speichern auf profile_id=NULL (Shared Pool)
        // zurueck, bis der naechste volle Reindex-Lauf ihn wieder korrekt zuordnete (siehe
        // TODO.md). Keine article_status-Pruefung hier, analog zu collectProfileTasks() -
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
            if ('mountpoint' !== $profile->indexSource || null === $profile->mountpointCategoryId || $profile->mountpointCategoryId <= 0) {
                continue;
            }
            if (in_array($categoryId, $this->getCategoryIdsRecursive($profile->mountpointCategoryId), true)) {
                $profileIds[] = $profile->id;
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

    private function secureRemoteUrl(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'http' || !isset($parts['host'])) {
            return $url;
        }

        $host = strtolower((string) $parts['host']);
        $isPrivateIp = filter_var($host, FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        if ($host === 'localhost' || str_ends_with($host, '.local') || $isPrivateIp) {
            return $url;
        }

        return 'https://' . substr($url, 7);
    }

    public function indexArticle(rex_article $article, int $clangId, ?int $chatProfileId = null): int
    {
        $chunkCount = 0;
        $addon = \rex_addon::get('ai_chat');
        $method = $addon->getConfig('index_method', 'internal');
        $text = '';

        if ($method === 'http') {
            try {
                $url = $article->getUrl();
                // Ensure absolute URL
                if (!str_starts_with($url, 'http')) {
                    $server = \rex::getServer();
                    if (!$server) {
                        // Fallback if no server set
                        $server = \rex_yrewrite::getCurrentDomain()->getUrl();
                    }
                    $url = rtrim($server, '/') . '/' . ltrim($url, '/');
                }

                $url = $this->secureRemoteUrl($url);

                $socket = \rex_socket::factoryUrl($url);
                $response = $socket->doGet();
                
                if ($response->isOk()) {
                    $html = $response->getBody();
                    
                    // Extract content via DOMDocument
                    $dom = new \DOMDocument();
                    // Suppress HTML5 errors
                    libxml_use_internal_errors(true);
                    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
                    libxml_clear_errors();

                    $xpath = new \DOMXPath($dom);

                    // <style>/<script>/<noscript> IMMER aus dem DOM entfernen, BEVOR textContent
                    // gelesen wird. DOMDocument::textContent liefert bei diesen Elementen ihren
                    // rohen Inhalt (CSS/JS) mit - das Verstecken ist reine Browser-Rendering-
                    // Konvention, keine DOM-API-Garantie. Ohne diesen Schritt landet z.B. der
                    // komplette Inhalt eines <style>-Blocks der Navigation als Fließtext im Index,
                    // weil zu diesem Zeitpunkt keine <style>-Tags mehr existieren, an denen die
                    // Tag-Entfernung in cleanText() ansetzen könnte.
                    $noiseNodes = $xpath->query('//style|//script|//noscript');
                    if ($noiseNodes instanceof \DOMNodeList) {
                        // Rückwärts entfernen, da removeChild() die (live) NodeList sonst verändert.
                        for ($i = $noiseNodes->length - 1; $i >= 0; --$i) {
                            $noiseNode = $noiseNodes->item($i);
                            if ($noiseNode instanceof \DOMNode && $noiseNode->parentNode !== null) {
                                $noiseNode->parentNode->removeChild($noiseNode);
                            }
                        }
                    }

                    // Ausschluss-Selektoren (z.B. Navigation, Footer, Cookie-Banner) zuerst aus dem
                    // DOM entfernen, damit ihr Text weder im Haupt-Selektor noch im Body-Fallback landet.
                    $excludeSelectors = $addon->getConfig('index_http_exclude_selectors', '');
                    foreach ($this->splitSelectorList((string) $excludeSelectors) as $excludeSelector) {
                        $excludeNodes = $xpath->query($this->cssSelectorToXpath($excludeSelector));
                        if (!$excludeNodes instanceof \DOMNodeList) {
                            continue;
                        }
                        // Rückwärts entfernen, da removeChild() die NodeList sonst live verändert.
                        for ($i = $excludeNodes->length - 1; $i >= 0; --$i) {
                            $node = $excludeNodes->item($i);
                            if ($node instanceof \DOMNode && $node->parentNode !== null) {
                                $node->parentNode->removeChild($node);
                            }
                        }
                    }

                    $selector = (string) $addon->getConfig('index_http_selector', 'body');
                    $text = '';
                    foreach ($this->splitSelectorList($selector !== '' ? $selector : 'body') as $mainSelector) {
                        $nodes = $xpath->query($this->cssSelectorToXpath($mainSelector));
                        if (!$nodes instanceof \DOMNodeList) {
                            continue;
                        }
                        foreach ($nodes as $node) {
                            if ($node instanceof \DOMNode) {
                                $text .= $node->textContent . ' ';
                            }
                        }
                    }

                    if (trim($text) === '') {
                        // Fallback to body if selector not found
                        $bodyNode = $dom->getElementsByTagName('body')->item(0);
                        $text = $bodyNode ? $bodyNode->textContent : '';
                    }
                }
            } catch (\Exception $e) {
                \rex_logger::logException($e);
                // Fallback to internal
                $method = 'internal';
            }
        }

        if ($method === 'internal' || empty($text)) {
            // Simulate Frontend Environment for correct module output
            $isBackend = \rex::isBackend();
            $originalArticleId = \rex::getProperty('article_id');
            $originalClang = \rex::getProperty('clang');

            if ($isBackend) {
                \rex::setProperty('is_backend', false);
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
                    \rex::setProperty('is_backend', true);
                }
                \rex::setProperty('article_id', $originalArticleId);
                \rex::setProperty('clang', $originalClang);
            }
        }
        
        $cleanText = $this->cleanText($text);

        if (empty($cleanText)) return 0;

        $chunks = $this->chunkText($cleanText);
        if ($chunks === []) {
            return 0;
        }

        $semanticChunks = [];
        foreach ($chunks as $chunk) {
            $semanticChunks[] = $this->prepareEmbeddingText($chunk, $article->getName(), $article->getUrl(), 'article');
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
     * Splits a comma-separated list of CSS selectors (e.g. from a settings field)
     * into trimmed, non-empty selector strings.
     *
     * @return list<string>
     */
    private function splitSelectorList(string $raw): array
    {
        $parts = explode(',', $raw);
        $selectors = [];
        foreach ($parts as $part) {
            $selector = trim($part);
            if ($selector !== '') {
                $selectors[] = $selector;
            }
        }

        return $selectors;
    }

    /**
     * Converts a simple CSS selector (tag, #id or .class – ohne Anführungszeichen)
     * into an XPath query. Nur einfache Einzel-Selektoren werden unterstützt.
     */
    private function cssSelectorToXpath(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return '//body';
        }

        if (str_starts_with($selector, '#')) {
            return '//*[@id="' . substr($selector, 1) . '"]';
        }

        if (str_starts_with($selector, '.')) {
            return '//*[contains(concat(" ",normalize-space(@class)," ")," ' . substr($selector, 1) . ' ")]';
        }

        return '//' . $selector;
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

            if ($label !== '' && $attributes !== []) {
                $facts[] = trim($label . ' – ' . implode('; ', $attributes));
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

    private function prepareEmbeddingText(string $text, string $title = '', string $url = '', string $sourceType = ''): string
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

        $contextHint = $this->getEmbeddingContextHint($sourceType);
        if ($contextHint !== '') {
            $meta[] = 'Kontext-Hinweis: ' . $contextHint;
        }

        foreach ($this->getEmbeddingFocusRules($sourceType) as $rule) {
            $label = (string) ($rule['label'] ?? 'Spezialthema');
            $pattern = (string) ($rule['pattern'] ?? '');
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

        if ($current !== '') {
            $chunks[] = $current;
        }

        if ($chunks === []) {
            return [$normalized];
        }

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
