# TODO

Frisch aufgesetzt nach der großen Entflechtung (reine, isolierte Profile statt
Hauptprofil/globaler Shared Pool, Developer-Chat entfernt - siehe CHANGELOG.md
für Details). Die alte TODO.md beschrieb ausschließlich den längst
abgeschlossenen `klxmchat` → `ai_chat`-Rebrand und ist nicht mehr relevant.

## Offene Entscheidung: forcal

`forcal` (Kalendereinträge) ist aktuell der letzte verbliebene **globale**,
profil-unabhängige Content-Provider (`ContentProviderRegistry`,
`Einstellungen → Indexierungs-Quellen`) - ein Rest aus der Zeit vor der
Profil-Entflechtung. Noch nicht entschieden, wie es weitergehen soll:

- **Option A - global bleiben**: forcal indexiert weiterhin alle
  Kalendereinträge unabhängig von Profilen (Status quo), Zeilen bekommen
  `profile_id = NULL` und sind für jedes Profil sichtbar (siehe
  `ChatQueryService::buildScopeVisibilityWhere()`s Kommentar dazu).
- **Option B - je Profil**: forcal-Kategorien werden wie Sitemap-/
  Struktur-Gruppen ein Profil-Feld (welche Kalender-Kategorien DIESES Profil
  sehen soll), analog zum bereits umgesetzten Muster bei Sitemap-/
  Struktur-Bereichen. Größerer Umbau: `ForcalContentProvider`,
  `ChatProfile`, `pages/profiles.php`, `install.php` betroffen.

Bis zur Entscheidung bleibt es wie es ist (Option A, unverändert).

## Entschieden: kein natives MySQL-Feature-Parity-Ziel

`NativeVectorRetrieval` (echtes `VEC_DISTANCE_COSINE()`/`VECTOR INDEX`) läuft
nur auf MariaDB ab 11.7 - auf allem anderen (aeltere MariaDB, jedes MySQL)
greift automatisch `BruteForceRetrieval` (PHP-seitiger Vergleich, siehe
`resolveRetrievalStrategy()`), das Addon bleibt also ueberall lauffaehig.
Entschieden (Nutzer-Vorgabe): geplante Erweiterungen dieses nativen Pfads
(siehe Hybrid-Search-Idee unten) werden bewusst NICHT auf MySQL-Kompatibilitaet
hin entworfen oder getestet - reines MariaDB-Feature, kein Rueckbau-Aufwand fuer
den PHP-Fallback, falls MySQL das nicht kann.

## Ideen für später: RAG-Qualität (Fortsetzung)

Ausgangspunkt war ein Abgleich gegen eine externe Best-Practice-Checkliste
(Chunk-Metadaten, Query-Rewriting, Multi-Query, Re-Ranking, Hybrid Search,
saubere Trennung Verlauf/Retrieval, vollständiges Retrieval-Logging). Bereits
umgesetzt: Re-Ranking (Heuristik statt LLM-Aufruf, siehe unten),
Kategorie-Pfad-Metadaten (echte REDAXO-Kategorie für Struktur-Inhalte,
geratene URL-Segmente als Fallback für Sitemap/YForm/Provider-URLs),
konfigurierbare Metainfo-Felder als Zusatzkontext, JSON-LD-Erweiterung um
`BreadcrumbList`/`FAQPage`/Öffnungszeiten, sowie ein optionales
Retrieval-Debug-Log (`ai_chat_retrieval_log`, `ChatQueryService::
logRetrievalDebug()`, standardmäßig aus - Toggle unter "Chunking & Cache" →
"Debugging", Auswertung unter Index → Retrieval-Log, hält Query/Profil/
Scope/ausgewählte Chunks samt Similarity fest, automatische 7-Tage-
Bereinigung). Einstellungen dazu unter "Chunking & Cache" →
"Kontext-Anreicherung" bzw. "RAG-Abruf".

Noch offen:

- **Re-Ranking ist aktuell nur eine Heuristik** (gewichtete Mischung aus
  normalisierter Similarity und Stichwort-Überdeckung mit der Frage,
  `ChatQueryService::rerankResults()`) statt eines echten Modell-basierten
  Re-Rankings. Ein LLM-Aufruf zum Neusortieren der Kandidaten (nur
  Titel+Snippet pro Kandidat, nicht Volltext) wäre der nächste
  Qualitätsschritt, braucht aber eine für alle vier Provider (Gemini/
  Cloudflare/OpenAI-kompatibel/ai_platform) einheitliche
  "kurze, strukturierte Antwort"-Schnittstelle, die es heute noch nicht gibt
  (`AiServiceInterface` kennt nur `generateAnswer()` für vollständige
  Chat-Antworten). Tradeoff: ein zusätzlicher LLM-Roundtrip pro
  Chatnachricht (Latenz + Kosten). Das neue Retrieval-Log liefert jetzt die
  Datengrundlage, um zu beurteilen, ob sich das überhaupt lohnt.
- **Query-Rewriting/Multi-Query**: aus der ursprünglichen Checkliste weiterhin
  nicht umgesetzt - die Nutzerfrage geht unverändert (nur um die letzten 4
  Gesprächsturns ergänzt) ins Embedding, keine Umformulierung in eine
  präzisere Suchanfrage, keine mehreren Suchvarianten.
- **Echtes Hybrid-Search via MariaDB Reciprocal Rank Fusion (RRF), statt der
  aktuellen PHP-Heuristik.** Recherche (2026-09-06, siehe MariaDB-Doku zu
  Vektoren/RRF sowie nevercodealone.de-Blogpost zu MariaDB Vector) ergab: MariaDB
  unterstuetzt eine native RRF-Query fuer genau dieses Problem - zwei
  Kandidaten-CTEs (ein `MATCH() AGAINST()`-Volltext-Ranking, ein
  `VEC_DISTANCE_COSINE()`-Vektor-Ranking), je mit `RANK() OVER()` bewertet, per
  `1/(k+rank)`-Formel gemergt (`FULL OUTER JOIN`-Ersatz via `LEFT JOIN` +
  `UNION` + `IFNULL()`). Das ersetzt `ChatQueryService::rerankResults()`s
  aktuelle Mischung aus normalisierter Similarity und grobem
  Stichwort-Treffer-Zaehler (`extractRelevantTokens()`/`tokenMatchesText()`)
  durch echtes TF-IDF-artiges Volltext-Ranking statt reinem Wort-Overlap -
  adressiert direkt das dokumentierte "thematisch zufaelliger Treffer schlaegt
  tatsaechlich passendere Seite"-Problem. Voraussetzungen bereits erfuellt:
  Instanz laeuft auf MariaDB 11.8.9 (RRF-Window-Functions seit 10.2,
  `VEC_DISTANCE_COSINE()`/`VECTOR INDEX` seit 11.7 verfuegbar), `embedding_vector`-
  Spalte + `VECTOR INDEX` existieren bereits (`NativeVectorRetrieval.php`) - es
  fehlt nur ein `FULLTEXT INDEX` auf `content`/`title` in `ai_chat_index`
  (aktuell nur BTREE-Indizes, siehe `install.php`), den es noch nie gab (die
  bisherige Stichwort-Suche in `search()`/`extractSearchTerms()` nutzt reines
  `LIKE '%term%'`, also ohnehin ungeindext). Aufwand: neue
  `FULLTEXT INDEX`-Migration, eine neue/erweiterte Retrieval-Strategie-Klasse
  (nur fuer den ohnehin schon MariaDB-exklusiven `NativeVectorRetrieval`-Pfad,
  siehe Entscheidung oben - kein Bedarf, das im PHP-Fallback nachzubauen),
  plus Nachziehen im Retrieval-Log (RRF-Teilscores mitprotokollieren waere fuer
  die Fehlersuche wertvoll). Zu beachten: der Blogpost nennt ~4096 Dimensionen
  als praktisch getestete Obergrenze - unsere Embeddings laufen exakt bei
  4096, also am oberen Rand des Erprobten (aktuell kein bekanntes Problem,
  aber im Auge behalten).
- **Token-gated Seiten-Prompts**: Idee verworfen (siehe Diskussion) - Seiten
  sollten der KI eigene Hinweise mitgeben können, nur sichtbar für den
  authentifizierten Crawler (Header-Token). Nicht weiterverfolgt, da der
  Schutz nur vor fremden Crawlern wirkt, nicht vor Redakteuren mit
  Schreibrecht - bei Bedarf später erneut aufgreifen.

## Ideen für später

- **Feste Systemprompt-Zusatzregeln einsehbar/erweiterbar machen**: die
  fest im Code verankerten Zusatzregeln (Markdown-Formatierung,
  "[Bereich: ...]"-Regel, Metadaten-Zeilen-Regel, Themen-Trennungsregel -
  siehe CHANGELOG 2026-09-06) sitzen ausschließlich in `PromptBuilder::
  buildSystemPrompt()`/`markdownFormattingInstruction()`, identisch
  dupliziert in `GeminiService`/`CloudflareService`/`OpenAiCompatibleService`.
  Kein Backend-Einblick, keine Möglichkeit, sie pro Profil oder global zu
  verfeinern/ergänzen, ohne Code zu ändern - bei der Fehlersuche zum
  "HTML-Codeblock statt Liste"-Fall musste der komplette effektive
  System-Prompt erst aus dem Code rekonstruiert werden. Idee: (a) eine
  Einstellungs-/Debug-Seite, die den vollständig zusammengesetzten
  System-Prompt für ein gewähltes Profil READ-ONLY anzeigt (Transparenz/
  Fehlersuche), und/oder (b) ein Textfeld für zusätzliche, kuratierte
  Zusatzregeln - additiv zum "Eigener Prompt"-Feld, nicht ersetzend -, die
  ohne Codeänderung ergänzt werden können. Berührt dieselbe
  4-Provider-Duplizierung wie der nächste Punkt - guter Anlass, beides
  zusammen anzugehen.
- **Direkte Provider (Gemini/Cloudflare/OpenAI-kompatibel) vereinheitlichen**:
  bewusst nicht Teil der letzten Entflechtung (Nutzer-Entscheidung: "Provider-
  Wahl bleibt bestehen"). Falls später doch auf `ai_platform` als einzigen
  Provider reduziert werden soll, dupliziert sich aktuell noch dieselbe
  Anrede-Fallback-Logik über vier Klassen (`PromptBuilder`, `GeminiService`,
  `CloudflareService`, `OpenAiCompatibleService`).
- **Aufräumen**: `search_source_type_labels`-Default-Konfigurationstext auf
  bereits laufenden Installationen kann noch `addon_docs=`/`github_docs=`-Zeilen
  aus der Zeit vor der GitHub-/AddOn-Docs-Entfernung enthalten - rein
  kosmetisch (Anzeige der Suchfilter-Bezeichnungen), keine Funktion mehr
  dahinter, aber es lohnt sich, das bei Gelegenheit auf der jeweiligen
  Installation zu bereinigen.
