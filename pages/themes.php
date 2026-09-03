<?php

use FriendsOfRedaxo\AiChat\Profile\ThemeRepository;

$addon = rex_addon::get('ai_chat');
$func = rex_request('func', 'string');
$id = rex_request('id', 'int');

if ('set_default' === $func && $id > 0) {
    $addon->setConfig('default_theme_id', $id);
    echo rex_view::success('Als Standard-Theme gesetzt.');
    $func = '';
} elseif ('delete' === $func) {
    if ((new ThemeRepository())->delete($id)) {
        echo rex_view::success('Theme gelöscht. Profile, die dieses Theme genutzt haben, verwenden jetzt automatisch das globale Standard-Theme.');
    } else {
        echo rex_view::error('Das globale Standard-Theme kann nicht gelöscht werden - erst ein anderes Theme als Standard setzen.');
    }
    $func = '';
}

if ('add' === $func || 'edit' === $func) {
    $form = rex_form::factory(rex::getTable('ai_chat_theme'), '', 'id=' . $id);
    $form->addParam('id', $id);

    $field = $form->addTextField('name');
    $field->setLabel('Name');
    $field->setNotice('Nur intern sichtbar, zur Wiedererkennung in der Profil-Auswahl und der Themes-Liste.');

    $form->addRawField('<div id="ai-chat-theme-preview-wrapper" class="ai-chat-settings-box">');
    $form->addRawField('<p class="ai-chat-settings-box-title">Farben</p>');

    $colorField = static function (rex_form_base $form, string $column, string $label, string $inputId, string $placeholder) use ($func) {
        $field = $form->addTextField($column);
        $field->setLabel($label);
        $field->setAttribute('id', $inputId);
        $field->setAttribute('class', trim($field->getAttribute('class', '') . ' ai-chat-theme-color-input'));
        // Bewusst NICHT "data-colorpicker" direkt setzen: Falls ein anderes Addon auf
        // derselben Instanz ebenfalls pickit_color vendort und global laedt (z.B.
        // uikit_theme_builder), scannt JEDE geladene Kopie der Bibliothek unabhaengig
        // voneinander (eigene Instance-Map pro Script-Tag) die Seite nach
        // "[data-colorpicker]" und haengt JEWEILS eine eigene Picker-UI an dasselbe Feld -
        // sichtbar als doppelt angezeigter Colorpicker. Das eigentliche Attribut wird
        // deshalb erst von unserem eigenen Init-Script (siehe unten) gesetzt, NACHDEM
        // alle automatischen Auto-Init-Laeufe bereits vorbei sind, und Init danach genau
        // einmal manuell ausgeloest.
        $field->setAttribute('data-ai-chat-colorpicker', 'format:hex,compact:true,language:de,alpha:true');
        $field->setAttribute('placeholder', $placeholder);
        $field->setAttribute('autocomplete', 'off');

        // pickit_color befuellt ein leeres Feld beim Init selbst sofort mit seiner
        // eigenen, generischen Default-Farbe (Blau) statt es leer zu lassen (wie ein
        // natives <input type="color"> nie wirklich leer ist) - ohne diese explizite
        // Vorbelegung wuerde ein neues Theme also nicht mit den hier als Platzhalter
        // gedachten, sinnvollen Werten starten, sondern mit fuenfmal derselben
        // Picker-eigenen Farbe.
        if ('add' === $func && '' === (string) $field->getValue()) {
            $field->setValue($placeholder);
        }

        return $field;
    };

    $colorField($form, 'primary_color', 'Akzentfarbe', 'ai-chat-theme-primary', '#007bff');
    $colorField($form, 'header_bg_color', 'Kopfzeile Hintergrund', 'ai-chat-theme-header-bg', '#f8f9fa');
    $colorField($form, 'chat_bg_color', 'Chat-Hintergrund', 'ai-chat-theme-chat-bg', '#ffffff');
    $colorField($form, 'text_color', 'Textfarbe', 'ai-chat-theme-text', '#333333');
    $colorField($form, 'bot_message_bg_color', 'Bot-Sprechblase Hintergrund', 'ai-chat-theme-bot-bg', '#f1f3f5');

    $field = $form->addTextField('border_radius');
    $field->setLabel('Eckenradius (px)');
    $field->setAttribute('type', 'number');
    $field->setAttribute('min', '0');
    $field->setAttribute('max', '30');
    $field->setAttribute('style', 'width:80px;');
    $field->setAttribute('id', 'ai-chat-theme-radius');
    if ('add' === $func && '' === (string) $field->getValue()) {
        $field->setValue(12);
    }

    $field = $form->addMediaField('avatar');
    $field->setLabel('Avatar');

    $form->addRawField('</div>');

    $content = $form->get();

    $previewHtml = '
<div class="klxmchat-theme-preview-wrapper">
    <p class="help-block">Live-Vorschau (aktualisiert sich beim Ändern der Felder links)</p>
    <div id="ai-chat-theme-preview" class="klxmchat-theme-preview" style="max-width:320px;border:1px solid rgba(0,0,0,0.15);box-shadow:0 5px 20px rgba(0,0,0,0.15);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
        <div id="ai-chat-theme-preview-header" style="padding:15px;font-weight:bold;">Website Chat</div>
        <div style="padding:15px;">
            <div id="ai-chat-theme-preview-bot" style="display:inline-block;padding:8px 12px;">Hallo! Wie kann ich Ihnen helfen?</div>
        </div>
    </div>
</div>';

    $content = '<div class="row" style="display:flex;flex-wrap:wrap;"><div class="col-md-8" style="min-width:0;">' . $content . '</div><div class="col-md-4" style="min-width:280px;">' . $previewHtml . '</div></div>';

    // pickit_color setzt input.value direkt und ruft KEIN natives input/change-Event auf
    // dem Feld auf (eigene onChange-Callback-API statt DOM-Events, siehe assets/pickit-
    // color/colorpicker.min.js) - ein Polling-Intervall ist deshalb der robusteste Weg,
    // Aenderungen ueber die Bibliothek hinweg zu erkennen, ohne von ihrer internen,
    // nicht dokumentierten API abhaengig zu sein.
    //
    // Bewusst NICHT ueber $form->addRawField() angehaengt: $content = $form->get() wurde
    // oben bereits VOR dieser Stelle aufgerufen und rendert das Formular sofort in einen
    // String - danach per addRawField() hinzugefuegte Felder landen im internen
    // Element-Array des Form-Objekts, aber nie mehr im bereits gerenderten $content. Das
    // Script wird deshalb direkt an $content angehaengt.
    $content .= '
<script>
(function() {
    function initAiChatColorpickers() {
        // Siehe Kommentar bei setAttribute("data-ai-chat-colorpicker", ...) in themes.php:
        // das echte "data-colorpicker"-Attribut wird erst HIER, in unserem eigenen
        // Script, gesetzt - zu diesem Zeitpunkt haben alle automatischen Auto-Init-
        // Laeufe der (moeglicherweise mehrfach auf der Seite geladenen) Bibliothek
        // bereits stattgefunden und nichts gefunden, da das Attribut vorher nicht
        // existierte. initColorPickers() wird darum bewusst nur genau einmal manuell
        // aufgerufen, ueber welche Kopie von window.colorpicker auch immer aktuell
        // global registriert ist (beide Kopien sind versionsgleich).
        var inputs = document.querySelectorAll("[data-ai-chat-colorpicker]");
        if (!inputs.length) return;
        inputs.forEach(function(el) {
            if (!el.hasAttribute("data-colorpicker")) {
                el.setAttribute("data-colorpicker", el.getAttribute("data-ai-chat-colorpicker"));
            }
        });
        if (window.colorpicker && typeof window.colorpicker.initColorPickers === "function") {
            window.colorpicker.initColorPickers();
        }

        // pickit_color v1.2.3 schreibt getippte Werte im Hex-Textfeld NUR in den
        // internen State + die Live-Vorschau der Bibliothek selbst (deren eigenes
        // "input"-Event ruft bewusst updateColorDisplay(false) auf) - das eigentliche,
        // von REDAXO gespeicherte Formularfeld wird dabei NIE aktualisiert (nur Ziehen
        // an Farbflaeche/Reglern committet sofort). Ohne diesen Fix waere exaktes
        // Eintippen eines Alpha-Hex-Werts (z.B. "#007bffcc") wirkungslos. Wir erzwingen
        // den Commit deshalb selbst bei Enter/Blur ueber die oeffentliche
        // instance.setColor()-API.
        if (window.colorpicker && window.colorpicker.ColorPicker && window.colorpicker.ColorPicker.getInstance) {
            document.querySelectorAll(".ai-chat-theme-color-input").forEach(function(el) {
                var instance = window.colorpicker.ColorPicker.getInstance(el);
                if (!instance || !instance.container) return;
                var hexField = instance.container.querySelector(".colorpicker-input");
                if (!hexField || hexField.dataset.aiChatCommitWired) return;
                hexField.dataset.aiChatCommitWired = "1";

                var commit = function() {
                    var value = hexField.value.trim();
                    if (value) instance.setColor(value);
                };
                hexField.addEventListener("change", commit);
                hexField.addEventListener("keydown", function(ev) {
                    if (ev.key === "Enter") {
                        ev.preventDefault();
                        commit();
                    }
                });
            });
        }
    }

    function initAiChatThemePreview() {
        var preview = document.getElementById("ai-chat-theme-preview");
        var header = document.getElementById("ai-chat-theme-preview-header");
        var bot = document.getElementById("ai-chat-theme-preview-bot");
        initAiChatColorpickers();
        if (!preview || !header || !bot) return;

        var fields = {
            primary: document.getElementById("ai-chat-theme-primary"),
            headerBg: document.getElementById("ai-chat-theme-header-bg"),
            chatBg: document.getElementById("ai-chat-theme-chat-bg"),
            text: document.getElementById("ai-chat-theme-text"),
            botBg: document.getElementById("ai-chat-theme-bot-bg"),
            radius: document.getElementById("ai-chat-theme-radius")
        };

        var lastValues = {};

        function apply() {
            var changed = false;
            for (var key in fields) {
                var el = fields[key];
                if (!el) continue;
                var value = el.value || "";
                if (lastValues[key] !== value) {
                    lastValues[key] = value;
                    changed = true;
                }
            }
            if (!changed) return;

            preview.style.background = fields.chatBg && fields.chatBg.value ? fields.chatBg.value : "#ffffff";
            preview.style.borderRadius = (fields.radius && fields.radius.value ? fields.radius.value : "12") + "px";
            header.style.background = fields.headerBg && fields.headerBg.value ? fields.headerBg.value : "#f8f9fa";
            header.style.color = fields.text && fields.text.value ? fields.text.value : "#333333";
            bot.style.background = fields.botBg && fields.botBg.value ? fields.botBg.value : "#f1f3f5";
            bot.style.color = fields.text && fields.text.value ? fields.text.value : "#333333";
            bot.style.borderRadius = (fields.radius && fields.radius.value ? fields.radius.value : "12") + "px";
        }

        apply();
        setInterval(apply, 150);
    }

    // "rex:ready" feuert (wie DOMContentLoaded) nur einmal pro tatsaechlichem
    // Seitenaufruf - bei einem normalen vollen Seitenladen (kein PJAX-Wechsel
    // innerhalb des Backends) ist dieses Inline-Script aber oft erst NACH dem
    // bereits erfolgten Event im DOM (Script steht am Ende des Formulars). Ohne
    // den sofortigen Aufruf unten bliebe der Colorpicker dann komplett uninitialisiert
    // (Attribut-Umschreibung + initColorPickers() liefen nie). initAiChatColorpickers()
    // selbst ist idempotent (prueft hasAttribute + die instances-Map der Bibliothek),
    // ein doppelter Aufruf bei einem spaeteren PJAX-"rex:ready" ist daher unschaedlich.
    if (document.readyState !== "loading") {
        initAiChatThemePreview();
    }

    if (typeof jQuery !== "undefined") {
        jQuery(document).on("rex:ready", initAiChatThemePreview);
    } else {
        document.addEventListener("DOMContentLoaded", initAiChatThemePreview);
    }
})();
</script>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', ('edit' === $func) ? 'Theme bearbeiten' : 'Neues Theme erstellen');
    $fragment->setVar('body', $content, false);
    echo $fragment->parse('core/page/section.php');
} else {
    $defaultThemeId = (int) $addon->getConfig('default_theme_id', 0);

    $list = rex_list::factory('SELECT id, name, primary_color FROM ' . rex::getTable('ai_chat_theme') . ' ORDER BY name ASC');
    $list->addTableAttribute('class', 'table-striped');

    $tdIcon = '<i class="rex-icon rex-icon-edit"></i>';
    $thIcon = '<a href="' . $list->getUrl(['func' => 'add']) . '" title="Hinzufügen"><i class="rex-icon rex-icon-add-module"></i></a>';
    $list->addColumn($thIcon, $tdIcon, 0, ['<th class="rex-table-icon">###VALUE###</th>', '<td class="rex-table-icon">###VALUE###</td>']);
    $list->setColumnParams($thIcon, ['func' => 'edit', 'id' => '###id###']);

    $list->setColumnLabel('name', 'Name');

    $list->addColumn('swatch', '', 1, ['<th></th>', '<td>###VALUE###</td>']);
    $list->setColumnFormat('swatch', 'custom', static function (array $params): string {
        $color = trim((string) $params['list']->getValue('primary_color'));
        if ('' === $color || 1 !== preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            $color = '#cccccc';
        }

        return '<span style="display:inline-block;width:20px;height:20px;border-radius:4px;border:1px solid rgba(0,0,0,0.15);background:' . rex_escape($color) . ';" title="' . rex_escape($color) . '"></span>';
    });

    $list->removeColumn('primary_color');

    $list->addColumn('default', '', 1, ['<th>Standard</th>', '<td>###VALUE###</td>']);
    $list->setColumnFormat('default', 'custom', static function (array $params) use ($defaultThemeId, $list): string {
        $rowId = (int) $params['list']->getValue('id');
        if ($rowId === $defaultThemeId) {
            return '<span class="label label-success">Standard</span>';
        }

        return '<a class="btn btn-xs btn-default" href="' . $list->getUrl(['func' => 'set_default', 'id' => $rowId]) . '">Als Standard setzen</a>';
    });

    $list->addColumn('delete', '<i class="rex-icon rex-icon-delete"></i> Löschen', -1, ['', '<td class="rex-table-action">###VALUE###</td>']);
    $list->setColumnParams('delete', ['func' => 'delete', 'id' => '###id###']);
    $list->addLinkAttribute('delete', 'data-confirm', 'Theme wirklich löschen? Profile, die dieses Theme gewählt haben, verwenden danach automatisch das globale Standard-Theme.');

    $content = $list->get();

    $fragment = new rex_fragment();
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
}
