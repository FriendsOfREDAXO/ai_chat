<?php

use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;
use FriendsOfRedaxo\AiChat\Service\AiServiceFactory;

$table = rex::getTable('ai_chat_cache');
$csrf = rex_csrf_token::factory('ai_chat_cache_entries');

$profiles = (new ProfileRepository())->getAll();
$profileNames = [];
foreach ($profiles as $cacheProfile) {
    $profileNames[$cacheProfile->id] = $cacheProfile->name;
}

$func = rex_request('func', 'string', '');
$id = rex_request('id', 'int', 0);
$profileFilter = rex_request('profile_id', 'int', 0);
$searchTerm = trim(rex_request('q', 'string', ''));
$notice = rex_request('cache_notice', 'string', '');

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

$redirectParams = ['profile_id' => $profileFilter];
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

    $editData = [
        'id' => $id,
        'question' => $question,
        'answer' => $answer,
        'created_at' => rex_post('created_at', 'string', ''),
    ];

    if ($question === '' || $answer === '') {
        $message = rex_view::error('Frage und Antwort dürfen nicht leer sein.');
        $func = 'edit';
    } else {
        try {
            $embedding = AiServiceFactory::create()->getEmbedding($question);

            $sql = rex_sql::factory();
            $sql->setTable($table);
            $sql->setValue('question', $question);
            $sql->setValue('answer', $answer);
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
        'SELECT id, question, answer, profile_id, created_at FROM ' . $table . ' WHERE id = :id LIMIT 1',
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
    $editProfileId = (int) ($editData['profile_id'] ?? 0);
    $content = '';
    $content .= '<form action="' . rex_url::currentBackendPage() . '" method="post">';
    $content .= $csrf->getHiddenField();
    $content .= '<input type="hidden" name="func" value="save">';
    $content .= '<input type="hidden" name="id" value="' . (int) ($editData['id'] ?? 0) . '">';
    $content .= '<input type="hidden" name="profile_id" value="' . $profileFilter . '">';
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
    $content .= '<p class="text-muted">Profil: ' . rex_escape($profileNames[$editProfileId] ?? ('#' . $editProfileId)) . ' - Angelegt: ' . rex_escape((string) ($editData['created_at'] ?? '')) . '</p>';
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
$totalCount = (int) $summarySql->getValue('SELECT COUNT(*) FROM ' . $table);

$filterForm = '';
$filterForm .= '<form class="form-inline" method="get" action="' . rex_url::currentBackendPage() . '" style="margin-bottom:15px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">';
$filterForm .= '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">';
$filterForm .= '<div class="form-group"><label for="klxm-cache-profile-filter" style="display:block;">Profil</label><select id="klxm-cache-profile-filter" class="form-control" name="profile_id">';
$filterForm .= '<option value="0"' . (0 === $profileFilter ? ' selected' : '') . '>Alle Profile</option>';
foreach ($profiles as $filterProfile) {
    $selected = $profileFilter === $filterProfile->id ? ' selected' : '';
    $filterForm .= '<option value="' . $filterProfile->id . '"' . $selected . '>' . rex_escape($filterProfile->name) . '</option>';
}
$filterForm .= '</select></div>';
$filterForm .= '<div class="form-group"><label for="klxm-cache-search" style="display:block;">Suche</label><input id="klxm-cache-search" class="form-control" type="text" name="q" value="' . rex_escape($searchTerm) . '" placeholder="Frage oder Antwort durchsuchen"></div>';
$filterForm .= '<div class="form-group"><button class="btn btn-primary" type="submit">Filtern</button> <a class="btn btn-default" href="' . rex_url::currentBackendPage() . '">Zurücksetzen</a></div>';
$filterForm .= '</form>';

$summary = '<div class="alert alert-info" style="margin-bottom:15px;">'
    . '<strong>Cache-Überblick:</strong> '
    . $totalCount . ' Einträge insgesamt.'
    . '</div>';

$where = [];
$listSql = rex_sql::factory();
if ($profileFilter > 0) {
    $where[] = 'profile_id = ' . $profileFilter;
}
if ($searchTerm !== '') {
    $likeNeedle = '%' . $searchTerm . '%';
    $where[] = '(question LIKE ' . $listSql->escape($likeNeedle) . ' OR answer LIKE ' . $listSql->escape($likeNeedle) . ')';
}

$query = 'SELECT id, profile_id, '
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
$list->setColumnParams('edit', ['func' => 'edit', 'id' => '###id###', 'profile_id' => $profileFilter, 'q' => $searchTerm]);

$list->setColumnLabel('profile_id', 'Profil');
$list->setColumnFormat('profile_id', 'custom', static function (array $params) use ($profileNames): string {
    $profileId = (int) $params['list']->getValue('profile_id');

    return rex_escape($profileNames[$profileId] ?? ('#' . $profileId));
});
$list->setColumnLabel('question_preview', 'Frage');
$list->setColumnLabel('answer_preview', 'Antwort');
$list->setColumnLabel('created_at', 'Erstellt');

$list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> Löschen', -1, ['', '<td class="rex-table-action">###VALUE###</td>']);
$list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###', 'profile_id' => $profileFilter, 'q' => $searchTerm] + $csrf->getUrlParams());
$list->addLinkAttribute('delete', 'data-confirm', 'Diesen Cache-Eintrag wirklich löschen?');

$listContent = $summary . $filterForm . $list->get();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Cache-Fragen');
$fragment->setVar('content', $listContent, false);
echo $fragment->parse('core/page/section.php');
