# klxmchat – Agent-Anweisungen

KLXM Chat ist ein REDAXO-Addon für einen KI-gestützten RAG-Chat (Frontend-Widget + Backend-Developer-Chat, Provider: Gemini/OpenAI-kompatibel/Cloudflare Workers AI).

## Architektur

- Web Component: `assets/klxm-chat-v4.js` (`<klxm-chat>`, Shadow DOM) ist die EINZIGE aktiv geladene Chat-UI. `klxm-chat-v2.js`, `-v3.js`, `-bundle.js`, `klxm-chat.js` sind unbenutzte Altlasten – vor Änderungen an "dem" Chat-Widget immer erst in `boot.php` prüfen, welche Datei tatsächlich per `<script>` eingebunden wird.
- Zwei Einbindungs-Kontexte in `boot.php`, die sich NIE gegenseitig beeinflussen dürfen:
  - **Backend-Chat** (`scope="developer"`, `allow-scope-switch="true"`, `scope-accent="true"`): kein individuelles Branding, dafür eine Scope-Akzentfarbe (`data-scope`-Attribut + CSS in `klxm-chat-v4.js`).
  - **Frontend-Chat** (`scope="frontend"`): individuelles Branding über `primary-color`/`avatar-url` sowie Theme-Editor-CSS-Variablen (`--klxm-chat-header-bg`, `-bg`, `-text`, `-bot-msg-bg`, `-radius`), injiziert als kleiner `<style>`-Block – nur bei validen Hex-/Zahlenwerten.
- Kernservices in `lib/Service/`: `IndexerService` (Indexierung/Chunking/HTML-Cleaning), `ChatQueryService` (RAG-Retrieval, Antwortgenerierung, System-Tools), `GeminiService`/`OpenAiCompatibleService`/`CloudflareService` (Provider-spezifisch, inkl. SSE-Streaming).
- Settings liegen in nativen REDAXO-Subpages (`pages/settings.<name>.php`), gemeinsamer Code (Tooltip-Helper, Tipps-Panels, Formular-Boilerplate) in `pages/settings.shared.php` und wird per `require` eingebunden – NICHT mehr eine einzelne Accordion-Seite.

## Kritische Gotchas (immer beachten)

- `$addon->i18n()` / `rex_i18n::msg()` sind bereits HTML-escaped – NIE zusätzlich mit `rex_escape()` umwickeln (führt zu sichtbarem `&quot;`-Doppel-Escaping). Nur `rex_i18n::rawMsg()` liefert unescapten Text.
- `rex_sql::escape()` liefert bereits inklusive umschließender Anführungszeichen (`PDO::quote()`) – KEINE manuellen `'...'` zusätzlich setzen, sonst SQL-Syntaxfehler.
- `rex_extension::register()`-Callbacks in `boot.php` immer mit `is_callable([$class, 'method'])` absichern, bevor sie registriert werden – sonst kann ein einzelner Bug in diesem Addon den kompletten REDAXO-Backend-Boot crashen.
- PHPDoc-Blöcke (`/** ... */`) dürfen NIE die Zeichenfolge `*/` mitten im Fließtext enthalten (z. B. „ART_*/SLICE_*" als Aufzählung) – schließt den Kommentar vorzeitig und erzeugt einen scheinbar unbeteiligten Parse-Fehler weiter unten.
- `.lang`-Dateien unterstützen nur einzeilige `key = value`-Paare. Mehrzeilige Inhalte (z. B. Tipps-Listen) über einen Trenner wie `||` codieren und in PHP splitten.
- System-Tool-Tokens (`[[ACTION:NAME]]`, ausgeführt via `SystemToolService::execute()`, nur `scope=developer`) müssen SOWOHL im Streaming- als auch im Nicht-Streaming-Antwortpfad korrekt und NUR EINMAL ausgeführt werden (siehe `ChatQueryService::wrapOnChunkForSystemTools()` / `replaceActionsWithPrecomputedResults()`). Bei jedem neuen token-artigen Feature in KI-Antworten diesen Doppelpfad prüfen.
- Neue PHP-Variablen, die in einer Closure innerhalb von `boot.php` verwendet werden, müssen ins `use (...)` aufgenommen werden – sonst nur zur Laufzeit sichtbare "Undefined variable"-Warnings, die bei aktivem Debug-Modus zu kaskadierenden "headers already sent"-Fehlern führen können.
- Kategorie-/Artikel-Ausschluss-Logik gibt es an ZWEI Stellen in `IndexerService` (`collectTasks()` für den vollen Reindex, `isExcluded()` für die Live-Reindizierung einzelner Artikel) – beide MÜSSEN identisch entscheiden. Der Start-Artikel einer Kategorie hat `parent_id` = Elternkategorie, aber seine eigene Artikel-id === Kategorie-id (`rex_article::getCategoryId()`/`isStartArticle()`); Ausschluss-Prüfungen anhand von `parent_id` allein übersehen deshalb den Start-Artikel der ausgeschlossenen Kategorie selbst.
- Für hierarchische Auswahlfelder (Kategorien) IMMER zuerst prüfen, ob REDAXO-Core/ein Addon bereits eine fertige `rex_*_select`-Klasse hat (z. B. `rex_category_select` aus dem `structure`-Addon), statt selbst rekursiv Optionen mit manuellen Leerzeichen einzurücken – normale Leerzeichen werden in `<option>`-Labels vom Browser kollabiert, nur `&nbsp;` (wie in `rex_select::outOption()`) funktioniert zuverlässig.
- Bei „Artikel X sollte laut SEO-/Sitemap-Addon eigentlich nicht erfasst werden, landet aber trotzdem im Index“: prüfen, ob das jeweilige Addon (z. B. `yrewrite` mit dem Metainfo-Feld `yrewrite_index`) einen eigenen Ausschluss-Mechanismus hat, den `IndexerService` noch nicht kennt.
- Nach Refactorings von Methoden mit vielen Rückgabe-Schlüsseln (z. B. `YformProfiles::normalizeProfile()`) IMMER das komplette Rückgabe-Array UND die direkt benachbarten Methoden-Docblocks gegenlesen – Code kann syntaktisch gültig, aber funktionslos in einen FREMDEN PHPDoc-Kommentarblock verrutschen (bleibt für `php -l` unsichtbar, wirkt wie tote Doku-Zeilen). Typisches Symptom: „Einstellung X wird beim Speichern nie übernommen“.
- Bei mehreren fast identischen `sprintf()`-Aufrufen (z. B. die beiden `<klxm-chat ...>`-Tags in `boot.php` für Backend- und Frontend-Einbindung) nach jeder Änderung an Platzhaltern IMMER Platzhalter-Anzahl im Format-String gegen Anzahl der Argumente durchzählen – `php -l` erkennt sprintf-Argument-Mismatches NICHT, es gibt nur einen `ArgumentCountError` zur Laufzeit (legt bei einem `OUTPUT_FILTER`-Extension-Point das komplette Backend lahm).
- Wiederverwendbare Formular-Helper, die selbst den `name`-Attributpfad bauen (z. B. `renderColumnSelect()` in `pages/yform.php`, Muster `profiles[X][feld]`), dürfen NIE unverändert für Repeater-Zeilen (Zusätzliche Felder/Bedingungen) verwendet werden – dort MUSS der volle Pfad `profiles[X][collection][rowIndex][feld]` explizit übergeben werden (Override-Parameter), sonst teilen sich alle Zeilen denselben Formularnamen und nur die letzte Zeile überlebt im POST (kein PHP-Fehler, nur stiller Datenverlust). Bei „Repeater-Zeilen verschwinden beim Speichern“-Reports die tatsächlich gerenderten `name`-Attribute durchzählen, am besten mit einem isolierten `parse_str()`-Testskript.
- Auswahlfelder ohne explizit als `selected` markierte leere Option lassen den Browser automatisch die ERSTE Option auswählen (z. B. eine interne YForm-Systemspalte) – bei dynamisch befüllten Selects (Repeater-Zeilen, Bedingungen) IMMER eine leere Platzhalter-Option mit `selected` bei leerem Wert rendern, sonst wird ein unbeabsichtigter Wert stillschweigend gespeichert.
- Für Nachrichtenlängen-/Rate-Limit-artige Guards in `ChatQueryService::process()` gilt dieselbe Reihenfolge-Konvention wie bei `evaluateCodeInjectionGuard()`/`evaluateSpamGuard()`/`evaluatePrivacyGuard()`: billigste Prüfung zuerst (z. B. `evaluateMessageLengthGuard()` direkt nach `checkRateLimit()`, vor Regex-lastigen Guards), scope-abhängige Config-Keys (`_frontend`/`_backend`) für Backend-/Developer-Chat bewusst großzügiger als Frontend.
- `ChatQueryService::extractSearchTerms()` (SQL-`LIKE`-Suchbegriffe für Keyword-Fallback-Suchen) und `extractRelevantTokens()` (Anzeige-Relevanzfilter) sind ZWEI getrennte Filterstellen mit potenziell unterschiedlicher Stoppwortabdeckung (gemeinsame Stoppwortliste: `getGermanStopwords()`) – bei RAG-Relevanz-Bugs immer BEIDE prüfen, ein Fix an nur einer Stelle reicht oft nicht.
- YForm-Mappings kennen keinen „Intern (yform://)“-URL-Modus mehr (entfernt) – nur noch „Aus Feldwert“, „URL-Profil (Namespace)“, „Template“; `YformContentProvider::buildUrl()` liefert bei keinem greifenden Modus `''` (kein Link) statt einer Platzhalter-URL. `ChatQueryService::collectDisplaySources()` zeigt ohnehin nur `http(s)://`-URLs an.

## RAG-/Antwortqualität-Debugging – Checkliste

Bei Berichten wie „falsche/fehlende Antwort", „Fakten vermischt" (z. B. Zahlen/Namen laufen zusammen), oder „Person X wird manchmal nicht erkannt" IMMER zuerst:

1. Zeilenzahl in `klxm_chat_index` vs. `rag_candidate_limit` vergleichen (Limit muss größer sein, sonst werden Inhalte beim Ähnlichkeitsvergleich nie berücksichtigt).
2. `chunk_size`/`chunk_overlap` prüfen.
3. Auf Tippfehler/Schreibvarianten in der Nutzerfrage prüfen (Fuzzy-Matching läuft über `ChatQueryService::tokenMatchesText()`, Levenshtein-Toleranz ab Wortlänge 5).
4. Bei „zusammengeklebten" Fakten zuerst `IndexerService::cleanText()` mit einem isolierten Testskript gegen das exakte gemeldete HTML durchspielen, statt zu spekulieren (siehe Skill `klxmchat-rag-debugging`).

## Build & Test

- PHP-Syntax nach jeder Änderung: `php -l <datei>`
- JS-Syntax nach jeder Änderung an der Web-Component-Datei: `node --check assets/klxm-chat-v4.js`
- Kein Bundler/Build-Schritt – JS/CSS werden direkt aus `assets/` ausgeliefert (Cache-Busting via `filemtime()` in `boot.php`, kein Versionsbump in `package.yml` nötig).

## Konventionen

- Neue Einstellungen: eigene `pages/settings.<name>.php` mit eigenem `rex_config_form::factory('klxmchat')`, gemeinsamen Code aus `settings.shared.php` wiederverwenden.
- Seitenrechte ausschließlich über `perm:` in `package.yml` (`klxmchat[]` allgemein, `klxmchat[chatadmin]` für Admin-Bereiche). Nicht seitengebundene Rechte (z. B. `klxmchat[backend_chat]`) explizit per `rex_perm::register()` in `boot.php` registrieren.
- Deutsche Doku-Texte (README/CHANGELOG/`.lang`) mit Umlauten (ä/ö/ü/ß) schreiben, keine erzwungenen ASCII-Umschreibungen (ae/oe/ue/ss).
- Alle Änderungen an einer Version im `CHANGELOG.md` unter der passenden `## [version]`-Überschrift dokumentieren.
