<?php

/**
 * Wird von allen settings.*.php-Unterseiten per require eingebunden.
 * Stellt $addon sowie gemeinsame Helper-Closures bereit (geteilter Scope durch require).
 */

$addon = rex_addon::get('ai_chat');

$rawMsg = static fn (string $key): string => rex_i18n::rawMsg('ai_chat_' . $key);

// Kurzer "?"-Tooltip direkt am Label für Einstellungen, die ohne Kontext leicht missverstanden
// werden (z.B. RAG-Kandidatenfenster, Chunking). Der ausführliche Text bleibt zusätzlich als
// Notice unter dem Feld stehen.
// Hinweis: $label und $addon->i18n(...) sind bereits HTML-escaped (rex_i18n::msg() escaped intern),
// daher hier NICHT nochmal mit rex_escape() escapen (sonst z.B. " -> &quot; -> sichtbares &quot;).
$tooltipLabel = static function (string $label, string $tooltipI18nKey) use ($addon): string {
    return $label . ' <i class="rex-icon fa-question-circle ai-chat-help-icon" data-toggle="tooltip" data-placement="right" title="' . $addon->i18n($tooltipI18nKey) . '"></i>';
};

$isConfigUnset = static function ($value): bool {
    if ($value === null || $value === '') {
        return true;
    }

    return is_array($value) && $value === [];
};

// Ja/Nein als Select statt Checkbox: rex_config_form speichert eine deaktivierte
// (unchecked) Checkbox nicht als "aus", sondern als PHP null - der Browser sendet fuer eine
// unchecked Checkbox schlicht gar keinen POST-Wert, und rex_form_element::getSaveValue()
// gibt in diesem Fall direkt null zurueck (der ueber setDefaultSaveValue() konfigurierbare
// Fallback greift nur bei einem leeren String, nicht bei null). rex_config::get() faellt bei
// gespeichertem null aber IMMER auf den uebergebenen $default zurueck (PHP behandelt "null"
// im ??-Operator wie "nicht gesetzt"). Bei einem $default von true/1 - wie es fast jede
// "an sich aktive" Einstellung dieses Addons hat - laesst sich eine so gebundene Checkbox
// dadurch NIE dauerhaft deaktivieren: der Haken kommt nach jedem Speichern zurueck (siehe
// https://github.com/KLXM/klxmchat/issues/23). Ein Select sendet dagegen immer explizit "1"
// oder "0" - nie "gar nichts" -, das Problem tritt hier also gar nicht erst auf.
$addBoolSelectField = static function (rex_form_base $form, string $configKey, string $label, string $notice, bool $defaultWhenUnset) {
    $field = $form->addSelectField($configKey);
    $field->setLabel($label);
    if ('' !== $notice) {
        $field->setNotice($notice);
    }
    $select = $field->getSelect();
    $select->addOption('Ja', '1');
    $select->addOption('Nein', '0');

    $raw = $field->getValue();
    if (null === $raw || '' === $raw) {
        $isTrue = $defaultWhenUnset;
    } elseif (is_bool($raw)) {
        $isTrue = $raw;
    } else {
        // Normalisiert Alt-Werte aus der frueheren Checkbox-Bindung (Pipe-Format "|1|") auf
        // das saubere '1'/'0', das dieses Select ab jetzt schreibt.
        $isTrue = in_array(strtolower(trim((string) $raw, '|')), ['1', 'true'], true);
    }
    $field->setValue($isTrue ? '1' : '0');

    return $field;
};

// Generischer Info-/Tipp-Panel-Baustein mit korrektem panel-heading (Bootstrap-Struktur),
// damit alle Sidebar-Boxen einheitlich aussehen (nicht nur ein nacktes <strong>).
// $title kommt i.d.R. bereits HTML-escaped aus $addon->i18n() und wird hier NICHT nochmal escaped.
$renderInfoPanel = static function (string $title, string $icon, string $bodyHtml, string $panelClass = 'panel-default'): string {
    return '<div class="panel ' . rex_escape($panelClass) . '" style="margin-bottom:20px;">'
        . '<header class="panel-heading"><div class="panel-title"><i class="rex-icon ' . rex_escape($icon) . '"></i> ' . $title . '</div></header>'
        . '<div class="panel-body">' . $bodyHtml . '</div>'
        . '</div>';
};

// Baut aus einem "||"-getrennten i18n-Text (bereits HTML-escaped) eine <ul>-Tippliste (echte
// Mehrzeiler sind in .lang-Dateien nicht möglich, da rex_i18n pro Zeile genau ein "key = value"-Paar erwartet).
$renderTipsList = static function (string $pipeSeparatedText): string {
    $items = array_filter(array_map('trim', explode('||', $pipeSeparatedText)));
    if ($items === []) {
        return '';
    }

    $html = '<ul style="padding-left:18px; margin-bottom:0;">';
    foreach ($items as $item) {
        $html .= '<li style="margin-bottom:6px;">' . $item . '</li>';
    }
    $html .= '</ul>';

    return $html;
};

// Praxisnahe Empfehlungen/Tipps-Panel für eine bestimmte Settings-Unterseite (i18n-Keys
// config_sidebar_tips_<subpage>_title / _text).
$renderTipsPanel = static function (rex_addon $addon, string $subpage) use ($renderInfoPanel, $renderTipsList): string {
    $title = $addon->i18n('config_sidebar_tips_' . $subpage . '_title');
    $text = $addon->i18n('config_sidebar_tips_' . $subpage . '_text');

    return $renderInfoPanel($title, 'fa-lightbulb-o', $renderTipsList($text), 'panel-info');
};

// Aktiviert Bootstrap-Tooltips für die $tooltipLabel-Icons; wird auf jeder Unterseite eingebunden.
$tooltipInitScript = '
<script>
(function() {
    function initKlxmSettingsTooltips() {
        if (typeof jQuery !== "undefined" && jQuery.fn.tooltip) {
            jQuery("[data-toggle=\"tooltip\"]").tooltip();
        }
    }
    if (typeof jQuery !== "undefined") {
        jQuery(document).on("rex:ready", initKlxmSettingsTooltips);
    } else {
        document.addEventListener("DOMContentLoaded", initKlxmSettingsTooltips);
    }
})();
</script>';

// Rendert Formular + Sidebar in einem gemeinsamen Layout (ohne eigenen Fragment-Titel,
// REDAXO rendert den Seitentitel der Unterseite bereits automatisch aus package.yml).
$renderSettingsPage = static function (rex_config_form $form, string $sidebarHtml): void {
    $fragment = new rex_fragment();
    $fragment->setVar('body', '<div class="row"><div class="col-md-9">' . $form->get() . '</div><div class="col-md-3">' . $sidebarHtml . '</div></div>', false);
    echo $fragment->parse('core/page/section.php');
};
