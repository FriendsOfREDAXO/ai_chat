<?php

namespace FriendsOfRedaxo\AiChat\ContentProvider;

use forCal\Factory\forCalEventsFactory;
use rex;
use rex_addon;

class ForcalContentProvider implements ContentProviderInterface
{
    public function getKey(): string
    {
        return 'forcal';
    }

    public function getLabel(): string
    {
        return 'forcal (Kalender-Termine)';
    }

    /**
     * @return list<string>
     */
    public function getSupportedSourceTypes(): array
    {
        return ['forcal_entry'];
    }

    public function getPromptInstruction(): string
    {
        return 'Forcal liefert Termin- und Veranstaltungsdaten. Bei Fragen nach dem nächsten Termin priorisiere zukünftige Vorkommen. Bei wiederkehrenden Terminen nenne den nächsten kommenden Termin mit Datum/Uhrzeit und verweise knapp auf den Wiederholungsrhythmus, falls vorhanden.';
    }

    /**
     * @return array<string, string>
     */
    public function getSourceTypeLabels(): array
    {
        return [
            'forcal_entry' => 'Termine',
        ];
    }

    public function getSearchIconSvg(string $sourceType): string
    {
        if ($sourceType !== 'forcal_entry') {
            return '';
        }

        return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M7 2a1 1 0 0 1 1 1v1h8V3a1 1 0 1 1 2 0v1h1a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h1V3a1 1 0 0 1 1-1Zm13 8H4v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9ZM5 6a1 1 0 0 0-1 1v1h16V7a1 1 0 0 0-1-1H5Z"/></svg>';
    }

    public function isAvailable(): bool
    {
        return rex_addon::exists('forcal')
            && rex_addon::get('forcal')->isAvailable()
            && class_exists(forCalEventsFactory::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectTasks(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $entries = forCalEventsFactory::create()
            ->from('-3 years')
            ->to('+3 years')
            ->withUserPermissions(false)
            ->get();

        if (!is_array($entries)) {
            return [];
        }

        $taskRows = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryId = (int) ($entry['id'] ?? 0);
            if ($entryId <= 0) {
                continue;
            }

            $title = trim((string) ($entry['title'] ?? ''));
            if ($title === '') {
                $title = 'forcal Termin #' . $entryId;
            }

            $updatedAt = $this->toTimestamp((string) ($entry['updatedate'] ?? ''));
            if ($updatedAt <= 0) {
                $updatedAt = $this->toTimestamp((string) ($entry['createdate'] ?? ''));
            }
            if ($updatedAt <= 0) {
                $updatedAt = time();
            }

            $taskRows[] = [
                'type' => 'provider_item',
                'provider' => $this->getKey(),
                'source_type' => 'forcal_entry',
                'source_id' => (string) $entryId,
                'entry_id' => $entryId,
                'title' => $title,
                'updatedate_ts' => $updatedAt,
            ];
        }

        return $taskRows;
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

        $entryId = (int) ($task['entry_id'] ?? 0);
        if ($entryId <= 0) {
            return null;
        }

        $entry = forCalEventsFactory::create()
            ->withUserPermissions(false)
            ->getEntryById($entryId);

        if (!is_array($entry) || $entry === []) {
            return null;
        }

        $title = trim((string) ($entry['title'] ?? ''));
        if ($title === '') {
            $title = 'forcal Termin #' . $entryId;
        }

        $teaser = trim((string) ($entry['teaser'] ?? ''));
        $description = trim((string) ($entry['text'] ?? ''));

        $parts = [];
        $parts[] = 'Titel: ' . $title;

        $startDate = trim((string) ($entry['start_date'] ?? ''));
        $endDate = trim((string) ($entry['end_date'] ?? ''));
        $startTime = trim((string) ($entry['start_time'] ?? ''));
        $endTime = trim((string) ($entry['end_time'] ?? ''));

        if ($startDate !== '') {
            $dateText = 'Termin: ' . $startDate;
            if ($endDate !== '' && $endDate !== $startDate) {
                $dateText .= ' bis ' . $endDate;
            }
            if ($startTime !== '') {
                $dateText .= ', ' . $startTime;
                if ($endTime !== '') {
                    $dateText .= ' - ' . $endTime;
                }
            }
            $parts[] = $dateText;
        }

        if ($teaser !== '') {
            $parts[] = 'Teaser: ' . $teaser;
        }
        if ($description !== '') {
            $parts[] = 'Beschreibung: ' . $description;
        }

        $repeatType = trim((string) ($entry['repeat'] ?? ''));
        if ($repeatType !== '') {
            $parts[] = 'Wiederholung: ' . $repeatType;
        }

        if (isset($entry['dates']) && is_array($entry['dates']) && $entry['dates'] !== []) {
            $nextStart = '';
            $nextEnd = '';
            $nowTs = time();

            foreach ($entry['dates'] as $occurrence) {
                if (!is_array($occurrence)) {
                    continue;
                }

                $candidateStart = trim((string) ($occurrence['entry_start_date'] ?? ''));
                if ($candidateStart === '') {
                    continue;
                }

                $candidateTs = strtotime($candidateStart);
                if ($candidateTs === false || $candidateTs < $nowTs) {
                    continue;
                }

                $nextStart = $candidateStart;
                $nextEnd = trim((string) ($occurrence['entry_end_date'] ?? ''));
                break;
            }

            if ($nextStart !== '') {
                $nextText = 'Nächster Termin: ' . $nextStart;
                if ($nextEnd !== '' && $nextEnd !== $nextStart) {
                    $nextText .= ' bis ' . $nextEnd;
                }
                $parts[] = $nextText;
            }
        }

        $content = trim(implode("\n", $parts));
        if ($content === '') {
            return null;
        }

        $updatedAt = $this->toTimestamp((string) ($entry['updatedate'] ?? ''));
        if ($updatedAt <= 0) {
            $updatedAt = $this->toTimestamp((string) ($entry['createdate'] ?? ''));
        }
        if ($updatedAt <= 0) {
            $updatedAt = time();
        }

        return [
            'source_type' => 'forcal_entry',
            'source_id' => (string) $entryId,
            'title' => $title,
            'content' => $content,
            'url' => $this->buildEntryUrl($entryId, $entry),
            'updatedate_ts' => $updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function buildEntryUrl(int $entryId, array $entry): string
    {
        $template = trim((string) rex_addon::get('ai_chat')->getConfig('forcal_url_schema', ''));
        if ($template === '') {
            return 'forcal://entry/' . $entryId;
        }

        $title = trim((string) ($entry['title'] ?? ''));
        $slug = $this->slugify($title);
        $startDate = trim((string) ($entry['start_date'] ?? ''));
        $endDate = trim((string) ($entry['end_date'] ?? ''));

        $url = strtr($template, [
            '{id}' => (string) $entryId,
            '{title}' => $title,
            '{slug}' => $slug,
            '{start_date}' => $startDate,
            '{end_date}' => $endDate,
        ]);

        $url = trim($url);

        return $url !== '' ? $url : ('forcal://entry/' . $entryId);
    }

    private function slugify(string $value): string
    {
        $slug = trim($value);
        if ($slug === '') {
            return '';
        }

        $slug = mb_strtolower($slug);
        $slug = strtr($slug, [
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($ascii) && $ascii !== '') {
            $slug = $ascii;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    private function toTimestamp(string $dateTime): int
    {
        if ($dateTime === '') {
            return 0;
        }

        $ts = strtotime($dateTime);

        return $ts !== false ? (int) $ts : 0;
    }
}
