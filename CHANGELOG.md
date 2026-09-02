# Changelog

## [Unreleased]

### Behoben (Theme-Farbfelder im Profil zeigten Schwarz statt der globalen Farbe)
- **`input[type=color]`-Felder ("leer = globale Farbe") zeigten bei leerem Wert das irreführende Browser-Standard-Schwarz** statt der tatsächlich wirksamen globalen Farbe – jetzt visuell mit der aufgelösten globalen Farbe vorbefüllt (`data-inherited="true"`), ohne dass unbearbeitete Felder dadurch zu echten Werten werden. "Theme zurücksetzen" nutzt denselben Mechanismus.
- **Nebenbei gefunden**: `input[type=color].value` lässt sich per JS nicht auf einen Leerstring setzen (der Browser lehnt das still ab) – das ursprüngliche Zurücksetzen-beim-Speichern schlug dadurch fehl und speicherte den zuvor nur zur Anzeige gedachten Farbwert als echten Override. Gelöst über ein verstecktes Ersatzfeld, das beim Speichern anstelle eines unberührten, nur zur Anzeige vorbefüllten Farbfelds den leeren Wert überträgt.
- **Bereits durch den ursprünglichen Bug korrumpierte Werte** (mehrere `theme_*`-Spalten des Standard-Profils waren durch frühere Testläufe fälschlich auf `#000000` bzw. eine falsche Testfarbe gesetzt) direkt in der Datenbank bereinigt.
- **Ein Eckenradius von `0` (eckige Ecken) wurde ignoriert und fiel auf den globalen Wert zurück**: `ProfileTheme::buildInlineStyle()` nutzte `?:`, und PHP behandelt den String `"0"` als falsy – eine bewusst eingegebene `0` sah für den Code damit wie "leer" aus. Auf einen expliziten Leerstring-Check umgestellt.

### Behoben (Suche: KI-Zusammenstellung blockierte die Trefferliste, weitere UX-Lücken)
- **Die optionale "KI-Zusammenstellung" (`search_ai_summary_enabled`) ließ jede Suche auf einen vollständigen KI-Aufruf warten, bevor überhaupt Treffer sichtbar wurden** – fühlte sich nicht mehr wie Live-Suche an. Läuft jetzt asynchron: Treffer erscheinen sofort, die Zusammenstellung wird über einen separaten Folgeaufruf (`mode=search_summary`, nutzt die bereits vorliegenden Treffer statt erneut in der Datenbank zu suchen) nachgeladen – exakt das bereits bestehende Muster für "KI beantwortet erkannte Fragen" (`shouldAskChat()`/`fetchChatAnswer()`).
- **KI-Zusammenstellung und eine echte Frage-Antwort trugen dieselbe Beschriftung "Antwort"** – die Zusammenstellung heißt jetzt "Überblick über die Bereiche" und hat eine eigene, gestrichelte Gestaltung, um sie erkennbar von einer direkten Antwort auf die gestellte Frage zu unterscheiden.
- **`ai_chat_query` gab bei einem eingeloggten Backend-Nutzer die PHP-Session nie frei** (nur der SSE-Streaming-Zweig tat das bereits) – nebeneinanderlaufende, session-gebundene Anfragen (z.B. die Backend-Admin-Leiste beim Testen im Frontend) konnten sich dadurch gegenseitig blockieren. Jetzt wie im Streaming-Zweig direkt zu Beginn freigegeben.
- **Fehlender "Suchen"-Button** ergänzt (Live-Suche mit Debounce bleibt der Regelfall, der Button löst sofort aus).
- **Das Suchfenster schloss sich manchmal ungewollt beim Markieren von Text im Suchfeld** – eine Text-Selektion per Maus-Drag, die kurz über den Rand des Feldes hinausgeht, konnte beim Loslassen auf dem Hintergrund enden und wurde fälschlich als Klick-zum-Schließen gewertet. Schließt jetzt nur noch, wenn sowohl Drücken als auch Loslassen auf dem Hintergrund selbst lagen.

### Neu (Antwortsprache je Profil)
- **Neues optionales Profil-Feld "Antwortsprache der KI"**: lässt die KI unabhängig von der Oberflächen-Sprache (`ui_language`) in einer festgelegten Sprache antworten (z.B. "Englisch"), providerübergreifend (Gemini/Cloudflare/OpenAI-kompatibel/ai_platform). Leer = unverändertes Verhalten (Deutsch). Wirkt auch auf vorgeschlagene Folgefragen. Eine milde Anweisung reichte im Test nicht aus (das Modell blieb bei starkem deutschsprachigem Kontext trotzdem beim Deutsch) – die Formulierung adressiert das jetzt explizit ("AUCH WENN Frage und Kontext auf Deutsch sind").

### Behoben (Indexierung: Navigations-/Menü-Text landete im Index, Titel mit störendem "(N)"-Suffix)
- **Chunk-Titel trugen einen technischen "(N)"-Suffix** (z.B. "Referenzen :: KLXM Crossmedia (6)"), der als Rest einer internen Chunk-Nummerierung ohne funktionalen Zweck direkt in Suchergebnissen sichtbar war – entfernt für alle betroffenen Quellenarten (Sitemap-URLs, GitHub-/Addon-Docs, eigene HTTP-gecrawlte Artikel).
- **`index_http_exclude_selectors` (Ausschluss-Selektoren) wurde für Sitemap-URLs nie ausgewertet** – nur der parallele HTTP-Crawl-Zweig für eigene Artikel nutzte die Einstellung. Wiederkehrende Navigations-/Off-Canvas-Menü-Texte (z.B. `<div id="sidebar-navi">`, kein `<nav>`-Tag) landeten dadurch bei jeder indexierten externen Seite im Kontext. `indexUrl()` unterstützt jetzt außerdem – wie der andere Zweig – mehrere kommagetrennte Haupt-Selektoren mit mehreren Treffern statt nur des ersten Treffers des ersten Selektors.

### Behoben (FAQ-Cache füllte sich bei aktiver Personalisierung nie)
- **Bei aktiver "Sie/Du"-Personalisierungsabfrage wurde nie etwas gecacht**: Die Personalisierungsantwort ("Du"/"Sie") wurde als normale Nutzer-Nachricht in die an den Server gesendete Konversationshistorie aufgenommen – `ChatQueryService::process()` erkannte die eigentliche erste Frage dadurch nie als Konversationsanfang (`!$hasPreviousUserMessage`), wodurch `faq_precache_enabled` faktisch wirkungslos blieb, sobald Personalisierung aktiv war (Standardeinstellung). `ai-chat.js` schließt die Personalisierungs-Meta-Nachrichten jetzt von der gesendeten Historie aus.

### Behoben (Übersetzungs-Endpunkt lieferte HTTP 404 trotz korrektem Inhalt)
- **`ai_chat_widget_translations` antwortete mit Status 404**, obwohl der JSON-Body korrekt befüllt war – bei einer `rex-api-call`-Anfrage auf einer yrewrite-Domain ist der HTTP-Status vor dem eigentlichen Dispatch bereits auf 404 vorbelegt; `ChatQuery` setzte ihn schon explizit zurück, dieser (später hinzugekommene) Endpunkt noch nicht. Betraf u.a. das Nachladen der Widget-Oberflächen-Übersetzungen im Frontend. `ai_chat_reindex_worker` hatte dieselbe Lücke (dort praktisch folgenlos, da die Antwort vom aufrufenden Hintergrundprozess ignoriert wird) – aus Konsistenz ebenfalls korrigiert.

### Neu (Missbrauchsschutz, Upkeep-Anbindung)
- **Prompt-Injection-/Jailbreak-Erkennung**: neuer Guard erkennt Muster wie "ignoriere alle vorherigen Anweisungen" oder Versuche, den Systemprompt offenzulegen – wirkt für Chat UND Suche gleichermaßen, zusätzlich zum bestehenden Schutz gegen SQL-/Code-Injection.
- **Wiederholte Angriffsversuche beenden die Konversation freundlich** statt sie hart abzublocken: Ab dem dritten erkannten Angriffsversuch im selben Verlauf (Code- und Prompt-Injection zusammengezählt) antwortet der Chat mit einer höflichen Abschluss-Nachricht.
- **Erkannte Angriffe lösen eine temporäre ("weiche") IP-Sperre über das `upkeep`-Addon aus** (1 Stunde, nicht dauerhaft), sofern `upkeep` installiert ist – rein über dessen öffentliche API, `upkeep` selbst wurde nicht verändert.
- **Sinnlose Suchanfragen werden erkannt**, bevor sie die KI belasten (leer, nur Sonderzeichen, zu kurz, Kauderwelsch) – Chat antwortet dann mit einer freundlichen Rückfrage statt eines KI-Aufrufs; die Suche überspringt bei erkanntem Kauderwelsch ihren bestehenden KI-Fallback für ergebnislose Suchen.

### Neu (Benannte Sitemap-Bereiche)
- **Sitemaps lassen sich pro Profil jetzt in benannte Gruppen aufteilen** (z.B. "Allgemein", "News") statt einer einzelnen Liste – neue Repeater-Oberfläche auf der Profil-Seite. Suchergebnisse und Chat-Kontext tragen diesen Namen jetzt mit (neue Facetten-Zeile in der Suche neben den bestehenden Typ-Filtern, `[Bereich: ...]`-Hinweis im KI-Kontext) – hilft der KI und den Suchenden, Treffer thematisch statt nur nach Ähnlichkeitswert einzuordnen. Bestehende Profile mit einer einzelnen (unbenannten) Sitemap werden beim Update automatisch in eine Gruppe überführt, kein Datenverlust.

### Neu (Profil-Kollisions-Hinweis, Chat ohne Bubble)
- **Profile → Liste warnt jetzt vor Prioritäts-Kollisionen**: Zwei aktivierte Profile, die sich bei GLEICHER Priorität überschneiden könnten (Kontext/Rolle/Domain/Sprache), werden als Hinweis angezeigt - sonst entscheidet unbemerkt nur die niedrigere Profil-ID.
- **Neuer Anzeigemodus „Ohne Bubble"** (`frontend_mode = anchored`, AI Chat → Darstellung): kein schwebender Chat-Button - der Chat öffnet sich stattdessen an einem selbst platzierten Seitenelement mit der Klasse `aichat` oder der ID `aichat` (z.B. ein eigener Button im Menü). Klappt automatisch nach oben oder unten auf, je nachdem wo auf dem Bildschirm gerade Platz ist, und bleibt an dieser Position fixiert (kein Nachführen beim Scrollen). Auf schmalen Bildschirmen (< 600px) wie die anderen Modi automatisch Vollbild.

### Neu (Natives Vektor-Retrieval)
- **MariaDB `VECTOR`/`VECTOR INDEX` wird automatisch genutzt, wenn verfügbar** (ab 11.7 Preview/11.8 GA, per `VECTOR INDEX INDEX = VEC_DISTANCE_COSINE()`) - deutlich schnellere Ähnlichkeitssuche als die bisherige PHP-Schleife bei großem Index. Capability-detected (`VectorCapability`, "Neu prüfen"-Button auf AI Chat → Indexierung), die bisherige PHP-Brute-Force-Berechnung bleibt als dauerhafter Fallback erhalten (wichtig für Installationen auf älterem MariaDB/MySQL) - beide stehen hinter einem gemeinsamen `RetrievalStrategyInterface`, keine Verhaltensänderung für Scope-/Profil-/aktuelle-URL-Filterung.
- Spalte/Index werden beim vollen Reindex automatisch angelegt/aktualisiert, inkl. sauberem Neuaufbau bei gewechseltem Embedding-Modell (andere Vektor-Dimension).

### Neu (Profile/Scope-Editor)
- **Mehrere Chat-Profile statt einer globalen Konfiguration**: Neue Tabelle `ai_chat_profile` – jedes Profil hat eigenen Kontext (Frontend/Backend/Beides), eigene Sichtbarkeit (Besucher/Redakteure/Admins), eigene Zielgruppe (alle/bestimmte Domains/bestimmte Sprachen), eigenen Prompt, eigene Begrüßung/Reset-Countdown/Verlauf-kopieren-Einstellung und ein `ui-language`-Feld (aktuell ohne Wirkung, siehe TODO.md). Ein Default-Profil wird bei der Installation automatisch angelegt, sodass ein frischer Install ohne weitere Konfiguration sofort einen funktionierenden Chat zeigt.
- **Wissens-Scope pro Profil**: Die bestehende globale Indexierung bleibt unverändert als „Shared Pool" bestehen (`profile_id IS NULL` in `ai_chat_index`). Ein Profil sieht diesen Pool zusätzlich zu seinen eigenen Quellen, wenn „Nutze Shared Scope" aktiviert ist – oder ausschließlich seine eigenen, exklusiv zugeordneten Inhalte, wenn nicht (vollständig isolierter Wissensstand).
- **Profile indexieren jetzt tatsächlich eigene Quellen**: `IndexerService` läuft zusätzlich zum globalen Shared-Pool-Scan jetzt auch profil-eigene Quellen ab – eine Auswahl bestimmter YForm-Mappings, eine eigene Sitemap, oder ein Struktur-Mountpoint (Kategorie-Teilbaum). Jeder so entstandene Inhalts-Chunk wird exklusiv dem jeweiligen Profil zugeordnet. Bekannte Einschränkung bei Überschneidung mit dem globalen Scan sowie bei der event-getriebenen Einzelartikel-Neuindizierung siehe TODO.md.
- **Eigener Prompt pro Profil**: `ChatProfile::$customPrompt` ersetzt bei Bedarf den global konfigurierten Frontend-Prompt, providerübergreifend (Gemini, OpenAI-kompatibel, Cloudflare, ai_platform). Wirkt nur im Frontend-Zweig – der feste Developer-Systemprompt (inkl. der `[[ACTION:...]]`-Anweisungen für die Backend-Chat-Werkzeuge) bleibt unverändert, damit dessen Funktionalität nicht versehentlich durch einen freien Prompt überschrieben wird.
- **`boot.php` löst jetzt pro Aufruf EIN Profil auf** (`ProfileResolver`) und steuert damit Chat UND Suche im Frontend gemeinsam – beide sind derselbe Scope, nicht zwei getrennte Sichtbarkeits-Systeme. `frontend_enabled`/`frontend_search_enabled` bleiben die zwei unabhängigen Ein/Aus-Schalter innerhalb des vom Profil bestimmten Scopes (z.B. nur Suche ohne Chat-Bubble). Die alten globalen Einstellungen „Sichtbarkeit im Frontend" (Testmodus), „Domains" und „Sprachen" sind dadurch vollständig durch die Profil-Felder ersetzt und aus den Einstellungen entfernt – ein Hinweistext verlinkt jetzt auf die neue Profile-Seite.
- **Neue Backend-Seite „Profile"** zum Anlegen/Bearbeiten/Löschen von Profilen – interaktiv (Kontext/Zielgruppe-/Quelle-abhängige Felder blenden sich automatisch ein/aus, z.B. Domains/Sprachen nur bei passender Zielgruppe, Sitemap-/Mountpoint-Feld nur bei passender Quelle, der ganze Zielgruppen-Block nur außerhalb von Kontext "Backend") und mit erklärendem Info-Panel + Tooltips zu den weniger offensichtlichen Feldern.
- **Erweiterbarkeit**: neuer Extension Point `AI_CHAT_PROFILE_CANDIDATES`, über den Dritt-Addons die Profil-Auswahl vor der finalen Entscheidung filtern/umsortieren können.
- **Anrede und Personalisierung jetzt ebenfalls je Profil**: `ChatProfile::$addressingMode`/`$personalizationMode` wirken jetzt serverseitig (vorher nur `$customPrompt`) – providerübergreifend über einen neuen, optionalen `$addressingModeOverride`-Parameter analog zum bestehenden Prompt-Override. Ohne aufgelöstes Profil (z.B. Developer-Chat) bleibt die globale Einstellung wie bisher maßgeblich.

### Behoben (Profil ließ sich nicht aktivieren)
- **`rex_form`-Checkbox-Falle**: `pages/profiles.php` band `status`/`use_shared_scope`/`chat_copy_history` zunächst über `addCheckboxField()` direkt an echte `tinyint(1)`-Spalten. REDAXOs `rex_form_element::setValue()` wandelt ein angehaktes Checkbox-Feld (kommt im POST immer als Array an, auch bei nur einer Option) in einen von Pipes umschlossenen String um (`"|1|"`) – das landet als nicht-numerischer String in der Spalte und wird beim Speichern stillschweigend zu `0`. Ergebnis: Ein Profil ließ sich nie aktivieren, egal was in der Checkbox stand. Jetzt werden diese drei Felder über normale (nicht-multiple) Select-Felder gepflegt, die diesen Array-Umweg nicht nehmen. Das bereits dadurch auf `status=0`/`use_shared_scope=0` korrumpierte Standard-Profil wurde direkt in der Datenbank repariert.

### Neu (YForm-Sprachfilter)
- **Optionaler Sprachfilter je YForm-Mapping**: Neue Felder „Sprach-Spalte" und „Sprachen (clang-IDs)" auf AI Chat → YForm-Tabellen. Nur wirksam, wenn eine Sprach-Spalte gewählt wurde – ohne Auswahl unverändertes Verhalten (alle Sprachen), keine stille Änderung für bestehende Mappings.

### Neu (Übersetzbare Widget-Oberfläche)
- **Chat- und Such-Widget sind jetzt übersetzbar** (Buttons, Platzhalter, Statusmeldungen – nicht die KI-Antwort selbst): Neue JSON-Sprachdateien (`assets/i18n/de.json`, `en.json`), ein PHP-Loader (`WidgetTranslator`, mit Extension Point `AI_CHAT_WIDGET_TRANSLATIONS` für Dritt-Addons) sowie ein gemeinsamer, minimaler JS-Loader für Chat und Suche (`assets/ai-i18n.js`). Gesteuert über das `ui-language`-Feld des jeweiligen Profils. Neue Sprache hinzufügen = neue JSON-Datei anlegen, kein Code nötig; fehlende Schlüssel fallen automatisch auf Deutsch zurück. Statische Widget-Texte werden erst nach dem asynchronen Laden gepatcht – die Anzeige bleibt für den deutschsprachigen Regelfall weiterhin sofort da, ohne Warten/Flackern.
- **Nebenbei gefunden und behoben**: `window.KlxmSearch` (öffentliches JS-API der Suche) war beim Rebrand nur in der README umbenannt worden, im tatsächlichen Code aber noch beim alten Namen – jetzt konsistent `window.AiSearch`.

### Neu (Profil testen, Theme je Profil, Chat/Suche je Profil)
- **"Profil testen"**: Jede Profil-Bearbeitungsseite zeigt rechts ein echtes, sticky positioniertes `<ai-chat>`-Widget fest gebunden an genau dieses Profil (Frontend/Developer-Scope umschaltbar) – testet den gespeicherten Stand inkl. Prompt, Anrede, Wissens-Scope und Theme, ohne auf die passende Domain/Sprache/Rolle angewiesen zu sein.
- **Theme je Profil**: 8 neue optionale Felder (Akzentfarbe, vier weitere Farben, Eckenradius, Position, Avatar) überschreiben die globale Darstellung nur für dieses Profil – leer gelassen gilt weiterhin die globale Einstellung. Die Farbfelder + Eckenradius aktualisieren das Test-Widget schon beim Tippen live, ein "Theme zurücksetzen"-Button leert alle Felder auf einmal.
- **Chat/Suche je Profil erzwingbar**: neue Felder "Chat automatisch einbinden"/"Suche automatisch einbinden" (Globale Einstellung/Ja/Nein) – erlauben z.B. ein Profil mit ausschließlich Suche, ohne die globale Einstellung für andere Profile zu ändern.

### Behoben (Sicherheit: profile_id ließ sich vom Client erschleichen)
- **`ChatQueryService` vertraute der vom Client mitgeschickten `profile_id` blind**: Ein Besucher hätte sich per manipulierter ID theoretisch ein fremdes Profil (andere Domain/Sprache, backend-only, deaktiviert) samt dessen Prompt und exklusivem Wissens-Scope erschleichen können. Echte Frontend-Anfragen (kein authentifizierter Backend-Nutzer) bekommen ihr Profil jetzt serverseitig neu zugeordnet; nur Backend-Nutzer dürfen eines explizit wählen (nötig für "Profil testen" und den Backend-Chat).

### Behoben (Checkbox-Einstellungen ließen sich nie dauerhaft deaktivieren)
- **`rex_config_form`-Checkbox-Falle** (siehe [KLXM/klxmchat#23](https://github.com/KLXM/klxmchat/issues/23)): Fünf globale Einstellungen (Live-Reindizierung, Frontend-Suche, Statistik-Logging, Struktur-Indexierung, yrewrite-SEO-Respekt) ließen sich nie dauerhaft deaktivieren – eine deaktivierte Checkbox wird bei `rex_config_form` als PHP `null` gespeichert, und `rex_config::get($key, $default)` behandelt gespeichertes `null` wie "nicht gesetzt" und fällt immer auf den (meist aktiven) Default zurück. Auf Select (Ja/Nein) umgestellt.
- Weitere tote Einstellungsreste aufgeräumt: das vollständig durch Profile abgelöste `frontend_visibility` wurde serverseitig noch ausgewertet (jetzt entfernt), die Backend-/Frontend-Reset-Countdown/Verlauf-kopieren-Einstellungen kommen längst nur noch aus dem Profil (aus den globalen Einstellungen entfernt), und "Scope-Switch erlauben" war in `boot.php` hart auf aus verdrahtet, obwohl aktiv einstellbar (jetzt verdrahtet).

## [1.0.0] - 2026-09-02

Kompletter Neuanfang: Das Addon heißt jetzt `ai_chat` (vormals `klxmchat`/„KLXM
Chat & Search"), ist als FriendsOfREDAXO-AddOn aufgesetzt und beginnt seine
Versionierung bei 1.0.0. Keine automatische Migration bestehender
`klxmchat`-Installationen (Daten/Config bleiben unangetastet, `ai_chat` startet
leer) – siehe README für Details.

- **Rebrand**: Verzeichnis, PHP-Namespace (`FriendsOfRedaxo\AiChat`),
  DB-Tabellen (`ai_chat_*`), Config-Namespace, Permission (`ai_chat[...]`),
  `rex-api-call`-Endpunktnamen (`ai_chat_query`/`_index`/`_test`/
  `_cloudflare_models`/`_reindex_worker`), Custom-Element-Tags
  (`<ai-chat>`/`<ai-search>`) und Asset-Dateinamen einheitlich umbenannt.
- **Aufgeräumt**: tote Legacy-Widget-Dateien entfernt (`klxm-chat.js`, `-v2.js`,
  `-v3.js`, `-bundle.js` – geladen wurde ohnehin nur die vormalige `-v4.js`,
  jetzt `ai-chat.js`).
- **Extension Point umbenannt**: `KLXMCHAT_CONTENT_PROVIDERS` →
  `AI_CHAT_CONTENT_PROVIDERS`.
- Alle Funktionen und Fixes aus der `klxmchat`-Historie (siehe „Vor dem
  Rebrand" unten) sind inhaltlich weiterhin enthalten, nur unter neuem Namen.
- **Neuer Provider: `ai_platform`-Addon** – nutzt dessen zentrale, Symfony-AI-
  basierte Profilverwaltung (OpenAI/Anthropic/Google/Ollama/OpenAI-kompatibel)
  statt eigener API-Keys; eigene Profile für Text und Embedding je Zweck
  auswählbar. Nur sichtbar, wenn `ai_platform` installiert ist. Dabei
  `PromptBuilder` als gemeinsame Stelle für System-/User-Prompt-Aufbau (Scope,
  Personalisierung, RAG-Kontext) eingeführt, damit der neue Provider die
  bestehende Logik von Gemini/OpenAI-kompatibel/Cloudflare nicht ein viertes
  Mal dupliziert (die drei bestehenden Services bleiben unverändert).
- **Erweiterbarkeit**: `AiServiceFactory` hat jetzt einen Extension Point
  (`AI_CHAT_REGISTER_PROVIDERS`), über den Dritt-Addons eigene KI-Provider
  registrieren können, ohne die Factory zu patchen.
- **Robustere API-Responses**: die bereits für die Indexierungs-API vorhandene
  Absicherung gegen von PHP-Warnings korrumpierte JSON-Antworten
  (`ob_start()` + Buffer-Clean vor jedem Response) ist jetzt in einem
  gemeinsamen `JsonResponseTrait` konsolidiert und auf alle API-Endpunkte
  angewendet (Chat-Anfrage, Verbindungstest, Cloudflare-Modell-Liste,
  Hintergrund-Worker) – nicht nur auf die Indexierung wie zuvor.
- **Chat-Widget**: ein neuer Sende-Vorgang oder ein Reset bricht eine noch
  laufende (auch streamende) vorherige Antwort jetzt sauber per
  `AbortController` ab, statt beide Antworten um den Chat-Verlauf wettlaufen
  zu lassen; ein einzelner, echter Netzwerkfehler (nicht: eine reguläre
  Fehler-HTTP-Antwort) löst automatisch einen einmaligen Retry mit kurzer
  Verzögerung aus, bevor die „Verbindungsproblem"-Meldung erscheint.

## Vor dem Rebrand (`klxmchat`-Historie)

Die folgenden Einträge sind historisch und referenzieren noch den alten
Addon-Namen `klxmchat` bzw. alte Datei-/Tabellennamen. Sie bleiben als
Referenz stehen, wurden aber nicht auf `ai_chat` umgeschrieben.

## [2.0.1-beta1] - 2026-09-01

### Behoben (Frontend-Chat verschwand, sobald eine Domain/Sprache ausgewählt war)
- **Domain-/Sprachauswahl für die Frontend-Sichtbarkeit funktionierte nur, solange nichts ausgewählt war**: `rex_config_form` speichert Mehrfachauswahl-Felder nicht als PHP-Array, sondern als von Pipes umschlossenen String (`"|1|2|"`, Core-Verhalten von `rex_form_element::setValue()`). `boot.php` hat diesen String bisher naiv mit `(array) ...` gecastet – das wickelt den kompletten String als einziges Element in ein Array (`["|1|2|"]"`), wodurch der anschließende `in_array()`-Abgleich gegen die aktuelle Domain/Sprache nie zutraf. Ergebnis: Sobald mindestens eine Domain oder Sprache ausgewählt wurde, verschwand der Chat für wirklich alle Besucher, unabhängig von der tatsächlichen Auswahl – nur eine leere Auswahl (= keine Einschränkung) hat "funktioniert". Jetzt wird der von REDAXO verwendete Pipe-String korrekt geparst (`frontend_allowed_domains`/`frontend_allowed_clangs`), analog zum bereits an anderer Stelle im Addon verwendeten Muster für Mehrfachauswahl-Konfiguration.

### Behoben (Verbindungstest ignorierte ungespeicherte Provider-Auswahl)
- **„Verbindung testen" nutzte immer die gespeicherte Config, nie den aktuellen Formularstand**: Wurde z.B. der Provider von Gemini (Installationsstandard) auf OpenAI umgestellt und ein API-Key eingetragen, aber noch nicht gespeichert, testete der Button trotzdem weiterhin gegen die zuletzt gespeicherte Config – meldete dadurch fälschlich „Gemini API Key is missing", obwohl OpenAI ausgewählt und der Key eingetragen war. Jetzt sendet der Test-Button die aktuellen Feldwerte (Provider, alle API-Keys/URLs/Modelle) mit; `AiServiceFactory::create()` sowie alle drei Service-Klassen akzeptieren dafür optionale Config-Overrides, die vor dem Speichern verwendet werden. Nicht mitgesendete bzw. leere Felder fallen weiterhin auf die gespeicherte Config zurück, das normale Laufzeitverhalten (Chat/Suche/Indexierung) ist unverändert.

### Behoben (Suche erschien im Backend)
- **`klxm-search.js`/`klxm-search-backend-demo.js`/`klxm-search.css` wurden auf jeder Backend-Seite geladen statt nur auf der Demo-Seite** – im Gegensatz zu `klxm-yform-mapping.js`/den Settings-Styles, die im selben `boot.php` bereits korrekt auf ihre jeweilige Unterseite beschränkt waren. Da das Such-Overlay sein Markup per `document.body.appendChild()` außerhalb des von PJAX ersetzten Inhaltsbereichs anhängt, blieb eine einmal auf der Demo-Seite erzeugte Instanz beim Wegnavigieren bestehen und konnte auf jeder anderen Backend-Seite auftauchen. Jetzt genauso wie yform-mapping.js auf `klxmchat/demo` beschränkt.

### Geändert (Einstellungen neu geordnet)
- **Alle Sichtbarkeits-/Zugriffsschalter in einer Box gebündelt** (Zugriff & Steuerung): „Chat im Frontend“, „Suche im Frontend“, „Chat im Backend“, Testmodus, Domains, Sprachen und Erlaubte IPs standen bisher verstreut zwischen Personalisierungs- und UX-Einstellungen. Jetzt gemeinsam unter „Sichtbarkeit & Zugriff“, gleiche Optik wie die bestehenden Gruppierungen bei der Indexierung.
- **„API-Zugriff“ als eigener Abschnitt, mit Erklärung statt nur einer unkommentierten Checkbox**: „Public API Endpunkt aktivieren“ war bisher nicht erklärt – der Endpunkt läuft wirklich komplett offen (kein Token, keine Origin-Prüfung), das stand nirgends. Neuer Hinweistext macht das explizit, plus ein Verweis auf das separate „api“-Addon (API → Token, Scope `klxmchat/chat/query`) für authentifizierten statt öffentlichen Zugriff – der Token wird dort verwaltet, nicht in klxmchat selbst.
- **Personalisierung, Anrede und Chat-Verlauf-UX von „Zugriff & Steuerung" nach „Verhalten" verschoben**: Diese Felder steuern WIE geantwortet wird, nicht WER/WO Zugriff hat – standen bisher als lose, unbeschriftete Felder unterhalb der Sichtbarkeits-/API-Boxen. Die Verhalten-Seite ist dafür jetzt in drei klar beschriftete Boxen aufgeteilt: „Allgemein & Sicherheit" (Rate-Limit, Nachrichtenlängen, Datenschutz-/Spam-Filter, Quellenanzeige – gilt für Chat und Suche gleichermaßen, da beide über denselben `process()`-Einstieg laufen), „Chat" (Begrüßung, Personalisierung, Anrede, Prompt, Zusatzkontext, Fehlermeldung, Folgefragen, Streaming, Reset-Countdown/Verlauf kopieren für Backend- und Frontend-Chat) und „Suche" (nur aktuelle Seite durchsuchen, Quellentyp-Labels, Mehrfach-Kontext-Snippets) – vorher waren Chat- und Suche-spezifische Einstellungen unübersichtlich vermischt.

## [2.0.0] - 2026-09-01

Erste stabile 2.0.0-Version nach der Beta-Reihe (zuletzt 2.0.0-beta5). Enthält
alles, was während der Beta-Phase gesammelt wurde, plus die unten aufgeführten
Ergänzungen.

### Neu (Sitemap-Indexierung)
- **Mehrere Sitemaps gleichzeitig indexierbar**: „Sitemap-URLs“ (`index_sitemap_url`) ist jetzt ein Mehrzeilenfeld statt eines einzelnen Textfelds – eine URL pro Zeile, z.B. für getrennte Bereiche/Subdomains. URLs aus allen konfigurierten Sitemaps werden zusammengeführt und dedupliziert (auch über Sitemaps hinweg, nicht nur innerhalb einer einzelnen). Bestehende Einzel-URL-Konfigurationen funktionieren unverändert weiter. Der „Sitemap prüfen“-Button testet jetzt alle eingetragenen Zeilen einzeln und zeigt das Ergebnis pro URL.

### Behoben (Hintergrund-Indizierung startete nie)
- **Root Cause gefunden und behoben**: Der Selbst-Aufruf gegen `Api\ReindexWorker` in `handleStartBackground()` nutzte die öffentliche `rex::getServer()`-URL (Host + Port). In containerisierten Setups mit Portmapping (z.B. Docker: externer Port 9444 → interner Port 443) ist dieser Host:Port von INNERHALB des Containers schlicht nicht erreichbar ("Connection refused") – der Worker wurde dadurch nie aufgerufen, `IndexRunStore` blieb bei `processed:0, total:0` für immer auf „running" stehen, jeder weitere Versuch endete in „Es läuft bereits eine Hintergrund-Indizierung". Jetzt versucht `handleStartBackground()` beim Start drei Wege nacheinander (curl `||`-verkettet, erster Erfolg gewinnt): die öffentliche URL direkt (Regelfall bei normalem Hosting), Loopback auf Port 443 und auf Port 80 – jeweils mit `--connect-to`, damit Host-Header/SNI für virtuelles Hosting weiterhin zum öffentlichen Hostnamen passen. Live im FairPlay-Docker-Setup verifiziert.

### Behoben (Hintergrund-Indizierung)
- **„Es läuft bereits eine Hintergrund-Indizierung" konnte für immer bestehen bleiben**: Stirbt der Worker-Prozess ab, ohne seinen eigenen Abschluss-Status noch schreiben zu können (z.B. durch PHP-FPMs `request_terminate_timeout`, oder weil der Self-Loopback-Call den Worker auf manchen Docker-/Proxy-Setups gar nicht erst erreicht), blieb der Status dauerhaft auf „running" stehen und blockierte jeden weiteren Startversuch – ohne Möglichkeit, das über die Oberfläche selbst zu lösen. Jetzt: (1) `IndexRunStore::readEffective()` behandelt „running" ohne Fortschritts-Update seit mehr als 10 Minuten automatisch als fehlgeschlagen, ein neuer Lauf lässt sich danach von selbst wieder starten; (2) „Abbrechen" setzt den Status jetzt sofort auf „cancelled" statt nur ein Flag zu setzen, das ein bereits toter Worker nie sieht; (3) meldet der Start „läuft bereits", erscheint direkt ein „Hängenden Hintergrundlauf zurücksetzen und neu starten"-Button für die sofortige manuelle Lösung, statt auf die 10 Minuten zu warten.

### Neu (Domain-/Sprachauswahl für Frontend-Anzeige)
- **Neue Einstellungen „Domains" und „Sprachen"** (Zugriff & Steuerung): Chat und Suche im Frontend lassen sich jetzt auf bestimmte yrewrite-Domains und/oder Frontend-Sprachen beschränken (Mehrfachauswahl, leer = überall/alle wie bisher). „Domains" erscheint nur, wenn yrewrite installiert ist und mehr als eine Domain konfiguriert hat; „Sprachen" nur bei mehr als einer `rex_clang`. Reine Anzeige-Filterung in `boot.php` – die Indexierung selbst bleibt domain-unabhängig (siehe TODO.md).

### Neu (Fortschritts-Karte mit Donut-Spinner)
- **Zwei gegenläufig rotierende Ringe statt eines winzigen drehenden Icons**: Die Fortschrittsanzeige ist jetzt eine richtige Karte mit einem Donut-Spinner (gleiches Grundprinzip wie REDAXOs eigener PJAX-Ladeindikator, `be_style .rex-ajax-loader-element`), größerer Statuszeile und dezenter Live-Info darunter statt reinem Fließtext.
- **Karte reagiert farblich auf den Zustand**: Läuft/Fertig/Fertig mit Fehlern/Fehler/Abgebrochen bekommen jeweils einen passenden Farbakzent (sanfter Verlauf, kein knalliges Vollflächen-Rot/Grün).
- **Palette 1:1 aus dem REDAXO-Backend-Theme übernommen**: `$color-a-dark` (#324050), `$color-b` (#4b9ad9), `$color-d` (#5bb585) usw. direkt aus `be_style`s `_variables.scss`/`_variables-dark.scss`, inklusive derselben Dunkelmodus-Umschaltlogik (`body.rex-theme-dark` / `prefers-color-scheme: dark`) – fügt sich damit in die bestehende REDAXO-Farbwelt statt eigene Farben zu erfinden.
- **Live-Zahlen blitzen kurz auf, wenn sie sich ändern** (z.B. die Indexgröße) – macht sichtbar, dass gerade wirklich etwas passiert.

### Neu (Indexierungs-Seite überarbeitet)
- **Eine zentrale Status-Anzeige statt reinem Fließtext**: Neues Badge oben auf der Seite (Bereit/Läuft im Browser/Läuft im Hintergrund/Fertig/Fertig mit Fehlern/Abgebrochen/Fehler), über Bootstrap-„label"-Kontextklassen umgesetzt statt eigener Farben – dadurch automatisch Light-/Dark-Mode-kompatibel über das Backend-Theme.
- **Buttons in zwei klar getrennte Gruppen sortiert**: „Indexierung" (Jetzt indexieren, Im Hintergrund indexieren, Refresh, Abbrechen) und „Wartung" (Index leeren, Cache leeren, Cache vorwärmen) waren bisher eine einzige, ununterschiedene Reihe – jetzt optisch gruppiert mit Beschriftung, damit die selten gebrauchten/teils destruktiven Wartungsaktionen nicht mit der eigentlichen Indexierung verwechselt werden.
- **Hintergrundlauf wird nach dem Zurückkommen automatisch wieder angedockt**: Ruft man die Seite auf, während im Hintergrund noch eine Indexierung läuft (neuer Tab, PJAX-Navigation weg und zurück), wusste die UI davon bisher nichts – der Fortschritt kommt ja ausschließlich aus dem Server-Zustand, nicht aus dem (dann verworfenen) Browser-Zustand. Jetzt wird das beim Laden aktiv geprüft und die Fortschrittsanzeige automatisch fortgesetzt.

### Neu (Indexierung)
- **Eigener Button „Im Hintergrund indexieren“** neben „Jetzt indexieren“ – bewusst zwei getrennte Buttons statt einem, der sich beim Klick unsichtbar für einen von zwei grundverschiedenen Modi entscheidet (das war im ersten Entwurf so gelöst, aber am Button selbst nicht erkennbar, welcher Modus gerade läuft). Der Hintergrund-Button prüft beim Laden der Seite per `action=background_available`, ob `shell_exec()` sowie `curl` oder `wget` auf dem Server verfügbar sind (inkl. Fallback auf absolute Standardpfade für PHP-FPM-Pools mit geleertem `$PATH`, sowie ein Windows-Zweig über `popen('start /B ...')` analog zu ffmpeg's `Api\Converter`) und ist nur dann aktiv – der Grund für „nicht verfügbar“ steht als Tooltip am Button. Ist er aktiv: läuft die komplette Pipeline (GitHub-Sync, Index leeren, alle Quellen neu einlesen) serverseitig in einem einzigen, abgekoppelten Request (`Api\ReindexWorker`, Einmal-Token-abgesichert, kein PHP-Timeout durch `set_time_limit(0)`+`ignore_user_abort(true)`, gestartet via `curl`/`wget` statt eines auf vielen Hosts nicht zuverlässig auffindbaren PHP-CLI-Binaries – analog zum mediaplace-Thumbnail-Warmup), der Browser pollt nur noch den Fortschritt und zeigt einen deutlichen Hinweis, dass der Tab jetzt geschlossen werden kann. „Jetzt indexieren“ bleibt der bisherige, browsergesteuerte Ablauf, unverändert und ohne versteckte Modus-Umschaltung. Abbrechen funktioniert für beide über denselben Cancel-Button.
- **Neuer Konsolenbefehl `php redaxo/bin/console klxmchat:reindex`** für eine vollständige Neuindizierung direkt von der Kommandozeile (nutzt dieselbe neue `IndexerService::runFull()`-Pipeline wie der Hintergrundlauf).
- **Indexierungsstatus-Anzeige überarbeitet, um die bisher verwirrende Zahlenlage aufzulösen**: „Aufgaben“ (Artikel/URLs/Dateien/Provider-Einträge) und „Abschnitte“ (die daraus per Chunking entstehenden Zeilen im Index) wurden bisher munter vermischt angezeigt – z. B. zeigte die Fortschrittsanzeige mitten im Lauf eine Aufgaben-Zahl wie „128“, während am Ende 2000 Abschnitte im Index standen, was den Eindruck erweckte, die Indexierung würde nur einen Bruchteil erfassen. Jetzt werden beide Zahlen getrennt und durchgehend live angezeigt („Aufgabe 45 von 128 — 812 Abschnitte bereits indiziert“), inklusive einer erklärenden Fußnote unter der „Aktuelle Indexgröße“. Die frühere „↻ Seite neu laden für aktuelle Zahl“-Ausweich-Meldung entfällt, die Zahl ist jetzt direkt nach Lauf-Ende (und beim inkrementellen Refresh) korrekt, ohne Neuladen.

### Geändert (Cronjob)
- **Robustheit angeglichen**: `IndexCronjob::execute()` setzt jetzt wie die übrigen Indexierungs-Einstiegspunkte `set_time_limit(0)` (ein Cronjob-Lauf mit vielen/langsamen Embeddings konnte sonst am PHP-Standardlimit für web-getriggerte Pseudo-Cronjobs lautlos abbrechen, ohne dass jemals eine Fehlermeldung im Cronjob-Log landete – ein Timeout-Kill ist keine catchbare Exception). Fehlerbehandlung von `\Exception` auf `\Throwable` erweitert (Konsistenz mit `Api\ChatIndex`), Fataler-werdende Fehler landen jetzt auch im System-Log statt nur in der Cronjob-Nachricht.

### Geändert (API-Endpunkte)
- **`rex_api_function`-Klassen auf Namespace-Registrierung umgestellt**: Die vier API-Endpunkte (`klxm_chat_query`, `klxm_chat_index`, `klxm_chat_test`, `klxm_chat_cloudflare_models`) lagen bisher als globale `rex_api_klxm_chat_*`-Klassen vor – als einzige Klassen im Addon außerhalb von `FriendsOfRedaxo\KlxmChat`. Jetzt unter `lib/Api/` mit passendem Namespace (`ChatQuery`, `ChatIndex`, `ChatTest`, `CloudflareModels`) und Registrierung per `rex_api_function::register()` in `boot.php` (seit REDAXO 5.17 vorgesehen, siehe [Doku](https://redaxo.org/doku/5.x/api#namespace-registrierung)). Die `rex-api-call`-Namen selbst (und damit alle bestehenden Frontend-Requests) bleiben unverändert.

### Lizenzwechsel
- **Umstellung auf MIT-Lizenz**: KLXM Chat war bisher kommerziell lizenziert (`LICENSE.md`). Die separate Lizenz-Unterseite im Backend (`package.yml`) sowie der Lizenzhinweis auf der Demo-Seite entfallen entsprechend. Danksagung in der README ergänzt: Der **Fußballverband Niederrhein e.V.** hat die Entwicklung maßgeblich unterstützt, **Oliver Kreischer** ([@olien](https://github.com/olien)) für ausführliches Testing.

### Geändert
- **Einstellungsseite in Unterseiten aufgeteilt** (nach Vorbild von `search_it`): Statt einer langen Accordion-Seite jetzt eigenständige Unterseiten mit nativer REDAXO-Subnavigation („Zugriff & Steuerung“, „Verhalten & Antworten“, „Erscheinungsbild“, „KI-Provider“, „Erweiterte KI-Parameter“, „Indexierung & Chunking“). Gemeinsamer Code liegt in `pages/settings.shared.php`, CSS in `assets/klxm-chat-settings-backend.css`. Jede Unterseite zeigt zusätzlich ein „Empfehlungen“-Panel mit praxisnahen Tipps.
- **Hilfe- und Lizenz-Seite direkt über package.yml eingebunden** (`subPath` auf `README.md`/`LICENSE.md`), die bisherige selbstgebaute Lizenz-Box entfällt.
- **Seitenrechte eingeführt**: „Indexierung“, „Cache-Fragen“, „YForm-Tabellen“ und „Einstellungen“ erfordern jetzt `klxmchat[chatadmin]`, der Rest bleibt über das allgemeine `klxmchat[]`-Recht erreichbar – rein über `perm:` in `package.yml`.
- **Neues Recht „Backend Chat nutzen“** (`klxmchat[backend_chat]`): Der automatisch eingebundene Backend-Chat wird nur noch angezeigt, wenn der Nutzer Admin ist oder dieses Recht besitzt (`rex_perm::register()` in `boot.php`).

### Neu
- **Maximale Nachrichtenlänge konfigurierbar**: Neue Einstellungen „Maximale Nachrichtenlänge (Frontend)“ (`max_message_length_frontend`, Standard 2000 Zeichen) und „Maximale Nachrichtenlänge (Backend/Developer-Chat)“ (`max_message_length_backend`, Standard 20000 Zeichen) unter Einstellungen → Verhalten & Antworten. Serverseitig durchgesetzt in `ChatQueryService::process()` (`evaluateMessageLengthGuard()`, läuft vor allen anderen Guards/vor dem KI-Aufruf), zusätzlich clientseitig als `maxlength`-Attribut auf der Eingabe (`klxm-chat-v4.js`, passt sich automatisch an, wenn im Backend-Widget zwischen Frontend-/Developer-Scope gewechselt wird). 0 = keine Begrenzung.
- **Live-Reindizierung**: Neuer Master-Schalter „Automatische Live-Reindizierung bei Änderungen“ (`live_reindex_enabled`). Artikel/Slices/Kategorien (inkl. `CAT_STATUS`) und YForm-Datensätze werden bei Änderungen automatisch neu indexiert – auch bei Frontend-ausgelösten YForm-Änderungen (z.B. öffentliche Formulare).
- **RAG-Kandidatenfenster automatisch optimierbar**: Warnung + Button auf der Indexierungs-Seite ermitteln aus der aktuellen Indexgröße einen passenden `rag_candidate_limit`-Wert und setzen ihn direkt.
- **HTTP-Crawler-Indexmodus erweitert**: Ausschluss-Selektoren (`index_http_exclude_selectors`) sowie mehrere kommagetrennte Content-Selektoren möglich.
- **Chunking konfigurierbar**: `chunk_size`/`chunk_overlap` einstellbar (Standard 1000/200 Zeichen); der Overlap wird jetzt auch tatsächlich angewendet.
- **Fakten-Qualität bei der Indexierung deutlich verbessert**: JSON-LD (schema.org `Person`/`Organization`/`ContactPoint`/`LocalBusiness`) wird jetzt ausgewertet und als „Strukturierte Fakten“-Absatz vorangestellt. Zusätzlich fügt `cleanText()` beim HTML-Cleaning an Blockgrenzen (Absätze, Tabellenzeilen, Listen, `<br>`) echte Zeilenumbrüche ein und trennt auch sonst benachbarte Inline-Elemente mit Leerzeichen – verhindert, dass eigentlich getrennte Fakten (z.B. zwei Zuständigkeiten oder Adresszeilen) zu einer falschen Aussage verschmelzen.
- **Tippfehler-Toleranz**: Fuzzy-Matching (`tokenMatchesText()`, Levenshtein-Distanz) findet Treffer auch bei leicht falsch geschriebenen Namen/Begriffen in der Nutzerfrage.
- **Tooltips** an häufig missverstandenen Einstellungsfeldern (Indexierungs-Quelle, RAG-Werte, Chunking, Cache-Ähnlichkeit u.a.).
- **Kategorien ausschließen überarbeitet**: Hierarchische Auswahl über das native REDAXO-Widget `rex_category_select`; der Start-Artikel einer ausgeschlossenen Kategorie wird jetzt korrekt mitausgeschlossen (vorher nur seine Unterartikel).
- **yrewrite-SEO-Einstellungen berücksichtigt**: Neuer Schalter `index_respect_yrewrite_seo` (Standard an) – Artikel, die yrewrite per `yrewrite_index` explizit auf NoIndex stellt, werden bei der internen Struktur-Indexierung übersprungen.
- **Backend-Chat**: Scope-Auswahl (Frontend/Developer) bleibt jetzt über Seitenwechsel hinweg erhalten (sessionStorage) und wird farblich hervorgehoben (Frontend blau, Developer dunkles Orange), ohne das Frontend-Widget zu beeinflussen.
- **Theme-Editor fürs Frontend-Widget** (Einstellungen → Erscheinungsbild): Header-/Chat-Hintergrund, Textfarbe, Bot-Bubble-Farbe und Eckenradius einstellbar, inkl. Live-Vorschau.

### Behoben
- **Rohe `[[ACTION:...]]`-Tokens im Developer-Chat bei aktivem Streaming**: System-Tool-Ausführung (`[[ACTION:CLEAR_CACHE]]` u.ä.) griff bisher nur auf dem fertigen, nicht-gestreamten Antworttext. Neuer Puffer-Wrapper verarbeitet Tokens jetzt auch live im Stream, ohne sie doppelt auszuführen.
- **Zufällig wirkende/unpassende Quell-Links**: Der Ähnlichkeitsvergleich hat Kandidaten vorher nach Aktualität vorsortiert und begrenzt, wodurch ältere aber passende Seiten (z.B. eine Kontaktseite) aus dem Vergleich fielen. Vorsortierung entfernt, Kandidatenfenster großzügiger dimensioniert (Standard 800, bis 6000). Zusätzlich harte Mindest-Ähnlichkeitsschwelle, damit keine schwachen/zufälligen Treffer mehr als Link angezeigt werden.
- **Addon-Docs/GitHub-Indexierung war fälschlich an die Indexierungs-Quelle gekoppelt** (lief im Sitemap-Modus nie).
- **Doppelt escapte Anführungszeichen (`&quot;`)** in mehreren Settings-Hilfetexten durch überflüssiges `rex_escape()` auf bereits escaptem `i18n()`-Text.
- **Fataler Boot-Crash bei inkonsistentem Deployment**: `rex_extension::register()`-Aufrufe in `boot.php` waren ungeschützt gegen fehlende Methoden (`TypeError` legte das komplette Backend lahm). Jetzt per `is_callable()`-Guard abgesichert.
- **SQL-Fehler auf der Cache-Fragen-Seite** bei gesetztem Scope-/Suchfilter durch doppelt gesetzte Anführungszeichen um bereits von `rex_sql::escape()` gequotete Werte.
- **„Kategorien ausschließen“** schloss den Start-Artikel der ausgeschlossenen Kategorie selbst nicht aus (siehe „Neu“ oben).
- **Interne Struktur-Indexierung berücksichtigte yrewrite-SEO-Einstellungen nicht** (siehe „Neu“ oben).
- **Checkbox-Falle bei „Artikel/Struktur-Inhalt indexieren“ (`index_frontend`)**: REDAXO speichert eine aktivierte Checkbox als `"|1|"` (Pipe-umschlossen), nicht als reines `"1"`. `EventListener::handleEvent()`/`handleCategoryEvent()` verglichen aber mit `!= '1'`, wodurch ein tatsächlich aktiviertes Häkchen nach dem Speichern die Live-Reindizierung einzelner Artikel/Kategorien fälschlich übersprang. `IndexerService::isExcluded()` hatte das Gegenstück: der Ausschluss-Check verglich auf `'0'`/`0`, was eine deaktivierte Checkbox (gespeichert als leer/`null`) nie erkannte. Beide Stellen nutzen jetzt einen robusten, Pipe-Format-kompatiblen Check; das Feld zeigt sich in den Einstellungen außerdem korrekt vorausgewählt (aktiviert), wenn es nie explizit gespeichert wurde.
- **„Zusätzliche Content-Provider“ (forcal/YForm) blieben nach dem Aktivieren als „deaktiviert“ markiert**: Das Feld war eine Mehrfach-Checkbox-Gruppe (`index_content_providers`); ersetzt durch ein natives Mehrfach-Auswahlfeld (Select), das dasselbe robuste Speicherformat wie „Kategorien ausschließen“ nutzt und Mehrfachauswahl zuverlässiger persistiert.
- **CSS-Rauschen aus `<style>`-Blöcken landete im Index (HTTP-Crawler-Modus)**: Bei `index_method=http` wird der Inhalt per `DOMDocument`/`$node->textContent` extrahiert. `textContent` liefert bei `<style>`/`<script>`/`<noscript>`-Elementen deren rohen Inhalt mit – das Verstecken dieser Elemente ist reine Browser-Rendering-Konvention, keine DOM-API-Garantie. Dadurch landete z.B. der komplette CSS-Inhalt eines Navigations-`<style>`-Blocks als Fließtext im Index (sichtbar als `.clicky`/`.iconlist`-Regeln vor dem eigentlichen Seiteninhalt), obwohl das HTML selbst korrekt war. Fix: `<style>`/`<script>`/`<noscript>`-Knoten werden jetzt vor der Textextraktion aus dem DOM entfernt (wie schon die konfigurierbaren Ausschluss-Selektoren). Zusätzlich entfernt `cleanText()` als generelles Sicherheitsnetz jetzt auch Zeilen, die eindeutig wie CSS aussehen (Selektor+Klammer, `property: value;`, CSS-Kommentare).
- **Reasoning-Modelle (o1/o3/o4, GPT-5.x u.a.) schlugen mit `API Error HTTP 400: Unsupported parameter: 'max_tokens' is not supported with this model...` fehl, nur `gpt-4o-mini`-artige Modelle funktionierten**: `OpenAiCompatibleService` schickte bei jeder Chat-Anfrage hart `max_tokens`/`temperature`, was OpenAIs neuere Reasoning-Modell-Familien per `unsupported_parameter`-Fehler ablehnen (die verlangen stattdessen `max_completion_tokens` bzw. akzeptieren `temperature` nur als Modell-Default). Statt eine Modellnamen-Liste zu pflegen, die mit jeder neuen OpenAI-Modellfamilie veraltet, liest `generateAnswer()`/`generateAnswerStream()` jetzt das `param`-Feld aus der API-Fehlermeldung und passt die Anfrage automatisch an (`max_tokens` → `max_completion_tokens` unter Beibehaltung des Werts, andere abgelehnte Parameter werden entfernt) und wiederholt sie – funktioniert dadurch auch für künftige Modellfamilien ohne Codeänderung.

### Performance
- **Wiederholte Neuberechnung der Vektor-Magnitude bei jedem Chat/Cache-Abgleich**: `cosineSimilarity()` berechnete für jede der bis zu `rag_candidate_limit` (Standard 800, bis 6000) bzw. `cache_candidate_limit` (Standard 150) Kandidatenzeilen sowohl die Magnitude des – über den gesamten Suchlauf identischen – Frage-Vektors als auch die Magnitude des gespeicherten Vektors neu, obwohl Letztere sich seit der letzten Indexierung/Cache-Schreibung nie ändert. Neue Spalte `embedding_norm` in `klxm_chat_index` und `klxm_chat_cache` speichert die Magnitude jetzt einmalig beim Schreiben; `findSimilarContent()`/`findCachedAnswer()` berechnen die Frage-Magnitude nur noch einmal pro Suchlauf statt pro Kandidat. Bestehende Zeilen ohne den Wert (vor diesem Update indexiert/gecacht) fallen automatisch auf die alte Neuberechnung zurück, bis sie neu geschrieben werden – kein erzwungener Reindex nötig.
- **Indexierung embeddete jeden Chunk einzeln**: Alle fünf Indizierungs-Pfade (Artikel/Struktur, Addon-Docs, GitHub-Docs, HTTP-Crawler/Sitemap, Content-Provider) riefen `getEmbedding()` pro Chunk auf – ein einzelner, blockierender HTTP-Request je Chunk ohne Connection-Reuse. Neue Methode `AiServiceInterface::getEmbeddings()` (OpenAI-kompatibel, Gemini `batchEmbedContents`, Cloudflare Workers AI – alle unterstützen native Mehrfach-Eingabe) embeddet jetzt alle Chunks eines Dokuments in Batches von 100 in einem Rutsch; verkürzt die Laufzeit einer vollständigen Neuindizierung bei größeren Sites deutlich, ohne das Ergebnis zu verändern.

### Neu (Zugriff & Steuerung)
- **Suche im Frontend separat abschaltbar**: Neue Einstellung „Suche im Frontend aktivieren" (`frontend_search_enabled`, Standard an). Bisher war das eigenständige Such-Widget (`klxm-search.js`/`.css`) fest an „Chat im Frontend anzeigen" gekoppelt – beides ließ sich nur gemeinsam ein-/ausschalten. Jetzt unabhängig steuerbar (z.B. nur Suche ohne Chat-Bubble, oder umgekehrt).
- **Testmodus für Chat & Suche im Frontend**: Neue Einstellung „Sichtbarkeit im Frontend" (`frontend_visibility`, Standard „Für alle Besucher"). Im Modus „Nur für angemeldete Backend-Benutzer" sind Chat und Suche im Frontend ausschließlich für eingeloggte Backend-User sichtbar (inkl. kleinem „Testmodus"-Hinweis-Badge unten links) – zusätzlich zur bereits bestehenden IP-Liste, aber ohne dass man dafür seine eigene IP eintragen/pflegen muss.
- **Zugriffskontrolle jetzt auch server-seitig statt nur im Markup**: `frontend_enabled`/`frontend_search_enabled`/`frontend_visibility` steuerten bisher nur, ob das Widget ins HTML injiziert wird – der API-Endpunkt selbst blieb erreichbar. `ChatQueryService::process()` weist Frontend-Anfragen (Chat wie Suche) jetzt auch dann ab, wenn die jeweilige Funktion deaktiviert ist oder der Testmodus aktiv ist und keine gültige Backend-Session vorliegt.

### Sicherheit
- **Neuer Code-Einschleusungs-Schutz**: Nutzereingaben mit Skript-/HTML-Markup (`<script>`, `<iframe>`, `<svg>`, `onerror=`/`onload=`-Attribute, `javascript:`-URLs u.ä.) werden jetzt VOR dem Embedding, dem KI-Aufruf und dem FAQ-Cache-Schreiben erkannt und abgelehnt (`ChatQueryService::evaluateCodeInjectionGuard()`/`containsCodeInjectionAttempt()`, analog zum bestehenden Datenschutz-Guard). Solche Versuche erreichen dadurch nie mehr die KI und landen nie im Cache; blockierte Versuche werden mit IP und Zähler ins System-Log geschrieben. Ausgabeseitig bestand bereits Schutz (Parsedown `setSafeMode(true)` serverseitig, HTML-Escaping in der JS-Ausgabe `formatMessage()`) – der neue Guard verhindert zusätzlich, dass solche Eingaben überhaupt verarbeitet/gespeichert werden.
- **Neuer Spam-/Werbe-Guard**: Typische Spam-Anfragen (Pharma-Spam, SEO-/Backlink-Spam, Casino/Glücksspiel, Kredit-/Finanzbetrug, Krypto-Versprechen, Erwachseneninhalte, sowie Nachrichten mit 3+ Links) werden ebenfalls vor Embedding/KI-Aufruf/Cache erkannt und abgelehnt (`ChatQueryService::evaluateSpamGuard()`/`containsSpamContent()`). Eingebaute Grundliste plus optionales, admin-konfigurierbares Feld „Zusätzliche Spam-/Werbe-Begriffe“ (`spam_badwords`, Einstellungen → Verhalten & Antworten) für website-spezifische Ergänzungen.

### Behoben (forCal)
- **forCal-Integration kannte nur „nächster Termin“, nie „letzter/vergangener Termin“**: Fragen wie „wann WAR das LETZTE Turnier“ wurden trotzdem mit dem nächsten ZUKÜNFTIGEN Termin beantwortet, weil `extractForcalUpcomingOccurrences()` vergangene Termine hart herausfilterte (`$startTs < $nowTs` → übersprungen) und die Zusammenfassung immer als „Nächster Termin“/„Nächste Termine“ beschriftet war – unabhängig davon, wonach gefragt wurde. Neue Methode `ChatQueryService::hasForcalPastIntent()` erkennt vergangenheitsbezogene Formulierungen („letzte“, „zuletzt“, „vergangen“, „fand statt“, „war das/der/die“ u.ä.) und wird jetzt an allen drei Stellen ausgewertet, an denen forCal-Termine in den Antwortkontext einfließen (normaler RAG-Treffer, keyword-erzwungener forCal-Fallback, Live-Suche). `extractForcalOccurrences()` (vormals `extractForcalUpcomingOccurrences()`) filtert je nach erkannter Richtung entweder auf zukünftige (chronologisch aufsteigend, wie bisher) oder auf vergangene Termine (chronologisch absteigend – der jüngste vergangene Termin zuerst), die Zusammenfassung beschriftet entsprechend mit „Letzter bekannter Termin“/„Letzte Termine“ statt immer „Nächster Termin“. Die Fallback-Zusammenfassung (wenn forCal keine Terminliste liefert, nur ein einzelnes Start-/Enddatum) zeigt ein Datum jetzt nur noch an, wenn es tatsächlich zur gefragten Richtung passt – statt ein zukünftiges Datum fälschlich als „letzten Termin“ zu präsentieren. Die forCal-Intent-Keyword-Liste (`forcal_intent_keywords`) enthält jetzt zusätzlich „letzte“/„zuletzt“/„vergangen“ als Standardwerte.

### Behoben (YForm-Mappings & Quell-Links)
- **YForm-Mappings: „Zusätzliche Felder“ und „Bedingungen“ (Repeater-Zeilen) gingen beim Speichern komplett verloren**: Das „Feld“-Auswahlfeld jeder Repeater-Zeile wurde über den generischen `renderColumnSelect()`-Helfer mit dem immer gleichen, nicht verschachtelten Namen `profiles[X][field]` gerendert – statt korrekt unter `profiles[X][fields][<Zeile>][field]`/`profiles[X][conditions][<Zeile>][field]` wie alle anderen Eingaben derselben Zeile (Label, Modus, Operator, Wert). Dadurch teilten sich ALLE Zeilen eines Profils denselben Formularnamen, nur die letzte überlebte im POST und landete zudem an der falschen Stelle im Profil-Array – `YformProfiles::normalizeProfile()` sah für „field“ dadurch bei JEDER Zeile einen leeren Wert und verwarf sie. Fix: `renderColumnSelect()` unterstützt jetzt einen expliziten Namenspfad, das „Feld“-Select nutzt für Repeater-Zeilen korrekt den gleichen `[fields][<Zeile>]`/`[conditions][<Zeile>]`-Präfix wie die übrigen Zeilenfelder.
- **Bedingungs-Zeilen konnten beim Anlegen automatisch eine falsche erste Tabellenspalte vorauswählen**: Das „Feld“-Auswahlfeld für Bedingungen wurde ohne leere Platzhalter-Option gerendert; ohne explizit als „selected“ markierte Option wählt der Browser automatisch die erste Spalte aus der Liste (z.B. eine interne YForm-Systemspalte wie „save“) – dieser Wert wurde beim Speichern dann fälschlich als echte Bedingung übernommen. Fix: leere Platzhalter-Option ist jetzt auch für Bedingungs-Zeilen aktiv, eine unausgefüllte Zeile bleibt beim Speichern korrekt leer und wird verworfen statt einen Zufallswert zu übernehmen.
- **YForm-Mappings: URL-Modus/URL-Profil/URL-Feld/URL-Template gingen beim Speichern (und bei jeder späteren Bearbeitung) verloren**: `YformProfiles::normalizeProfile()` übernahm diese vier Schlüssel nie in das gespeicherte Profil-Array – die zugehörigen Zeilen waren versehentlich in einen fremden PHPDoc-Kommentarblock verrutscht und damit toter Code. Jedes Profil fiel dadurch beim Speichern immer auf den internen Platzhalter-URL-Modus zurück, unabhängig von der Admin-Konfiguration. Fix: die vier Schlüssel wieder korrekt in den Rückgabe-Array von `normalizeProfile()` aufgenommen.
- **„Intern (yform://)“ als URL-Modus entfernt**: Dieser Platzhalter-Modus erzeugte URLs wie `yform://tabelle/123/slug`, die für Website-Besucher nie aufrufbar waren und teils sogar kaputt kodiert im Chat auftauchten. Der URL-Modus kennt jetzt nur noch „Aus Feldwert“, „URL-Profil (Namespace)“ und „Template“ (Standard: „Aus Feldwert“). Ist keiner dieser Modi konfiguriert oder liefert keine URL, wird jetzt gar kein Link angezeigt statt einer kaputten Platzhalter-URL.
- **Irrelevante YForm-/forCal-Quell-Links durch zu schwachen Stichwort-Filter**: `extractSearchTerms()` (genutzt für die SQL-`LIKE`-Fallback-Suche in `findForcalKeywordMatches()`/`findProviderKeywordMatches()`) filterte nur Wörter unter 2 Zeichen und hatte keine Stoppwortliste. Dadurch wurden generische, hochfrequente Wörter wie „das“, „ist“, „der“, „in“ als Suchbegriffe verwendet, die praktisch mit jedem indexierten Datensatz matchen – z.B. lieferte eine reine Korrektur-Nachricht („das ist in der Vergangenheit …“) plötzlich thematisch völlig unpassende News-Links. Die bereits für `extractRelevantTokens()` bestehende, umfangreiche deutsche Stoppwortliste wurde in `getGermanStopwords()` ausgelagert und wird jetzt auch von `extractSearchTerms()` verwendet (zusätzlich Mindestlänge von 2 auf 3 Zeichen angehoben).
- **Nicht-http(s)-URLs (`yform://…`, `forcal://…`) konnten trotz allem als klickbarer „Links:“-Eintrag erscheinen**: `collectDisplaySources()` zeigt jetzt nur noch URLs an, die tatsächlich mit `http://`/`https://` beginnen (zusätzliches Sicherheitsnetz unabhängig von der eigentlichen Ursache oben).

## [2.0.0-beta4] - 2026-08-13

### Neu
- **Echtes Live-Streaming (SSE) für Chat-Antworten**: Neue Option „Live-Antworten streamen (SSE)“ in den Einstellungen. Aktiviert, wird die KI-Antwort Wort für Wort angezeigt, sobald das Modell sie liefert – kein Warten auf die komplette Antwort mehr. Implementiert per echtem Provider-seitigem Token-Streaming (kein nachträgliches Zerlegen einer fertigen Antwort):
  - OpenAI-kompatible Provider (inkl. Ollama/OpenWebUI) und Cloudflare Workers AI streamen über deren native SSE-Endpunkte.
  - Google Gemini streamt über den `streamGenerateContent`-Endpunkt (statt der bisherigen Komplettantwort).
  - Fällt automatisch auf die normale Antwort zurück, wenn Browser oder Provider kein Streaming unterstützen.
  - Kein zusätzliches AddOn nötig – die Implementierung ist vollständig im Chat-AddOn selbst enthalten (kein Bezug zum `sse_demo`-AddOn).

### Behoben
- **Folgefragen-Sprung**: Das Erscheinen der vorgeschlagenen Folgefragen sprang bisher immer ans Ende des Chatverlaufs. Bei langen Antworten musste man danach wieder nach oben scrollen. Der Chat scrollt jetzt nur noch automatisch mit, wenn man ohnehin schon am unteren Ende ist.
- **Scrollen während des Streamens**: Während die Antwort live eintraf, wurde die Scroll-Position bei jedem einzelnen Textstück zurückgesetzt, sodass manuelles Scrollen unmöglich war. Sobald der Nutzer während des Streamens selbst scrollt, wird der Auto-Scroll für den Rest dieser Antwort deaktiviert.
- **Developer-Chat ohne Antwort**: Bei leerem oder noch nicht befülltem Entwickler-Index (AddOn-/GitHub-Dokumentation) antwortete der Backend-Chat auf jede Frage – auch reine System-Aktionen wie „Welche Addons sind installiert?“ – nur mit einer Standard-Floskel. Der Entwickler-Chat kann jetzt auch ohne indizierten Kontext aus eigenem Modellwissen und über System-Aktionen antworten.

## [2.0.0-beta3] - 2026-08-12

### Neu
- **FAQ-Cache Vorwärmung**: Optionaler Warmup-Flow für vorbereitete Frage-Antwort-Kombinationen. Vorcache-Fragen können in den Einstellungen gepflegt und auf der Indexierungsseite gezielt per Button in den Cache geschrieben werden.
- **Indexquellen-Modi erweitert**: Neue klare Auswahl zwischen `Lokale Inhalte`, `Nur externe Sitemap` und `Lokale Inhalte + externe Sitemap`.
- **Statistik-Dashboard**: Neue Backend-Ansicht für Such- und Chat-Statistiken mit Überblick, Zeitfenster, Scope-Übersicht und Top-Begriffen pro Bereich.
- **Demo-/Beispielseite erweitert**: Demos für Suche und Chat sind jetzt klar getrennt und besser für Live-Prüfungen im Backend nutzbar.
- **Chat-UX-Optionen**: Der automatisch eingebundene Backend- und Frontend-Chat kann nun optional mit einem sichtbaren Reset-Countdown und einem Verlauf-Kopieren-/Download-Button erweitert werden.

### Verbessert
- **Einstellungsseite neu strukturiert**: Bereiche sind jetzt deutlich klarer gegliedert, mit Sprungmenü, Hilfsspalte und kontextsensitiver Anzeige relevanter Felder.
- **Sitemap-Konfiguration**: Eigener sichtbarer Abschnitt für Sitemap-URL und Sitemap-Test. Im Nur-Sitemap-Modus werden irrelevante lokale Felder ausgeblendet, Provider bleiben aber verfügbar.
- **Nur-Sitemap-Indexierung**: Lokale Seiten, AddOn-Dokumentation und GitHub-Dokus lassen sich jetzt sauber von Sitemap-Quellen trennen. Die kombinierte Variante `lokal + Sitemap` ist ebenfalls möglich.
- **Cache-Verhalten**: Optionales FAQ-Vorcaching ist jetzt per Schalter aktivierbar und funktioniert auch bei Frontend-Anfragen mit `current_url`.
- **Backend-Theme-Handling**: REDAXO Light/Dark/Auto-Themes wurden für die Statistik- und Einstellungsbereiche konsistent harmonisiert. Hilfreiche, lesbare Dark-Mode-Farben sind jetzt gesetzt.
- **REDAXO-Backend-Konventionen**: Doppelte Page-Titel wurden aus Subpages entfernt, damit der Standard-Header des backendseitigen Page-Layouts nicht doppelt gerendert wird.
- **Asset-Organisation**: CSS/JS der Statistikseite wurden in die Addon-Assets ausgelagert und per `boot.php` eingebunden, damit die Logik und das Styling sauber vom Page-Template getrennt sind.
- **Statistics UX**: Zeitraum-Auswahl bleibt jetzt auf der richtigen Backend-Seite, ohne unerwünschte Redirects auf `structure`.
- **Quelle-Relevanzfilter**: In Antworten werden nur noch inhaltlich passende Quellen angezeigt; irrelevante Treffer mit wenig Bezug zur Frage werden deutlich reduziert.
- **Such-/Chat-Analytik verbessert**: Duplikate, irrelevante Zwischenzustände und leere, unbrauchbare Suchbegriffe werden sauber herausgefiltert, damit die Statistik aussagekräftiger bleibt.
- **Relevanz und Latenz feinjustiert**: RAG- und Cache-Kandidatenfenster sind jetzt konfigurierbar, low-signal-Treffer werden vor dem Ranking herausgefiltert und ähnliche Suchfragen werden im Cache robuster zusammengeführt.
- **Reset-Countdown stabilisiert**: Der Countdown im Chat startet sauber, zeigt sichtbare Sekunden an und setzt den Verlauf nach Ablauf korrekt zurück.

### Behoben
- **Cache-Warmup Statusanzeige**: `Cache vorwärmen` läuft jetzt zuverlässig per API und zeigt den laufenden Status im Backend sichtbar an.
- **Antwortformatierung**: Escaped Zeilenumbrüche wie `\n\n` werden vor dem Quellenblock korrekt normalisiert; der Quellenabschnitt wird sauber getrennt ausgegeben.
- **Hybrid-Lock-Auslese**: Fehlerhafte Interpretation des DB-Locks behoben, sodass keine falschen "läuft bereits"-Meldungen mehr entstehen.
- **Wiki-Deduplizierung**: Stabile Batch-Slugs und Bereinigung veralteter Batch-Einträge verhindern doppelte Wiki-Datensätze bei erneuten Läufen.
- **Doppelter Header in Statistikseiten**: Überflüssige manuelle Titel-Ausgabe entfernt, wodurch die Page-Leiste nicht doppelt auftaucht.
- **Dark-Mode-Styles für Einstellungen**: Fehlende Theme-Overrides korrigiert, sodass die Einstellungsseite im Dark Mode nicht mehr „scheiße“ aussieht.
- **Statistik-Stylesheet-Fehler**: Feste helle Farbwerte im Dashboard wurden auf REDAXO-konforme Theme-Variablen umgestellt.
- **Asset-Ladeprobleme**: CSS/JS wurden nach dem Refactor korrekt im Backend registriert, sodass die Statistikseite nicht mehr wegen fehlender Assets kaputt aussieht.
- **Reset-Flow im Chat**: Der Countdown löst nach Ablauf nun wirklich den Reset des Chat-Verlaufs aus; der Chat beginnt danach sauber neu.

## [2.0.1-dev] - 2026-06-08

### Neu
- **Provider-Extension-Point**: Neue Erweiterung `KLXMCHAT_CONTENT_PROVIDERS` für externe Addons. Content-Provider können sich nun selbst registrieren.

### Verbessert
- **Indexierungsseite**: Anzeige der Content-Provider wurde auf die Registry umgestellt.
	- Zeigt jetzt dynamisch **aktivierte** Provider mit Trefferzahlen im Index.
	- Zeigt zusätzlich **verfügbare** Provider inkl. Aktivierungsstatus.
- **Einstellungen**: Provider-Optionen für `index_content_providers` werden dynamisch aus der Registry aufgebaut (statt hart verdrahteter Liste).
- **Provider-Status in Settings**: Klarer Hinweis, wenn `knowledgebase` zwar vorhanden, aber nicht aktiviert ist.
- **Indexer-Statusanzeige**: Laufender Status zeigt Provider- und Quelltyp-Labels statt nur technischer Keys.
- **Frontend-RAG Retrieval**: Berücksichtigt aktivierte Content-Provider-Source-Types dynamisch (inkl. Knowledgebase).
- **Kontext-Fallback**: Keyword-basierter Fallback mischt Provider-Kontext ein, wenn Vektor-Treffer keinen Provider-Inhalt liefern.
- **Live-Suche**: Berücksichtigt aktivierte Provider-Source-Types ebenfalls dynamisch (nicht nur Chat-Antwortmodus).
- **Live-Suche optional**: Neue Option für mehrere Fundstellen-Kontexte pro Treffer statt nur eines Teasers.

## [2.0.0-dev] - 2026-06-03

### Neu
- **Datenschutz-Guard für Eingaben**: Sensible personenbezogene Inhalte (z.B. IBAN, Konto-/Kartendaten, Passwörter, E-Mail-Muster) werden serverseitig erkannt und nicht an die KI weitergegeben. Nutzer erhalten stattdessen einen klaren Warnhinweis.
- **Whitelist für E-Mail-Domains**: In den Einstellungen kann optional eine Domain-Liste hinterlegt werden (z.B. `klxm.de`), damit bekannte Website-Mailadressen nicht blockiert werden.
- **Ansprache als Select-Setting**: Neuer Frontend-Select für den Antwortstil (`Automatisch`, `Immer Sie`, `Immer Du`, `Neutral`).

### Verbessert
- **Spotlight-Suche UX**: Ergebnisdarstellung strukturiert (Typ-Label + Datum), technische Quellschlüssel wie `sitemap_url` werden für Besucher nicht mehr roh angezeigt.
- **Frag-die-KI Button**: Übernimmt nun Branding der Chat-Bubble (Primärfarbe und Avatar/Symbol) inklusive Textlabel.
- **Warteindikatoren**: Sichtbare KI-Aktivitätsanzeige im Chat und in der Spotlight-Antwortbox während Warte- und Antwortphase.
- **Scroll-Verhalten Spotlight**: Antwort und Treffer nutzen einen gemeinsamen Scrollbereich; die Antwort bleibt nicht mehr visuell fixiert.

### Sicherheit
- **Personalisierungs-Absicherung**: Du/Sie/Name-Rückfragen in Suchkontexten und ungewollte Personenabfragen werden stärker unterbunden (Prompt-Guard + serverseitige Bereinigung).

## [1.4.0] - 2026-06-02

### Sicherheit
- **TLS-Absicherung OpenAI-kompatibel**: Zertifikatsprüfung ist jetzt standardmäßig aktiv. Eine Deaktivierung erfolgt nur noch für echte lokale Entwicklungs-Hosts (`localhost`, `127.0.0.1`, `::1`, `host.docker.internal`; optional `*.local` nur im Debug-Modus).
- **Origin-Validierung gehärtet**: `Origin`/`Referer` werden nun strikt auf Schema + Host + Port geprüft (statt Präfix-Vergleich), um Umgehungen zu verhindern.

### Performance
- **Rate-Limit Query optimiert**: Zusätzlicher DB-Index auf `ip + session_id + created_at` verbessert die `COUNT`-Abfrage unter Last.
- **Backend-Overhead reduziert**: V4-Chat-Skript wird im globalen Backend-Filter nur noch auf `klxmchat`-Seiten injiziert.
- **Frontend-Bundle bereinigt**: Produktive Debug-Logs entfernt, doppelte `toggleChat()`-Methode entfernt, Session-Historie auf die letzten 60 Nachrichten begrenzt.
- **Retrieval beschleunigt**: RAG- und Cache-Lookup nutzen jetzt ein SQL-Kandidatenfenster mit anschließendem Cosine-Ranking statt Vollscan über alle Einträge.
- **Schnellere Erstantwort**: Folgefragen werden asynchron nachgeladen (`mode=followups`) und blockieren nicht mehr die Hauptantwort.

### Konfiguration
- **Quellenausgabe steuerbar**: Neue Option „Quellen/Links in Antworten anzeigen“ in den Einstellungen. So kann die Link-Sektion pro Instanz ein-/ausgeschaltet werden.

### Verhalten
- **Folgefragen nur im Frontend**: Im Backend/Developer-Scope werden keine Folgefragen mehr generiert oder nachgeladen, damit Admin-Antworten schneller und fokussierter bleiben.

### API-Addon
- **Neue API-Addon Endpunkte**: `klxmchat/chat/query` (Bearer-geschützt), `klxmchat/public/chat/query` (optional öffentlich), `klxmchat/capabilities` sowie Backend-Mirror unter `backend/…` analog zum Muster aus `code`.
- **Public-Gate per Einstellung**: Öffentlicher Zugriff ist nur verfügbar, wenn in den Einstellungen „Public API Endpunkt aktivieren" gesetzt ist.

## [1.3.7] - 2026-04-16

### Neu
- **Vorgeschlagene Folgefragen**: Nach jeder Antwort werden optional 2–3 klickbare Folgefragen als Chips angezeigt. Per Klick wird die Frage direkt gesendet. Aktivierbar in den Einstellungen unter "Vorgeschlagene Folgefragen anzeigen".

---

## [1.3.6] - 2026-04-16

### Behoben
- **Indexer-Fehler**: Spalte `category_id` existiert nicht in `rex_article`. Korrekte Spalte `parent_id` wird jetzt verwendet. Behebt `SQLSTATE[42S22]: Unknown column 'category_id'` beim Indexieren auf Live-Servern.

---

## [1.3.5] - 2026-04-16

### Sicherheit
- **Bot-Schutz**: Neue `validateOrigin()`-Prüfung lehnt Anfragen ohne gültigen `Origin`- oder `Referer`-Header ab (HTTP 403). Schützt den API-Endpunkt vor direkten Bot-Zugriffen ohne Browser-Kontext. Backend-Nutzer sind ausgenommen.
- **JS**: `X-Requested-With: XMLHttpRequest`-Header wird bei allen Chat-Anfragen mitgesendet, damit Browser den `Origin`-Header korrekt setzen.

### Behoben
- **Log-Spam**: Leere Nachrichten (Bot-Requests) werden nun still mit `{"answer":""}` beantwortet, ohne Exception oder Log-Eintrag.
- **Log-Spam**: Debug-Context-Logs (`KlxmChat: Context 0/1/2 (Sim: ...)`) aus dem produktiven Betrieb entfernt.
- **Typsicherheit**: Alle rexstan-Fehler in `rex_api_klxm_chat_query` behoben – `(string)`-Casts für `getValue()`- und `file_get_contents()`-Aufrufe, `@param float[]`-PHPDoc für Embedding-Parameter.

---

## [1.3.3] - 2026-02-26

### Verbessert
- **OpenAI Provider**: Base URL ist nun optionaler Parameter. Wenn leer gelassen wird automatisch die originale OpenAI API (`https://api.openai.com/v1`) verwendet. (Dank an den Kollegen!)
- Hinweis in den Einstellungen bzgl. Base URL präzisiert.

## [1.3.2] - 2026-02-26

### Verbessert
- **RAG Retrieval**: Unterstützung für `sitemap_url` in der Suchabfrage korrigiert. Sitemap-Inhalte werden nun korrekt in die Antwortgenerierung einbezogen.
- **Context-Retrieval (Overlap)**: Einführung von Chunk-Überlappung (300 Zeichen) beim Indexieren. Verhindert, dass zusammenhängende Informationen an Chunk-Grenzen zerschnitten werden.
- **Prompt Engineering**: Optimierte Struktur des System-Prompts für bessere Kontext-Treue bei Modellen wie Gemma 3 und Qwen.
- **Debugging**: Warnung im Log bei inkompatiblen Embedding-Dimensionen (Hinweis auf notwendige Neu-Indexierung).

---

## [1.3.0] - 2026-02-26

### Neu
- **Intelligente Text-Bereinigung**: Der Indexer entfernt nun automatisch störende Elemente wie `<script>`, `<style>`, `<nav>`, `<footer>` und `<header>`, um die Qualität der KI-Antworten zu steigern und Token zu sparen.
- **Improved Logging**: Integration des REDAXO-Systemlogs zur besseren Nachverfolgung von Indexierungs-Vorgängen.

### Verbessert
- **OpenAI Kompatibilität**: Automatisches Fallback auf das korrekte Embedding-Modell (`text-embedding-3-small`), wenn die offizielle OpenAI API genutzt wird.
- **Mobile UX**: Der Z-Index wurde auf `1.000.000` angehoben. Ein Resize-Fix verhindert, dass manuell skalierte Fenster die mobile Ansicht stören.
- **Dark Mode**: Vollständige Unterstützung für REDAXO Dark Mode auf der Demo- und Lizenzseite.

### Behoben
- **Sitemap-Tester**: Fix eines JavaScript-Fehlers beim Prüfen von Sitemap-URLs im Backend (ID-Konflikt & PJAX/rex:ready Support).
- **Logger-Fix**: Behebung eines PHP-Fehlers bei statischen Aufrufen von PSR-3 Logger-Methoden.
- **Syntax-Fix**: Korrektur von Maskierungsfehlern in der `settings.php`.

---

## [1.2.0] - 2026-02-26

### Neu
- **Sitemap Indexer**: Neue Indexierungs-Quelle "Sitemap". Erlaubt das automatisierte Abarbeiten einer externen `sitemap.xml` URL (inkl. Support für Sitemap-Indizes).
- **Content Selector**: Präzise Extraktion von Inhalten mittels CSS-Selektoren (Tag, ID, Klasse) beim HTTP-Crawling, um Rauschen (Navigation, Footer) zu minimieren.
- **Konfigurations-Optionen**: Globaler Standard-Anzeigemodus (`bubble` vs `inline`) und Option zur Freischaltung des Scope-Switchers im Frontend.
- **HTTP Indexer**: Vollständige Integration von `rex_socket` und `DOMDocument` zum Crawling und Parsing externer Webseiten.

### Verbessert
- **Web-Component Architektur**: Die Styling-Engine der Web-Component nutzt nun CSS-Variablen mit intelligenten Fallbacks, was eine themenübergreifende Anpassung ohne Shadow-DOM-Durchbruch ermöglicht.
- **Backend-UX**: Das Einstellungs-Formular nutzt nun JavaScript-Trigger, um kontextsensitive Felder (z.B. Sitemap-URL) an- oder auszublenden.
- **Robustheit**: Verbesserte Validierung von XML-Strukturen und Fehlerprotokollierung bei fehlgeschlagenen HTTP-Anfragen im `IndexerService`.
- **API Mapping**: Erweiterung des `boot.php` Mappings, um Backend-Konfigurationen konsistent an die Frontend-Component zu übergeben.

---

## [1.1.7] - 2026-02-26

### Neu
- **Native REDAXO Theme Integration**: Automatisches Umschalten zwischen Light-, Dark- und Auto-Mode (Systemeinstellung) im REDAXO-Backend via `body.rex-theme-dark` Selectoren.
- **Inline Mode**: Flexibler Layout-Modus für die Integration in Dashboards oder statische Inhaltsseiten.
- **Mobile UX**: Implementierung von `100dvh` für echtes Vollbild auf iOS/Android und Hinzufügen eines Close-Buttons für bessere Navigation.
- **Backend Playground**: Interaktive Dokumentations- und Testseite (`demo.php`) mit Live-Beispielen für alle Integrationsvarianten.
- **Code-Interaktion**: Automatisches Wrapping von Code-Blöcken mit Sprach-Labels und funktionalem "Copy"-Button.
- **Live-Scope-Switch**: Benutzergesteuerter Wechsel der Suchbasis (z.B. Dokumentation vs. Website-Inhalt) während des Chats.

### Verbessert
- **Concurrency**: Einsatz von `session_write_close()` in den API-Endpunkten zur Vermeidung von Request-Queuing bei zeitintensiven KI-Generierungen.
- **Theming-System**: Einführung von `--klxm-chat-*` CSS-Variablen als öffentliche Schnittstelle für Designer.
- **Vektor-Indexierung**: Optimiertes Chunking-Verfahren (1500 Zeichen Überlappung) für bessere Kontextrelevanz der KI-Antworten.
- **Markdown-Parser**: Unterstützung für verschachtelte Listen, Fettformatierung und sichere Link-Konvertierung (XSS-geschützt).

### Behoben
- **Multibyte-Sicherheit (UTF-8)**: Behebung eines kritischen Fehlers (HTTP 422: Parameter required), der durch ungültiges UTF-8 Chunking mittels `str_split` verursacht wurde. Ersetzung durch `mb_substr` für alle Indexierungs-Pfade (Artikel, Dateien, URLs).
- **Backend-Stabilität**: Fix einer PHP-Syntaxfehlermeldung (`unexpected T_FOREACH`) im `IndexerService.php` nach fehlerhaftem Merge.
- **Z-Index**: Korrektur von Z-Index Konflikten mit REDAXO-Modals und Flyout-Menüs.
- **Race-Condition**: Beseitigung einer Async-Race-Condition, bei der `startQuery()` zu früh aufgerufen wurde.

---

## [1.0.0] - Initial Release
- Basis Chat-Funktionalität
- Indexierung von REDAXO Slices
- Einfache Frontend-Schnittstelle
