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

## Ideen für später

- **Chunking-Empfehlung nach Indexgröße**: siehe CHANGELOG - falls noch nicht
  umgesetzt, wäre eine automatische Chunk-Size/Overlap-Empfehlung basierend auf
  der tatsächlichen Indexgröße/Chunk-Verteilung ein sinnvoller Ausbau der
  Indexierungs-Übersicht (Vorbild: der bereits vorhandene "RAG-Kandidatenfenster
  automatisch optimieren"-Mechanismus).
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
