// Gemeinsamer Übersetzungs-Loader für Chat- und Such-Widget (siehe
// FriendsOfRedaxo\AiChat\Service\WidgetTranslator für die Server-Seite und
// assets/i18n/*.json für die Quelldateien). Bewusst ein einfaches globales
// Objekt statt ES-Modul, da ai-chat.js als type="module" läuft, ai-search.js
// aber als klassisches Script - ein globaler Namespace funktioniert in beiden.
(function () {
    var cache = {};

    // Pro Locale nur einmal laden, auch wenn Chat UND Suche auf derselben
    // Seite gleichzeitig danach fragen.
    function load(locale) {
        locale = (locale || 'de').toLowerCase();
        if (!cache[locale]) {
            cache[locale] = fetch('index.php?rex-api-call=ai_chat_widget_translations&locale=' + encodeURIComponent(locale))
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('i18n fetch failed: ' + response.status);
                    }
                    return response.json();
                })
                .catch(function () {
                    // Laden fehlgeschlagen (Netzwerk, o.ä.) - t() faellt dann
                    // pro Aufruf einfach auf den mitgegebenen Fallback-Text
                    // zurueck, nichts bricht dadurch.
                    return {};
                });
        }
        return cache[locale];
    }

    // dict: das per load() aufgeloeste Übersetzungs-Objekt (oder null/undefined,
    // solange es noch laedt). fallback: deutscher Text, falls der Schlüssel
    // fehlt. vars: optionale {name: wert}-Ersetzungen für "{name}"-Platzhalter.
    function t(dict, key, fallback, vars) {
        var text = (dict && Object.prototype.hasOwnProperty.call(dict, key)) ? dict[key] : (fallback || key);
        if (vars) {
            for (var name in vars) {
                if (Object.prototype.hasOwnProperty.call(vars, name)) {
                    text = text.split('{' + name + '}').join(String(vars[name]));
                }
            }
        }
        return text;
    }

    window.AiChatI18n = { load: load, t: t };
})();
