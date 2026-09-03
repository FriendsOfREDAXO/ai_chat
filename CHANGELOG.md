# Changelog

## [Unreleased]

### Geändert (YForm-Mapping: URL-Profil als Auswahl statt Freitext)
- „URL-Profil" (der Namespace fürs url-Addon) ist jetzt ein Auswahlfeld mit
  den tatsächlich registrierten URL-Profilen statt eines Freitextfelds -
  verhindert Tippfehler und zeigt nur existierende Profile. Fällt auf ein
  Textfeld zurück, wenn das url-Addon nicht verfügbar ist oder keine
  Profile registriert hat, damit ein bereits gespeicherter Namespace nicht
  einfach verschwindet.

### Geändert (YForm-Mapping: URL-Modus zeigt nur das passende Feld)
- „URL-Feld", „URL-Profil" und „URL-Template" standen bisher immer alle drei
  gleichzeitig nebeneinander, obwohl sich der gewählte „URL-Modus" für genau
  eines davon entscheidet - die anderen beiden hatten dann schlicht keine
  Wirkung, ohne dass das erkennbar war. Zeigt jetzt nur noch das zum
  gewählten Modus passende Feld, sowohl bei bestehenden Mappings als auch
  bei neu hinzugefügten.

### Geändert (Profil-Formular logisch gruppiert)
- Die Abschnitte auf der Profil-Bearbeitungsseite heißen jetzt „Allgemeine
  Einstellungen" → „Sichtbarkeit" (Kontext, Sichtbar für, Anzeigebereich/
  Domain/Sprache, Chat/Suche automatisch einbinden) → „Individuelle
  Einstellungen (Indizierung)" (Wissens-Scope, eigene Quellen) →
  „Verhalten" → „Theme" - in genau der Reihenfolge, in der man ein Profil
  sinnvollerweise durcharbeitet. Vorher hieß der Sichtbarkeits-Block
  „Kontext & Sichtbarkeit", was leicht überlesen wurde.
- Das Feld „Zielgruppe" heißt jetzt „Anzeigebereich (Domain/Sprache)" -
  „Zielgruppe" klang nach Personen/Rollen (das steuert bereits „Sichtbar
  für" darüber), gemeint ist aber, auf welcher Domain/in welcher Sprache
  das Profil erscheint.
- Domain-/Sprach-Auswahl unter „Anzeigebereich" verschwindet nicht mehr
  ersatzlos, wenn nur eine Domain/Sprache konfiguriert ist (vorher: das
  Auswahlfeld wurde komplett weggelassen, obwohl die Optionen „Nur
  bestimmte Domains"/"-Sprachen" weiterhin wählbar blieben) - stattdessen
  erscheint ein Hinweis, warum es dort nichts auszuwählen gibt.

### Neu (Systemcheck, zusammengeführt mit den Statistiken)
- Die Statistik-Seite (jetzt Landing-Page von AI Chat) zeigt oben einen
  Systemcheck: PHP-/REDAXO-Version, PDF-Textextraktion (pdftotext vs.
  smalot/pdfparser-Fallback), Voraussetzungen für die Hintergrund-Indexierung,
  natives Vektor-Retrieval, KI-Provider-Konfiguration - vorher verstreut
  (Vektor-Status nur auf der Indexierung-Seite) oder nur als Fehlermeldung im
  Versuch sichtbar. `SystemCheckService` bündelt das an einer Stelle;
  `resolveBinary()`/`backgroundRunnerDiagnostics()` (vorher privat in
  `Api\ChatIndex`) und `PdfTextExtractor`s eigene Binary-Suche nutzen jetzt
  dieselbe Stelle statt eigener Kopien.
- Die Nutzungsstatistik kennt jetzt Profile: neuer `profile_id`-Spalte an
  `rex_ai_chat_stats`, ein Profil-Filter neben dem Zeitraum-Filter, und eine
  "Anfragen je Profil"-Übersicht. Zeilen von vor diesem Feld (und Anfragen
  ganz ohne aufgelöstes Profil) laufen unter "Kein Profil (global)".

## [1.0.1] - 2026-09-03

### Verbessert (Such-Widget: KI-Antwortbox)
- Eine lange KI-Antwort im Such-Overlay schiebt nicht mehr die eigentliche
  Trefferliste weit nach unten - die Antwortbox begrenzt ihre Höhe jetzt und
  bekommt bei Bedarf einen „Mehr anzeigen"/„Weniger anzeigen"-Umschalter. Ein
  eventueller Quellen-Block („Links:") bleibt dabei immer vollständig
  sichtbar, auch eingeklappt.
- Code-Beispiele (Inline und Blöcke) in der Antwort sehen jetzt unabhängig
  vom Website-Theme konsistent aus - das Such-Overlay läuft anders als
  `<ai-chat>` (Shadow DOM) im Light DOM der Seite und übernahm bisher
  ungewollt globale `<code>`-Styles der jeweiligen Website (z.B. UIkits
  pinkes Standard-Inline-Code), inklusive Abschneiden bei langen einzeiligen
  Code-Schnipseln ohne Zeilenumbruch.

### Behoben (Fataler Fehler im Frontend bei bestimmten yrewrite-Multidomain-Konstellationen)
- `rex_yrewrite::getCurrentDomain()` wirft in manchen Multidomain-/
  Mehrsprachen-Setups (z.B. eine Sprache offline, siehe [#1](https://github.com/FriendsOfREDAXO/ai_chat/issues/1))
  selbst einen fatalen Fehler statt wie dokumentiert `null` zurückzugeben
  (`Call to a member function getId() on null` in yrewrite intern) - legte
  bislang das komplette Frontend lahm, sobald ai_chat aktiv war. Alle vier
  Aufrufstellen laufen jetzt über die neue, defensive
  `YrewriteDomainResolver::getCurrentDomain()` (fängt das ab und liefert
  `null`), inklusive eines zusätzlichen fehlenden Null-Checks bei der
  PDF-URL-Auflösung (`MediaPoolContentProvider`/`IndexerService`).

### Neu (Globale PDF-Indexierung, gleichwertig zur profil-eigenen)
- Die Indexierung-Einstellungen (AI Chat → Indexierung & Chunking) haben jetzt
  dieselben zwei PDF-Auswahlfelder wie ein Profil („PDF-Dokumente" +
  „PDFs aus Medienpool-Kategorien") – global ausgewählte PDFs tragen zum
  gemeinsamen Wissens-Pool bei, exakt nach demselben Muster wie die
  profil-exklusive Auswahl. Kein separates Aktivieren-Häkchen in der
  „Content-Provider aktivieren"-Liste – wie beim Profil ist die Auswahl selbst
  die Aktivierung.
- `MediaPoolContentProvider` ist damit kein rein profil-exklusiver Provider mehr;
  `renderSourceFields()` rendert die beiden Felder für globale Einstellungen UND
  Profile aus derselben Stelle, damit beide nie auseinanderlaufen.

### Geändert (Profile ersetzen die globalen Sichtbarkeits-Schalter vollständig)
- Sobald mindestens ein aktives, frontend-fähiges Profil existiert, sind die globalen
  Schalter „Chat im Frontend anzeigen"/„Suche im Frontend aktivieren" komplett
  wirkungslos und in den Einstellungen entsprechend deaktiviert – jedes Profil
  entscheidet dann eigenständig über die Felder „Chat/Suche automatisch einbinden"
  (Standard dort: aktiv, kein „globale Einstellung entscheidet" mehr). Ohne aktive
  Profile bleiben die globalen Schalter die alleinige Instanz – Profile sind
  weiterhin komplett optional. Vorher konnten globaler Schalter und Profil
  gleichzeitig um dieselbe Entscheidung „konkurrieren", was zu widersprüchlichem
  Verhalten führte (Chat/Suche mal an, mal aus, je nachdem welche Einstellung
  zuletzt geändert wurde).
- `ProfileTheme::resolve*()`/`buildInlineStyle()` akzeptieren jetzt ein nullbares
  Profil und fallen dann direkt auf die globale Darstellung zurück – nötig, damit
  der Fall „keine aktiven Profile" (reiner globaler Fallback) nicht auf einen
  Methodenaufruf mit `null` crasht.

### Neu (PDF-/Dokument-Indexierung aus dem Medienpool)
- Profile können jetzt PDF-Dateien aus dem Medienpool indexieren – einzeln ausgewählte
  Dokumente und/oder ganze Medienpool-Kategorien, exklusiv für das jeweilige Profil (kein
  globaler „alle PDFs indexieren"-Modus). Neue Felder „Eigene PDF-Dokumente" (nativer
  REDAXO-Mehrfach-Datei-Picker) und „PDFs aus Medienpool-Kategorien" auf der Profil-Seite.
- Textextraktion nutzt, falls auf dem Server vorhanden, `pdftotext` (poppler-utils) für
  bessere Qualität bei komplexem Layout, sonst die reine PHP-Bibliothek
  `smalot/pdfparser` (kein Systembinary nötig, läuft auf jedem Hosting). Kein OCR –
  gescannte/reine Bild-PDFs liefern leeren Text und werden übersprungen statt einen
  Fehler auszulösen.
- Extrahierter Text wird vor der Weiterverarbeitung auf gültiges UTF-8 bereinigt –
  manche PDFs (v.a. mit ungewöhnlichen internen Font-/Encoding-Deklarationen) liefern
  sonst Byte-Sequenzen, an denen der anschließende Embedding-Request scheitern würde.
- Die Live-Suche (`search()`) berücksichtigt jetzt auch exklusiv indexierte Quellen
  (PDFs UND YForm-Tabellen) unabhängig davon, ob der jeweilige Content-Provider zusätzlich
  für den globalen Shared Pool aktiviert ist – vorher tauchten profil-exklusive Inhalte im
  Chat/RAG-Pfad zwar auf, waren aber für die Live-Suche unsichtbar.

### Neu (Trefferhervorhebung in der Suche)
- Suchergebnis-Snippets markieren den gefundenen Suchbegriff jetzt optisch
  (`<mark>`-Hervorhebung mit der Akzentfarbe des Widgets). Serverseitig sauber
  HTML-escaped, damit ein Suchbegriff mit `<`/`&`/Anführungszeichen die Darstellung nicht
  durcheinanderbringen oder Markup einschleusen kann.

### Geändert (Inkrementelles Refresh läuft jetzt immer im Hintergrund)
- „Refresh (inkrementell)" lief bisher als einzelner, synchroner Request im Browser-Tab
  (Timeout-Risiko bei größerem Index, Tab musste offen bleiben). Läuft jetzt wie „Im
  Hintergrund indexieren" über den entkoppelten Server-Worker (`Api\ReindexWorker`, jetzt
  mit `mode=incremental`) – Fortschritt kommt live aus `IndexRunStore`, der Tab kann
  geschlossen werden. Braucht dieselbe Server-Voraussetzung (shell_exec + curl/wget) wie
  der Hintergrund-Button und ist entsprechend deaktiviert, wenn die nicht verfügbar ist.

### Behoben (Live-Suche ignorierte den Profil-Scope komplett)
- **`search()` filterte nie nach `profile_id`**: Ein Profil mit „Nicht nutzen" bei
  „Gemeinsamer Wissens-Pool" (isolierter Wissensstand) bekam in der Live-Suche trotzdem
  Treffer aus dem Shared Pool und aus fremden Profilen zu sehen, sobald der Quellentyp
  passte – der Chat/RAG-Pfad (`findSimilarContent()`) hatte diese Profil-Grenze schon
  immer, die Suche kannte sie gar nicht. Jetzt dieselbe Regel: `use_shared_scope=1` sieht
  Shared Pool + eigene Quellen, `use_shared_scope=0` ausschließlich die eigenen.

### Behoben (Profil-Erzwingung von Chat/Suche wurde serverseitig ignoriert)
- **`resolveFrontendAccessDenial()` prüfte nur die globalen Schalter**
  („Chat im Frontend anzeigen"/„Suche im Frontend aktivieren"), nicht das
  Tri-State-Override eines Profils (`chat_enabled`/`search_enabled`). Waren
  beide globalen Schalter aus, blockte dieser Endpoint jede Anfrage mit einer
  leeren, fehlerfreien Antwort – selbst wenn `boot.php` das Widget wegen
  eines Profil-Force-On längst korrekt angezeigt hatte. Prüft jetzt dieselbe
  Tri-State-Logik wie `boot.php`.
- `pages/settings.access.php` warnt jetzt direkt neben den globalen
  Sichtbarkeits-Schaltern, wenn ein oder mehrere aktive Profile Chat/Suche
  per Tri-State erzwingen, samt Link zur Profil-Seite.

### Behoben (Markdown-Formatierung von KI-Antworten ging teilweise verloren)
- Mehrzeilige Code-Beispiele kamen vom Modell gelegentlich als einzelne
  Inline-Backticks statt eines Codeblocks zurück, und Listen direkt nach
  einem Absatz ohne Leerzeile wurden nicht als Liste erkannt (CommonMark/
  Parsedown-Regel) – beides ließ die Antwort als ein einziger,
  unformatierter Absatz erscheinen. System-Prompt aller vier Provider weist
  das Modell jetzt explizit auf saubere Markdown-Formatierung hin
  (`PromptBuilder::markdownFormattingInstruction()`); zusätzlich fügt
  `normalizeAnswerMarkdown()` als Sicherheitsnetz eine Leerzeile vor
  Listenzeilen ein, die direkt auf Fließtext folgen.

## [1.0.0] - 2026-09-02

Kompletter Neuanfang: Das Addon heißt jetzt `ai_chat` (vormals `klxmchat`/„KLXM
Chat & Search"), ist als FriendsOfREDAXO-AddOn aufgesetzt und beginnt seine
Versionierung bei 1.0.0. Keine automatische Migration bestehender
`klxmchat`-Installationen (Daten/Config bleiben unangetastet, `ai_chat` startet
leer) – siehe README für Details.

### Rebrand
- Verzeichnis, PHP-Namespace (`FriendsOfRedaxo\AiChat`), DB-Tabellen
  (`ai_chat_*`), Config-Namespace, Permission (`ai_chat[...]`),
  `rex-api-call`-Endpunktnamen (`ai_chat_query`/`_index`/`_test`/
  `_cloudflare_models`/`_reindex_worker`), Custom-Element-Tags
  (`<ai-chat>`/`<ai-search>`) und Asset-Dateinamen einheitlich umbenannt.
- Tote Legacy-Widget-Dateien entfernt (`klxm-chat.js`, `-v2.js`, `-v3.js`,
  `-bundle.js` – geladen wurde ohnehin nur die vormalige `-v4.js`, jetzt
  `ai-chat.js`).
- Umstellung auf MIT-Lizenz (vormals kommerziell lizenziert).

### Profile statt globaler Konfiguration
- **Mehrere Chat-Profile statt einer globalen Konfiguration**: Jedes Profil
  hat eigenen Kontext (Frontend/Backend/Beides), eigene Sichtbarkeit
  (Besucher/Redakteure/Admins), eigene Zielgruppe (alle/bestimmte
  Domains/bestimmte Sprachen), eigenen Prompt, eigene Begrüßung/Anrede/
  Personalisierung/Reset-Countdown/Verlauf-kopieren-Einstellung sowie eine
  eigene Oberflächen-Sprache (`ui_language`) und optionale KI-Antwortsprache.
  Ein Default-Profil wird bei der Installation automatisch angelegt, sodass
  ein frischer Install ohne weitere Konfiguration sofort einen
  funktionierenden Chat zeigt.
- **Wissens-Scope pro Profil**: Die globale Indexierung bleibt als „Shared
  Pool" bestehen. Ein Profil sieht diesen Pool zusätzlich zu seinen eigenen
  Quellen, wenn „Nutze Shared Scope" aktiviert ist – oder ausschließlich
  seine eigenen, exklusiv zugeordneten Inhalte, wenn nicht. Profile können
  eigene Quellen indexieren: eine Auswahl bestimmter YForm-Mappings, eine
  eigene Sitemap (in benannte Bereiche wie „Allgemein"/„News" gruppierbar),
  oder ein Struktur-Mountpoint.
- **Eigener Prompt pro Profil**, providerübergreifend – wirkt nur im
  Frontend-Zweig; der feste Developer-Systemprompt bleibt unverändert.
- **„Profil testen"**: Jede Profil-Bearbeitungsseite zeigt ein echtes,
  sticky positioniertes `<ai-chat>`-Widget fest gebunden an genau dieses
  Profil – testet den gespeicherten Stand inkl. Prompt, Anrede,
  Wissens-Scope und Theme.
- **Theme je Profil**: Akzentfarbe, weitere Farben, Eckenradius, Position
  und Avatar überschreiben die globale Darstellung nur für dieses Profil –
  leer gelassen gilt die globale Einstellung. Live-Vorschau beim Tippen,
  „Theme zurücksetzen"-Button.
- **Chat/Suche je Profil erzwingbar**, unabhängig von der globalen
  Einstellung für andere Profile.
- **Backend-Seite „Profile"** zum Anlegen/Bearbeiten/Löschen – interaktiv
  (kontext-/zielgruppe-/quelle-abhängige Felder blenden sich automatisch
  ein/aus) mit Info-Panel und Tooltips. Warnt vor Prioritäts-Kollisionen
  zwischen zwei Profilen mit gleicher Priorität.
- `boot.php` löst pro Aufruf EIN Profil auf (`ProfileResolver`) und steuert
  damit Chat UND Suche im Frontend gemeinsam.
- Serverseitige Zugriffskontrolle: Die vom Client mitgeschickte `profile_id`
  wird bei echten Frontend-Anfragen ignoriert und serverseitig neu
  zugeordnet – nur Backend-Nutzer dürfen eines explizit wählen (für „Profil
  testen" und den Backend-Chat).
- Optionaler Sprachfilter je YForm-Mapping (Sprach-Spalte + clang-IDs).

### Suche
- Eigener „Suchen"-Button neben der Live-Suche mit Debounce.
- Trefferliste erscheint sofort; eine optionale KI-Zusammenstellung über
  mehrere Bereiche lädt asynchron nach (eigene Beschriftung „Überblick über
  die Bereiche" und eigene Gestaltung, damit sie nicht mit einer direkten
  Antwort verwechselt wird).
- Benannte Sitemap-Bereiche erscheinen als eigene Facette neben den
  bestehenden Typ-Filtern.
- Das Suchfenster schließt sich nicht mehr versehentlich, wenn eine
  Text-Selektion per Maus-Drag über den Rand des Suchfelds hinausgeht.

### Übersetzbare Widget-Oberfläche
- Chat- und Such-Widget sind übersetzbar (Buttons, Platzhalter,
  Statusmeldungen – nicht die KI-Antwort selbst): JSON-Sprachdateien
  (`assets/i18n/de.json`, `en.json`), PHP-Loader (`WidgetTranslator`, mit
  Extension Point `AI_CHAT_WIDGET_TRANSLATIONS`) sowie ein gemeinsamer,
  minimaler JS-Loader für Chat und Suche (`assets/ai-i18n.js`). Gesteuert
  über das `ui-language`-Feld des Profils. Neue Sprache = neue JSON-Datei,
  kein Code nötig; fehlende Schlüssel fallen auf Deutsch zurück.

### Natives Vektor-Retrieval
- MariaDB `VECTOR`/`VECTOR INDEX` wird automatisch genutzt, wenn verfügbar
  (ab 11.7 Preview/11.8 GA) – deutlich schnellere Ähnlichkeitssuche als die
  PHP-Brute-Force-Berechnung bei großem Index. Capability-detected
  (`VectorCapability`, „Neu prüfen"-Button auf AI Chat → Indexierung), die
  PHP-Berechnung bleibt als Fallback für ältere MariaDB/MySQL-Versionen
  erhalten – beide stehen hinter einem gemeinsamen
  `RetrievalStrategyInterface`. Spalte/Index werden beim vollen Reindex
  automatisch angelegt/aktualisiert, inkl. sauberem Neuaufbau bei
  gewechseltem Embedding-Modell.

### Indexierung
- Chunk-Titel tragen keinen technischen „(N)"-Suffix mehr in
  Suchergebnissen.
- Ausschluss-Selektoren (`index_http_exclude_selectors`) werden jetzt auch
  für Sitemap-URLs ausgewertet, nicht nur für den HTTP-Crawl-Zweig; mehrere
  kommagetrennte Haupt-Selektoren mit mehreren Treffern statt nur des
  ersten Treffers des ersten Selektors.

### KI-Provider
- **Neuer Provider: `ai_platform`-Addon** – nutzt dessen zentrale,
  Symfony-AI-basierte Profilverwaltung (OpenAI/Anthropic/Google/
  Ollama/OpenAI-kompatibel) statt eigener API-Keys; eigene Profile für Text
  und Embedding je Zweck auswählbar. Nur sichtbar, wenn `ai_platform`
  installiert ist. `PromptBuilder` als gemeinsame Stelle für System-/
  User-Prompt-Aufbau eingeführt, damit der neue Provider die bestehende
  Logik von Gemini/OpenAI-kompatibel/Cloudflare nicht dupliziert.
- **Antwortsprache je Profil**: lässt die KI unabhängig von der
  Oberflächen-Sprache in einer festgelegten Sprache antworten,
  providerübergreifend – wirkt auch auf vorgeschlagene Folgefragen.

### Missbrauchsschutz
- Prompt-Injection-/Jailbreak-Erkennung (z.B. „ignoriere alle vorherigen
  Anweisungen") – wirkt für Chat UND Suche.
- Wiederholte Angriffsversuche beenden die Konversation ab dem dritten
  erkannten Versuch freundlich statt sie hart abzublocken.
- Erkannte Angriffe lösen eine temporäre IP-Sperre über das
  `upkeep`-Addon aus, sofern installiert.
- Sinnlose Such-/Chat-Anfragen (leer, nur Sonderzeichen, zu kurz,
  Kauderwelsch) werden erkannt, bevor sie die KI belasten.

### Robustere API-Responses
- Absicherung gegen von PHP-Warnings korrumpierte JSON-/SSE-Antworten
  (`ob_start()` + Buffer-Clean vor jedem Response) in einem gemeinsamen
  `JsonResponseTrait` konsolidiert und auf alle API-Endpunkte angewendet.
- Ein neuer Sende-Vorgang oder ein Reset bricht eine noch laufende (auch
  streamende) Antwort sauber per `AbortController` ab; ein einzelner,
  echter Netzwerkfehler löst automatisch einen einmaligen Retry aus, bevor
  die „Verbindungsproblem"-Meldung erscheint.
- Alle API-Endpunkte geben die PHP-Session sofort frei, damit
  nebeneinanderlaufende, session-gebundene Anfragen sich nicht gegenseitig
  blockieren.

### Erweiterbarkeit
Konsequent auf austauschbare Bausteine übertragen statt hart verdrahtet –
jeweils Interface/Registry + Extension Point:
- `AI_CHAT_CONTENT_PROVIDERS` – eigene Content-Provider registrieren.
- `AI_CHAT_REGISTER_PROVIDERS` – eigene KI-Provider registrieren.
- `AI_CHAT_PROFILE_CANDIDATES` – Profil-Auswahl vor der finalen Entscheidung
  filtern/umsortieren.
- `AI_CHAT_WIDGET_TRANSLATIONS` – zusätzliche Sprachen/Schlüssel für die
  Widget-Oberfläche nachliefern.

## Ältere Historie

Vor `ai_chat` hieß dieses Addon `klxmchat`/„KLXM Chat & Search" und wurde
unter eigenem Namen bei [KLXM/klxmchat](https://github.com/KLXM/klxmchat)
entwickelt. Diese Historie ist im alten Repository weiterhin einsehbar und
wird hier nicht fortgeführt.
