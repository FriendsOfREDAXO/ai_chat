<?php

use FriendsOfRedaxo\AiChat\Profile\ProfileRepository;

$addon = rex_addon::get('ai_chat');
$table = rex::getTable('ai_chat_retrieval_log');
$resetToken = rex_csrf_token::factory('ai_chat_retrieval_log_reset');

$profiles = (new ProfileRepository())->getAll();
$profileNames = [];
foreach ($profiles as $logProfile) {
    $profileNames[$logProfile->id] = $logProfile->name;
}

$message = '';

if (rex_request('reset_log', 'string', '') !== '') {
    if (!$resetToken->isValid()) {
        $message = rex_view::error('Die Sicherheitsprüfung für das Zurücksetzen des Logs ist fehlgeschlagen. Bitte erneut versuchen.');
    } else {
        $sql = rex_sql::factory();
        $sql->setQuery('TRUNCATE TABLE ' . $table);
        $message = rex_view::success('Das Retrieval-Log wurde geleert.');
    }
}

if (!(bool) $addon->getConfig('retrieval_debug_log_enabled', false)) {
    $message .= rex_view::info(
        'Das Retrieval-Debug-Log ist aktuell deaktiviert - hier erscheinen keine neuen Einträge. '
        . 'Einschalten unter <a href="' . rex_url::backendPage('ai_chat/settings/systemcheck') . '">Einstellungen → Check &amp; Debug → Debugging</a>.',
    );
}


$func = rex_request('func', 'string', '');
$id = rex_request('id', 'int', 0);
$profileFilter = rex_request('profile_id', 'int', 0);

echo $message;

if ($func === 'view' && $id > 0) {
    $sql = rex_sql::factory();
    $rows = $sql->getArray(
        'SELECT id, scope, query, context_count, sufficient_context, rerank_enabled, context_json, profile_id, created_at
         FROM ' . $table . ' WHERE id = :id LIMIT 1',
        ['id' => $id],
    );

    if ($rows === []) {
        echo rex_view::error('Der Log-Eintrag wurde nicht gefunden.');
        return;
    }

    $row = $rows[0];
    $entries = json_decode((string) ($row['context_json'] ?? ''), true);
    $entries = is_array($entries) ? $entries : [];

    $content = '<dl class="dl-horizontal">';
    $content .= '<dt>Anfrage</dt><dd>' . rex_escape((string) $row['query']) . '</dd>';
    $content .= '<dt>Profil</dt><dd>' . rex_escape($profileNames[(int) $row['profile_id']] ?? ('#' . (int) $row['profile_id'])) . '</dd>';
    $content .= '<dt>Scope</dt><dd>' . rex_escape((string) $row['scope']) . '</dd>';
    $content .= '<dt>Kontext-Chunks</dt><dd>' . (int) $row['context_count'] . '</dd>';
    $content .= '<dt>Kontext ausreichend</dt><dd>' . ((int) $row['sufficient_context'] === 1 ? 'Ja' : 'Nein - allgemeine Ausweich-Antwort') . '</dd>';
    $content .= '<dt>Re-Ranking aktiv</dt><dd>' . ((int) $row['rerank_enabled'] === 1 ? 'Ja' : 'Nein') . '</dd>';
    $content .= '<dt>Zeitpunkt</dt><dd>' . rex_escape((string) $row['created_at']) . '</dd>';
    $content .= '</dl>';

    if ($entries === []) {
        $content .= '<p class="text-muted">Kein Kontext gefunden - die Anfrage landete direkt in der allgemeinen Ausweich-Antwort.</p>';
    } else {
        $content .= '<table class="table table-striped table-hover">';
        $content .= '<thead><tr><th>#</th><th>Similarity</th><th>Typ</th><th>Bereich</th><th>Titel / URL</th><th>Ausschnitt</th></tr></thead><tbody>';
        foreach ($entries as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entryUrl = (string) ($entry['url'] ?? '');
            $titleCell = '<strong>' . rex_escape((string) ($entry['title'] ?? '')) . '</strong>';
            if ($entryUrl !== '') {
                $titleCell .= '<br><span class="text-muted">' . rex_escape($entryUrl) . '</span>';
            }
            $content .= '<tr>';
            $content .= '<td>' . ((int) $index + 1) . '</td>';
            $content .= '<td>' . rex_escape((string) ($entry['similarity'] ?? '')) . '</td>';
            $content .= '<td>' . rex_escape((string) ($entry['source_type'] ?? '')) . '</td>';
            $content .= '<td>' . rex_escape((string) ($entry['source_label'] ?? '')) . '</td>';
            $content .= '<td>' . $titleCell . '</td>';
            $content .= '<td>' . rex_escape((string) ($entry['snippet'] ?? '')) . ' …</td>';
            $content .= '</tr>';
        }
        $content .= '</tbody></table>';
    }

    $content .= '<p><a class="btn btn-default" href="' . rex_url::currentBackendPage(['profile_id' => $profileFilter]) . '">Zurück zur Liste</a></p>';

    $fragment = new rex_fragment();
    $fragment->setVar('title', 'Retrieval-Log: Details');
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
    return;
}

$summarySql = rex_sql::factory();
$summarySql->setQuery('SELECT COUNT(*) AS total FROM ' . $table);
$totalCount = (int) $summarySql->getValue('total');

$filterForm = '<form class="form-inline" method="get" action="' . rex_url::currentBackendPage() . '" style="display:inline-block; margin-bottom:15px; margin-right:15px; vertical-align:top;">';
$filterForm .= '<input type="hidden" name="page" value="' . rex_escape(rex_be_controller::getCurrentPage()) . '">';
$filterForm .= '<div class="form-group" style="margin-right:15px;"><label for="klxm-retrieval-log-profile-filter" style="margin-right:6px;">Profil</label><select id="klxm-retrieval-log-profile-filter" class="form-control selectpicker" data-width="auto" data-style="btn-default" name="profile_id">';
$filterForm .= '<option value="0"' . (0 === $profileFilter ? ' selected' : '') . '>Alle Profile</option>';
foreach ($profiles as $filterProfile) {
    $selected = $profileFilter === $filterProfile->id ? ' selected' : '';
    $filterForm .= '<option value="' . $filterProfile->id . '"' . $selected . '>' . rex_escape($filterProfile->name) . '</option>';
}
$filterForm .= '</select></div>';
$filterForm .= '<div class="btn-group" role="group"><button class="btn btn-primary" type="submit">Filtern</button> <a class="btn btn-default" href="' . rex_url::currentBackendPage() . '">Zurücksetzen</a></div>';
$filterForm .= '</form>';

$resetForm = '<form method="post" action="' . rex_url::currentBackendPage() . '" style="display:inline-block; margin-bottom:15px; vertical-align:top;" onsubmit="return confirm(\'Das komplette Retrieval-Log wirklich leeren?\');">';
$resetForm .= $resetToken->getHiddenField();
$resetForm .= '<input type="hidden" name="reset_log" value="1">';
$resetForm .= '<button type="submit" class="btn btn-default"><i class="rex-icon rex-icon-delete"></i> Log leeren</button>';
$resetForm .= '</form>';

$summary = rex_view::info('<strong>Überblick:</strong> ' . $totalCount . ' protokollierte Anfragen (automatisch nach 7 Tagen bereinigt).');

$where = [];
if ($profileFilter > 0) {
    $where[] = 'profile_id = ' . $profileFilter;
}

$query = 'SELECT id, scope, LEFT(query, 140) AS query_preview, context_count, sufficient_context, rerank_enabled, profile_id, created_at
    FROM ' . $table;
if ($where !== []) {
    $query .= ' WHERE ' . implode(' AND ', $where);
}
$query .= ' ORDER BY created_at DESC, id DESC';

$list = rex_list::factory($query);
$list->addTableAttribute('class', 'table-striped');

$viewColumn = '<i class="rex-icon rex-icon-view"></i>';
$list->addColumn('view', $viewColumn, 0, ['<th class="rex-table-icon"></th>', '<td class="rex-table-icon">###VALUE###</td>']);
$list->setColumnParams('view', ['func' => 'view', 'id' => '###id###', 'profile_id' => $profileFilter]);

$list->setColumnLabel('created_at', 'Zeitpunkt');
$list->setColumnLabel('scope', 'Scope');
$list->setColumnLabel('profile_id', 'Profil');
$list->setColumnFormat('profile_id', 'custom', static function (array $params) use ($profileNames): string {
    $profileId = (int) $params['list']->getValue('profile_id');

    return rex_escape($profileNames[$profileId] ?? ('#' . $profileId));
});
$list->setColumnLabel('query_preview', 'Anfrage');
$list->setColumnLabel('context_count', 'Chunks');
$list->setColumnLabel('sufficient_context', 'Ausreichend');
$list->setColumnFormat('sufficient_context', 'custom', static function (array $params): string {
    return (int) $params['list']->getValue('sufficient_context') === 1
        ? '<span class="text-success">Ja</span>'
        : '<span class="text-danger">Nein</span>';
});
$list->setColumnLabel('rerank_enabled', 'Re-Ranking');
$list->setColumnFormat('rerank_enabled', 'custom', static function (array $params): string {
    return (int) $params['list']->getValue('rerank_enabled') === 1 ? 'Ja' : 'Nein';
});

echo $summary;
echo $filterForm;
echo $resetForm;

$listContent = $list->get();

$fragment = new rex_fragment();
$fragment->setVar('title', 'Retrieval-Log');
$fragment->setVar('content', $listContent, false);
echo $fragment->parse('core/page/section.php');
