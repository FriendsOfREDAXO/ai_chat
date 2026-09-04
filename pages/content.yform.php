<?php

use FriendsOfRedaxo\AiChat\ContentProvider\YformProfiles;

$addon = rex_addon::get('ai_chat');

if (!rex_addon::exists('yform') || !rex_addon::get('yform')->isAvailable()) {
    echo rex_view::warning('YForm ist nicht verfügbar.');
    return;
}

$csrf = rex_csrf_token::factory('ai_chat_yform_profiles');
$func = rex_request('func', 'string', '');
$key = rex_request('key', 'string', '');
$message = '';

if (in_array($func, ['save', 'delete'], true) && !$csrf->isValid()) {
    $message = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    $func = '';
}

if ('delete' === $func) {
    $profiles = YformProfiles::getAll($addon);
    if (isset($profiles[$key])) {
        unset($profiles[$key]);
        rex_config::set('ai_chat', 'yform_provider_profiles', $profiles);
        $message = rex_view::success('Mapping gelöscht.');
    }
    $func = '';
}

if ('save' === $func) {
    $posted = rex_post('profile', 'array', []);
    $originalKey = rex_post('original_key', 'string', '');
    $normalized = YformProfiles::sanitizeProfiles(['_' => $posted]);

    if ([] === $normalized) {
        $message = rex_view::error('Bitte mindestens Mapping-ID und Tabelle angeben.');
        $func = '' !== $originalKey ? 'edit' : 'add';
        $key = $originalKey;
    } else {
        $profiles = YformProfiles::getAll($addon);
        if ('' !== $originalKey) {
            unset($profiles[$originalKey]);
        }
        $profiles = array_merge($profiles, $normalized);
        rex_config::set('ai_chat', 'yform_provider_profiles', $profiles);
        $message = rex_view::success('Mapping gespeichert.');
        $func = '';
    }
}

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

$renderColumnSelect = static function (string $fieldName, string $currentValue, string $tableName, array $columnsMap, bool $allowEmpty = true, string $placeholder = '— optional —', ?string $explicitName = null) use ($renderOptions): string {
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

    $name = $explicitName ?? ('profile[' . $fieldName . ']');

    return '<select class="form-control js-column-select" data-allow-empty="' . ($allowEmpty ? '1' : '0') . '" data-empty-label="' . rex_escape($placeholder) . '" data-current-value="' . rex_escape($currentValue) . '" name="' . rex_escape($name) . '">' . $options . '</select>';
};

// Select statt Freitext, wenn das url-Addon verfuegbar ist und Profile registriert hat -
// verhindert Tippfehler beim Namespace und zeigt nur tatsaechlich existierende Profile.
// Faellt auf ein Textfeld zurueck, wenn keine Profile bekannt sind (z.B. url-Addon nicht
// installiert), damit ein bereits gespeicherter Namespace nicht "verschwindet".
$renderUrlProfileField = static function (string $currentValue) use ($renderOptions, $urlProfileOptions): string {
    $name = 'profile[url_profile]';

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

$renderFieldRow = static function ($rowIndex, array $row, string $tableName, array $columnsMap, string $kind) use ($renderColumnSelect, $renderOptions): string {
    $isCondition = 'condition' === $kind;
    $fieldName = (string) ($row['field'] ?? '');
    $labelValue = (string) ($row['label'] ?? '');
    $modeValue = (string) ($row['mode'] ?? 'auto');
    $operatorValue = (string) ($row['operator'] ?? 'equals');
    $valueValue = (string) ($row['value'] ?? '');
    $valueTypeValue = (string) ($row['value_type'] ?? 'auto');
    $includeChecked = !isset($row['include']) || !empty($row['include']);

    $collectionName = $isCondition ? 'conditions' : 'fields';
    $nameBase = 'profile[' . $collectionName . '][' . $rowIndex . ']';
    $html = '<div class="row klxm-repeater-row" data-repeater-row="1">';
    $html .= '<div class="col-md-3">' . $renderColumnSelect('field', $fieldName, $tableName, $columnsMap, true, '— bitte wählen —', $nameBase . '[field]') . '</div>';

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

if ('add' === $func || 'edit' === $func) {
    $profiles = YformProfiles::getAll($addon);
    $profile = ('edit' === $func && isset($profiles[$key])) ? $profiles[$key] : [];

    $profileTable = (string) ($profile['table'] ?? '');
    $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
    $conditions = is_array($profile['conditions'] ?? null) ? $profile['conditions'] : [];

    $body = '<div id="ai-yform-mapping-root" data-profile-card="1" data-columns-map="' . rex_escape(json_encode($columnsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . '">';
    $body .= $message;
    // rex_url::currentBackendPage() escaped den "&"-Trenner zwischen mehreren Params
    // bereits selbst (3. Parameter $escape, Standard true) - kein zusaetzliches
    // rex_escape() davorsetzen, das wuerde bei mehr als einem Param zu "&amp;amp;"
    // doppelt escapen.
    $body .= '<form method="post" action="' . rex_url::currentBackendPage() . '">';
    $body .= $csrf->getHiddenField();
    $body .= '<input type="hidden" name="func" value="save">';
    $body .= '<input type="hidden" name="original_key" value="' . rex_escape($key) . '">';

    $body .= '<div class="row"><div class="col-md-6"><label>Mapping-ID</label><input class="form-control" type="text" name="profile[id]" value="' . rex_escape((string) ($profile['id'] ?? $key)) . '" placeholder="news"></div><div class="col-md-6"><label>Bezeichnung</label><input class="form-control" type="text" name="profile[label]" value="' . rex_escape((string) ($profile['label'] ?? '')) . '" placeholder="News"></div></div>';

    $body .= '<div class="row" style="margin-top:10px;"><div class="col-md-6"><label>Tabelle</label><select class="form-control js-table-select" name="profile[table]"><option value="">— Tabelle wählen —</option>';
    foreach ($availableTables as $tableName => $label) {
        $selected = $tableName === $profileTable ? ' selected' : '';
        $body .= '<option value="' . rex_escape($tableName) . '"' . $selected . '>' . rex_escape($label) . '</option>';
    }
    $body .= '</select></div>';
    $body .= '<div class="col-md-3"><label>Sortier-Richtung</label><select class="form-control" name="profile[sort_dir]">' . $renderOptions(['DESC' => 'Absteigend', 'ASC' => 'Aufsteigend'], (string) ($profile['sort_dir'] ?? 'DESC'), '— Richtung —', false) . '</select></div>';
    $body .= '<div class="col-md-3"><label>Sortier-Spalte</label>' . $renderColumnSelect('sort_field', (string) ($profile['sort_field'] ?? ''), $profileTable, $columnsMap, false, '— bitte wählen —') . '</div></div>';

    $body .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Titel-Spalte</label>' . $renderColumnSelect('title_field', (string) ($profile['title_field'] ?? ''), $profileTable, $columnsMap, false, '— bitte wählen —') . '</div>';
    $body .= '<div class="col-md-4"><label>Content-Spalte</label>' . $renderColumnSelect('content_field', (string) ($profile['content_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
    $body .= '<div class="col-md-4"><label>Content-Modus</label><select class="form-control" name="profile[content_field_mode]">' . $renderOptions([
        'auto' => 'Auto',
        'text' => 'Plain Text',
        'html' => 'HTML',
        'markdown' => 'Markdown',
        'textile' => 'Textile',
        'content_builder' => 'Content Builder JSON',
        'json' => 'JSON',
    ], (string) ($profile['content_field_mode'] ?? 'auto'), '— Typ —', false) . '</select></div></div>';

    $body .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Status-Spalte</label>' . $renderColumnSelect('status_field', (string) ($profile['status_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
    $body .= '<div class="col-md-4"><label>Statuswerte</label><input class="form-control" type="text" name="profile[status_values]" value="' . rex_escape((string) ($profile['status_values'] ?? '')) . '" placeholder="1,online,published"></div>';
    $body .= '<div class="col-md-4"><label>Datumsspalte</label>' . $renderColumnSelect('date_field', (string) ($profile['date_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div></div>';

    $body .= '<div class="row" style="margin-top:10px;"><div class="col-md-6"><label>Erstellt am</label>' . $renderColumnSelect('created_field', (string) ($profile['created_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
    $body .= '<div class="col-md-6"><label>Aktualisiert am</label>' . $renderColumnSelect('updated_field', (string) ($profile['updated_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div></div>';

    $body .= '<div class="row" style="margin-top:10px;"><div class="col-md-4"><label>Sprach-Spalte</label>' . $renderColumnSelect('clang_field', (string) ($profile['clang_field'] ?? ''), $profileTable, $columnsMap, true, '— optional, keine Sprachfilterung —') . '</div>';
    $body .= '<div class="col-md-8"><label>Sprachen (clang-IDs)</label><input class="form-control" type="text" name="profile[clang_ids]" value="' . rex_escape((string) ($profile['clang_ids'] ?? '')) . '" placeholder="1,2"><p class="help-block">Kommagetrennte clang-IDs. Nur wirksam, wenn eine Sprach-Spalte gewählt ist; leer = alle Sprachen (Standard, unverändertes Verhalten).</p></div></div>';

    $body .= '<div class="row" style="margin-top:10px;"><div class="col-md-3"><label>URL-Modus</label><select class="form-control js-url-mode-select" name="profile[url_mode]">' . $renderOptions([
        'field' => 'Aus Feldwert',
        'profile' => 'URL-Profil (Namespace)',
        'template' => 'Template',
    ], (string) ($profile['url_mode'] ?? 'field'), '— Modus —', false) . '</select></div>';
    $body .= '<div class="col-md-3" data-url-mode-field="field"><label>URL-Feld</label>' . $renderColumnSelect('url_field', (string) ($profile['url_field'] ?? ''), $profileTable, $columnsMap, true, '— optional —') . '</div>';
    $body .= '<div class="col-md-3" data-url-mode-field="profile"><label>URL-Profil</label>' . $renderUrlProfileField((string) ($profile['url_profile'] ?? '')) . '</div>';
    $body .= '<div class="col-md-3" data-url-mode-field="template"><label>URL-Template</label><input class="form-control" type="text" name="profile[url_template]" value="' . rex_escape((string) ($profile['url_template'] ?? '')) . '" placeholder="/news/{id}-{slug}"></div></div>';

    $body .= '<hr><h4>Zusätzliche Felder</h4><p class="help-block">Diese Felder werden in den Suchtext aufgenommen. Feldtyp und Anzeige können pro Zeile überschrieben werden.</p>';
    $body .= '<div class="klxm-repeater" data-repeater-name="fields">';
    $body .= '<div class="klxm-repeater-items">';
    if ($fields === []) {
        $body .= $renderFieldRow(0, [], $profileTable, $columnsMap, 'field');
    } else {
        foreach ($fields as $idx => $fieldRow) {
            $body .= $renderFieldRow((int) $idx, is_array($fieldRow) ? $fieldRow : [], $profileTable, $columnsMap, 'field');
        }
    }
    $body .= '</div><button type="button" class="btn btn-default btn-sm klxm-repeater-add" data-add-repeater-row="1"><i class="rex-icon fa-plus"></i> Feld hinzufügen</button>';
    $body .= '<template class="klxm-repeater-template">' . $renderFieldRow('__ROW__', [], $profileTable, $columnsMap, 'field') . '</template>';
    $body .= '</div>';

    $body .= '<hr><h4>Bedingungen</h4><p class="help-block">Beispiel: Feld <code>news_date</code> mit Operator <code>vor jetzt</code> verhindert, dass zukünftige News indexiert werden.</p>';
    $body .= '<div class="klxm-repeater" data-repeater-name="conditions">';
    $body .= '<div class="klxm-repeater-items">';
    if ($conditions === []) {
        $body .= $renderFieldRow(0, [], $profileTable, $columnsMap, 'condition');
    } else {
        foreach ($conditions as $idx => $conditionRow) {
            $body .= $renderFieldRow((int) $idx, is_array($conditionRow) ? $conditionRow : [], $profileTable, $columnsMap, 'condition');
        }
    }
    $body .= '</div><button type="button" class="btn btn-default btn-sm klxm-repeater-add" data-add-repeater-row="1"><i class="rex-icon fa-plus"></i> Bedingung hinzufügen</button>';
    $body .= '<template class="klxm-repeater-template">' . $renderFieldRow('__ROW__', [], $profileTable, $columnsMap, 'condition') . '</template>';
    $body .= '</div>';

    $body .= '<p style="margin-top:20px;"><button type="submit" class="btn btn-primary"><i class="rex-icon fa-save"></i> Mapping speichern</button> ';
    $body .= '<a class="btn btn-default" href="' . rex_url::currentBackendPage() . '">Abbrechen</a></p>';
    $body .= '</form></div>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', 'edit' === $func ? 'Mapping bearbeiten' : 'Neues Mapping erstellen');
    $fragment->setVar('body', $body, false);
    echo $fragment->parse('core/page/section.php');
} else {
    if ('' !== $message) {
        echo $message;
    }

    echo rex_view::info('Hier pflegst du die expliziten YForm-Mappings. Nur die hier angelegten Mappings werden indexiert. Für News kannst du z.B. eine Bedingung <code>news_date vor jetzt</code> setzen, damit keine zukünftigen Einträge in die Suche kommen.');

    $profiles = YformProfiles::getAll($addon);

    $urlModeLabels = ['field' => 'Aus Feldwert', 'profile' => 'URL-Profil', 'template' => 'Template'];

    $sql = rex_sql::factory();
    $selects = [];
    foreach ($profiles as $profileKey => $profile) {
        $fieldCount = is_array($profile['fields'] ?? null) ? count($profile['fields']) : 0;
        $selects[] = 'SELECT '
            . $sql->escape($profileKey) . ' AS id, '
            . $sql->escape((string) ($profile['label'] ?: $profileKey)) . ' AS label, '
            . $sql->escape((string) ($profile['table'] ?? '')) . ' AS tbl, '
            . $sql->escape((string) ($profile['url_mode'] ?? 'field')) . ' AS url_mode, '
            . (int) $fieldCount . ' AS field_count';
    }
    $query = [] !== $selects
        ? implode(' UNION ALL ', $selects)
        : "SELECT '' AS id, '' AS label, '' AS tbl, '' AS url_mode, 0 AS field_count WHERE 1=0";

    $list = rex_list::factory($query);
    $list->addTableAttribute('class', 'table-striped');

    $thIcon = '<a href="' . $list->getUrl(['func' => 'add']) . '" title="Hinzufügen"><i class="rex-icon rex-icon-add-module"></i></a>';
    $tdIcon = '<i class="rex-icon rex-icon-edit"></i>';
    $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
    $list->setColumnParams($thIcon, ['func' => 'edit', 'key' => '###id###']);

    $list->setColumnLabel('label', 'Bezeichnung');
    $list->setColumnLabel('tbl', 'Tabelle');
    $list->setColumnLabel('url_mode', 'URL-Modus');
    $list->setColumnLabel('field_count', 'Zusätzl. Felder');
    $list->setColumnFormat('url_mode', 'custom', static function (array $params) use ($urlModeLabels): string {
        $value = (string) $params['list']->getValue('url_mode');

        return rex_escape($urlModeLabels[$value] ?? $value);
    });

    $list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> Löschen', -1, ['', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnParams('delete', ['func' => 'delete', 'key' => '###id###']);
    $list->addLinkAttribute('delete', 'data-confirm', 'Mapping wirklich löschen? Bereits indexierte Inhalte dieser Quelle bleiben im Index stehen, werden aber von keinem Profil mehr genutzt.');

    $content = $list->get();

    $fragment = new rex_fragment();
    $fragment->setVar('title', 'YForm-Mappings', false);
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
}
