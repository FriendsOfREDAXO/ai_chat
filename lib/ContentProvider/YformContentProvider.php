<?php

namespace FriendsOfRedaxo\AiChat\ContentProvider;

use rex;
use rex_addon;
use rex_sql;
use rex_yform_manager_table;

final class YformContentProvider implements ContentProviderInterface
{
    public function getKey(): string
    {
        return 'yform';
    }

    public function getLabel(): string
    {
        return 'YForm Tabellen';
    }

    /**
     * @return list<string>
     */
    public function getSupportedSourceTypes(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return YformProfiles::getSupportedSourceTypes(rex_addon::get('ai_chat'));
    }

    public function getPromptInstruction(): string
    {
        return 'YForm-Tabellen liefern strukturierte Inhalte wie News, Blog-Beiträge oder andere Datensätze. Nutze Titel, Content und Metadaten pro Tabelle. Berücksichtige Status-, Datum-, Aktualisierungs- und Erstellungsfelder, wenn du die Aktualität einschätzen sollst.';
    }

    /**
     * @return array<string, string>
     */
    public function getSourceTypeLabels(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return YformProfiles::getSourceTypeLabels(rex_addon::get('ai_chat'));
    }

    public function getSearchIconSvg(string $sourceType): string
    {
        if (!str_starts_with($sourceType, 'yform_')) {
            return '';
        }

        return '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 4h16v16H4zM6 6v12h12V6zm2 2h8v2H8zm0 4h8v2H8zm0 4h5v2H8z"/></svg>';
    }

    public function isAvailable(): bool
    {
        return class_exists(rex_yform_manager_table::class) && rex_addon::exists('yform') && rex_addon::get('yform')->isAvailable();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectTasks(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        return $this->buildTasksForProfiles(YformProfiles::getAll(rex_addon::get('ai_chat')));
    }

    /**
     * Wie collectTasks(), aber beschränkt auf einzelne YForm-Mapping-Profile -
     * für ein ai_chat-Profil mit eigener, exklusiver YForm-Quellenauswahl
     * (siehe ChatProfile::$yformProfileIds), statt aller konfigurierten Mappings.
     *
     * @param list<string> $yformProfileKeys
     * @return list<array<string, mixed>>
     */
    public function collectTasksForKeys(array $yformProfileKeys): array
    {
        if (!$this->isAvailable() || [] === $yformProfileKeys) {
            return [];
        }

        $allProfiles = YformProfiles::getAll(rex_addon::get('ai_chat'));
        $selected = array_intersect_key($allProfiles, array_flip($yformProfileKeys));

        return $this->buildTasksForProfiles($selected);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @return list<array<string, mixed>>
     */
    private function buildTasksForProfiles(array $profiles): array
    {
        if ($profiles === []) {
            return [];
        }

        $tasks = [];
        foreach ($profiles as $profile) {
            $tableName = (string) ($profile['table'] ?? '');
            if ($tableName === '') {
                continue;
            }

            $rows = $this->fetchRows($profile);
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $title = $this->buildTitle($profile, $row, $id);
                $updatedTs = $this->resolveUpdatedTimestamp($profile, $row);

                $tasks[] = [
                    'type' => 'provider_item',
                    'provider' => $this->getKey(),
                    'source_type' => YformProfiles::sourceTypeForProfile((string) $profile['id']),
                    'source_id' => $this->buildSourceId((string) $profile['id'], $id),
                    'profile_id' => (string) $profile['id'],
                    'table_name' => $tableName,
                    'record_id' => $id,
                    'title' => $title,
                    'updatedate_ts' => $updatedTs,
                ];
            }
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

        $profileId = trim((string) ($task['profile_id'] ?? ''));
        $recordId = (int) ($task['record_id'] ?? 0);
        if ($profileId === '' || $recordId <= 0) {
            return null;
        }

        $addon = rex_addon::get('ai_chat');
        $profile = YformProfiles::getAll($addon)[$profileId] ?? null;
        if (!is_array($profile)) {
            return null;
        }

        $tableName = (string) ($profile['table'] ?? '');
        if ($tableName === '') {
            return null;
        }

        $dataset = null;
        try {
            $dataset = rex_yform_manager_table::get($tableName)->getDataset($recordId);
        } catch (\Throwable) {
            return null;
        }

        if (!is_object($dataset) || !method_exists($dataset, 'getValue')) {
            return null;
        }

        $title = $this->buildTitle($profile, $dataset, $recordId);
        $content = $this->buildContent($profile, $dataset);
        if ($title === '' && $content === '') {
            return null;
        }

        return [
            'source_type' => YformProfiles::sourceTypeForProfile($profileId),
            'source_id' => $this->buildSourceId($profileId, $recordId),
            'title' => $title !== '' ? $title : $this->fallbackTitle($profile, $recordId),
            'content' => $content,
            'url' => $this->buildUrl($profile, $dataset, $recordId),
            'updatedate_ts' => $this->resolveUpdatedTimestamp($profile, $dataset),
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return list<array<string, mixed>>
     */
    private function fetchRows(array $profile): array
    {
        $tableName = trim((string) ($profile['table'] ?? ''));
        if ($tableName === '' || !class_exists(rex_sql::class)) {
            return [];
        }

        $table = rex_yform_manager_table::get($tableName);
        if (!$table instanceof rex_yform_manager_table) {
            return [];
        }

        $columns = YformProfiles::collectColumns($tableName);
        $sql = rex_sql::factory();

        $where = [];
        $params = [];

        $statusField = trim((string) ($profile['status_field'] ?? ''));
        if ($statusField !== '' && isset($columns[$statusField])) {
            $allowedValues = $this->parseList((string) ($profile['status_values'] ?? ''));
            if ($allowedValues !== []) {
                $placeholders = implode(', ', array_fill(0, count($allowedValues), '?'));
                $where[] = $sql->escapeIdentifier($statusField) . ' IN (' . $placeholders . ')';
                array_push($params, ...$allowedValues);
            }
        }

        // Optionaler Sprachfilter: nur wirksam, wenn eine Sprach-Spalte GEWÄHLT ist -
        // ohne Auswahl bleibt das Verhalten unveraendert (alle Sprachen, wie vor
        // diesem Feature), keine stille Verhaltensaenderung fuer bestehende Mappings.
        $clangField = trim((string) ($profile['clang_field'] ?? ''));
        if ($clangField !== '' && isset($columns[$clangField])) {
            $allowedClangs = $this->parseList((string) ($profile['clang_ids'] ?? ''));
            if ($allowedClangs !== []) {
                $placeholders = implode(', ', array_fill(0, count($allowedClangs), '?'));
                $where[] = $sql->escapeIdentifier($clangField) . ' IN (' . $placeholders . ')';
                array_push($params, ...$allowedClangs);
            }
        }

        $orderField = trim((string) ($profile['sort_field'] ?? ''));
        if ($orderField === '' || !isset($columns[$orderField])) {
            foreach (['updated_field', 'date_field', 'created_field'] as $candidateKey) {
                $candidate = trim((string) ($profile[$candidateKey] ?? ''));
                if ($candidate !== '' && isset($columns[$candidate])) {
                    $orderField = $candidate;
                    break;
                }
            }
        }

        if ($orderField === '' || !isset($columns[$orderField])) {
            $orderField = 'id';
        }

        $orderDir = strtoupper(trim((string) ($profile['sort_dir'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';
        $query = 'SELECT * FROM ' . $sql->escapeIdentifier($tableName);
        if ($where !== []) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }
        $query .= ' ORDER BY ' . $sql->escapeIdentifier($orderField) . ' ' . $orderDir . ', id DESC';

        $rows = $sql->getArray($query, $params);

        if (!is_array($rows)) {
            return [];
        }

        $filteredRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if (!YformProfiles::profileMatchesRow($profile, $row)) {
                continue;
            }

            $filteredRows[] = $row;
        }

        return $filteredRows;
    }

    /**
     * @param array<string, mixed> $profile
     * @param object|array<string, mixed> $data
     */
    private function buildTitle(array $profile, object|array $data, int $recordId): string
    {
        $field = trim((string) ($profile['title_field'] ?? ''));
        if ($field !== '') {
            $value = $this->getValue($data, $field);
            $title = YformProfiles::renderValue($value, 'auto');
            if ($title !== '') {
                return $title;
            }
        }

        return $this->fallbackTitle($profile, $recordId);
    }

    /**
     * @param array<string, mixed> $profile
     * @param object|array<string, mixed> $data
     */
    private function buildContent(array $profile, object|array $data): string
    {
        $parts = [];

        $contentField = trim((string) ($profile['content_field'] ?? ''));
        if ($contentField !== '') {
            $mode = (string) ($profile['content_field_mode'] ?? 'auto');
            $content = YformProfiles::renderValue($this->getValue($data, $contentField), $mode);
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        $additionalFields = $profile['fields'] ?? ($profile['additional_fields'] ?? []);
        if (is_array($additionalFields)) {
            foreach ($additionalFields as $fieldConfig) {
                if (!is_array($fieldConfig)) {
                    continue;
                }

                if (!(bool) ($fieldConfig['include'] ?? true)) {
                    continue;
                }

                $field = trim((string) ($fieldConfig['field'] ?? ''));
                if ($field === '') {
                    continue;
                }

                $value = $this->getValue($data, $field);
                $mode = (string) ($fieldConfig['mode'] ?? 'auto');
                $text = YformProfiles::renderValue($value, $mode);
                if ($text !== '') {
                    $label = trim((string) ($fieldConfig['label'] ?? ''));
                    $parts[] = $label !== '' ? ($label . ': ' . $text) : $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $profile
     * @param object|array<string, mixed> $data
     */
    private function buildUrl(array $profile, object|array $data, int $recordId): string
    {
        $titleField = trim((string) ($profile['title_field'] ?? ''));
        $title = $titleField !== '' ? YformProfiles::renderValue($this->getValue($data, $titleField), 'auto') : '';
        $slug = $title !== '' ? $this->slugify($title) : (string) $recordId;

        $urlMode = trim((string) ($profile['url_mode'] ?? 'field'));
        $urlField = trim((string) ($profile['url_field'] ?? ''));
        $urlProfile = trim((string) ($profile['url_profile'] ?? ''));
        $urlTemplate = trim((string) ($profile['url_template'] ?? ''));

        if ($urlMode === 'field' && $urlField !== '') {
            $fieldUrl = trim((string) YformProfiles::renderValue($this->getValue($data, $urlField), 'auto'));
            if ($fieldUrl !== '') {
                return $fieldUrl;
            }
        }

        if ($urlMode === 'profile' && $urlProfile !== '' && function_exists('rex_getUrl')) {
            try {
                return (string) rex_getUrl(0, \rex_clang::getCurrentId(), [$urlProfile => $recordId]);
            } catch (\Throwable) {
                // Fallback below.
            }
        }

        if ($urlMode === 'template' && $urlTemplate !== '') {
            $renderedUrl = $this->renderUrlTemplate($urlTemplate, $profile, $recordId, $title, $slug);
            if ($renderedUrl !== '') {
                return $renderedUrl;
            }
        }

        // Keine funktionierende URL-Zuordnung konfiguriert: lieber gar keine URL liefern
        // (kein Quellen-Link) als eine interne Platzhalter-URL, die für Website-Besucher
        // ohnehin nie aufrufbar wäre.
        return '';
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function renderUrlTemplate(string $template, array $profile, int $recordId, string $title, string $slug): string
    {
        $url = strtr($template, [
            '{id}' => (string) $recordId,
            '{slug}' => $slug,
            '{title}' => $title,
            '{table}' => (string) ($profile['table'] ?? ''),
            '{profile}' => (string) ($profile['id'] ?? ''),
        ]);

        return trim($url);
    }

    /**
     * @param array<string, mixed> $profile
     * @param object|array<string, mixed> $data
     */
    private function resolveUpdatedTimestamp(array $profile, object|array $data): int
    {
        foreach (['updated_field', 'created_field', 'date_field'] as $key) {
            $field = trim((string) ($profile[$key] ?? ''));
            if ($field === '') {
                continue;
            }

            $timestamp = $this->toTimestamp((string) $this->getValue($data, $field));
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        foreach (['updatedate', 'createdate'] as $field) {
            $timestamp = $this->toTimestamp((string) $this->getValue($data, $field));
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        return time();
    }

    /**
     * @param object|array<string, mixed> $data
     */
    private function getValue(object|array $data, string $fieldName): mixed
    {
        if ($fieldName === '') {
            return null;
        }

        if (is_object($data) && method_exists($data, 'getValue')) {
            return $data->getValue($fieldName);
        }

        if (is_array($data)) {
            return $data[$fieldName] ?? null;
        }

        return null;
    }

    private function buildSourceId(string $profileId, int $recordId): string
    {
        return $this->slugify($profileId) . ':' . $recordId;
    }

    private function fallbackTitle(array $profile, int $recordId): string
    {
        $label = trim((string) ($profile['label'] ?? 'YForm'));

        return $label . ' #' . $recordId;
    }

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value;

        return trim($value, '_') ?: 'item';
    }

    /**
     * @return list<string>
     */
    private function parseList(string $value): array
    {
        $parts = preg_split('/[\r\n,;|]+/', $value);
        if (!is_array($parts)) {
            return [];
        }

        $items = [];
        foreach ($parts as $part) {
            $item = trim((string) $part);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    private function toTimestamp(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? 0 : $timestamp;
    }
}