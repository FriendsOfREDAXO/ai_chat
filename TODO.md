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

## Ideen für später: RAG-Qualität

Ausgangspunkt: ein Abgleich gegen eine externe Best-Practice-Checkliste (Chunk-
Metadaten, Query-Rewriting, Multi-Query, Re-Ranking, Hybrid Search, saubere
Trennung Verlauf/Retrieval, vollständiges Retrieval-Logging). Größte Hebel,
noch nicht priorisiert/umgesetzt:

- **Re-Ranking + mehr Kandidaten holen**: `rag_results` liefert heute per
  Default nur 3 Treffer direkt aus der rohen Cosine-Similarity an die KI,
  ohne Zwischenschritt. Idee: `rag_candidate_limit`-Fenster nutzen, davon
  die Top ~20 nehmen und per kurzem, günstigem LLM-Aufruf (nur Titel+Snippet
  pro Kandidat, nicht Volltext) nach echter Relevanz zur Frage neu sortieren
  lassen, erst danach die besten 5-8 in den Prompt geben. Kein neuer
  Provider nötig (bestehende `AiServiceFactory`). Tradeoff: ein zusätzlicher
  LLM-Roundtrip pro Chatnachricht (Latenz + Kosten).
- **URL-Pfad als Kategorie-Metadaten fürs Embedding**: Sitemap-Inhalte haben
  keine echte REDAXO-Kategorie, aber meist sprechende URL-Segmente (z.B.
  `/agentur/leistungen/webentwicklung/`). Aktuell fließt der Pfad nur
  *nachträglich* als Stichwort-Fallback ein (`ensureKeywordMatchedContext()`/
  `scoreSearchHit()`/`stripUrlHost()`), nie *vorher* ins Embedding selbst.
  Idee: für `source_type='sitemap_url'` die Pfadsegmente in lesbare Labels
  umwandeln und als zusätzliche Metadaten-Zeile in
  `IndexerService::prepareEmbeddingText()` aufnehmen (analog zu Titel/URL/
  Typ dort). Nur so gut wie die tatsächliche URL-Struktur der Seite, wirkt
  erst nach Reindex.
- **JSON-LD-Extraktion erweitern**: `extractJsonLdFacts()`/
  `jsonLdNodeToFacts()` liest bereits `Person`/`Organization`/
  `ContactPoint`/`LocalBusiness` aus (Name/Rolle/Kontakt). Noch nicht
  abgedeckt: `BreadcrumbList` (verlässlicheres Kategorie-Signal als geratene
  URL-Segmente, siehe vorheriger Punkt - wo vorhanden nutzen, sonst auf
  URL-Segmente zurückfallen), `FAQPage` (Frage/Antwort-Paare direkt als
  Fakten) und `openingHours`/`OpeningHoursSpecification`.

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
