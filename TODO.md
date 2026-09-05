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

## Ideen für später: RAG-Qualität (Fortsetzung)

Ausgangspunkt war ein Abgleich gegen eine externe Best-Practice-Checkliste
(Chunk-Metadaten, Query-Rewriting, Multi-Query, Re-Ranking, Hybrid Search,
saubere Trennung Verlauf/Retrieval, vollständiges Retrieval-Logging). Bereits
umgesetzt: Re-Ranking (Heuristik statt LLM-Aufruf, siehe unten),
Kategorie-Pfad-Metadaten (echte REDAXO-Kategorie für Struktur-Inhalte,
geratene URL-Segmente als Fallback für Sitemap/YForm/Provider-URLs),
konfigurierbare Metainfo-Felder als Zusatzkontext, JSON-LD-Erweiterung um
`BreadcrumbList`/`FAQPage`/Öffnungszeiten. Einstellungen dazu unter
"Chunking & Cache" → "Kontext-Anreicherung" bzw. "RAG-Abruf".

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
  Chatnachricht (Latenz + Kosten).
- **Query-Rewriting/Multi-Query**: aus der ursprünglichen Checkliste weiterhin
  nicht umgesetzt - die Nutzerfrage geht unverändert (nur um die letzten 4
  Gesprächsturns ergänzt) ins Embedding, keine Umformulierung in eine
  präzisere Suchanfrage, keine mehreren Suchvarianten.
- **Vollständiges Retrieval-Logging**: `recordUsageStat()` protokolliert nur
  grobe Nutzungsstatistik (Modus/Scope/Status/Query/Trefferzahl), keine
  Embeddings/Similarity-/Rerank-Scores/tatsächlich übergebenen Chunks - für
  gezieltes Debugging künftiger Relevanz-Reports wäre ein optionales,
  detaillierteres Debug-Log hilfreich.
- **Token-gated Seiten-Prompts**: Idee verworfen (siehe Diskussion) - Seiten
  sollten der KI eigene Hinweise mitgeben können, nur sichtbar für den
  authentifizierten Crawler (Header-Token). Nicht weiterverfolgt, da der
  Schutz nur vor fremden Crawlern wirkt, nicht vor Redakteuren mit
  Schreibrecht - bei Bedarf später erneut aufgreifen.

## Ideen für später

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
