<?php
/**
 * AI Chat Suchseite - Suchformular.
 *
 * Vars:
 *   action         string  Ziel-URL des Formulars (aktuelle Artikel-URL).
 *   query          string  Aktueller Suchbegriff (befuellt das Eingabefeld erneut).
 *   showDateRange  bool    Zeigt zusaetzlich zwei Datumsfelder. Default: false.
 *   dateFrom       string  Aktueller "von"-Wert (ISO-Datum), falls gesetzt.
 *   dateTo         string  Aktueller "bis"-Wert (ISO-Datum), falls gesetzt.
 *
 * @var rex_fragment $this
 */
$action = (string) $this->getVar('action', rex_getUrl());
$query = (string) $this->getVar('query', '');
$showDateRange = (bool) $this->getVar('showDateRange', false);
$dateFrom = (string) $this->getVar('dateFrom', '');
$dateTo = (string) $this->getVar('dateTo', '');
?>
<form class="ai-search-page-form" method="get" action="<?= rex_escape($action) ?>" role="search">
    <label class="ai-search-page-visually-hidden" for="ai-search-page-q"><?= rex_i18n::msg('ai_chat_search_page_label') ?></label>
    <input
        type="search"
        name="q"
        id="ai-search-page-q"
        class="ai-search-page-form__input"
        value="<?= rex_escape($query) ?>"
        placeholder="<?= rex_escape(rex_i18n::msg('ai_chat_search_page_placeholder'), 'html_attr') ?>"
        autocomplete="off">
<?php if ($showDateRange): ?>
    <label class="ai-search-page-form__date-label" for="ai-search-page-date-from">
        <?= rex_i18n::msg('ai_chat_search_page_date_from') ?>
        <input type="date" name="date_from" id="ai-search-page-date-from" class="ai-search-page-form__date" value="<?= rex_escape($dateFrom) ?>">
    </label>
    <label class="ai-search-page-form__date-label" for="ai-search-page-date-to">
        <?= rex_i18n::msg('ai_chat_search_page_date_to') ?>
        <input type="date" name="date_to" id="ai-search-page-date-to" class="ai-search-page-form__date" value="<?= rex_escape($dateTo) ?>">
    </label>
<?php endif; ?>
    <button type="submit" class="ai-search-page-form__submit"><?= rex_i18n::msg('ai_chat_search_page_submit') ?></button>
</form>
