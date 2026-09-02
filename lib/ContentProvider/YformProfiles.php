<?php

namespace FriendsOfRedaxo\AiChat\ContentProvider;

use rex_addon;
use rex_yform_manager_field;
use rex_yform_manager_table;

final class YformProfiles
{
    private const CONFIG_KEY = 'yform_provider_profiles';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getAll(rex_addon $addon): array
    {
        $profiles = [];

        $configured = self::normalizeConfigValue($addon->getConfig(self::CONFIG_KEY));
        if (is_array($configured)) {
            foreach ($configured as $id => $profile) {
                $normalized = self::normalizeProfile((string) $id, is_array($profile) ? $profile : []);
                if ($normalized !== null) {
                    $profiles[$normalized['id']] = $normalized;
                }
            }
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $profiles
     * @return array<string, array<string, mixed>>
     */
    public static function sanitizeProfiles(array $profiles): array
    {
        $normalized = [];

        foreach ($profiles as $id => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $entryId = trim((string) ($profile['id'] ?? ''));
            $entry = self::normalizeProfile($entryId !== '' ? $entryId : (string) $id, $profile);
            if ($entry !== null) {
                $normalized[$entry['id']] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    public static function getSourceTypeLabels(rex_addon $addon): array
    {
        $labels = [];

        foreach (self::getAll($addon) as $profile) {
            $sourceType = trim((string) ($profile['source_type'] ?? ''));
            $label = trim((string) ($profile['label'] ?? ''));
            if ($sourceType !== '' && $label !== '') {
                $labels[$sourceType] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public static function getSupportedSourceTypes(rex_addon $addon): array
    {
        /** @var list<string> $sourceTypes */
        $sourceTypes = array_values(array_keys(self::getSourceTypeLabels($addon)));

        return $sourceTypes;
    }

    public static function sourceTypeForProfile(string $profileId): string
    {
        return 'yform_' . self::slugify($profileId);
    }

    
    /**
     * Liefert alle Profile, die eine bestimmte YForm-Tabelle indexieren.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getByTable(rex_addon $addon, string $tableName): array
    {
        $tableName = trim($tableName);
        if ($tableName === '') {
            return [];
        }

        $profiles = [];
        foreach (self::getAll($addon) as $profile) {
            if ((string) ($profile['table'] ?? '') !== $tableName) {
                continue;
            }

            $profiles[(string) ($profile['id'] ?? '')] = $profile;
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>|null
     */
    private static function normalizeProfile(string $id, array $profile): ?array
    {
        $id = self::slugify($id);
        $table = trim((string) ($profile['table'] ?? ''));
        if ($id === '' || $table === '') {
            return null;
        }

        $label = trim((string) ($profile['label'] ?? ''));
        if ($label === '') {
            $label = self::normalizeLabel($table, $table);
        }

        $fields = [];
        $rawFields = $profile['fields'] ?? [];
        if (is_array($rawFields)) {
            foreach ($rawFields as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fieldName = trim((string) ($field['field'] ?? $field['name'] ?? ''));
                if ($fieldName === '') {
                    continue;
                }

                $fields[] = [
                    'field' => $fieldName,
                    'label' => trim((string) ($field['label'] ?? '')),
                    'mode' => self::normalizeMode((string) ($field['mode'] ?? 'auto')),
                    'include' => !isset($field['include']) || self::toBool($field['include']),
                ];
            }
        }

        $conditions = [];
        $rawConditions = $profile['conditions'] ?? [];
        if (is_array($rawConditions)) {
            foreach ($rawConditions as $condition) {
                if (!is_array($condition)) {
                    continue;
                }

                $normalizedCondition = self::normalizeCondition($condition);
                if ($normalizedCondition !== null) {
                    $conditions[] = $normalizedCondition;
                }
            }
        }

        return [
            'id' => $id,
            'label' => $label,
            'table' => $table,
            'title_field' => trim((string) ($profile['title_field'] ?? '')),
            'content_field' => trim((string) ($profile['content_field'] ?? '')),
            'content_field_mode' => self::normalizeMode((string) ($profile['content_field_mode'] ?? 'auto')),
            'status_field' => trim((string) ($profile['status_field'] ?? '')),
            'status_values' => trim((string) ($profile['status_values'] ?? '')),
            'date_field' => trim((string) ($profile['date_field'] ?? '')),
            'created_field' => trim((string) ($profile['created_field'] ?? '')),
            'updated_field' => trim((string) ($profile['updated_field'] ?? '')),
            'sort_field' => trim((string) ($profile['sort_field'] ?? '')),
            'sort_dir' => self::normalizeSortDir((string) ($profile['sort_dir'] ?? 'DESC')),
            'url_mode' => self::normalizeUrlMode((string) ($profile['url_mode'] ?? 'field')),
            'url_field' => trim((string) ($profile['url_field'] ?? '')),
            'url_profile' => trim((string) ($profile['url_profile'] ?? '')),
            'url_template' => trim((string) ($profile['url_template'] ?? '')),
            'clang_field' => trim((string) ($profile['clang_field'] ?? '')),
            'clang_ids' => trim((string) ($profile['clang_ids'] ?? '')),
            'fields' => $fields,
            'conditions' => $conditions,
            'source_type' => self::sourceTypeForProfile($id),
        ];
    }

    /**
     * @param array<string, mixed> $condition
     * @return array<string, mixed>|null
     */
    private static function normalizeCondition(array $condition): ?array
    {
        $field = trim((string) ($condition['field'] ?? ''));
        if ($field === '') {
            return null;
        }

        $operator = self::normalizeOperator((string) ($condition['operator'] ?? 'equals'));
        $value = trim((string) ($condition['value'] ?? ''));
        $valueType = self::normalizeValueType((string) ($condition['value_type'] ?? 'auto'));

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'value_type' => $valueType,
        ];
    }

    /**
     * @param list<string> $preferredNames
     */
    private static function guessFieldName(rex_yform_manager_table $table, array $preferredNames): string
    {
        $preferredNames = array_values(array_filter(array_map(static fn ($value): string => mb_strtolower(trim((string) $value)), $preferredNames)));
        if ($preferredNames === []) {
            return '';
        }

        foreach ($table->getValueFields() as $field) {
            if (!$field instanceof rex_yform_manager_field) {
                continue;
            }

            $fieldName = mb_strtolower(trim((string) $field->getName()));
            if ($fieldName !== '' && in_array($fieldName, $preferredNames, true)) {
                return (string) $field->getName();
            }
        }

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function buildFieldDefaults(rex_yform_manager_table $table): array
    {
        $fields = [];
        foreach ($table->getValueFields() as $field) {
            if (!$field instanceof rex_yform_manager_field) {
                continue;
            }

            $fieldName = trim((string) $field->getName());
            if ($fieldName === '' || $fieldName === 'id') {
                continue;
            }

            $fields[] = [
                'field' => $fieldName,
                'label' => trim((string) $field->getLabel()),
                'mode' => self::detectFieldMode((string) $field->getTypeName(), $fieldName),
                'include' => true,
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function collectColumns(string $tableName): array
    {
        $tableName = trim($tableName);
        if ($tableName === '' || !class_exists(rex_yform_manager_table::class)) {
            return [];
        }

        try {
            $table = rex_yform_manager_table::get($tableName);
        } catch (\Throwable) {
            return [];
        }

        if (!$table instanceof rex_yform_manager_table) {
            return [];
        }

        $columns = [];
        foreach ($table->getValueFields() as $field) {
            if (!$field instanceof rex_yform_manager_field) {
                continue;
            }

            $name = trim((string) $field->getName());
            if ($name === '') {
                continue;
            }

            $columns[$name] = [
                'name' => $name,
                'label' => trim((string) $field->getLabel()) !== '' ? trim((string) $field->getLabel()) : $name,
                'type_name' => trim((string) $field->getTypeName()),
                'type' => trim((string) $field->getType()),
            ];
        }

        return $columns;
    }

    public static function detectFieldMode(string $typeName, string $fieldName): string
    {
        $typeNameLower = mb_strtolower(trim($typeName));
        $fieldNameLower = mb_strtolower(trim($fieldName));

        if ($typeNameLower === 'content_builder' || str_contains($fieldNameLower, 'content_builder')) {
            return 'content_builder';
        }

        if (in_array($typeNameLower, ['date'], true) || str_contains($fieldNameLower, 'date') || str_contains($fieldNameLower, 'datum')) {
            return 'date';
        }

        if (in_array($typeNameLower, ['datetime', 'datetime_local'], true) || str_contains($fieldNameLower, 'updated') || str_contains($fieldNameLower, 'created')) {
            return 'datetime';
        }

        if ($typeNameLower === 'time' || str_contains($fieldNameLower, 'time')) {
            return 'time';
        }

        if (in_array($typeNameLower, ['choice_status', 'status', 'select', 'checkbox', 'radio'], true) || str_contains($fieldNameLower, 'status') || str_contains($fieldNameLower, 'publish') || str_contains($fieldNameLower, 'visible') || str_contains($fieldNameLower, 'active')) {
            return 'status';
        }

        if (in_array($typeNameLower, ['integer', 'int', 'decimal', 'number', 'float'], true)) {
            return 'number';
        }

        if (in_array($typeNameLower, ['media', 'medialist', 'upload', 'filepond'], true) || str_contains($fieldNameLower, 'media') || str_contains($fieldNameLower, 'image') || str_contains($fieldNameLower, 'file')) {
            return 'media';
        }

        if (str_contains($fieldNameLower, 'markdown')) {
            return 'markdown';
        }

        if (str_contains($fieldNameLower, 'textile')) {
            return 'textile';
        }

        if (in_array($typeNameLower, ['textarea', 'be_textarea', 'richtext', 'ckeditor', 'tinymce'], true) || str_contains($fieldNameLower, 'html')) {
            return 'html';
        }

        return 'text';
    }

    public static function renderValue(mixed $value, string $mode = 'auto'): string
    {
        $mode = self::normalizeMode($mode);

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if ($mode === 'json') {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return is_string($json) ? $json : '';
            }

            $parts = [];
            foreach ($value as $item) {
                $rendered = self::renderValue($item, $mode);
                if ($rendered !== '') {
                    $parts[] = $rendered;
                }
            }

            return self::normalizeText(implode(' ', $parts));
        }

        if (!is_string($value)) {
            return self::normalizeText((string) $value);
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ($mode === 'content_builder') {
            return self::extractContentBuilderText($value);
        }

        if ($mode === 'json') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return self::normalizeText((string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }

        if ($mode === 'html') {
            return self::normalizeText(strip_tags($value));
        }

        if ($mode === 'markdown') {
            $value = preg_replace('/^#{1,6}\s+/m', '', $value) ?? $value;
            $value = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $value) ?? $value;
            $value = preg_replace('/[*_`>#-]/', ' ', $value) ?? $value;

            return self::normalizeText($value);
        }

        if ($mode === 'textile') {
            $value = preg_replace('/\*(.*?)\*/', '$1', $value) ?? $value;
            $value = preg_replace('/\[(.*?)\|(.*?)\]/', '$1', $value) ?? $value;

            return self::normalizeText($value);
        }

        return self::normalizeText($value);
    }

    public static function extractContentBuilderText(string $jsonContent): string
    {
        $decoded = json_decode($jsonContent, true);
        if (!is_array($decoded)) {
            return self::normalizeText($jsonContent);
        }

        $parts = [];
        self::walkContentBuilderNode($decoded, $parts);

        return self::normalizeText(implode(' ', $parts));
    }

    /**
     * @param array<int|string, mixed> $node
     * @param list<string> $parts
     */
    private static function walkContentBuilderNode(array $node, array &$parts): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'online' && $value === false) {
                return;
            }

            if (is_string($value)) {
                $keyName = is_string($key) ? mb_strtolower($key) : '';
                if (self::isIgnoredContentBuilderKey($keyName)) {
                    continue;
                }

                $text = self::normalizeText(strip_tags($value));
                if ($text !== '') {
                    $parts[] = $text;
                }
                continue;
            }

            if (is_array($value)) {
                self::walkContentBuilderNode($value, $parts);
            }
        }
    }

    private static function isIgnoredContentBuilderKey(string $key): bool
    {
        return $key !== '' && (str_contains($key, 'image') || str_contains($key, 'media') || str_contains($key, 'file') || str_contains($key, 'icon'));
    }

    private static function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function normalizeMode(string $mode): string
    {
        $mode = mb_strtolower(trim($mode));
        $allowed = ['auto', 'plain', 'text', 'html', 'markdown', 'textile', 'content_builder', 'json', 'date', 'datetime', 'time', 'number', 'status', 'media'];

        return in_array($mode, $allowed, true) ? $mode : 'auto';
    }

    private static function normalizeSortDir(string $sortDir): string
    {
        return strtoupper(trim($sortDir)) === 'ASC' ? 'ASC' : 'DESC';
    }
    private static function normalizeUrlMode(string $mode): string
    {
        $mode = mb_strtolower(trim($mode));
        $allowed = ['field', 'profile', 'template'];

        return in_array($mode, $allowed, true) ? $mode : 'field';
    }

    private static function normalizeLabel(string $label, string $fallback): string
    {
        $label = trim($label);
        if ($label === '') {
            $label = trim($fallback);
        }

        return $label !== '' ? $label : $fallback;
    }

    private static function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/^rex_/', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/i', '_', $value) ?? $value;

        return trim($value, '_') ?: 'profile';
    }

    private static function normalizeOperator(string $operator): string
    {
        $operator = mb_strtolower(trim($operator));
        $allowed = ['equals', 'not_equals', 'contains', 'not_contains', 'lt', 'lte', 'gt', 'gte', 'before_now', 'after_now', 'is_empty', 'is_not_empty'];

        return in_array($operator, $allowed, true) ? $operator : 'equals';
    }

    private static function normalizeValueType(string $valueType): string
    {
        $valueType = mb_strtolower(trim($valueType));
        $allowed = ['auto', 'text', 'number', 'date', 'datetime', 'time', 'timestamp'];

        return in_array($valueType, $allowed, true) ? $valueType : 'auto';
    }

    /**
     * @param mixed $value
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $value = mb_strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on', 'checked'], true);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>|null
     */
    private static function normalizeConfigValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $row
     */
    public static function profileMatchesRow(array $profile, array $row): bool
    {
        $conditions = $profile['conditions'] ?? [];
        if (!is_array($conditions) || $conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            if (!self::conditionMatchesRow($condition, $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $condition
     * @param array<string, mixed> $row
     */
    private static function conditionMatchesRow(array $condition, array $row): bool
    {
        $field = trim((string) ($condition['field'] ?? ''));
        if ($field === '') {
            return true;
        }

        $operator = self::normalizeOperator((string) ($condition['operator'] ?? 'equals'));
        $rowValue = $row[$field] ?? null;
        $value = (string) ($condition['value'] ?? '');
        $valueType = self::normalizeValueType((string) ($condition['value_type'] ?? 'auto'));

        if ($operator === 'is_empty') {
            return self::normalizeConditionValue($rowValue) === '';
        }

        if ($operator === 'is_not_empty') {
            return self::normalizeConditionValue($rowValue) !== '';
        }

        if ($operator === 'before_now' || $operator === 'after_now') {
            $rowTs = self::toTimestamp(self::normalizeConditionValue($rowValue));
            if ($rowTs <= 0) {
                return false;
            }

            $now = time();
            return $operator === 'before_now' ? $rowTs <= $now : $rowTs >= $now;
        }

        $rowText = self::normalizeConditionValue($rowValue);
        $compareText = self::normalizeConditionValue(self::resolveConditionValue($value, $valueType));

        if ($operator === 'contains') {
            return '' !== $compareText && str_contains(mb_strtolower($rowText), mb_strtolower($compareText));
        }

        if ($operator === 'not_contains') {
            return '' === $compareText || !str_contains(mb_strtolower($rowText), mb_strtolower($compareText));
        }

        if (in_array($operator, ['lt', 'lte', 'gt', 'gte'], true)) {
            $rowComparable = self::resolveComparableValue($rowText, $valueType);
            $compareComparable = self::resolveComparableValue($compareText, $valueType);

            if (is_numeric($rowComparable) && is_numeric($compareComparable)) {
                $rowComparable = (float) $rowComparable;
                $compareComparable = (float) $compareComparable;
            }

            return match ($operator) {
                'lt' => $rowComparable < $compareComparable,
                'lte' => $rowComparable <= $compareComparable,
                'gt' => $rowComparable > $compareComparable,
                'gte' => $rowComparable >= $compareComparable,
                default => true,
            };
        }

        if ($operator === 'not_equals') {
            return mb_strtolower($rowText) !== mb_strtolower($compareText);
        }

        return mb_strtolower($rowText) === mb_strtolower($compareText);
    }

    private static function normalizeConditionValue(mixed $value): string
    {
        if (is_array($value)) {
            return trim(implode(' ', array_map(static fn ($item): string => self::normalizeConditionValue($item), $value)));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private static function resolveConditionValue(string $value, string $valueType): string
    {
        if ($value === 'now') {
            return (string) time();
        }

        if ($value === 'today') {
            return date('Y-m-d 00:00:00');
        }

        if ($valueType === 'timestamp' && ctype_digit($value)) {
            return $value;
        }

        return $value;
    }

    private static function resolveComparableValue(string $value, string $valueType): string|float|int
    {
        if ($valueType === 'number' && is_numeric($value)) {
            return (float) $value;
        }

        if (in_array($valueType, ['date', 'datetime', 'time', 'timestamp'], true)) {
            $ts = self::toTimestamp($value);
            if ($ts > 0) {
                return $ts;
            }
        }

        if ($value === 'now') {
            return time();
        }

        return $value;
    }

    private static function toTimestamp(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return false === $timestamp ? 0 : $timestamp;
    }
}