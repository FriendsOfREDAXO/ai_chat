<?php

namespace FriendsOfRedaxo\AiChat\Service;

use rex_file;
use rex_path;

/**
 * Persists the live progress of a full reindex run to a JSON state file, so a
 * detached background process (started via shell_exec, see
 * Api\ChatIndex::handleStartBackground()) and the browser-facing status poll
 * always agree on the same ground truth - unlike the classic foreground AJAX
 * loop, nothing in the browser is driving the run itself in that mode.
 *
 * File-based rather than a DB row on purpose: the background worker request
 * (Api\ReindexWorker) is deliberately unauthenticated (called via curl/wget,
 * no backend session/cookies available) and must not depend on anything a
 * normal request sets up - a plain file next to the addon's other data is the
 * simplest thing that works from both a web request and would work from a
 * real CLI process just the same.
 */
class IndexRunStore
{
    private const STATE_FILE = 'reindex_status.json';

    // Ohne Fortschritts-Update seit mehr als dieser Zeitspanne gilt ein
    // "running"-Status als verwaist statt als aktiv - siehe isRunning().
    private const STALE_AFTER_SECONDS = 600;

    /**
     * @param array<string, mixed> $state
     */
    public static function write(array $state): void
    {
        $state['updated_at'] = time();
        rex_file::put(self::path(), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        if (!is_file(self::path())) {
            return ['status' => 'idle'];
        }

        /** @var mixed $decoded */
        $decoded = json_decode(rex_file::get(self::path(), '{}'), true);

        return is_array($decoded) ? $decoded : ['status' => 'idle'];
    }

    /**
     * Wie read(), aber ein "running"-Status ohne Fortschritts-Update seit mehr
     * als STALE_AFTER_SECONDS wird hier bereits als "error" mit erklärender
     * Nachricht ausgewiesen (write() setzt updated_at bei JEDEM Aufruf, auch
     * beim initialen Start) - die Datei selbst bleibt unangetastet, bis der
     * nächste echte Schreibvorgang (z.B. ein neu gestarteter Lauf) sie
     * überschreibt. Einzige Quelle für sowohl isRunning() als auch den Status,
     * den Api\ChatIndex::handleBackgroundStatus() an den Browser meldet -
     * beide müssen sich einig sein, sonst pollt die UI einen für den Start-
     * Check bereits als tot geltenden Lauf ewig weiter.
     *
     * Ohne diese Prüfung würde ein einzelner Lauf, dessen Worker-Prozess
     * abstirbt, ohne seinen eigenen Abschluss-Status noch schreiben zu können
     * - z.B. durch PHP-FPMs request_terminate_timeout, oder weil der
     * Self-Loopback-Call in Api\ChatIndex::handleStartBackground() den Worker
     * auf manchen Docker-/Proxy-Setups gar nicht erst erreicht -, jede
     * künftige Hintergrund-Indizierung für immer blockieren (siehe "Es läuft
     * bereits eine Hintergrund-Indizierung").
     *
     * @return array<string, mixed>
     */
    public static function readEffective(): array
    {
        $state = self::read();
        $updatedAt = (int) ($state['updated_at'] ?? 0);
        $isStale = $updatedAt > 0 && (time() - $updatedAt) > self::STALE_AFTER_SECONDS;

        if ('running' === ($state['status'] ?? 'idle') && $isStale) {
            $state['status'] = 'error';
            $state['message'] = 'Der Hintergrundlauf antwortet seit mehr als '
                . (int) (self::STALE_AFTER_SECONDS / 60)
                . ' Minuten nicht mehr (vermutlich abgestürzt oder nicht erreichbar) und wurde als fehlgeschlagen markiert.';
        }

        return $state;
    }

    public static function isRunning(): bool
    {
        return 'running' === (self::readEffective()['status'] ?? 'idle');
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function path(): string
    {
        return rex_path::addonData('ai_chat', self::STATE_FILE);
    }
}
