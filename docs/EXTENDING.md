# Erweiterungspunkte

`ai_chat` bietet vier eigene `rex_extension`-Punkte für Dritt-Addons. Alle
vier feuern über `rex_extension::registerPoint()` - ein Listener wird über
das übliche `rex_extension::register('NAME', callable)` registriert und
bekommt einen `rex_extension_point` übergeben, dessen `getSubject()` den
aktuellen Wert liefert und dessen Rückgabewert (sofern nicht `null`) den
Wert für den weiteren Ablauf ersetzt.

## `AI_CHAT_PROFILE_CANDIDATES`

**Wann:** Bei jeder Profil-Auflösung fürs Frontend, bevor aus mehreren
gleichzeitig passenden Profilen (Domain/Sprache/Rolle) das mit der höchsten
Priorität gewählt wird.

**Wo im Code:** `lib/Profile/ProfileResolver.php:66-72` (Methode
`applyExtensionPoint()`).

**Subject:** `list<ChatProfile>` - die bereits nach Domain/Sprache/Rolle
gefilterte Kandidatenliste.

**Parameter:** `context` (string, z.B. `'frontend'`).

**Zweck:** eigene, über die reine Domain-/Sprach-Filterung hinausgehende
Auswahllogik einbringen (z.B. Kundengruppen-Zuordnung), ohne den Resolver
selbst zu patchen.

```php
rex_extension::register('AI_CHAT_PROFILE_CANDIDATES', static function (rex_extension_point $ep) {
    $candidates = $ep->getSubject();
    // z.B. nach eigenem Kriterium weiter einschränken/umsortieren
    return $candidates;
});
```

**Fallstrick:** die Rückgabe muss ein `list<ChatProfile>` sein - liefert der
Listener etwas anderes zurück (z.B. `null` oder ein Objekt), wird das
stillschweigend verworfen und die ursprüngliche Kandidatenliste bleibt
unverändert (kein Fehler, kein Log-Eintrag).

## `AI_CHAT_CONTENT_PROVIDERS`

**Wann:** Beim Aufbau der `ContentProviderRegistry`, direkt nach der
Registrierung der eingebauten Provider (`forcal`, `yform`, `mediapool`,
optional `knowledgebase`).

**Wo im Code:** `lib/ContentProvider/ContentProviderRegistry.php:37-41`
(Konstruktor).

**Subject:** `array<string, ContentProviderInterface>` - Provider-Key auf
Provider-Instanz.

**Parameter:** `registry` (die `ContentProviderRegistry`-Instanz selbst).

**Zweck:** eigene Wissensquellen (z.B. eine externe API, ein eigenes
Datenbankschema) als zusätzliche `source_type`s anbieten, die Redakteure
dann in der Profil-Konfiguration wie jede andere Quelle wählen können.

```php
rex_extension::register('AI_CHAT_CONTENT_PROVIDERS', static function (rex_extension_point $ep) {
    $providers = $ep->getSubject();
    $providers['mein_addon'] = new MeinContentProvider();
    return $providers;
});
```

**Fallstrick:** jeder zurückgegebene Eintrag muss tatsächlich
`ContentProviderInterface` implementieren - die Registry verwirft
(`instanceof`-Check) alles andere kommentarlos, statt einen Fehler zu
werfen. Ein Tippfehler in der Klasse fällt also nur auf, wenn die eigene
Quelle im Backend nicht wie erwartet auftaucht.

## `AI_CHAT_WIDGET_TRANSLATIONS`

**Wann:** Beim Laden der i18n-Übersetzungen für die Frontend-Widget-Oberfläche
(Buttons, Platzhalter, Statusmeldungen - unabhängig von der Sprache der
KI-Antworten selbst).

**Wo im Code:** `lib/Service/WidgetTranslator.php:38-44` (Methode `load()`).

**Subject:** `array<string, string>` - bereits mit der deutschen
Fallback-Sprache gemergte Übersetzungstabelle für die angeforderte Sprache.

**Parameter:** `locale` (string, z.B. `'en'`, `'de-at'`).

**Zweck:** zusätzliche Sprachen oder einzelne fehlende Schlüssel nachliefern,
ohne eine eigene Datei unter `assets/i18n/` in diesem Addon anzulegen (z.B.
wenn ein Dritt-Addon selbst mehrsprachig ausgeliefert wird und seine
Übersetzungen zentral verwalten will).

```php
rex_extension::register('AI_CHAT_WIDGET_TRANSLATIONS', static function (rex_extension_point $ep) {
    $translations = $ep->getSubject();
    if ('fr' === $ep->getParam('locale')) {
        $translations['greeting_fallback'] = 'Bonjour ! Comment puis-je vous aider ?';
    }
    return $translations;
});
```

**Fallstrick:** die Rückgabe muss weiterhin ein `array<string, string>` sein
und sollte die bereits vorhandenen Schlüssel nicht versehentlich entfernen -
am sichersten per `array_merge($translations, [...])` statt eines
Neuaufbaus. Ein Nicht-Array-Rückgabewert wird verworfen, die ursprüngliche
Übersetzungstabelle bleibt unverändert.

## `AI_CHAT_REGISTER_PROVIDERS`

**Wann:** Beim Erzeugen des aktiven KI-Providers (`AiServiceFactory::create()`),
vor der Auflösung des konfigurierten Provider-Keys zur passenden Klasse.

**Wo im Code:** `lib/Service/AiServiceFactory.php:37`.

**Subject:** `array<string, class-string<AiServiceInterface>>` - Mapping
Provider-Key auf Klassenname (Standard: `gemini`, `cloudflare`, `openai`,
`openai_compatible`, `ai_platform`).

**Parameter:** keine.

**Zweck:** einen eigenen KI-Provider unter einem neuen Schlüssel registrieren
(z.B. Anthropic direkt, ohne den Umweg über `ai_platform`), der dann in den
Provider-Einstellungen wählbar wird.

```php
rex_extension::register('AI_CHAT_REGISTER_PROVIDERS', static function (rex_extension_point $ep) {
    $providers = $ep->getSubject();
    $providers['mein_provider'] = MeinAiService::class;
    return $providers;
});
```

**Fallstrick:** die registrierte Klasse muss `AiServiceInterface`
implementieren - tut sie das nicht, fällt `AiServiceFactory::create()`
stillschweigend auf `GeminiService` zurück (kein Fehler, keine Exception).
Ein falsch registrierter Provider-Key zeigt sich also erst dadurch, dass
Anfragen unerwartet gegen Gemini statt gegen den eigenen Provider laufen.
