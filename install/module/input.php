<?php
/**
 * AI Chat Suchseite - Modul-Eingabe.
 *
 * Alle Einstellungen sind optional; ohne Angabe funktioniert die Suche mit
 * sinnvollen Standardwerten.
 *
 * Hinweis zu Checkboxen: REDAXO ersetzt REX_VALUE[n] durch den gespeicherten
 * Wert als gequoteten PHP-Ausdruck, auch innerhalb von PHP-Strings. Eine
 * Checkbox muss diesen Wert deshalb VERGLEICHEN statt ihn auszugeben -
 * ausgeben wuerde den nackten Wert neben die Attribute schreiben und nie
 * "checked" erzeugen, das Kaestchen erscheint dann leer und der naechste
 * Speichervorgang loescht die Einstellung stillschweigend.
 */
?>
<div class="row">
    <div class="col-sm-6">
        <label for="ai-chat-search-per-page"><?= rex_i18n::msg('ai_chat_module_per_page') ?></label>
        <input class="form-control" id="ai-chat-search-per-page" type="number" min="1" max="50"
               name="REX_INPUT_VALUE[1]" value="REX_VALUE[1]" placeholder="<?= rex_escape(rex_i18n::msg('ai_chat_module_per_page_placeholder'), 'html_attr') ?>">
    </div>
    <div class="col-sm-6">
        <label>
            <input type="checkbox" name="REX_INPUT_VALUE[2]" value="1"<?= '1' === 'REX_VALUE[2]' ? ' checked' : '' ?>>
            <?= rex_i18n::msg('ai_chat_module_show_date_range') ?>
        </label>
        <p class="help-block"><?= rex_i18n::msg('ai_chat_module_show_date_range_notice') ?></p>
    </div>
</div>
