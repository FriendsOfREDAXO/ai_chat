<?php

namespace FriendsOfRedaxo\AiChat\ContentProvider;

use FriendsOfRedaxo\AiChat\Service\PdfTextExtractor;
use rex_addon;
use rex_media;
use rex_path;
use Smalot\PdfParser\Parser;

/**
 * Indexiert PDF-Dokumente aus dem Medienpool - bewusst rein profil-exklusiv
 * (siehe ChatProfile::$pdfMediaIds/$pdfCategoryIds), kein Beitrag zum
 * geteilten Shared-Pool (collectTasks() liefert daher immer []). Ein Profil
 * waehlt konkrete Dateien und/oder Medienpool-Kategorien; die eigentliche
 * Aufloesung der Kategorien zu Dateinamen passiert in
 * IndexerService::collectProfileTasks().
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
     * @return list<array<string, mixed>>
     */
    public function collectTasks(): array
    {
        return [];
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
        if ('' === $server && rex_addon::get('yrewrite')->isAvailable()) {
            $server = \rex_yrewrite::getCurrentDomain()->getUrl();
        }
        if ('' === $server) {
            return $url;
        }

        $path = (string) preg_replace('#^(?:\.\./)+#', '', $url);

        return rtrim($server, '/') . '/' . ltrim($path, '/');
    }
}
