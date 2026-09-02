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

$form->addRawField('<p class="help-block">' . $rawMsg('config_section_advanced_hint') . '</p>');

$field = $form->addSelectField('openai_timeout');
$field->setLabel($addon->i18n('config_openai_timeout'));
$field->setNotice($addon->i18n('config_openai_timeout_notice'));
$select = $field->getSelect();
$select->addOption('30 s', 30);
$select->addOption('60 s', 60);
$select->addOption('120 s', 120);
$select->addOption('180 s', 180);
$select->addOption('300 s (5 min)', 300);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(120);
}

$field = $form->addSelectField('ai_temperature');
$field->setLabel($addon->i18n('config_ai_temperature'));
$field->setNotice($addon->i18n('config_ai_temperature_notice'));
$select = $field->getSelect();
$select->addOption('0.1 – sehr präzise / deterministisch', '0.1');
$select->addOption('0.3 – präzise', '0.3');
$select->addOption('0.5 – ausgewogen', '0.5');
$select->addOption('0.7 – kreativ (Standard)', '0.7');
$select->addOption('1.0 – sehr kreativ', '1.0');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('0.7');
}

$field = $form->addSelectField('ai_max_tokens');
$field->setLabel($addon->i18n('config_ai_max_tokens'));
$field->setNotice($addon->i18n('config_ai_max_tokens_notice'));
$select = $field->getSelect();
$select->addOption('512 – kurze Antworten', 512);
$select->addOption('1024', 1024);
$select->addOption('2048 (Standard)', 2048);
$select->addOption('4096 – ausführliche Antworten', 4096);
$select->addOption('8192 – sehr ausführlich', 8192);
if ($isConfigUnset($field->getValue())) {
    $field->setValue(2048);
}

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

$addBoolSelectField($form, 'faq_precache_enabled', $addon->i18n('config_faq_precache_enabled'), $addon->i18n('config_faq_precache_enabled_notice'), false);

$field = $form->addTextAreaField('faq_precache_questions');
$field->setLabel($addon->i18n('config_faq_precache_questions'));
$field->setNotice($addon->i18n('config_faq_precache_questions_notice'));
$field->setAttribute('rows', '8');
if ($isConfigUnset($field->getValue())) {
    $field->setValue('');
}

// Select statt Checkbox - siehe Kommentar bei $addBoolSelectField in settings.shared.php
// (eine per Checkbox deaktivierte Einstellung mit "true"-Default liesse sich sonst nie
// dauerhaft abschalten).
$addBoolSelectField($form, 'stats_logging_enabled', 'Statistiken für Suche und Chat aktivieren', 'Erfasst häufig gestellte Begriffe, leere Treffer und fehlende Antworten für die Admin-Statistiken.', true);

$form->addRawField($tooltipInitScript);

$sidebar = $renderTipsPanel($addon, 'advanced')
    . '<div class="panel panel-default" style="margin-bottom:20px;">'
    . '<header class="panel-heading"><div class="panel-title"><i class="rex-icon fa-bolt"></i> ' . $addon->i18n('config_sidebar_precache_title') . '</div></header>'
    . '<div class="panel-body"><p style="margin-bottom:0;">' . $addon->i18n('config_sidebar_precache_text') . '</p></div>'
    . '</div>';

$renderSettingsPage($form, $sidebar);
