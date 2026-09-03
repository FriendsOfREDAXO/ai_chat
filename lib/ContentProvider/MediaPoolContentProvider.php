<?php

namespace FriendsOfRedaxo\AiChat\ContentProvider;

use FriendsOfRedaxo\AiChat\Service\PdfTextExtractor;
use FriendsOfRedaxo\AiChat\Service\YrewriteDomainResolver;
use rex;
use rex_addon;
use rex_form_base;
use rex_media;
use rex_media_category_select;
use rex_path;
use rex_sql;
use Smalot\PdfParser\Parser;

/**
 * Indexiert PDF-Dokumente aus dem Medienpool - sowohl global (eigene Auswahl
 * in den Indexierung-Einstellungen, traegt zum geteilten Shared Pool bei) als
 * auch profil-exklusiv (ChatProfile::$pdfMediaIds/$pdfCategoryIds, siehe
 * IndexerService::collectProfileTasks()). Beide Ebenen nutzen dieselbe
 * Feld-Struktur (Dateien + Kategorien, siehe renderSourceFields()) - es gibt
 * bewusst kein separates "Provider aktivieren"-Haekchen dafuer (siehe
 * ContentProviderRegistry/pages/settings.indexing.php): die Auswahl selbst
 * IST die Aktivierung, leer bedeutet nichts indexieren.
 */
final class MediaPoolContentProvider implements ContentProviderInterface
{
    public function getKey(): string
    {
        return 'mediapool';
    }

    public function getLabel(): string
    {
        return 'Medienpool-Dokumente';
    }

    /**
     * @return list<string>
     */
    public function getSupportedSourceTypes(): array
    {
        return ['mediapool_pdf'];
    }

    public function getPromptInstruction(): string
    {
        return 'PDF-Dokumente aus dem Medienpool. Der Inhalt ist automatisch aus der PDF-Datei extrahierter Text - Layout/Formatierung gehen dabei verloren, Tabellen/Spalten koennen durcheinandergeraten.';
    }

    /**
     * @return array<string, string>
     */
    public function getSourceTypeLabels(): array
    {
        return ['mediapool_pdf' => 'PDF-Dokument'];
    }

    public function getSearchIconSvg(string $sourceType): string
    {
        if ('mediapool_pdf' !== $sourceType) {
            return '';
        }

        return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.5V9h5.5L13 3.5zM8 13h2a1.5 1.5 0 0 1 0 3H8v2H7v-6h1v1zm0 1v1h1.5a.5.5 0 0 0 0-1H8zm4-1h2.2c.7 0 1.3.6 1.3 1.5v2c0 .9-.6 1.5-1.3 1.5H12v-5zm1 1v3h1.1c.3 0 .4-.2.4-.5v-2c0-.3-.1-.5-.4-.5H13zm4-1h2v1h-1.3v1H19v1h-1.3v2H17v-5z"/></svg>';
    }

    public function isAvailable(): bool
    {
        return rex_addon::get('mediapool')->isAvailable() && class_exists(Parser::class);
    }

    /**
     * Globale PDF-Auswahl (pages/settings.indexing.php) - traegt zum geteilten
     * Shared Pool bei. Liefert [], solange dort keine Dateien/Kategorien gewaehlt
     * sind (kein separates Aktivieren-Haekchen noetig, siehe Klassenkommentar).
     *
     * @return list<array<string, mixed>>
     */
    public function collectTasks(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $addon = rex_addon::get('ai_chat');
        $mediaIds = self::decodeCommaList((string) $addon->getConfig('pdf_media_ids'));
        $categoryIds = self::decodeIntList((string) $addon->getConfig('pdf_category_ids'));

        if ([] === $mediaIds && [] === $categoryIds) {
            return [];
        }

        $filenames = $mediaIds;
        if ([] !== $categoryIds) {
            $filenames = array_merge($filenames, $this->resolveFilenamesForCategories($categoryIds));
        }

        return $this->collectTasksForKeys(array_values(array_unique($filenames)));
    }

    /**
     * Dateinamen aller PDF-Dateien in den angegebenen Medienpool-Kategorien
     * (nicht rekursiv - jede gewuenschte Kategorie muss einzeln gewaehlt werden).
     * Gemeinsam genutzt von collectTasks() (global) und
     * IndexerService::collectProfileTasks() (je Profil).
     *
     * @param list<int> $categoryIds
     * @return list<string>
     */
    public function resolveFilenamesForCategories(array $categoryIds): array
    {
        if ([] === $categoryIds || !class_exists(rex_media::class)) {
            return [];
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT filename FROM ' . rex::getTable('media') . ' WHERE category_id IN (' . implode(',', array_fill(0, count($categoryIds), '?')) . ') AND filetype = ?',
            [...$categoryIds, 'application/pdf'],
        );

        $filenames = [];
        foreach ($rows as $row) {
            $filenames[] = (string) $row['filename'];
        }

        return $filenames;
    }

    /**
     * Rendert die beiden PDF-Auswahlfelder (Dateien + Kategorien) - identisches
     * Muster fuer globale Einstellungen (pages/settings.indexing.php) und Profile
     * (pages/profiles.php), damit wer eines der beiden kennt das andere sofort
     * wiedererkennt. $form ist bewusst rex_form_base (Basisklasse von rex_form
     * UND rex_config_form), damit beide Aufrufer dieselbe Methode nutzen koennen.
     */
    public static function renderSourceFields(
        rex_form_base $form,
        string $mediaFieldName,
        string $mediaLabel,
        string $mediaNotice,
        string $categoryFieldName,
        string $categoryLabel,
        string $categoryNotice,
    ): void {
        $field = $form->addMedialistField($mediaFieldName);
        $field->setLabel($mediaLabel);
        $field->setNotice($mediaNotice);

        $field = $form->addSelectField($categoryFieldName);
        $field->setLabel($categoryLabel);
        $field->setNotice($categoryNotice);
        $field->setAttribute('class', 'selectpicker');
        $field->setAttribute('data-actions-box', 'true');
        $mediaCategorySelect = new rex_media_category_select();
        $mediaCategorySelect->setMultiple();
        $field->setSelect($mediaCategorySelect);
    }

    /**
     * rex_form_base::addMedialistField() speichert komma-getrennt, nicht im sonst
     * ueblichen Pipe-Format - siehe rex_var_medialist im mediapool-Addon. Gleiches
     * Format fuer die globale Config wie fuer ChatProfile::decodeCommaList().
     *
     * @return list<string>
     */
    private static function decodeCommaList(string $raw): array
    {
        $trimmed = trim($raw, ',');

        return '' === $trimmed ? [] : array_values(array_filter(explode(',', $trimmed), static fn (string $v): bool => '' !== $v));
    }

    /**
     * @return list<int>
     */
    private static function decodeIntList(string $raw): array
    {
        $trimmed = trim($raw, '|');
        if ('' === $trimmed) {
            return [];
        }

        return array_map('intval', array_values(array_filter(explode('|', $trimmed), static fn (string $v): bool => '' !== $v)));
    }

    /**
     * @param list<string> $mediaFilenames
     * @return list<array<string, mixed>>
     */
    public function collectTasksForKeys(array $mediaFilenames): array
    {
        if (!$this->isAvailable() || [] === $mediaFilenames) {
            return [];
        }

        $tasks = [];
        foreach (array_unique($mediaFilenames) as $filename) {
            $filename = trim($filename);
            if ('' === $filename) {
                continue;
            }

            $media = rex_media::get($filename);
            if (null === $media || 'application/pdf' !== $media->getType()) {
                continue;
            }

            $tasks[] = [
                'type' => 'provider_item',
                'provider' => $this->getKey(),
                'source_type' => 'mediapool_pdf',
                'source_id' => $filename,
                'filename' => $filename,
                'title' => '' !== $media->getTitle() ? $media->getTitle() : $filename,
                'updatedate_ts' => $media->getUpdateDate(),
            ];
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>|null
     */
    public function prepareDocument(array $task): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $filename = trim((string) ($task['filename'] ?? ''));
        if ('' === $filename) {
            return null;
        }

        // Erneut laden statt dem Task blind zu vertrauen - die Datei kann
        // zwischen collectTasksForKeys() und der Verarbeitung geloescht
        // worden sein.
        $media = rex_media::get($filename);
        if (null === $media || 'application/pdf' !== $media->getType()) {
            return null;
        }

        $absolutePath = rex_path::media($filename);
        $content = (new PdfTextExtractor())->extract($absolutePath);
        if ('' === $content) {
            return null;
        }

        $title = '' !== $media->getTitle() ? $media->getTitle() : $filename;

        return [
            'source_type' => 'mediapool_pdf',
            'source_id' => $filename,
            'title' => $title,
            'content' => $content,
            'url' => $this->resolveAbsoluteUrl($media->getUrl()),
            'updatedate_ts' => $media->getUpdateDate(),
        ];
    }

    /**
     * rex_media::getUrl() liefert je nach Pfad-Provider-Konfiguration (siehe
     * z.B. IndexerService::indexArticle()'s HTTP-Zweig fuer denselben Fall) einen
     * relativen statt absoluten Pfad - besonders auffaellig bei einer per Konsole
     * (php bin/console ai_chat:reindex) angestossenen Indexierung ohne HTTP-Request-
     * Kontext. Suchergebnis-Links muessen aber unabhaengig vom Indexierungsweg
     * funktionieren. Manche Pfad-Provider-Setups (z.B. mit eigenem Backend-Ordner-
     * Offset) liefern zusaetzlich fuehrende "../"-Segmente, die nur im Browser
     * relativ zur aktuellen Backend-Seite aufloesen, nicht absolut von der
     * Domain-Wurzel aus - werden hier entfernt, Medien liegen immer direkt unter
     * "/<mediapool-ordner>/" der Domain.
     */
    private function resolveAbsoluteUrl(string $url): string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        $server = \rex::getServer();
        if ('' === $server) {
            $server = YrewriteDomainResolver::getCurrentDomain()?->getUrl() ?? '';
        }
        if ('' === $server) {
            return $url;
        }

        $path = (string) preg_replace('#^(?:\.\./)+#', '', $url);

        return rtrim($server, '/') . '/' . ltrim($path, '/');
    }
}
