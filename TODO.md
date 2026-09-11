# TODO

Frisch aufgesetzt nach der großen Entflechtung (reine, isolierte Profile statt
Hauptprofil/globaler Shared Pool, Developer-Chat entfernt - siehe CHANGELOG.md
für Details). Die alte TODO.md beschrieb ausschließlich den längst
abgeschlossenen `klxmchat` → `ai_chat`-Rebrand und ist nicht mehr relevant.

## Entschieden: forcal bleibt global (vorerst)

`forcal` (Kalendereinträge) bleibt der letzte verbliebene **globale**,
profil-unabhängige Content-Provider (`ContentProviderRegistry`,
`Einstellungen → Indexierungs-Quellen`) - Zeilen behalten `profile_id = NULL`
und sind für jedes Profil sichtbar (siehe
`ChatQueryService::buildScopeVisibilityWhere()`s Kommentar dazu). Bewusste
Entscheidung (2026-09-11): **kein** Umbau auf ein Profil-Feld analog zu
Sitemap-/Struktur-Gruppen. Stattdessen soll die Kalender-Indexierung
mittelfristig komplett aus `ai_chat` heraus- und ins `forcal`-Addon selbst
verlagert werden (eigener Content-Provider von dort aus registriert, über
`AI_CHAT_CONTENT_PROVIDERS` o.ä. - noch nicht spezifiziert). Bis dahin bleibt
`ForcalContentProvider` unverändert in `ai_chat`.

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
- ~~Echtes Hybrid-Search via MariaDB Reciprocal Rank Fusion (RRF)~~ - **umgesetzt
  (siehe CHANGELOG "Unreleased").** `lib/Retrieval/HybridRrfRetrieval.php`
  fusioniert `MATCH() AGAINST()`-Volltext-Ranking und `VEC_DISTANCE_COSINE()`-
  Vektor-Ranking per `1/(k+rank)`-Formel, ersetzt bei Aktivierung
  `NativeVectorRetrieval` komplett (nicht nur `rerankResults()`) und liefert
  bereits SQL-seitig fusionierte, auf die bestehende 0-1-Similarity-Skala
  normalisierte Ergebnisse - `rerankResults()` wird uebersprungen, wenn RRF
  aktiv ist. Opt-in per Einstellung "Hybrid-Suche" (Chunking & Cache), Default
  nur bei frischer Installation automatisch an (`install.php`), bestehende
  Installationen bleiben nach einem Update unveraendert. Live getestet
  (Vergleich beider Modi per direktem API-Call, Retrieval-Log zeigt
  `fulltext_rank`/`vector_rank`/`rrf_score_raw` pro Kontext-Item, ein
  vektoriell schwacher aber lexikalisch starker Treffer schaffte es dank RRF
  in den Top-5-Kontext). Embedding-Dimension dieser Installation bleibt bei
  4096 (oberer Rand des in der urspruenglichen Recherche als erprobt
  genannten Bereichs) - kein Performance-Problem beobachtet, aber weiter im
  Auge zu behalten.
- **Token-gated Seiten-Prompts**: Idee verworfen (siehe Diskussion) - Seiten
  sollten der KI eigene Hinweise mitgeben können, nur sichtbar für den
  authentifizierten Crawler (Header-Token). Nicht weiterverfolgt, da der
  Schutz nur vor fremden Crawlern wirkt, nicht vor Redakteuren mit
  Schreibrecht - bei Bedarf später erneut aufgreifen.

## Ideen für später

- ~~Systemprompt-Transparenz (READ-ONLY-Ansicht)~~ - **umgesetzt (siehe
  CHANGELOG "Unreleased").** Zugeklapptes Panel auf `pages/profiles.php`
  ("System-Prompt anzeigen (Debug)") zeigt provider-genau den tatsächlich
  gesendeten System-Prompt eines gespeicherten Profils
  (`AiServiceFactory::previewSystemPrompt()`, delegiert an je eine neue
  `buildSystemPromptText()`-Methode in `GeminiService`/`CloudflareService`/
  `OpenAiCompatibleService`, bzw. `PromptBuilder::buildSystemPrompt()` fuer
  `ai_platform`). Dabei einen bereits vorher bestehenden Bug gefunden und
  behoben: die drei aelteren Provider ueberschrieben den Zeit-Kontext-Satz
  sofort wieder (`=` statt `.=`), die KI bekam das aktuelle Datum dort nie
  mitgeteilt. **Noch nicht umgesetzt** (bewusst nicht Teil dieser Aenderung):
  Teil (b) der urspruenglichen Idee - ein zusaetzliches, kuratiertes
  Freitextfeld additiv zum "Eigener Prompt". Die zugrunde liegende
  4-Provider-Textduplizierung (`buildSystemPromptText()` existiert jetzt
  dreifach fast identisch) bleibt bestehen, siehe naechster Punkt.
- ~~Direkte Provider (Gemini/Cloudflare/OpenAI-kompatibel) vereinheitlichen~~ -
  **Nutzer-Entscheidung (2026-09-11): nicht Code-vereinheitlichen, sondern
  `ai_platform` als empfohlenen Weg kennzeichnen** (siehe CHANGELOG
  "Unreleased"). Die drei direkten Provider gelten jetzt als veraltet
  (Provider-Select, "Einfach"-Übersicht, README), bleiben aber vollständig
  funktionsfähig und unverändert im Code - **kein** Zusammenführen der
  Anrede-/Prompt-Logik in eine gemeinsame Stelle. `package.yml` bekommt
  bewusst **keine** Pflichtabhängigkeit auf `ai_platform` (geprüft und
  verworfen) - Installationen ohne `ai_platform` bleiben unverändert
  lauffähig. Die 4-fache Textduplizierung (`buildSystemPromptText()` in
  `PromptBuilder`/`GeminiService`/`CloudflareService`/
  `OpenAiCompatibleService`) bleibt technisch bestehen, wird aber nicht mehr
  als zu lösendes Problem verfolgt - die drei älteren Provider bekommen
  ohnehin keine neuen Features mehr.
- **Aufräumen (Code-Teil erledigt, siehe CHANGELOG "Unreleased")**: die toten
  `addon_docs`/`github_docs`-Fallcases in `ChatQueryService::
  getDefaultSourceTypeIconSvg()`/`getSourceTypeLabels()` sowie die
  entsprechenden Beispielzeilen in den Einstellungs-Hinweistexten
  (`lang/de_de.lang`) sind entfernt. **Weiterhin offen**: der
  `search_source_type_labels`-Konfigurationswert auf bereits laufenden
  Installationen (individuell in `rex_config` gespeicherter Freitext) kann
  noch `addon_docs=`/`github_docs=`-Zeilen aus der Zeit vor der
  GitHub-/AddOn-Docs-Entfernung enthalten - rein kosmetisch (Anzeige der
  Suchfilter-Bezeichnungen), keine Funktion mehr dahinter, aber lässt sich
  nicht zentral bereinigen, nur bei Gelegenheit auf der jeweiligen
  Installation.
