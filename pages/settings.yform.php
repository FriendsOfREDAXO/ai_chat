<?php

$addon = rex_addon::get('ai_chat');

if (!rex_addon::exists('yform') || !rex_addon::get('yform')->isAvailable()) {
    echo rex_view::warning('YForm ist nicht verfügbar.');
    return;
}

$csrf = rex_csrf_token::factory('ai_chat_yform_profiles');
$func = rex_request('func', 'string', '');
$message = '';

if ($func !== '' && !$csrf->isValid()) {
    $message = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    $func = '';
}

if ($func === 'save') {
    $postedProfiles = rex_post('profiles', 'array', []);
    $normalizedProfiles = FriendsOfRedaxo\AiChat\ContentProvider\YformProfiles::sanitizeProfiles($postedProfiles);
    rex_config::set('ai_chat', 'yform_provider_profiles', $normalizedProfiles);
    $message = rex_view::success('YForm-Mappings gespeichert.');
}

$profiles = FriendsOfRedaxo\AiChat\ContentProvider\YformProfiles::getAll($addon);

$availableTables = [];
$columnsMap = [];
if (class_exists(rex_yform_manager_table::class)) {
    try {
        foreach (rex_yform_manager_table::getAll() as $table) {
            $tableName = trim((string) $table->getTableName());
            if ($tableName === '') {
                continue;
            }

            $availableTables[$tableName] = $table->getName() . ' (' . $tableName . ')';
            $columns = FriendsOfRedaxo\AiChat\ContentProvider\YformProfiles::collectColumns($tableName);
            $columnsMap[$tableName] = array_values($columns);
        }
    } catch (Throwable) {
        $availableTables = [];
        $columnsMap = [];
    }
}
ksort($availableTables);

// "URL-Profil" bezeichnet einen Namespace des url-Addons (rex_getUrl($namespace, ...)) -
// als Auswahl statt Freitext, damit man sich nicht auf die exakte Schreibweise verlassen
// muss und nur tatsaechlich existierende Profile waehlen kann.
$urlProfileOptions = [];
if (class_exists(\Url\Profile::class)) {
    try {
        foreach (\Url\Profile::getAll() as $urlProfile) {
            $namespace = trim((string) $urlProfile->getNamespace());
            if ('' === $namespace) {
                continue;
            }

            $urlProfileOptions[$namespace] = $namespace . ' (' . $urlProfile->getTableName() . ')';
        }
    } catch (Throwable) {
        $urlProfileOptions = [];
    }
}
ksort($urlProfileOptions);

$assetVersion = static function (string $assetName) use ($addon): string {
    $path = $addon->getPath('assets/' . $assetName);
    return is_file($path) ? (string) filemtime($path) : '1';
};

$renderOptions = static function (array $options, string $currentValue, string $emptyLabel = '', bool $allowEmpty = true): string {
    $html = '';
    if ($allowEmpty) {
        $html .= '<option value=""' . ($currentValue === '' ? ' selected' : '') . '>' . rex_escape($emptyLabel !== '' ? $emptyLabel : '— optional —') . '</option>';
    }

    foreach ($options as $value => $label) {
        $selected = ((string) $value === $currentValue) ? ' selected' : '';
        $html .= '<option value="' . rex_escape((string) $value) . '"' . $selected . '>' . rex_escape((string) $label) . '</option>';
    }

    return $html;
};

$renderColumnSelect = static function (string $profileKey, string $fieldName, string $currentValue, string $tableName, array $columnsMap, bool $allowEmpty = true, string $placeholder = '— optional —', ?string $explicitName = null) use ($renderOptions): string {
    $columns = [];
    if ($tableName !== '' && isset($columnsMap[$tableName]) && is_array($columnsMap[$tableName])) {
        foreach ($columnsMap[$tableName] as $column) {
            if (!is_array($column)) {
                continue;
            }

            $name = trim((string) ($column['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $label = trim((string) ($column['label'] ?? ''));
            $columns[$name] = $label !== '' ? $label : $name;
        }
    }

    $options = '';
    if ($currentValue !== '' && !isset($columns[$currentValue])) {
        $options .= '<option value="' . rex_escape($currentValue) . '" selected>' . rex_escape($currentValue) . ' (nicht in Tabelle)</option>';
    }

    $options .= $renderOptions($columns, $currentValue, $placeholder, $allowEmpty);

    $name = $explicitName ?? ('profiles[' . $profileKey . '][' . $fieldName . ']');

    return '<select class="form-control js-column-select" data-profile-key="' . rex_escape($profileKey) . '" data-allow-empty="' . ($allowEmpty ? '1' : '0') . '" data-empty-label="' . rex_escape($placeholder) . '" data-current-value="' . rex_escape($currentValue) . '" name="' . rex_escape($name) . '">' . $options . '</select>';
};

// Select statt Freitext, wenn das url-Addon verfuegbar ist und Profile registriert hat -
// verhindert Tippfehler beim Namespace und zeigt nur tatsaechlich existierende Profile.
// Faellt auf ein Textfeld zurueck, wenn keine Profile bekannt sind (z.B. url-Addon nicht
// installiert), damit ein bereits gespeicherter Namespace nicht "verschwindet".
$renderUrlProfileField = static function (string $profileKey, string $currentValue) use ($renderOptions, $urlProfileOptions): string {
    $name = 'profiles[' . $profileKey . '][url_profile]';

    if ([] === $urlProfileOptions) {
        return '<input class="form-control" type="text" name="' . rex_escape($name) . '" value="' . rex_escape($currentValue) . '" placeholder="news">';
    }

    $options = '';
    if ($currentValue !== '' && !isset($urlProfileOptions[$currentValue])) {
        $options .= '<option value="' . rex_escape($currentValue) . '" selected>' . rex_escape($currentValue) . ' (nicht gefunden)</option>';
    }
    $options .= $renderOptions($urlProfileOptions, $currentValue, '— URL-Profil wählen —', true);

    return '<select class="form-control" name="' . rex_escape($name) . '">' . $options . '</select>';
};

$renderRepeaterRows = static function (string $profileKey, string $repeaterName, array $rows, callable $renderRow): string {
    $html = '<div class="klxm-repeater" data-repeater-name="' . rex_escape($repeaterName) . '">';
    $html .= '<div class="klxm-repeater-items">';
    if ($rows === []) {
        $html .= $renderRow($profileKey, 0, []);
    } else {
        foreach ($rows as $index => $row) {
            $html .= $renderRow($profileKey, (int) $index, is_array($row) ? $row : []);
        }
    }
    $html .= '</div>';
    $html .= '<button type="button" class="btn btn-default btn-sm klxm-repeater-add" data-add-repeater-row="1"><i class="rex-icon fa-plus"></i> Hinzufügen</button>';
    $html .= '<template class="klxm-repeater-template">';
    $html .= $renderRow($profileKey, '__ROW__', []);
    $html .= '</template>';
    $html .= '</div>';

    return $html;
};

$renderFieldRow = static function (string $profileKey, $rowIndex, array $row, string $tableName, array $columnsMap, string $kind) use ($renderColumnSelect, $renderOptions): string {
    $isCondition = 'condition' === $kind;
    $fieldName = (string) ($row['field'] ?? '');
    $labelValue = (string) ($row['label'] ?? '');
    $modeValue = (string) ($row['mode'] ?? 'auto');
    $operatorValue = (string) ($row['operator'] ?? 'equals');
    $valueValue = (string) ($row['value'] ?? '');
    $valueTypeValue = (string) ($row['value_type'] ?? 'auto');
    $includeChecked = !isset($row['include']) || !empty($row['include']);

    $collectionName = $isCondition ? 'conditions' : 'fields';
    $nameBase = 'profiles[' . $profileKey . '][' . $collectionName . '][' . $rowIndex . ']';
    $html = '<div class="row klxm-repeater-row" data-repeater-row="1">';
    $html .= '<div class="col-md-3">' . $renderColumnSelect($profileKey, 'field', $fieldName, $tableName, $columnsMap, true, '— bitte wählen —', $nameBase . '[field]') . '</div>';

    if ($isCondition) {
        $html .= '<div class="col-md-3"><select class="form-control" name="' . rex_escape($nameBase . '[operator]') . '">';
        $html .= $renderOptions([
            'equals' => 'ist gleich',
            'not_equals' => 'ist ungleich',
            'contains' => 'enthält',
            'not_contains' => 'enthält nicht',
            'lt' => 'kleiner als',
            'lte' => 'kleiner/gleich',
            'gt' => 'größer als',
            'gte' => 'größer/gleich',
            'before_now' => 'vor jetzt',
            'after_now' => 'nach jetzt',
            'is_empty' => 'ist leer',
            'is_not_empty' => 'ist nicht leer',
        ], $operatorValue, '— Operator —', false);
        $html .= '</select></div>';
        $html .= '<div class="col-md-4"><input class="form-control" type="text" name="' . rex_escape($nameBase . '[value]') . '" value="' . rex_escape($valueValue) . '" placeholder="Wert oder now/today"></div>';
        $html .= '<div class="col-md-2 text-right"><button type="button" class="btn btn-danger btn-sm klxm-repeater-remove" data-remove-repeater-row="1"><i class="rex-icon fa-trash"></i></button></div>';
        $html .= '<input type="hidden" name="' . rex_escape($nameBase . '[value_type]') . '" value="' . rex_escape($valueTypeValue !== '' ? $valueTypeValue : 'auto') . '">';
    } else {
        $html .= '<div class="col-md-3"><input class="form-control" type="text" name="' . rex_escape($nameBase . '[label]') . '" value="' . rex_escape($labelValue) . '" placeholder="Label"></div>';
        $html .= '<div class="col-md-2"><select class="form-control" name="' . rex_escape($nameBase . '[mode]') . '">';
        $html .= $renderOptions([
            'auto' => 'Auto',
            'text' => 'Plain Text',
            'html' => 'HTML',
            'markdown' => 'Markdown',
            'textile' => 'Textile',
            'content_builder' => 'Content Builder JSON',
            'json' => 'JSON',
            'date' => 'Datum',
            'datetime' => 'Datum/Uhrzeit',
            'time' => 'Uhrzeit',
            'number' => 'Zahl',
            'status' => 'Status',
            'media' => 'Medienname',
        ], $modeValue, '— Typ —', false);
        $html .= '</select></div>';
        $html .= '<div class="col-md-1" style="padding-top:7px;"><label class="checkbox-inline"><input type="checkbox" name="' . rex_escape($nameBase . '[include]') . '" value="1"' . ($includeChecked ? ' checked' : '') . '> aktiv</label></div>';
        $html .= '<div class="col-md-3 text-right"><button type="button" class="btn btn-danger btn-sm klxm-repeater-remove" data-remove-repeater-row="1"><i class="rex-icon fa-trash"></i></button></div>';
    }

    $html .= '</div>';

    return $html;
};

$profileRowsHtml = '';
if ($profiles === []) {
    $profileRowsHtml .= '<div class="alert alert-info">Noch keine Mappings angelegt.</div>';
} else {
    foreach ($profiles as $profileId => $profile) {
        $profileTable = (string) ($profile['table'] ?? '');
        $profileColumns = $profileTable !== '' && isset($columnsMap[$profileTable]) ? $columnsMap[$profileTable] : [];
        $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
        $conditions = is_array($profile['conditions'] ?? null) ? $profile['conditions'] : [];

        $profileRowsHtml .= '<section class="panel panel-default klxm-yform-profile" data-profile-card="1" data-profile-key="' . rex_escape((string) $profileId) . '">';
        $profileRowsHtml .= '<div class="panel-heading clearfix"><strong>' . rex_escape((string) ($profile['label'] ?: $profileId)) . '</strong><div class="pull-right"><button type="button" class="btn btn-danger btn-xs" data-remove-profile="1"><i class="rex-icon fa-trash"></i> Entfernen</button></div></div>';
        $profileRowsHtml .= '<div class="panel-body">';

        $profileRowsHtml .= '<input type="hidden" name="profiles[' . rex_escape((string) $profileId) . '][_key]" value="' . rex_escape((string) $profileId) . '">';
        $profileRowsHtml .= '<div class="row"><div class="col-md-6"><label>Mapping-ID</label><input class="form-control" type="text" name="profiles[' . rex_escape((string) $profileId) . '][id]" value="' . rex_escape((string) ($profile['id'] ?? $profileId)) . '" placeholder="news"></div><div class="col-md-6"><label>Bezeichnung</label><input class="form-control" type="text" name="profiles[' . rex_escape((string) $profileId) . '][label]" value="' . rex_escape((string) ($profile['label'] ?? '')) . '" placeholder="News"></div></div>';

        $profileRowsHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-6"><label>Tabelle</label><select class="form-control js-table-select" name="profiles[' . rex_escape((string) $profileId) . '][table]" data-profile-key="' . rex_escape((string) $profileId) . '"><option value="">— Tabelle wählen —</option>';
        foreach ($availableTables as $tableName => $label) {
            $selected = $tableName === $profileTable ? ' selected' : '';
            $profileRowsHtml .= '<option value="' . rex_escape($tableName) . '"' . $selected . '>' . rex_escape($label) . '</option>';
        }
        $profileRowsHtml .= '</select></div>';
        $profileRowsHtml .= '<div class="col-md-3"><label>Sortier-Richtung</label><select class="form-control" name="profiles[' . rex_escape((string) $profileId) . '][sort_dir]">' . $renderOptions(['DESC' => 'Absteigend', 'ASC' => 'Aufsteigend'], (string) ($profile['sort_dir'] ?? 'DESC'), '— Richtung —', false) . '</select></div>';
        $profileRowsHtml .= '<div class="col-md-3"><label>Sortier-Spalte</label>' . $renderColumnSelect((string) $profileId, 'sort_field', (string) ($profile['sort_field'] ?? ''), $profileTable, $columnsMap, false, '— bitte wählen —') . '</div></div>';

        $profileRowsHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Titel-Spalte</label>' . $renderColumnSelect((string) $profileId, 'title_field', (string) ($profile['title_field'] ?? ''), $profileTable, $columnsMap, false, '— bitte wählen —') . '</div>';
        $profileRowsHtml .= '<div class="col-md-4"><label>Content-Spalte</label>' . $renderColumnSelect((string) $profileId, 'content_field', (string) ($profile['content_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
        $profileRowsHtml .= '<div class="col-md-4"><label>Content-Modus</label><select class="form-control" name="profiles[' . rex_escape((string) $profileId) . '][content_field_mode]">' . $renderOptions([
            'auto' => 'Auto',
            'text' => 'Plain Text',
            'html' => 'HTML',
            'markdown' => 'Markdown',
            'textile' => 'Textile',
            'content_builder' => 'Content Builder JSON',
            'json' => 'JSON',
        ], (string) ($profile['content_field_mode'] ?? 'auto'), '— Typ —', false) . '</select></div></div>';

        $profileRowsHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Status-Spalte</label>' . $renderColumnSelect((string) $profileId, 'status_field', (string) ($profile['status_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
        $profileRowsHtml .= '<div class="col-md-4"><label>Statuswerte</label><input class="form-control" type="text" name="profiles[' . rex_escape((string) $profileId) . '][status_values]" value="' . rex_escape((string) ($profile['status_values'] ?? '')) . '" placeholder="1,online,published"></div>';
        $profileRowsHtml .= '<div class="col-md-4"><label>Datumsspalte</label>' . $renderColumnSelect((string) $profileId, 'date_field', (string) ($profile['date_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div></div>';

        $profileRowsHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-6"><label>Erstellt am</label>' . $renderColumnSelect((string) $profileId, 'created_field', (string) ($profile['created_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
        $profileRowsHtml .= '<div class="col-md-6"><label>Aktualisiert am</label>' . $renderColumnSelect((string) $profileId, 'updated_field', (string) ($profile['updated_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div></div>';

        $profileRowsHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Sprach-Spalte</label>' . $renderColumnSelect((string) $profileId, 'clang_field', (string) ($profile['clang_field'] ?? ''), $profileTable, $columnsMap, true, '— optional, keine Sprachfilterung —') . '</div>';
        $profileRowsHtml .= '<div class="col-md-8"><label>Sprachen (clang-IDs)</label><input class="form-control" type="text" name="profiles[' . rex_escape((string) $profileId) . '][clang_ids]" value="' . rex_escape((string) ($profile['clang_ids'] ?? '')) . '" placeholder="1,2"><p class="help-block">Kommagetrennte clang-IDs. Nur wirksam, wenn eine Sprach-Spalte gewählt ist; leer = alle Sprachen (Standard, unverändertes Verhalten).</p></div></div>';

        $profileRowsHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-3"><label>URL-Modus</label><select class="form-control js-url-mode-select" name="profiles[' . rex_escape((string) $profileId) . '][url_mode]">' . $renderOptions([
            'field' => 'Aus Feldwert',
            'profile' => 'URL-Profil (Namespace)',
            'template' => 'Template',
        ], (string) ($profile['url_mode'] ?? 'field'), '— Modus —', false) . '</select></div>';
        $profileRowsHtml .= '<div class="col-md-3" data-url-mode-field="field"><label>URL-Feld</label>' . $renderColumnSelect((string) $profileId, 'url_field', (string) ($profile['url_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
        $profileRowsHtml .= '<div class="col-md-3" data-url-mode-field="profile"><label>URL-Profil</label>' . $renderUrlProfileField((string) $profileId, (string) ($profile['url_profile'] ?? '')) . '</div>';
        $profileRowsHtml .= '<div class="col-md-3" data-url-mode-field="template"><label>URL-Template</label><input class="form-control" type="text" name="profiles[' . rex_escape((string) $profileId) . '][url_template]" value="' . rex_escape((string) ($profile['url_template'] ?? '')) . '" placeholder="/news/{id}-{slug}"></div></div>';

        $profileRowsHtml .= '<hr><h4>Zusätzliche Felder</h4><p class="help-block">Diese Felder werden in den Suchtext aufgenommen. Feldtyp und Anzeige können pro Zeile überschrieben werden.</p>';
        $profileRowsHtml .= '<div class="klxm-repeater" data-repeater-name="fields">';
        $profileRowsHtml .= '<div class="klxm-repeater-items">';
        if ($fields === []) {
            $profileRowsHtml .= $renderFieldRow((string) $profileId, 0, [], $profileTable, $columnsMap, 'field');
        } else {
            foreach ($fields as $idx => $fieldRow) {
                $profileRowsHtml .= $renderFieldRow((string) $profileId, (int) $idx, is_array($fieldRow) ? $fieldRow : [], $profileTable, $columnsMap, 'field');
            }
        }
        $profileRowsHtml .= '</div><button type="button" class="btn btn-default btn-sm klxm-repeater-add" data-add-repeater-row="1"><i class="rex-icon fa-plus"></i> Feld hinzufügen</button>';
        $profileRowsHtml .= '<template class="klxm-repeater-template">' . $renderFieldRow((string) $profileId, '__ROW__', [], $profileTable, $columnsMap, 'field') . '</template>';
        $profileRowsHtml .= '</div>';

        $profileRowsHtml .= '<hr><h4>Bedingungen</h4><p class="help-block">Beispiel: Feld <code>news_date</code> mit Operator <code>vor jetzt</code> verhindert, dass zukünftige News indexiert werden.</p>';
        $profileRowsHtml .= '<div class="klxm-repeater" data-repeater-name="conditions">';
        $profileRowsHtml .= '<div class="klxm-repeater-items">';
        if ($conditions === []) {
            $profileRowsHtml .= $renderFieldRow((string) $profileId, 0, [], $profileTable, $columnsMap, 'condition');
        } else {
            foreach ($conditions as $idx => $conditionRow) {
                $profileRowsHtml .= $renderFieldRow((string) $profileId, (int) $idx, is_array($conditionRow) ? $conditionRow : [], $profileTable, $columnsMap, 'condition');
            }
        }
        $profileRowsHtml .= '</div><button type="button" class="btn btn-default btn-sm klxm-repeater-add" data-add-repeater-row="1"><i class="rex-icon fa-plus"></i> Bedingung hinzufügen</button>';
        $profileRowsHtml .= '<template class="klxm-repeater-template">' . $renderFieldRow((string) $profileId, '__ROW__', [], $profileTable, $columnsMap, 'condition') . '</template>';
        $profileRowsHtml .= '</div>';

        $profileRowsHtml .= '</div></section>';
    }
}

$templateProfileKey = '__PROFILE_KEY__';
$templateHtml = '<section class="panel panel-default klxm-yform-profile" data-profile-card="1" data-profile-key="' . $templateProfileKey . '">';
$templateHtml .= '<div class="panel-heading clearfix"><strong>Neues Mapping</strong><div class="pull-right"><button type="button" class="btn btn-danger btn-xs" data-remove-profile="1"><i class="rex-icon fa-trash"></i> Entfernen</button></div></div>';
$templateHtml .= '<div class="panel-body">';
$templateHtml .= '<input type="hidden" name="profiles[' . $templateProfileKey . '][_key]" value="">';
$templateHtml .= '<div class="row"><div class="col-md-6"><label>Mapping-ID</label><input class="form-control" type="text" name="profiles[' . $templateProfileKey . '][id]" value="" placeholder="news"></div><div class="col-md-6"><label>Bezeichnung</label><input class="form-control" type="text" name="profiles[' . $templateProfileKey . '][label]" value="" placeholder="News"></div></div>';
$templateHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-6"><label>Tabelle</label><select class="form-control js-table-select" name="profiles[' . $templateProfileKey . '][table]" data-profile-key="' . $templateProfileKey . '"><option value="">— Tabelle wählen —</option>';
foreach ($availableTables as $tableName => $label) {
    $templateHtml .= '<option value="' . rex_escape($tableName) . '">' . rex_escape($label) . '</option>';
}
$templateHtml .= '</select></div><div class="col-md-3"><label>Sortier-Richtung</label><select class="form-control" name="profiles[' . $templateProfileKey . '][sort_dir]"><option value="DESC" selected>Absteigend</option><option value="ASC">Aufsteigend</option></select></div><div class="col-md-3"><label>Sortier-Spalte</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][sort_field]"><option value="">— bitte wählen —</option></select></div></div>';
$templateHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Titel-Spalte</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][title_field]"><option value="">— bitte wählen —</option></select></div><div class="col-md-4"><label>Content-Spalte</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][content_field]"><option value="">— optional —</option></select></div><div class="col-md-4"><label>Content-Modus</label><select class="form-control" name="profiles[' . $templateProfileKey . '][content_field_mode]"><option value="auto" selected>Auto</option><option value="text">Plain Text</option><option value="html">HTML</option><option value="markdown">Markdown</option><option value="textile">Textile</option><option value="content_builder">Content Builder JSON</option><option value="json">JSON</option></select></div></div>';
$templateHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Status-Spalte</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][status_field]"><option value="">— optional —</option></select></div><div class="col-md-4"><label>Statuswerte</label><input class="form-control" type="text" name="profiles[' . $templateProfileKey . '][status_values]" value="1,online,published" placeholder="1,online,published"></div><div class="col-md-4"><label>Datumsspalte</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][date_field]"><option value="">— optional —</option></select></div></div>';
$templateHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-6"><label>Erstellt am</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][created_field]"><option value="">— optional —</option></select></div><div class="col-md-6"><label>Aktualisiert am</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][updated_field]"><option value="">— optional —</option></select></div></div>';
$templateHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Sprach-Spalte</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][clang_field]"><option value="">— optional, keine Sprachfilterung —</option></select></div><div class="col-md-8"><label>Sprachen (clang-IDs)</label><input class="form-control" type="text" name="profiles[' . $templateProfileKey . '][clang_ids]" value="" placeholder="1,2"><p class="help-block">Kommagetrennte clang-IDs. Nur wirksam, wenn eine Sprach-Spalte gewählt ist; leer = alle Sprachen.</p></div></div>';
$templateHtml .= '<div class="row" style="margin-top:10px;"><div class="col-md-3"><label>URL-Modus</label><select class="form-control js-url-mode-select" name="profiles[' . $templateProfileKey . '][url_mode]"><option value="field" selected>Aus Feldwert</option><option value="profile">URL-Profil (Namespace)</option><option value="template">Template</option></select></div><div class="col-md-3" data-url-mode-field="field"><label>URL-Feld</label><select class="form-control js-column-select" data-profile-key="' . $templateProfileKey . '" name="profiles[' . $templateProfileKey . '][url_field]"><option value="">— optional —</option></select></div><div class="col-md-3" data-url-mode-field="profile"><label>URL-Profil</label>' . $renderUrlProfileField($templateProfileKey, '') . '</div><div class="col-md-3" data-url-mode-field="template"><label>URL-Template</label><input class="form-control" type="text" name="profiles[' . $templateProfileKey . '][url_template]" value="" placeholder="/news/{id}-{slug}"></div></div>';
$templateHtml .= '<hr><h4>Zusätzliche Felder</h4><div class="klxm-repeater" data-repeater-name="fields"><div class="klxm-repeater-items">' . $renderFieldRow($templateProfileKey, 0, [], '', $columnsMap, 'field') . '</div><button type="button" class="btn btn-default btn-sm klxm-repeater-add" data-add-repeater-row="1"><i class="rex-icon fa-plus"></i> Feld hinzufügen</button><template class="klxm-repeater-template">' . $renderFieldRow($templateProfileKey, '__ROW__', [], '', $columnsMap, 'field') . '</template></div>';
$templateHtml .= '<hr><h4>Bedingungen</h4><div class="klxm-repeater" data-repeater-name="conditions"><div class="klxm-repeater-items">' . $renderFieldRow($templateProfileKey, 0, [], '', $columnsMap, 'condition') . '</div><button type="button" class="btn btn-default btn-sm klxm-repeater-add" data-add-repeater-row="1"><i class="rex-icon fa-plus"></i> Bedingung hinzufügen</button><template class="klxm-repeater-template">' . $renderFieldRow($templateProfileKey, '__ROW__', [], '', $columnsMap, 'condition') . '</template></div>';
$templateHtml .= '</div></section>';

$body = '<div id="ai-yform-mapping-root" data-columns-map="' . rex_escape(json_encode($columnsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . '">';
$body .= $message;
$body .= '<p class="help-block">Hier pflegst du die expliziten YForm-Mappings. Nur die hier angelegten Mappings werden indexiert. Für News kannst du z.B. eine Bedingung <code>news_date vor jetzt</code> setzen, damit keine zukünftigen Einträge in die Suche kommen. URL-Modus: <code>URL-Profil</code> nutzt den Namespace via <code>rex_getUrl(..., [... =&gt; id])</code>, <code>Template</code> unterstützt <code>{id}</code>, <code>{slug}</code>, <code>{title}</code>, <code>{table}</code>, <code>{profile}</code>.</p>';
// rex_url::currentBackendPage() escaped den "&"-Trenner zwischen mehreren Params
// bereits selbst (3. Parameter $escape, Standard true) - kein zusaetzliches
// rex_escape() davorsetzen, das wuerde bei mehr als einem Param zu "&amp;amp;"
// doppelt escapen.
$body .= '<form method="post" action="' . rex_url::currentBackendPage() . '">';
$body .= $csrf->getHiddenField();
$body .= '<input type="hidden" name="func" value="save">';
$body .= '<div id="klxm-profiles-list">' . $profileRowsHtml . '</div>';
$body .= '<p><button type="button" class="btn btn-primary" data-add-profile="1"><i class="rex-icon fa-plus"></i> Profil hinzufügen</button></p>';
$body .= '<p><button type="submit" class="btn btn-primary"><i class="rex-icon fa-save"></i> Mappings speichern</button></p>';
$body .= '<template id="klxm-profile-template">' . $templateHtml . '</template>';
$body .= '</form></div>';

$fragment = new rex_fragment();
$fragment->setVar('title', 'YForm-Tabellen', false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');