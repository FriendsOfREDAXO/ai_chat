<?php

namespace FriendsOfRedaxo\AiChat;

use FriendsOfRedaxo\AiChat\Service\IndexerService;
use rex_addon;
use rex_addon_interface;
use rex_extension_point;

class EventListener
{
    /**
     * Zentraler Schalter für die Live-Reindizierung bei ART_, SLICE_, CAT_ und YFORM_-Events,
     * damit Admins die automatische Reindizierung bei Bedarf abschalten können
     * (z.B. bei sehr vielen Änderungen / teuren Embedding-API-Calls). Seit Phase 6 (kein
     * globaler Struktur-Toggle mehr, index_frontend ohne UI) der EINZIGE Schalter hier -
     * ob ein Artikel tatsächlich indiziert wird, entscheidet ausschließlich, ob ihn ein
     * Profil per $profile->mountpointGroups führt (siehe IndexerService::updateArticleIndex()).
     */
    private static function isLiveReindexEnabled(rex_addon_interface $addon): bool
    {
        return (bool) $addon->getConfig('live_reindex_enabled', true);
    }

    /**
     * @param rex_extension_point<mixed> $ep
     */
    public static function handleEvent(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('ai_chat');
        if (!self::isLiveReindexEnabled($addon)) {
            return;
        }

        $service = new IndexerService();
        $epName = $ep->getName();
        
        // Extract ID and Clang
        $articleId = $ep->getParam('article_id') ?? $ep->getParam('id');
        $clangId = $ep->getParam('clang_id') ?? $ep->getParam('clang');
        
        if (!$articleId || !$clangId) {
            return;
        }

        // Debug Logging
        // \rex_logger::logError(E_WARNING, 'AiChat Event: ' . $epName . ' Article: ' . $articleId . ' Clang: ' . $clangId, __FILE__, __LINE__);

        // Ensure we have fresh data
        \rex_article::clearInstance((int)$articleId);
        \rex_article_cache::delete((int)$articleId, (int)$clangId);

        if ($epName === 'ART_DELETED') {
            $service->deleteArticleFromIndex((int)$articleId, (int)$clangId);
            return;
        }
        
        if ($epName === 'ART_STATUS') {
            $status = $ep->getParam('status'); // 1 = online, 0 = offline
            if ($status == 0) {
                $service->deleteArticleFromIndex((int)$articleId, (int)$clangId);
            } else {
                $service->updateArticleIndex((int)$articleId, (int)$clangId);
            }
            return;
        }
        
        // For SLICE_* and ART_UPDATED/ADDED
        // We only index if the article is online. updateArticleIndex checks this internally.
        $service->updateArticleIndex((int)$articleId, (int)$clangId);
    }

    /**
     * Reindiziert bzw. entfernt alle direkten Artikel einer Kategorie bei CAT_STATUS.
     *
     * @param rex_extension_point<mixed> $ep
     */
    public static function handleCategoryEvent(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('ai_chat');
        if (!self::isLiveReindexEnabled($addon)) {
            return;
        }

        $categoryId = (int) ($ep->getParam('id') ?? $ep->getParam('category_id') ?? 0);
        if ($categoryId <= 0) {
            return;
        }

        $service = new IndexerService();

        if ($ep->getName() === 'CAT_STATUS') {
            $status = $ep->getParam('status'); // 1 = online, 0 = offline
            if ($status == 0) {
                $service->refreshCategoryArticles($categoryId, true);
                return;
            }
        }

        $service->refreshCategoryArticles($categoryId);
    }

    /**
     * Reindiziert YForm-Datensaetze nur dann, wenn ein YForm-Mapping fuer die Tabelle existiert.
     *
     * @param rex_extension_point<mixed> $ep
     */
    public static function handleYformEvent(rex_extension_point $ep): void
    {
        $addon = rex_addon::get('ai_chat');
        if (!rex_addon::get('yform')->isAvailable() || !$addon->isAvailable() || !self::isLiveReindexEnabled($addon)) {
            return;
        }

        $table = $ep->getParam('table');
        if (!$table instanceof \rex_yform_manager_table) {
            return;
        }

        $tableName = (string) $table->getTableName();
        $dataId = (int) ($ep->getParam('data_id') ?? 0);
        if ($dataId <= 0 || $tableName === '') {
            return;
        }

        $service = new IndexerService();
        if ($ep->getName() === 'YFORM_DATA_DELETED') {
            $service->deleteYformRecord($tableName, $dataId);
            return;
        }

        $service->refreshYformRecord($tableName, $dataId);
    }
}
