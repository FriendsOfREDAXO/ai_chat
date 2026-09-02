<?php

use FriendsOfRedaxo\AiChat\Service\AiServiceFactory;

$table = rex::getTable('ai_chat_cache');
$csrf = rex_csrf_token::factory('ai_chat_cache_entries');

$allowedScopes = ['all', 'frontend', 'developer'];
$editableScopes = ['frontend', 'developer'];

$func = rex_request('func', 'string', '');
$id = rex_request('id', 'int', 0);
$scopeFilter = rex_request('scope', 'string', 'all');
$searchTerm = trim(rex_request('q', 'string', ''));
$notice = rex_request('cache_notice', 'string', '');

if (!in_array($scopeFilter, $allowedScopes, true)) {
    $scopeFilter = 'all';
}

$message = '';
if ($notice === 'saved') {
    $message = rex_view::success('Cache-Eintrag gespeichert.');
} elseif ($notice === 'deleted') {
    $message = rex_view::success('Cache-Eintrag gelöscht.');
}

if (in_array($func, ['save', 'delete'], true) && !$csrf->isValid()) {
    $message = rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    $func = '';
}

$redirectParams = ['scope' => $scopeFilter];
if ($searchTerm !== '') {
    $redirectParams['q'] = $searchTerm;
}

if ($func === 'delete' && $id > 0) {
    $sql = rex_sql::factory();
    $existing = $sql->getArray('SELECT id FROM ' . $table . ' WHERE id = :id LIMIT 1', ['id' => $id]);
    if ($existing === []) {
        $message = rex_view::error('Der Cache-Eintrag wurde nicht gefunden.');
        $func = '';
    } else {
        $sql->setTable($table);
        $sql->setWhere(['id' => $id]);
        $sql->delete();
        rex_response::sendRedirect(rex_url::currentBackendPage($redirectParams + ['cache_notice' => 'deleted']));
    }
}

$editData = null;

if ($func === 'save' && $id > 0) {
    $question = trim(rex_post('question', 'string', ''));
    $answer = trim(rex_post('answer', 'string', ''));
    $scopeValue = rex_post('scope_value', 'string', 'frontend');

    $editData = [
        'id' => $id,
        'question' => $question,
        'answer' => $answer,
        'scope' => $scopeValue,
        'created_at' => rex_post('created_at', 'string', ''),
    ];

    if ($question === '' || $answer === '') {
        $message = rex_view::error('Frage und Antwort dürfen nicht leer sein.');
        $func = 'edit';
    } elseif (!in_array($scopeValue, $editableScopes, true)) {
        $message = rex_view::error('Ungültiger Scope.');
        $func = 'edit';
    } else {
        try {
            $embedding = AiServiceFactory::create()->getEmbedding($question);

            $sql = rex_sql::factory();
            $sql->setTable($table);
            $sql->setValue('question', $question);
            $sql->setValue('answer', $answer);
            $sql->setValue('scope', $scopeValue);
            $sql->setValue('embedding', json_encode($embedding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $sql->setWhere(['id' => $id]);
            $sql->update();

            rex_response::sendRedirect(rex_url::currentBackendPage($redirectParams + ['cache_notice' => 'saved']));
        } catch (Throwable $e) {
            $message = rex_view::error('Der Cache-Eintrag konnte nicht gespeichert werden: ' . rex_escape($e->getMessage()));
            $func = 'edit';
        }
    }
}

if ($func === 'edit' && $id > 0 && !is_array($editData)) {
    $sql = rex_sql::factory();
    $rows = $sql->getArray(
        'SELECT id, question, answer, scope, created_at FROM ' . $table . ' WHERE id = :id LIMIT 1',
        ['id' => $id],
    );

    if ($rows === []) {
        $message = rex_view::error('Der Cache-Eintrag wurde nicht gefunden.');
        $func = '';
    } else {
        $editData = $rows[0];
    }
}

echo $message;

if ($func === 'edit' && is_array($editData)) {
    $cancelUrl = rex_url::currentBackendPage($redirectParams);
    $content = '';
    $content .= '<form action="' . rex_url::currentBackendPage() . '" method="post">';
    $content .= $csrf->getHiddenField();
    $content .= '<input type="hidden" name="func" value="save">';
    $content .= '<input type="hidden" name="id" value="' . (int) ($editData['id'] ?? 0) . '">';
    $content .= '<input type="hidden" name="scope" value="' . rex_escape($scopeFilter) . '">';
    $content .= '<input type="hidden" name="q" value="' . rex_escape($searchTerm) . '">';
    $content .= '<input type="hidden" name="created_at" value="' . rex_escape((string) ($editData['created_at'] ?? '')) . '">';
    $content .= '<div class="form-group">';
    $content .= '<label for="klxm-cache-question">Frage</label>';
    $content .= '<textarea id="klxm-cache-question" class="form-control" name="question" rows="4" required>' . rex_escape((string) ($editData['question'] ?? '')) . '</textarea>';
    $content .= '<p class="help-block">Wenn die Frage geändert wird, wird das Embedding beim Speichern automatisch neu berechnet.</p>';
    $content .= '</div>';
    $content .= '<div class="form-group">';
    $content .= '<label for="klxm-cache-answer">Antwort</label>';
    $content .= '<textarea id="klxm-cache-answer" class="form-control" name="answer" rows="12" required>' . rex_escape((string) ($editData['answer'] ?? '')) . '</textarea>';
    $content .= '</div>';
    $content .= '<div class="form-group">';
    $content .= '<label for="klxm-cache-scope">Scope</label>';
    $content .= '<select id="klxm-cache-scope" class="form-control" name="scope_value">';
    foreach ($editableScopes as $scopeOption) {
        $selected = ((string) ($editData['scope'] ?? '') === $scopeOption) ? ' selected' : '';
        $label = $scopeOption === 'frontend' ? 'Frontend' : 'Developer';
        $content .= '<option value="' . rex_escape($scopeOption) . '"' . $selected . '>' . rex_escape($label) . '</option>';
    }
    $content .= '</select>';
    $content .= '</div>';
    $content .= '<p class="text-muted">Angelegt: ' . rex_escape((string) ($editData['created_at'] ?? '')) . '</p>';
    $content .= '<p>';
    $content .= '<button type="submit" class="btn btn-save">Speichern</button> ';
    $content .= '<a class="btn btn-default" href="' . $cancelUrl . '">Zurück zur Liste</a>';
    $content .= '</p>';
    $content .= '</form>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', 'Cache-Eintrag bearbeiten', false);
    $fragment->setVar('body', $content, false);
    echo $fragment->parse('core/page/section.php');
    return;
}

$summarySql = rex_sql::factory();
$summaryRows = $summarySql->getArray(
    'SELECT scope, COUNT(*) AS total FROM ' . $table . ' GROUP BY scope ORDER BY scope ASC'
);

$counts = [
    'frontend' => 0,
    'developer' => 0,
];
foreach ($summaryRows as $row) {
    $scope = (string) ($row['scope'] ?? '');
    if (isset($counts[$scope])) {
        $counts[$scope] = (int) ($row['total'] ?? 0);
    }
}

$filterForm = '';
$filterForm .= '<form class="form-inline" method="get" action="' . rex_url::currentBackendPage() . '" style="margin-bottom:15px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">';
$filterForm .= '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">';
$filterForm .= '<div class="form-group"><label for="klxm-cache-scope-filter" style="display:block;">Scope</label><select id="klxm-cache-scope-filter" class="form-control" name="scope">';
$scopeOptions = [
    'all' => 'Alle',
    'frontend' => 'Frontend',
    'developer' => 'Developer',
];
foreach ($scopeOptions as $scopeValue => $scopeLabel) {
    $selected = $scopeFilter === $scopeValue ? ' selected' : '';
    $filterForm .= '<option value="' . rex_escape($scopeValue) . '"' . $selected . '>' . rex_escape($scopeLabel) . '</option>';
}
$filterForm .= '</select></div>';
$filterForm .= '<div class="form-group"><label for="klxm-cache-search" style="display:block;">Suche</label><input id="klxm-cache-search" class="form-control" type="text" name="q" value="' . rex_escape($searchTerm) . '" placeholder="Frage oder Antwort durchsuchen"></div>';
$filterForm .= '<div class="form-group"><button class="btn btn-primary" type="submit">Filtern</button> <a class="btn btn-default" href="' . rex_url::currentBackendPage() . '">Zurücksetzen</a></div>';
$filterForm .= '</form>';

$summary = '<div class="alert alert-info" style="margin-bottom:15px;">'
    . '<strong>Cache-Überblick:</strong> '
    . 'Frontend: ' . $counts['frontend'] . ' Einträge, '
    . 'Developer: ' . $counts['developer'] . ' Einträge.'
    . '</div>';

$where = [];
$listSql = rex_sql::factory();
if ($scopeFilter !== 'all') {
    // rex_sql::escape() nutzt PDO::quote() und liefert den Wert bereits INKLUSIVE
    // umschließender Anführungszeichen zurück - hier daher keine zusätzlichen '...' setzen.
    $where[] = 'scope = ' . $listSql->escape($scopeFilter);
}
if ($searchTerm !== '') {
    $likeNeedle = '%' . $searchTerm . '%';
    $where[] = '(question LIKE ' . $listSql->escape($likeNeedle) . ' OR answer LIKE ' . $listSql->escape($likeNeedle) . ')';
}

$query = 'SELECT id, scope, '
    . 'LEFT(REPLACE(REPLACE(question, CHAR(13), " "), CHAR(10), " "), 140) AS question_preview, '
    . 'LEFT(REPLACE(REPLACE(answer, CHAR(13), " "), CHAR(10), " "), 180) AS answer_preview, '
    . 'created_at '
    . 'FROM ' . $table;
if ($where !== []) {
    $query .= ' WHERE ' . implode(' AND ', $where);
}
$query .= ' ORDER BY created_at DESC, id DESC';

$list = rex_list::factory($query);
$list->addTableAttribute('class', 'table-striped table-hover');

$editColumn = '<i class="rex-icon rex-icon-edit"></i>';
$list->addColumn('edit', $editColumn, 0, ['<th class="rex-table-icon"></th>', '<td class="rex-table-icon">###VALUE###</td>']);
$list->setColumnParams('edit', ['func' => 'edit', 'id' => '###id###', 'scope' => $scopeFilter, 'q' => $searchTerm]);

$list->setColumnLabel('scope', 'Scope');
$list->setColumnLabel('question_preview', 'Frage');
$list->setColumnLabel('answer_preview', 'Antwort');
$list->setColumnLabel('created_at', 'Erstellt');

$list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> Löschen', -1, ['', '<td class="rex-table-action">###VALUE###</td>']);
$list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###', 'scope' => $scopeFilter, 'q' => $searchTerm] + $csrf->getUrlParams());
$list->addLinkAttribute('delete', 'data-confirm', 'Diesen Cache-Eintrag wirklich löschen?');

$listContent = $summary . $filterForm . $list->get();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Cache-Fragen');
$fragment->setVar('content', $listContent, false);
echo $fragment->parse('core/page/section.php');