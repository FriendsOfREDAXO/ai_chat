<?php

require __DIR__ . '/settings.shared.php';

/**
 * Von settings.shared.php per require in diesen Scope injiziert - PHPStan kann require-
 * Variablenfluss nicht ueber Dateigrenzen hinweg verfolgen, daher hier explizit annotiert.
 *
 * @var rex_addon_interface $addon
 * @var Closure(string): string $rawMsg
 * @var Closure(string, string): string $tooltipLabel
 * @var Closure(mixed): bool $isConfigUnset
 * @var Closure(rex_form_base, string, string, string, bool): rex_form_select_element $addBoolSelectField
 * @var Closure(string, string, string, string=): string $renderInfoPanel
 * @var Closure(string): string $renderTipsList
 * @var Closure(rex_addon_interface, string): string $renderTipsPanel
 * @var string $tooltipInitScript
 * @var Closure(rex_config_form, string): void $renderSettingsPage
 */

$form = rex_config_form::factory('ai_chat');

$form->addRawField('<p class="help-block">' . $rawMsg('config_section_retrieval_hint') . '</p>');

// Chunking & Embedding-Kontext: gilt fuer JEDE indexierte Quelle gleichermassen (Struktur,
// Sitemap, Provider, profil-eigene Quellen) - deshalb hier als eigener, uebergreifender
// Reiter statt Teil der quellenspezifischen Indexierungs-Einstellungen.
$form->addRawField('<div id="klxm-retrieval-chunking-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">' . $addon->i18n('config_index_embedding_section_title') . '</p>');
$form->addRawField('<p class="help-block" style="margin: 0 0 15px;">' . $addon->i18n('config_index_embedding_section_notice') . '</p>');

$field = $form->addSelectField('chunk_size');
$field->setLabel($tooltipLabel($addon->i18n('config_chunk_size'), 'config_chunk_size_notice'));
$field->setNotice($addon->i18n('config_chunk_size_notice'));
$select = $field->getSelect();
$select->addOption('600 Zeichen – sehr fokussiert', 600);
$select->addOption('1000 Zeichen – Standard (empfohlen)', 1000);
$select->addOption('1500 Zeichen – mehr Zusammenhang', 1500);
$select->addOption('2000 Zeichen – lange, zusammenhängende Abschnitte', 2000);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(1000);
}

$field = $form->addSelectField('chunk_overlap');
$field->setLabel($tooltipLabel($addon->i18n('config_chunk_overlap'), 'config_chunk_overlap_notice'));
$field->setNotice($addon->i18n('config_chunk_overlap_notice'));
$select = $field->getSelect();
$select->addOption('0 – kein Overlap', 0);
$select->addOption('100 Zeichen', 100);
$select->addOption('200 Zeichen – Standard (empfohlen)', 200);
$select->addOption('300 Zeichen – viel Überlappung', 300);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(200);
}

$field = $form->addTextAreaField('embedding_context_hint');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_context_hint'), 'config_embedding_context_hint_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_context_hint_notice')));
$field->setAttribute('rows', '3');
$field->setAttribute('placeholder', "Dies ist die Website eines Vereins/Verbands. Kontakt-, Ansprechpartner- und Terminangaben sind für Besucher besonders wichtig und sollten bevorzugt gefunden werden.");

$field = $form->addTextAreaField('embedding_context_hint_sources');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_context_hint_sources'), 'config_embedding_context_hint_sources_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_context_hint_sources_notice')));
$field->setAttribute('rows', '5');
$field->setAttribute('placeholder', "article=Dies ist redaktioneller Inhalt der eigenen Website.\nsitemap_url=Diese Seite wurde über die externe Sitemap eingebunden.");

$field = $form->addTextAreaField('embedding_focus_rules');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_focus_rules'), 'config_embedding_focus_rules_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_focus_rules_notice')));
$field->setAttribute('rows', '6');
if ($isConfigUnset($field->getValue())) {
    $field->setValue("Kontakt-/Ansprechpartnerinformationen|kontakt|ansprechpartner|telefon|email|e-mail|adresse\nÖffnungs-/Sprechzeiten|öffnungszeiten|sprechzeiten|geöffnet\nTermine & Veranstaltungen|termin|veranstaltung|kalender|event\nMitgliedschaft & Beitritt|mitglied|beitritt|aufnahmeantrag|beitrag\nDokumente & Formulare|formular|antrag|satzung|download|pdf");
}

$field = $form->addTextAreaField('embedding_focus_rules_sources');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_focus_rules_sources'), 'config_embedding_focus_rules_sources_notice'));
$field->setNotice(nl2br($addon->i18n('config_embedding_focus_rules_sources_notice')));
$field->setAttribute('rows', '5');
$field->setAttribute('placeholder', "article=Kontakt|telefon|email|ansprechpartner\nsitemap_url=Formulare|antrag|download|pdf");

$form->addRawField('</div>');

// Kontext-Anreicherung: zusaetzliche, strukturierte Hinweise, die VOR dem Embedding jedem
// Chunk vorangestellt werden (siehe IndexerService::prepareEmbeddingText()) - ergaenzt Titel/
// URL/Typ dort um eine Kategorie-Einordnung und optionale Metainfo-Keywords.
$form->addRawField('<div id="klxm-retrieval-enrichment-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">Kontext-Anreicherung</p>');

$addBoolSelectField($form, 'embedding_category_path_enabled', $addon->i18n('config_embedding_category_path_enabled'), $addon->i18n('config_embedding_category_path_enabled_notice'), true);

$field = $form->addTextField('embedding_metainfo_fields');
$field->setLabel($tooltipLabel($addon->i18n('config_embedding_metainfo_fields'), 'config_embedding_metainfo_fields_notice'));
$field->setNotice($addon->i18n('config_embedding_metainfo_fields_notice'));
$field->setAttribute('placeholder', 'art_meta_keywords, art_kurzbeschreibung');

$form->addRawField('</div>');

// RAG-Abruf: wie viele/aus wie vielen Kandidaten der Kontext fuer JEDE Anfrage ausgewaehlt
// wird - unabhaengig davon, aus welcher Quelle/welchem Profil der Kandidat stammt.
$form->addRawField('<div id="klxm-retrieval-rag-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">RAG-Abruf</p>');

$field = $form->addSelectField('rag_results');
$field->setLabel($tooltipLabel($addon->i18n('config_rag_results'), 'config_rag_results_notice'));
$field->setNotice($addon->i18n('config_rag_results_notice'));
$select = $field->getSelect();
$select->addOption('3 (Standard)', 3);
$select->addOption('5 – mehr Kontext', 5);
$select->addOption('7', 7);
$select->addOption('10 – maximaler Kontext', 10);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(3);
}

$field = $form->addSelectField('rag_candidate_limit');
$field->setLabel($tooltipLabel($addon->i18n('config_rag_candidate_limit'), 'config_rag_candidate_limit_notice'));
$field->setNotice($addon->i18n('config_rag_candidate_limit_notice'));
$select = $field->getSelect();
$select->addOption('300 – kleine Website', 300);
$select->addOption('800 – Standard (empfohlen)', 800);
$select->addOption('1500 – große Website', 1500);
$select->addOption('3000 – sehr große Website', 3000);
$select->addOption('6000 – maximal', 6000);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(800);
}

$addBoolSelectField($form, 'rerank_enabled', $addon->i18n('config_rerank_enabled'), $addon->i18n('config_rerank_enabled_notice'), true);

$field = $form->addSelectField('rerank_candidate_count');
$field->setLabel($tooltipLabel($addon->i18n('config_rerank_candidate_count'), 'config_rerank_candidate_count_notice'));
$field->setNotice($addon->i18n('config_rerank_candidate_count_notice'));
$select = $field->getSelect();
$select->addOption('10', 10);
$select->addOption('20 – Standard (empfohlen)', 20);
$select->addOption('30', 30);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(20);
}

$form->addRawField('</div>');

// Antwort-Cache: gilt ebenfalls quer ueber alle Scopes/Profile - eine gecachte Antwort wird
// unabhaengig davon wiederverwendet, welches Profil die urspruengliche Frage beantwortet hat.
$form->addRawField('<div id="klxm-retrieval-cache-settings" class="ai-chat-settings-box">');
$form->addRawField('<p class="ai-chat-settings-box-title">Antwort-Cache & FAQ-Vorcaching</p>');

$field = $form->addSelectField('cache_similarity');
$field->setLabel($tooltipLabel($addon->i18n('config_cache_similarity'), 'config_cache_similarity_notice'));
$field->setNotice($addon->i18n('config_cache_similarity_notice'));
$select = $field->getSelect();
$select->addOption('0.90 – großzügig (mehr Cache-Treffer)', '0.90');
$select->addOption('0.95 – Standard', '0.95');
$select->addOption('0.98 – streng (fast identische Fragen)', '0.98');
$select->addOption('1.00 – deaktiviert', '1.00');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('0.95');
}

$field = $form->addSelectField('cache_candidate_limit');
$field->setLabel($addon->i18n('config_cache_candidate_limit'));
$field->setNotice($addon->i18n('config_cache_candidate_limit_notice'));
$select = $field->getSelect();
$select->addOption('80 – sehr fokussiert', 80);
$select->addOption('150 – Standard', 150);
$select->addOption('300 – breiter Vergleich', 300);
$select->addOption('500 – maximaler Vergleich', 500);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(150);
}

$form->addRawField('<p class="help-block">FAQ-Vorcaching (welche Fragen vorab beantwortet/gecacht werden) ist seit Kurzem je Profil konfigurierbar - siehe AI Chat → Profile.</p>');

$form->addRawField('</div>');

$form->addRawField($tooltipInitScript);

$sidebar = $renderTipsPanel($addon, 'retrieval')
    . '<div class="panel panel-default" style="margin-bottom:20px;">'
    . '<header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-bolt"></i> ' . $addon->i18n('config_sidebar_precache_title') . '</div></header>'
    . '<div class="panel-body"><p style="margin-bottom:0;">' . $addon->i18n('config_sidebar_precache_text') . '</p></div>'
    . '</div>';

$renderSettingsPage($form, $sidebar);
