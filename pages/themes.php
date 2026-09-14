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

    $shadowField = static function (rex_form_base $form, string $colorColumn, string $intensityColumn, string $label, string $colorInputId, string $intensitySelectId) use ($colorField) {
        $colorField($form, $colorColumn, $label . ' – Farbe', $colorInputId, '');

        $field = $form->addSelectField($intensityColumn);
        $field->setLabel($label . ' – Intensität');
        $field->setAttribute('id', $intensitySelectId);
        $select = $field->getSelect();
        $select->addOption('Kein Schatten', 'none');
        $select->addOption('Leicht', 'light');
        $select->addOption('Mittel (Standard)', 'medium');
        $select->addOption('Stark', 'strong');
        if ('' === (string) $field->getValue()) {
            $field->setValue('medium');
        }
    };

    // Gruppierung nach sichtbarem Bereich statt nach Feldtyp ("Farben"/"Eingabefeld") -
    // Bubble/Fenster/Nachrichten/Eingabefeld sind die vier optisch getrennten Bereiche
    // des Widgets, in genau dieser Reihenfolge auch in der Vorschau von aussen nach
    // innen sichtbar (Bubble -> Fensterrahmen -> Nachrichtenverlauf -> Eingabefeld).
    $form->addRawField('<p class="ai-chat-settings-box-title">Akzentfarbe</p>');
    $colorField($form, 'primary_color', 'Akzentfarbe', 'ai-chat-theme-primary', '#007bff');
    $form->addRawField('<p class="help-block">Fällt überall dort ein, wo kein spezifischeres Feld unten gesetzt ist (Bubble, Folgefragen, Rahmen/Fokus-Effekte).</p>');

    $form->addRawField('<p class="ai-chat-settings-box-title" style="margin-top:20px;">Bubble (Öffnen-Button)</p>');
    $bubbleColorField = $colorField($form, 'bubble_color', 'Farbe', 'ai-chat-theme-bubble', '');
    $bubbleColorField->setNotice('Leer = folgt der Akzentfarbe (bisheriges Verhalten).');
    $shadowField($form, 'bubble_shadow_color', 'bubble_shadow_intensity', 'Schatten', 'ai-chat-theme-bubble-shadow-color', 'ai-chat-theme-bubble-shadow-intensity');
    $field = $form->addMediaField('avatar');
    $field->setLabel('Avatar');
    $field->setNotice('Erscheint auf der Bubble statt des Standard-Icons.');

    $form->addRawField('<p class="ai-chat-settings-box-title" style="margin-top:20px;">Chat-Fenster</p>');
    $colorField($form, 'header_bg_color', 'Kopfzeile Hintergrund', 'ai-chat-theme-header-bg', '#f8f9fa');
    $colorField($form, 'text_color', 'Textfarbe (Kopfzeile)', 'ai-chat-theme-text', '#333333');
    $chatBgColorField = $colorField($form, 'chat_bg_color', 'Fenster-Hintergrund', 'ai-chat-theme-chat-bg', '#ffffff');
    $chatBgColorField->setNotice('Alpha-Wert im Colorpicker reduzieren, um den Hintergrund transparent zu machen - siehe "Hintergrund-Weichzeichner" unten für einen Glassmorphism-Effekt.');
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
    $field = $form->addTextField('backdrop_blur');
    $field->setLabel('Hintergrund-Weichzeichner (px)');
    $field->setNotice('Weichzeichnet alles hinter dem Chat-Fenster (Glassmorphism-Effekt) - nur sichtbar, wenn der Fenster-Hintergrund oben transparent/teiltransparent eingestellt ist (Alpha im Colorpicker). Leer = deaktiviert.');
    $field->setAttribute('type', 'number');
    $field->setAttribute('min', '0');
    $field->setAttribute('max', '30');
    $field->setAttribute('style', 'width:80px;');
    $field->setAttribute('id', 'ai-chat-theme-backdrop-blur');
    $shadowField($form, 'container_shadow_color', 'container_shadow_intensity', 'Schatten', 'ai-chat-theme-container-shadow-color', 'ai-chat-theme-container-shadow-intensity');
    $field = $form->addSelectField('open_animation');
    $field->setLabel('Einblend-Animation');
    $field->setNotice('Wie sich das Chat-Fenster beim Öffnen der Bubble einblendet.');
    $field->setAttribute('id', 'ai-chat-theme-open-animation');
    $animationSelect = $field->getSelect();
    $animationSelect->addOption('Standard (Ein-/Ausblenden)', 'fade_slide');
    $animationSelect->addOption('Zoom', 'zoom');
    $animationSelect->addOption('Von unten einschieben', 'slide_up');
    $animationSelect->addOption('3D-Flip', 'flip_3d');
    $form->addRawField('<p class="help-block"><button type="button" class="btn btn-default btn-xs" id="ai-chat-theme-animation-test">Animation testen</button></p>');

    $form->addRawField('<p class="ai-chat-settings-box-title" style="margin-top:20px;">Nachrichten</p>');
    $colorField($form, 'bot_message_bg_color', 'Bot-Sprechblase Hintergrund', 'ai-chat-theme-bot-bg', '#f1f3f5');
    // Vorher teilte sich die Bot-Sprechblase die Textfarbe mit der Kopfzeile (dasselbe
    // Feld), und die Nutzer-Sprechblase hatte ueberhaupt kein Textfarb-Feld (im
    // Widget-CSS fest auf "white" verdrahtet) - bei einer hellen Akzentfarbe war der
    // Text darin praktisch unlesbar. Beide sind jetzt eigene, unabhaengige Felder.
    $colorField($form, 'bot_message_text_color', 'Bot-Sprechblase Textfarbe', 'ai-chat-theme-bot-text', '#333333');
    $colorField($form, 'user_message_text_color', 'Nutzer-Sprechblase Textfarbe', 'ai-chat-theme-user-text', '#ffffff');
    $followupColorField = $colorField($form, 'followup_color', 'Folgefragen (Farbe)', 'ai-chat-theme-followup', '');
    $followupColorField->setNotice('Farbe der Folgefragen-Chips nach einer Antwort. Leer = folgt der Akzentfarbe (bisheriges Verhalten).');

    $form->addRawField('<p class="ai-chat-settings-box-title" style="margin-top:20px;">Eingabefeld</p>');
    // --ai-chat-input-* existierten im Widget-CSS schon vorher, waren aber bislang von
    // keinem Theme-Feld aus befuellbar - ein dunkles Theme bekam dadurch trotz dunklem
    // Chat-/Kopfzeilen-Hintergrund ein stur weisses Eingabefeld.
    $colorField($form, 'input_bg_color', 'Hintergrund', 'ai-chat-theme-input-bg', '#ffffff');
    $colorField($form, 'input_text_color', 'Textfarbe', 'ai-chat-theme-input-text', '#333333');
    $colorField($form, 'input_border_color', 'Rahmen', 'ai-chat-theme-input-border', '#dddddd');

    $form->addRawField('</div>');

    $content = $form->get();

    // Statt eines von Hand nachgebauten Mockups (optisch nie ganz deckungsgleich mit dem
    // echten Widget-CSS und bei jeder Design-Aenderung an assets/ai-chat.js erneut
    // pflegepflichtig) wird hier die ECHTE <ai-chat>-Webcomponent eingebettet - exakt das
    // gleiche Vorgehen wie schon beim bestehenden "Profil testen"-Vorschaufenster in
    // pages/profiles.php. connectedCallback() der Komponente macht beim Einhaengen KEINEN
    // Netzwerk-Aufruf (siehe assets/ai-chat.js) - erst ein tatsaechliches Absenden einer
    // Nachricht wuerde einen echten API-Request ausloesen, was hier ueber einen
    // Submit-Blocker im Init-Script unterbunden wird, da die Vorschau rein optisch sein
    // soll und keinem echten Profil zugeordnet ist.
    //
    // mode="bubble" (statt des frueheren, permanent offenen mode="inline") zeigt den Chat
    // GENAU so, wie er im echten Frontend erscheint: als schwebende Bubble unten rechts,
    // per "position: fixed" relativ zum GESAMTEN Browser-Fenster (assets/ai-chat.js,
    // :host { position: fixed; ... }) - bewusst OHNE einen einsperrenden Vorschau-Kasten
    // (kein "transform" auf einem umschliessenden Element, das position:fixed sonst auf
    // diesen Kasten umlenken wuerde). Die Bubble bleibt dadurch beim Scrollen durch das
    // lange Formular immer an derselben Stelle sichtbar, exakt wie im echten Frontend -
    // kein zusaetzliches CSS/JS zum "Kleben" noetig, das Widget kann das schon selbst.
    $previewHtml = '
<div class="klxmchat-theme-preview-wrapper">
    <p class="help-block">Live-Vorschau: unten rechts auf dieser Seite, genau wie im echten Frontend (aktualisiert sich beim Ändern der Felder links).</p>
    <ai-chat id="ai-chat-theme-preview" mode="bubble" title="Website Chat" greeting="Hallo! Wie kann ich Ihnen helfen?" ui-language="de" style="--ai-chat-width:320px;--ai-chat-height:440px;"></ai-chat>
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
            radius: document.getElementById("ai-chat-theme-radius"),
            bubble: document.getElementById("ai-chat-theme-bubble"),
            backdropBlur: document.getElementById("ai-chat-theme-backdrop-blur"),
            openAnimation: document.getElementById("ai-chat-theme-open-animation"),
            bubbleShadowColor: document.getElementById("ai-chat-theme-bubble-shadow-color"),
            bubbleShadowIntensity: document.getElementById("ai-chat-theme-bubble-shadow-intensity"),
            containerShadowColor: document.getElementById("ai-chat-theme-container-shadow-color"),
            containerShadowIntensity: document.getElementById("ai-chat-theme-container-shadow-intensity")
        };

        var shadowOffsets = {
            bubble: { light: "0 2px 6px", medium: "0 4px 12px", strong: "0 6px 20px" },
            container: { light: "0 3px 10px", medium: "0 5px 20px", strong: "0 8px 32px" }
        };
        var shadowDefaultColor = {
            bubble: "rgba(0,0,0,0.15)",
            container: "rgba(0,0,0,0.2)"
        };

        function buildShadow(kind, colorField, intensityField) {
            var intensity = (intensityField && intensityField.value) || "medium";
            if (intensity === "none") return "none";
            var offsets = shadowOffsets[kind][intensity] || shadowOffsets[kind].medium;
            var color = (colorField && colorField.value) || shadowDefaultColor[kind];
            return offsets + " " + color;
        }

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
            if (fields.bubble && fields.bubble.value) preview.style.setProperty("--ai-chat-bubble", fields.bubble.value);
            if (fields.backdropBlur) {
                var blurValue = fields.backdropBlur.value;
                preview.style.setProperty("--ai-chat-backdrop-filter", blurValue ? "blur(" + blurValue + "px)" : "none");
            }
            if (fields.openAnimation && fields.openAnimation.value) {
                preview.setAttribute("open-animation", fields.openAnimation.value);
            }
            preview.style.setProperty("--ai-chat-bubble-shadow", buildShadow("bubble", fields.bubbleShadowColor, fields.bubbleShadowIntensity));
            preview.style.setProperty("--ai-chat-container-shadow", buildShadow("container", fields.containerShadowColor, fields.containerShadowIntensity));

            // --ai-chat-primary wird von der Komponente dagegen NUR aus dem
            // "primary-color"-Attribut heraus in ihr eigenes :host { --ai-chat-primary: ... }
            // hineingerendert (siehe render() in assets/ai-chat.js) - das ueberschreibt/
            // "beschattet" jeden von aussen gesetzten Wert des gleichnamigen Custom-
            // Property. Ein Update wirkt hier deshalb nur durch ein erneutes render() +
            // setupEventListeners() der Komponente selbst (unschaedlich, siehe
            // Kommentar an der <ai-chat>-Definition oben - kein Netzwerk-Aufruf darin).
            if (changed.primary && fields.primary) {
                // render() baut den Shadow-DOM komplett neu und faellt dabei immer auf den
                // geschlossenen Ausgangszustand zurueck (siehe render(), Zeile ~1480 in
                // assets/ai-chat.js) - im jetzigen mode="bubble" (anders als im frueheren,
                // permanent offenen mode="inline") wuerde eine gerade GEOEFFNETE Vorschau
                // beim Aendern der Akzentfarbe sichtbar wieder zuklappen. Offenen Zustand
                // deshalb merken und nach dem Neu-Rendern wiederherstellen.
                var wasOpen = !!preview.shadowRoot.querySelector(".chat-container.open");

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

                if (wasOpen) {
                    var toggleBtn = preview.shadowRoot.querySelector(".chat-toggle");
                    var reopenedContainer = preview.shadowRoot.querySelector(".chat-container");
                    if (reopenedContainer) reopenedContainer.classList.add("open");
                    if (toggleBtn) toggleBtn.classList.add("open");
                }
            }
        }

        apply();
        setInterval(apply, 150);

        // Vorschau laeuft bereits im echten mode="bubble" - der Testbutton muss dafuer
        // nicht mehr umschalten, sondern spielt nur einmal Zu-/Aufklappen durch (per
        // .open-Klasse direkt, statt ueber preview.toggleChat() - so bleibt der
        // tatsaechliche Oeffnen/Schliessen-Zustand der Vorschau fuer den Redakteur
        // unangetastet, falls er sie gerade selbst per Klick geoeffnet hatte).
        var animationTestBtn = document.getElementById("ai-chat-theme-animation-test");
        if (animationTestBtn) {
            animationTestBtn.addEventListener("click", function () {
                var container = preview.shadowRoot.querySelector(".chat-container");
                var toggleBtn = preview.shadowRoot.querySelector(".chat-toggle");
                if (!container) return;
                var wasOpen = container.classList.contains("open");
                container.classList.remove("open");
                if (toggleBtn) toggleBtn.classList.remove("open");
                window.setTimeout(function () {
                    container.classList.add("open");
                    if (toggleBtn) toggleBtn.classList.add("open");
                }, 50);
                if (!wasOpen) {
                    window.setTimeout(function () {
                        container.classList.remove("open");
                        if (toggleBtn) toggleBtn.classList.remove("open");
                    }, 1800);
                }
            });
        }
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

    // Sichtbar machen, wenn Profile ohne eigene Theme-Auswahl aktuell auf feste
    // Hartcode-Farben statt eines konfigurierten Themes zurueckfallen (ProfileTheme::
    // resolveTheme() liefert in beiden Faellen still null, siehe dortiger Kommentar) -
    // entweder weil noch nie ein Theme angelegt wurde, oder weil das als Standard
    // hinterlegte Theme zwischenzeitlich geloescht wurde (verwaiste default_theme_id).
    $themeCount = (int) rex_sql::factory()->setQuery('SELECT COUNT(*) AS c FROM ' . rex::getTable('ai_chat_theme'))->getValue('c');
    if (0 === $themeCount) {
        echo rex_view::warning('Es ist noch kein Theme angelegt. Profile ohne eigene Theme-Auswahl verwenden aktuell feste Hartcode-Farben. <a href="' . rex_url::currentBackendPage(['func' => 'add']) . '">Erstes Theme anlegen</a>.');
    } elseif ($defaultThemeId > 0 && null === (new ThemeRepository())->find($defaultThemeId)) {
        echo rex_view::warning('Das als globales Standard-Theme hinterlegte Theme (ID ' . $defaultThemeId . ') existiert nicht mehr. Bitte unten ein anderes Theme als Standard setzen.');
    } elseif (0 === $defaultThemeId) {
        echo rex_view::warning('Es ist noch kein globales Standard-Theme festgelegt - Profile ohne eigene Theme-Auswahl verwenden aktuell feste Hartcode-Farben.');
    }

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
    $fragment->setVar('title', 'Themes', false);
    $fragment->setVar('content', $content, false);
    echo $fragment->parse('core/page/section.php');
}
