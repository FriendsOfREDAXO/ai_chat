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
    $followupColorField = $colorField($form, 'followup_color', 'Folgefragen (Farbe)', 'ai-chat-theme-followup', '');
    $followupColorField->setNotice('Farbe der Folgefragen-Chips nach einer Antwort. Leer = folgt der Akzentfarbe (bisheriges Verhalten).');
    $colorField($form, 'header_bg_color', 'Kopfzeile Hintergrund', 'ai-chat-theme-header-bg', '#f8f9fa');
    $colorField($form, 'chat_bg_color', 'Chat-Hintergrund', 'ai-chat-theme-chat-bg', '#ffffff');
    $colorField($form, 'text_color', 'Textfarbe (Kopfzeile)', 'ai-chat-theme-text', '#333333');
    $colorField($form, 'bot_message_bg_color', 'Bot-Sprechblase Hintergrund', 'ai-chat-theme-bot-bg', '#f1f3f5');
    // Vorher teilte sich die Bot-Sprechblase die Textfarbe mit der Kopfzeile (dasselbe
    // Feld), und die Nutzer-Sprechblase hatte ueberhaupt kein Textfarb-Feld (im
    // Widget-CSS fest auf "white" verdrahtet) - bei einer hellen Akzentfarbe war der
    // Text darin praktisch unlesbar. Beide sind jetzt eigene, unabhaengige Felder.
    $colorField($form, 'bot_message_text_color', 'Bot-Sprechblase Textfarbe', 'ai-chat-theme-bot-text', '#333333');
    $colorField($form, 'user_message_text_color', 'Nutzer-Sprechblase Textfarbe', 'ai-chat-theme-user-text', '#ffffff');

    $form->addRawField('<p class="ai-chat-settings-box-title" style="margin-top:20px;">Eingabefeld</p>');
    // --ai-chat-input-* existierten im Widget-CSS schon vorher, waren aber bislang von
    // keinem Theme-Feld aus befuellbar - ein dunkles Theme bekam dadurch trotz dunklem
    // Chat-/Kopfzeilen-Hintergrund ein stur weisses Eingabefeld.
    $colorField($form, 'input_bg_color', 'Hintergrund', 'ai-chat-theme-input-bg', '#ffffff');
    $colorField($form, 'input_text_color', 'Textfarbe', 'ai-chat-theme-input-text', '#333333');
    $colorField($form, 'input_border_color', 'Rahmen', 'ai-chat-theme-input-border', '#dddddd');

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

    // Statt eines von Hand nachgebauten Mockups (optisch nie ganz deckungsgleich mit dem
    // echten Widget-CSS und bei jeder Design-Aenderung an assets/ai-chat.js erneut
    // pflegepflichtig) wird hier die ECHTE <ai-chat>-Webcomponent eingebettet - exakt das
    // gleiche Vorgehen wie schon beim bestehenden "Profil testen"-Vorschaufenster in
    // pages/profiles.php (dort mode="inline", einmalig serverseitig aufgeloest statt
    // live). connectedCallback() der Komponente macht beim Einhaengen KEINEN
    // Netzwerk-Aufruf (siehe assets/ai-chat.js) - erst ein tatsaechliches Absenden einer
    // Nachricht wuerde einen echten API-Request ausloesen, was hier ueber einen
    // Submit-Blocker im Init-Script unterbunden wird, da die Vorschau rein optisch sein
    // soll und keinem echten Profil zugeordnet ist.
    $previewHtml = '
<div class="klxmchat-theme-preview-wrapper">
    <p class="help-block">Live-Vorschau (aktualisiert sich beim Ändern der Felder links)</p>
    <ai-chat id="ai-chat-theme-preview" mode="inline" title="Website Chat" greeting="Hallo! Wie kann ich Ihnen helfen?" ui-language="de" style="display:block;max-width:320px;--ai-chat-height:440px;"></ai-chat>
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

    // Fuellt die Vorschau-Instanz mit denselben drei Demo-Nachrichten (Begruessung,
    // Nutzerfrage, Antwort) - einmal beim ersten Verbinden und danach jedesmal erneut
    // nach einem erzwungenen render() (siehe primary-color-Zweig in apply()), da render()
    // die komplette Shadow-DOM-Nachrichtenliste verwirft. this.messages wird dabei
    // bewusst zurueckgesetzt statt nur angehaengt, sonst wuerde bei mehrfachem Aendern
    // der Akzentfarbe dieselbe Demo-Konversation immer wieder dupliziert.
    function seedAiChatPreviewMessages(el) {
        el.messages = [];
        var messagesContainer = el.shadowRoot && el.shadowRoot.querySelector(".chat-messages");
        if (messagesContainer) messagesContainer.innerHTML = "";
        el.addMessage("bot", el.getAttribute("greeting") || "Hallo! Wie kann ich Ihnen helfen?");
        el.addMessage("user", "Was kostet das?");
        el.addMessage("bot", "Das kommt auf Ihre Anforderungen an ...");
    }

    function initAiChatThemePreview() {
        var preview = document.getElementById("ai-chat-theme-preview");
        initAiChatColorpickers();
        if (!preview || typeof preview.addMessage !== "function") return;

        // Vorschau ist rein optisch (keinem echten Profil zugeordnet, keine gueltige
        // api-url) - ein Klick auf "Senden" soll sichtbar nichts tun statt einen
        // fehlschlagenden Request auszuloesen. "submit" ist ein composed Event und
        // durchquert daher beim Capturing auch offene Shadow-Roots - ein Abfangen hier
        // auf dem Host, VOR dem eigenen bubble-phase-Listener der Komponente auf dem
        // <form>, verhindert dessen Ausfuehrung zuverlaessig.
        preview.addEventListener("submit", function(ev) {
            ev.preventDefault();
            ev.stopPropagation();
        }, true);

        seedAiChatPreviewMessages(preview);

        var fields = {
            primary: document.getElementById("ai-chat-theme-primary"),
            headerBg: document.getElementById("ai-chat-theme-header-bg"),
            chatBg: document.getElementById("ai-chat-theme-chat-bg"),
            text: document.getElementById("ai-chat-theme-text"),
            botBg: document.getElementById("ai-chat-theme-bot-bg"),
            botText: document.getElementById("ai-chat-theme-bot-text"),
            userText: document.getElementById("ai-chat-theme-user-text"),
            inputBg: document.getElementById("ai-chat-theme-input-bg"),
            inputText: document.getElementById("ai-chat-theme-input-text"),
            inputBorder: document.getElementById("ai-chat-theme-input-border"),
            radius: document.getElementById("ai-chat-theme-radius")
        };

        var lastValues = {};

        function apply() {
            var changed = {};
            var anyChanged = false;
            for (var key in fields) {
                var el = fields[key];
                if (!el) continue;
                var value = el.value || "";
                if (lastValues[key] !== value) {
                    lastValues[key] = value;
                    changed[key] = true;
                    anyChanged = true;
                }
            }
            if (!anyChanged) return;

            // --ai-chat-header-bg/-bg/-text/-bot-msg-bg/-radius sind im echten Widget-CSS
            // ganz normale, von aussen ueberschreibbare Custom Properties (var(--x, ...) in
            // .chat-container etc.) - eine Aktualisierung ueber den Host-Style wirkt sofort,
            // ganz ohne Neu-Rendern der Komponente.
            if (fields.headerBg) preview.style.setProperty("--ai-chat-header-bg", fields.headerBg.value || "#f8f9fa");
            if (fields.chatBg) preview.style.setProperty("--ai-chat-bg", fields.chatBg.value || "#ffffff");
            if (fields.text) preview.style.setProperty("--ai-chat-text", fields.text.value || "#333333");
            if (fields.botBg) preview.style.setProperty("--ai-chat-bot-msg-bg", fields.botBg.value || "#f1f3f5");
            if (fields.botText) preview.style.setProperty("--ai-chat-bot-msg-text", fields.botText.value || "#333333");
            if (fields.userText) preview.style.setProperty("--ai-chat-user-msg-text", fields.userText.value || "#ffffff");
            if (fields.inputBg) preview.style.setProperty("--ai-chat-input-bg", fields.inputBg.value || "#ffffff");
            if (fields.inputText) preview.style.setProperty("--ai-chat-input-text", fields.inputText.value || "#333333");
            if (fields.inputBorder) preview.style.setProperty("--ai-chat-input-border", fields.inputBorder.value || "#dddddd");
            if (fields.radius) preview.style.setProperty("--ai-chat-radius", (fields.radius.value || "12") + "px");

            // --ai-chat-primary wird von der Komponente dagegen NUR aus dem
            // "primary-color"-Attribut heraus in ihr eigenes :host { --ai-chat-primary: ... }
            // hineingerendert (siehe render() in assets/ai-chat.js) - das ueberschreibt/
            // "beschattet" jeden von aussen gesetzten Wert des gleichnamigen Custom-
            // Property. Ein Update wirkt hier deshalb nur durch ein erneutes render() +
            // setupEventListeners() der Komponente selbst (unschaedlich, siehe
            // Kommentar an der <ai-chat>-Definition oben - kein Netzwerk-Aufruf darin).
            if (changed.primary && fields.primary) {
                preview.setAttribute("primary-color", fields.primary.value || "#007bff");
                preview.render();
                preview.setupEventListeners();
                seedAiChatPreviewMessages(preview);

                // render() baut nur die generische Standard-Kopfzeile ("Chat Assistant")
                // ins Template - den Titel setzt sonst ausschliesslich connectedCallback()
                // einmalig NACH dem allerersten render(), direkt als .chat-title-Textinhalt
                // (kein Attribut, das render() selbst ausliest). Ein erzwungenes
                // Neu-Rendern muss das deshalb hier nachholen, sonst "vergisst" die
                // Vorschau ihren Titel bei der ersten Farbaenderung wieder.
                var headerTitle = preview.shadowRoot.querySelector(".chat-title");
                if (headerTitle) headerTitle.textContent = preview.getAttribute("title") || "Website Chat";
            }
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
