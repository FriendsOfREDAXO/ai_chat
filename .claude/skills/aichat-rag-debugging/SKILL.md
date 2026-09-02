---
name: aichat-rag-debugging
description: 'Debug ai_chat RAG-/Antwortqualitätsprobleme: falsche, unvollständige oder unsinnige Chat-Antworten, vermischte/zusammengeklebte Fakten (z. B. Adressziffern die zusammenlaufen), Personen/Namen die nur manchmal erkannt werden, fehlende oder irreführende Quellen-Links, Inhalte die trotz Indexierung nie im Vergleich landen, oder YForm-Mappings (Zusätzliche Felder/Bedingungen/URL-Modus) die auf der Einstellungsseite nicht korrekt speichern. Use when investigating why the ai_chat AI answer is wrong, incomplete, or garbled, or why YForm-Mapping settings don''t persist.'
---

# ai_chat RAG-Debugging

Systematisches Vorgehen, um Antwortqualitäts-Bugs im `ai_chat`-Addon einzugrenzen, BEVOR an Embeddings, Prompts oder Modellen herumgeschraubt wird. Die meisten gemeldeten Probleme sind eine von vier bekannten Ursachen (siehe unten).

## Schritt 1: Kandidatenfenster prüfen

Symptom: Eine eigentlich vorhandene, indexierte Seite wird nie als Quelle gefunden/erwähnt (z. B. Kontaktseite, seltene Fachbegriffe).

1. Gesamtzahl Zeilen in `ai_chat_index` ermitteln (Indexierungs-Seite im Backend zeigt die Zahl, oder direkt per SQL: `SELECT COUNT(*) FROM ai_chat_index;`).
2. Aktuellen Wert von `rag_candidate_limit` in den Einstellungen (Indexierung & Chunking) vergleichen.
3. Ist die Zeilenzahl >= Limit, werden ältere/seltener aktualisierte Zeilen beim Ähnlichkeitsvergleich in `ChatQueryService::findSimilarContent()` komplett übersprungen (SQL-`LIMIT` vor der Cosine-Similarity-Berechnung).
4. Fix: Button „RAG-Kandidatenfenster automatisch optimieren" auf der Indexierungs-Seite nutzen, oder `rag_candidate_limit` manuell erhöhen (muss > Gesamtzahl sein).

## Schritt 2: Chunking prüfen

Symptom: Antwort wirkt abgeschnitten, oder ein Fakt fehlt obwohl der Ausgangstext ihn enthält.

- `chunk_size`/`chunk_overlap` in den Einstellungen prüfen (Standard 1000/200).
- Zu kleine `chunk_size` reißt zusammenhängende Fakten auseinander; zu kleiner `chunk_overlap` verliert Kontext an Chunk-Grenzen.

## Schritt 3: Tippfehler/Schreibvarianten in der Nutzerfrage

Symptom: „Bei fast identischer Frage manchmal erkannt, manchmal nicht" (z. B. Name mit Tippfehler wie „Pattrick" statt „Patrick").

- `ChatQueryService::tokenMatchesText()` nutzt Levenshtein-Fuzzy-Matching (ab Wortlänge 5, Distanz 1–2), aber nur bis zu dieser Toleranz.
- Bei ausbleibendem Fuzzy-Match: Nutzerfrage exakt gegen den indexierten Text abgleichen (Tippfehler-Distanz größer als der konfigurierte Schwellenwert?).

## Schritt 4: Fakten-Konflation in `cleanText()` isoliert testen

Symptom: Zwei eigentlich getrennte Fakten werden zu einer falschen Aussage verschmolzen (z. B. „Allee 10" + „47055 Duisburg" → „1047055", oder zwei unabhängige Eigenschaften einer Person werden zu einer falschen kombinierten Aussage).

**Wichtig**: Bei diesem Bug-Typ IMMER zuerst das exakte, real gemeldete HTML-Snippet vom Nutzer anfordern/bestätigen lassen, statt eine Hypothese zu raten – frühere Fixes scheiterten zunächst an falschen Annahmen über die Quellstruktur.

Vorgehen:

1. Kopiere die aktuelle Implementierung von `IndexerService::cleanText()` (und ggf. `extractJsonLdFacts()`/`jsonLdNodeToFacts()`) aus [../../../lib/Service/IndexerService.php](../../../lib/Service/IndexerService.php) in ein isoliertes Testskript nach dem Muster [scripts/test_clean_text.php](./scripts/test_clean_text.php).
2. Trage das exakte gemeldete HTML-Snippet als `$html`-Variable ein.
3. Führe das Skript mit `php scripts/test_clean_text.php` aus und prüfe die Zeilenaufteilung der Ausgabe.
4. Typische Root Causes, die bereits einmal gefixt wurden (zuerst ausschließen, bevor ein neuer Fix gebaut wird):
   - Fehlender Block-Tag in der "schließender Tag → Zeilenumbruch"-Liste (`p,div,li,tr,td,th,h1-h6,dt,dd,blockquote,section,article,ul,ol,table,address`).
   - Zwei Textfragmente ohne jegliches trennendes Element (z. B. zwei `<span>` direkt hintereinander ohne `<br>`) – dafür gibt es den generischen Catch-all, der JEDEN schließenden Tag zu einem Leerzeichen macht.
   - Strukturierte Daten in `<script type="application/ld+json">`, die vor der generischen Script-Entfernung ausgelesen werden müssten (`extractJsonLdFacts()`).
5. Nach einem Fix: Testskript-Ausgabe erneut prüfen, dann `php -l` auf die geänderte Datei, dann Testskript aus `/tmp`/dem Skill-Ordner wieder aufräumen falls temporär angelegt.

**Hinweis für den Nutzer nach jedem `cleanText()`-Fix**: Nur NEU indexierte Inhalte profitieren vom Fix – eine vollständige Reindizierung ist nötig, damit bereits indexierte Seiten den korrigierten Text erhalten.

## Schritt 5: Quellen-Links prüfen

Symptom: Irreführende oder fehlende Quellen-Links unter der Antwort.

- `ChatQueryService::collectDisplaySources()` hat harte Mindestschwellen (`topSimilarity < 0.20` → keine Quellen; ohne Token-Überlappung zusätzlich `< 0.35` → keine Quellen).
- Prüfen, ob die tatsächliche Similarity der erwarteten Quelle über oder unter diesen Schwellen liegt.
- Nur `http(s)://`-URLs werden angezeigt; interne Platzhalter-Schemas (`yform://…`, `forcal://…`) werden herausgefiltert. Wird eine YForm-Quelle nie verlinkt, prüfen ob das zugehörige Mapping-Profil (`pages/yform.php` → YForm-Tabellen) einen funktionierenden `url_mode` (Feldwert/URL-Profil/Template) konfiguriert hat – `YformContentProvider::buildUrl()` liefert sonst bewusst `''` statt einer nicht aufrufbaren Platzhalter-URL.
- Irrelevante Treffer über die Keyword-Fallback-Suche (`findForcalKeywordMatches()`/`findProviderKeywordMatches()`) deuten auf eine zu schwache Stoppwortliste in `ChatQueryService::extractSearchTerms()` hin (SQL-`LIKE`-Suche) – gemeinsame Stoppwortliste mit `extractRelevantTokens()` ist `getGermanStopwords()`.

## Schritt 6: YForm-Mappings speichern nicht korrekt (Zusätzliche Felder/Bedingungen/URL-Modus)

Symptom: Repeater-Zeilen unter „Zusätzliche Felder“ oder „Bedingungen“ auf der YForm-Mappings-Seite verschwinden beim Speichern, oder URL-Modus/URL-Profil/URL-Feld/URL-Template werden nach dem Speichern immer wieder auf den Default zurückgesetzt.

1. NIEMALS nur den PHP-Code der Render-Funktion lesen – die tatsächlich generierten `name`-Attribute jeder Zeile zählen (am einfachsten mit einem isolierten `parse_str()`-Testskript, das die erwartete Formularstruktur `profiles[X][fields][0][field]=...` nachbaut und mit dem echten HTML-Output vergleicht).
2. Prüfen, ob ein wiederverwendbarer Select-Helper (`renderColumnSelect()`) für eine Repeater-Zeile den vollen verschachtelten Pfad (`profiles[X][collection][rowIndex][feld]`) nutzt, oder fälschlich den generischen Top-Level-Pfad (`profiles[X][feld]`) – Letzteres lässt alle Zeilen eines Profils denselben Formularnamen teilen (stiller Datenverlust, kein PHP-Fehler).
3. Prüfen, ob dynamisch befüllte Selects (v.a. Bedingungen) eine explizit als `selected` markierte leere Platzhalter-Option haben – fehlt sie, wählt der Browser automatisch die erste echte Option aus, die dann fälschlich gespeichert wird.
4. Bei fehlenden URL-Feldern (`url_mode`/`url_field`/`url_profile`/`url_template`): das komplette Rückgabe-Array von `YformProfiles::normalizeProfile()` gegenlesen UND die direkt benachbarten Methoden-Docblocks auf hineinverrutschten Code prüfen (siehe `.github/copilot-instructions.md`).

## Konvention: Neue `rex-api-call`-Endpunkte

Alle Klassen im Addon liegen unter dem Namespace `FriendsOfRedaxo\AiChat\...` – das gilt auch für `rex_api_function`-Endpunkte. NIEMALS eine neue globale `rex_api_ai_chat_*`-Klasse anlegen (alte Konvention, inzwischen vollständig abgelöst).

Stattdessen, wie seit REDAXO 5.17 vorgesehen (siehe [redaxo.org/doku/5.x/api#namespace-registrierung](https://redaxo.org/doku/5.x/api#namespace-registrierung)):

1. Klasse unter `lib/Api/` anlegen, Namespace `FriendsOfRedaxo\AiChat\Api`, z. B. `lib/Api/ChatIndex.php` mit `class ChatIndex extends rex_api_function`.
2. In `boot.php` registrieren: `rex_api_function::register('ai_chat_index', \FriendsOfRedaxo\AiChat\Api\ChatIndex::class);` – der `rex-api-call`-Name (erstes Argument) ist die öffentliche Schnittstelle nach außen (JS-`fetch()`-Aufrufe etc.) und bleibt beim Umbenennen/Verschieben der Klasse unverändert.
3. Da die Klasse jetzt in einem Namespace liegt, müssen alle referenzierten globalen REDAXO-Klassen (`rex`, `rex_addon`, `rex_response`, `rex_sql`, `rex_logger`, `rex_api_exception`, `rex_socket`, …) explizit per `use` importiert werden – PHP löst unqualifizierte Klassennamen in einem Namespace NICHT automatisch in den globalen Namespace auf (anders als bei globalen Funktionen/Konstanten wie `rex_request()`). Eingebaute PHP-Klassen (`\Exception`, `\Throwable`, `\SimpleXMLElement`) werden im Addon durchgängig mit führendem Backslash referenziert statt importiert – dabei bleiben.

Beispiele für die korrekte Umsetzung: `lib/Api/ChatQuery.php`, `lib/Api/ChatIndex.php`, `lib/Api/ChatTest.php`, `lib/Api/CloudflareModels.php` sowie deren Registrierung in `boot.php`.
