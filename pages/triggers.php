<?php

$func = rex_request('func', 'string');
$id = rex_request('id', 'int');

if ($func == 'delete') {
    $sql = rex_sql::factory();
    $sql->setTable(rex::getTable('ai_chat_triggers'));
    $sql->setWhere(['id' => $id]);
    $sql->delete();
    echo rex_view::success('Eintrag gelöscht.');
    $func = '';
}

if ($func == 'add' || $func == 'edit') {
    $form = rex_form::factory(rex::getTable('ai_chat_triggers'), 'Trigger', 'id=' . $id);
    $form->addParam('id', $id);

    $form->addRawField(rex_view::info(
        'Trigger gelten immer <strong>global</strong>, unabhängig vom Profil und egal ob Chat, Suche oder '
        . 'Backend-Chat (Developer) - anders als z.B. die Profile unter AI Chat → Profile, die nur fürs '
        . 'Frontend gelten und je nach Domain/Sprache unterschiedlich sein können.'
    ));

    $field = $form->addTextField('keyword');
    $field->setLabel('Keyword');
    $field->setNotice('Das Wort oder die Phrase, auf die reagiert werden soll (case-insensitive). Sobald die Anfrage des Besuchers dieses Wort enthält, wird die Zusatz-Antwort unten an die KI-Antwort angehängt.');

    $field = $form->addTextAreaField('content');
    $field->setLabel('Zusatz-Antwort');
    $field->setNotice('Dieser Text wird unverändert (nicht durch die KI umformuliert) an die KI-Antwort angehängt - z.B. für Öffnungszeiten oder Kontaktdaten, die exakt so und nicht paraphrasiert erscheinen sollen. Markdown ist erlaubt (z.B. Tabellen, Überschriften, **fett**).');

    if ($func == 'add') {
        $form->addHiddenField('created_at', date('Y-m-d H:i:s'));
    }
    $form->addHiddenField('updated_at', date('Y-m-d H:i:s'));

    $content = $form->get();
    
    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', ($func == 'edit') ? 'Trigger bearbeiten' : 'Neuen Trigger erstellen');
    $fragment->setVar('body', $content, false);
    echo $fragment->parse('core/page/section.php');

} else {
    $list = rex_list::factory('SELECT id, keyword, content FROM ' . rex::getTable('ai_chat_triggers') . ' ORDER BY keyword ASC');
    $list->addTableAttribute('class', 'table-striped');
    
    $tdIcon = '<i class="rex-icon rex-icon-edit"></i>';
    $thIcon = '<a href="' . $list->getUrl(['func' => 'add']) . '" title="Hinzufügen"><i class="rex-icon rex-icon-add-module"></i></a>';
    
    $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
    $list->setColumnParams($thIcon, ['func' => 'edit', 'id' => '###id###']);
    
    $list->setColumnLabel('keyword', 'Keyword');
    $list->setColumnLabel('content', 'Antwort-Zusatz');
    
    $list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> Löschen', -1, ['', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###']);
    $list->addLinkAttribute('delete', 'data-confirm', 'Wirklich löschen?');

    $content = $list->get();

    $fragment = new rex_fragment();
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
}
