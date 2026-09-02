# TODO

Stand nach dem Rebrand `klxmchat` → `ai_chat` (Version 1.0.0). Die früheren
offenen Fragen zu Vektorsuche, übersetzbarer Widget-UI und Domain-scoped
Indexierung wurden entschieden und sind Teil eines größeren, mehrphasigen
Umbaus (Profile/Scope-Editor). Diese Datei listet den aktuellen Umsetzungsstand
der einzelnen Phasen.

## Phase 0 — Rebrand `klxmchat` → `ai_chat` (in Arbeit)

- [x] Verzeichnis, Namespace, DB-Tabellen, Config-Namespace, Permission,
      API-Endpunktnamen, Custom-Element-Tags (`<ai-chat>`/`<ai-search>`),
      Asset-Dateinamen umbenannt.
- [x] Tote Legacy-Widget-Dateien (`klxm-chat.js`/`-v2`/`-v3`/`-bundle.js`)
      entfernt, nur `ai-chat.js` (vormals `-v4.js`) bleibt.
- [x] `AI_CHAT_CONTENT_PROVIDERS`-Extension-Point umbenannt (vormals
      `KLXMCHAT_CONTENT_PROVIDERS`).
- [x] README/CHANGELOG/rexstan-Baseline aktualisiert.
- [x] `ai_platform`-Addon als optionaler Provider (`AiPlatformService`,
      `PromptBuilder` als gemeinsame Prompt-Aufbau-Stelle für neue Provider).
- [x] `AI_CHAT_REGISTER_PROVIDERS`-Extension-Point in `AiServiceFactory`.
- [x] `ob_start()`+Buffer-Clean-Absicherung (`JsonResponseTrait`) auf allen
      `Api\*`-Endpunkten (vorher nur `ChatIndex`).
- [x] Chat-Widget: `AbortController` für Sende-/Reset-Vorgänge, einmaliger
      Retry mit Backoff bei echten Netzwerkfehlern (nicht bei regulären
      HTTP-Fehlerantworten).
- [ ] Streaming (`generateAnswerStream()`) fehlt noch für `AiPlatformService`
      (Symfony-AI-Streaming-API noch nicht geprüft) – fällt sauber auf
      Non-Streaming zurück, kein Fehler.
- [ ] Ein gemeinsames `assets/ai-client.js` (Fetch-Wrapper, SSE-Parsing
      extrahiert) für Chat UND Suche wurde bewusst zurückgestellt – das
      bestehende, funktionierende Streaming in `ai-chat.js` sollte nicht ohne
      Not in einem Rutsch mit dem laufenden Rebrand angefasst werden. Die
      AbortController-/Retry-Logik wurde daher direkt in `ai-chat.js`
      ergänzt statt in einem neuen Modul; `ai-search.js` hat noch keine
      eigene AbortController-Absicherung (geringeres Risiko, da keine
      Streaming-Antworten).
- [ ] Repo-Transfer zu github.com/FriendsOfRedaxo/ai_chat (separate,
      explizit zu bestätigende Aktion, kein Automatismus).

## Phase 1 — Profile/Scope-Editor

Mehrere Chat-„Profile" mit eigenem Wissensstand, Zielgruppe (Domain/Sprache),
Sichtbarkeit (Frontend/Backend, Besucher/Redakteure/Admins), eigenem Prompt
und eigener Oberflächen-Sprache.

- [x] Tabelle `ai_chat_profile` + `profile_id`/`clang_id` auf `ai_chat_index`,
      ein Default-Profil wird beim Install automatisch angelegt
      (context=both, target_mode=all, use_shared_scope=1).
- [x] `ChatProfile` (Wertobjekt), `ProfileRepository` (CRUD), `ProfileResolver`
      (Auswahl-Logik: Kontext/Rolle/Domain/Sprache, höchste `priority` gewinnt,
      `AI_CHAT_PROFILE_CANDIDATES`-Extension-Point für Dritt-Addons).
- [x] `boot.php`: Backend-Chat, Frontend-Chat UND Frontend-Suche werden jetzt
      über EIN gemeinsam aufgelöstes Profil gesteuert (Chat und Suche sind im
      Frontend derselbe Scope) - `frontend_enabled`/`frontend_search_enabled`
      bleiben nur noch die zwei unabhängigen Ein/Aus-Schalter *innerhalb*
      des vom Profil bestimmten Scopes. Begrüßung/Reset-Countdown/
      Copy-History/Personalisierung/`ui-language`/`profile-id` kommen für
      den Chat aus dem Profil; die Suche nutzt bisher keine profil-eigenen
      Felder, nur dessen Sichtbarkeits-Matching (Domain/Sprache/Rolle).
      `frontend_visibility` (Testmodus), `frontend_allowed_domains` und
      `frontend_allowed_clangs` sind dadurch vollständig abgelöst (durch
      `viewer_roles`/`target_mode`/`domains`/`clangs` je Profil) und wurden
      aus `pages/settings.access.php` entfernt - ein Hinweistext dort
      verlinkt jetzt auf die Profile-Seite. Die drei Config-Keys werden
      nirgends mehr gelesen, sind aber noch nicht aus `lang/de_de.lang`
      aufgeräumt (harmlos, nur unbenutzte Strings).
- [x] `ChatQueryService::process()` liest `profile_id` aus dem Request,
      filtert `findSimilarContent()` entsprechend (Shared Pool vs. exklusive
      Profil-Quellen) und reicht `ChatProfile::$customPrompt` als
      System-Prompt-Override an alle vier Provider durch (`generateAnswer()`/
      `generateAnswerStream()` haben dafür einen neuen, optionalen Parameter
      bekommen - wirkt nur im Frontend-Zweig, der feste Developer-Systemprompt
      mit den `[[ACTION:...]]`-Anweisungen bleibt unverändert).
- [x] Backend-UI: `pages/profiles.php` (Liste + Formular in einer Datei,
      `rex_form`-gebunden an `ai_chat_profile`, analog `pages/triggers.php`),
      neuer Menüpunkt "Profile". Interaktiv (Kontext-/Zielgruppe-/Quelle-
      abhängige Felder blenden sich per JS ein/aus, analog
      `settings.provider.php`), mit erklärendem Info-Panel und Tooltips.
- [x] `rex_form`-Checkbox-Falle behoben (`status`/`use_shared_scope`/
      `chat_copy_history` sind jetzt Select- statt Checkbox-Felder, siehe
      CHANGELOG - Checkboxen speichern bei echten `tinyint`-Spalten sonst
      stillschweigend Murks).
- [x] **Profil-bewusste Indexierung**: `IndexerService::collectProfileTasks()`
      läuft jetzt zusätzlich zum globalen Shared-Pool-Scan (Punkt 5 in
      `collectTasks()`) und deckt alle drei Profil-Quellenarten ab -
      `yform_profile_ids` (über `YformContentProvider::collectTasksForKeys()`,
      neu, filtert `YformProfiles::getAll()` auf die gewählten Keys statt wie
      `collectTasks()` immer alle zu nehmen), `index_source=sitemap`
      (`sitemap_urls`, wiederverwendet `parseSitemapUrls()`/
      `fetchSitemapUrls()`) und `index_source=mountpoint`
      (`mountpoint_category_id`, neue `collectArticlesUnderCategory()` -
      rekursiver Kategorie-Teilbaum wie bei den bestehenden
      `index_exclude_categories`). Jeder so erzeugte Task trägt
      `chat_profile_id` (Task-Array-Schlüssel, NICHT zu verwechseln mit dem
      gleichnamigen, string-basierten YForm-Mapping-`profile_id`-Schlüssel),
      `processTask()`/`indexArticle()`/`indexUrl()`/`indexPreparedDocument()`
      schreiben das in die neue `profile_id`-Spalte. `indexArticle()` stempelt
      jetzt zusätzlich immer `clang_id` (auch für Shared-Pool-Artikel), das
      war vorher gar nicht gesetzt.
  - [x] **Behoben (war: Bekannte Einschränkung)**: `sync()` (inkrementeller
    Lauf) deduplizierte über `(source_type, source_id)`, ohne `profile_id` in
    diesem Schlüssel - überschnitt sich ein Profil-Mountpoint mit dem global
    indexierten Struktur-Bereich, "gewann" beim inkrementellen Sync zufällig
    eine der beiden Varianten. Dedup-Schlüssel und der DELETE vor dem Neuschreiben
    schließen `profile_id` jetzt ein (`NULL` explizit über `IS NULL` behandelt,
    nicht über `rex_sql::setWhere()` - das bindet `null` als Parameter, was in
    SQL `profile_id = NULL` ergibt und wegen der Drei-Werte-Logik nie matcht).
  - [x] **Behoben (war: Bekannte Einschränkung)**: die event-getriebene
    Einzelartikel-Neuindizierung (`updateArticleIndex()`, ausgelöst bei
    `ART_UPDATED` etc.) kannte keine Profil-Mountpoints - speicherte ein
    Redakteur einen Artikel, der exklusiv zu einem Profil-Mountpoint gehört,
    erneut, fiel dessen Chunk auf `profile_id = NULL` (Shared Pool) zurück.
    Neue `resolveChatProfileIdsForMountpoint()` prüft bei jedem Speichern,
    welche aktivierten Profile die Artikelkategorie (oder eine Elternkategorie)
    als Mountpoint führen, und zieht deren exklusive Zeile korrekt nach -
    zusätzlich zur (unabhängig davon weiter geltenden) Shared-Pool-Zeile.
- [ ] YForm-Sprachfilter (`clang_ids` je YForm-Mapping-Profil) noch offen,
      siehe Phase 2 unten.
- [x] `addressing_mode`/`personalization_mode` werden jetzt serverseitig pro
      Profil ausgewertet: `ChatQueryService::process()` liest
      `ChatProfile::$personalizationMode` (statt der globalen Config) für den
      "off"-Check und reicht `ChatProfile::$addressingMode` als neuen,
      optionalen `$addressingModeOverride`-Parameter an alle vier Provider
      durch (`AiServiceInterface::generateAnswer()`, alle
      `generateAnswerStream()`-Varianten, `PromptBuilder::buildSystemPrompt()`)
      - exakt analog zum bereits bestehenden `$systemPromptOverride`-Muster.
      Ohne Profil (z.B. Developer-Chat) bleibt das Verhalten unverändert
      (Fallback auf die globale Config).
- [ ] `mountpoint_category_id` hat noch keinen Kategorie-Auswahl-Dialog
      (nur ein Zahlenfeld) - ein `rex_var_link`-artiger Picker wäre
      benutzerfreundlicher.
- [ ] Kein automatisierter End-to-End-Test (mehrere Profile parallel
      anlegen, Sichtbarkeit + Retrieval-Isolation live durchklicken) -
      bisher nur per Schema-/Lint-/rexstan-Prüfung verifiziert.

## Phase 1b — Profil testen, Theme je Profil, Sicherheitslücke geschlossen (erledigt)

- [x] **Sicherheitslücke geschlossen**: `ChatQueryService::process()` vertraute
      der vom Client mitgeschickten `profile_id` bisher blind - ein Besucher
      hätte per manipulierter ID ein fremdes (domain-/sprachfremdes,
      backend-only oder deaktiviertes) Profil samt dessen Prompt/Wissens-Scope
      abgreifen können. Echte Frontend-Anfragen (kein authentifizierter
      Backend-Nutzer) bekommen ihr Profil jetzt serverseitig per
      `ProfileResolver::resolveForFrontend()` neu zugeordnet; nur Backend-
      Nutzer dürfen ein Profil explizit wählen (nötig für "Profil testen").
- [x] **"Profil testen"**: `pages/profiles.php` zeigt beim Bearbeiten eines
      gespeicherten Profils rechts (sticky) ein echtes `<ai-chat>`-Widget,
      fest an dieses eine Profil gebunden (Frontend/Developer-Scope umschaltbar
      über `allow-scope-switch`), inkl. Theme-Vorschau. Testet immer den
      gespeicherten Stand, nicht den ggf. ungespeicherten Formularinhalt.
      Zweispaltiges Layout braucht `display:flex` auf dem `.row`-Wrapper -
      das float-basierte Bootstrap-Grid gleicht Spaltenhöhen sonst nicht an,
      wodurch `position:sticky` keinen Raum zum "Kleben" hätte.
- [x] **Theme je Profil**: 8 neue, optionale `theme_*`-Spalten (Akzentfarbe,
      Kopfzeile/Chat/Text/Bot-Sprechblase-Hintergrund, Eckenradius, Position,
      Avatar) - leer = globale Darstellung-Einstellung greift weiter (siehe
      neue `ProfileTheme`-Klasse). Die 5 Farbfelder + Eckenradius aktualisieren
      das Test-Widget schon beim Tippen live (vor dem Speichern) per
      `element.style.setProperty('--ai-chat-*', ...)` - funktioniert, weil
      `ai-chat.js` diese Werte konsequent über `var(--ai-chat-*, fallback)`
      liest und ein inline gesetzter CSS-Custom-Property-Wert höhere
      Spezifität hat als die interne `:host{}`-Regel im Shadow-DOM.
      "Theme zurücksetzen"-Button leert alle Felder wieder auf "globale
      Einstellung". `primary-color`/`avatar-url`/`position` sind normale
      Widget-Attribute (kein `attributeChangedCallback` in `ai-chat.js`) und
      wirken daher erst nach Speichern+Neuladen, nicht live.
- [x] **Chat/Suche je Profil automatisch einbinden**: neue Tri-State-Felder
      `chat_enabled`/`search_enabled` (leer = globale
      `frontend_enabled`/`frontend_search_enabled`-Einstellung entscheidet,
      sonst je Profil erzwungen an/aus) - `boot.php` liest sie vor der
      globalen Einstellung.
- [x] **Checkbox+`rex_config_form`-Falle systemweit behoben** (siehe
      https://github.com/KLXM/klxmchat/issues/23): fünf globale Einstellungen
      (`live_reindex_enabled`, `frontend_search_enabled`,
      `stats_logging_enabled`, `index_frontend`, `index_respect_yrewrite_seo`)
      ließen sich nie dauerhaft deaktivieren, weil eine deaktivierte Checkbox
      bei `rex_config_form` als PHP `null` gespeichert wird und
      `rex_config::get($key, $default)` gespeichertes `null` wie "nicht
      gesetzt" behandelt und immer auf den (meist `true`) Default zurückfällt.
      Auf Select (Ja/Nein) umgestellt, neuer `$addBoolSelectField`-Helfer in
      `settings.shared.php` dokumentiert die Falle für künftige Felder.
- [x] Weitere tote/inkonsistente Config-Reste aufgeräumt: `frontend_visibility`
      (vollständig durch Profile ersetzt, wurde aber in
      `resolveFrontendAccessDenial()` noch ausgewertet),
      `backend_chat_reset_countdown`/`_copy_history` und
      `frontend_chat_reset_countdown`/`_copy_history` (kommen längst
      ausschließlich aus dem Profil) aus den Einstellungsseiten entfernt.
      `frontend_allow_scope_switch` war in `boot.php` hart auf `false`
      verdrahtet, obwohl als aktive Einstellung angezeigt - jetzt verdrahtet.
- [x] `stream_enabled` war auf der FairPlay-Instanz aus - SSE-Streaming
      (inkl. sichtbarem "Tippen" im Widget) war dadurch fertig gebaut, aber
      nicht aktiv. Für dieses Vorführ-/Testinstanz aktiviert.

## Phase 1a — Natives Vektor-Retrieval (erledigt, 2026-09-02)

MariaDB `VECTOR`/`VECTOR INDEX` wird capability-detected genutzt
(`FriendsOfRedaxo\AiChat\Db\VectorCapability::isSupported()`, Ergebnis in
`rex_config` gecacht, "Neu prüfen"-Button auf AI Chat → Indexierung). Fallback
auf die bestehende PHP-Brute-Force-Cosine-Berechnung bleibt dauerhaft
erhalten (bewusste Entscheidung, siehe Chat-Verlauf 2026-09-02: ein
öffentliches FriendsOfREDAXO-Addon muss auch auf älteren MariaDB-Versionen
und MySQL funktionieren) - beide Implementierungen stehen hinter
`RetrievalStrategyInterface` (`BruteForceRetrieval`/`NativeVectorRetrieval`),
`ChatQueryService::resolveRetrievalStrategy()` wählt einmalig pro Anfrage.

- **FairPlay-Instanz auf MariaDB 11.8.9 aktualisiert** (vorher 11.6.2) -
  `docker-compose.yml` (`mariadb:11.8`), vorher vollständiger `mariadb-dump`-
  Backup (`database/backups/`), `mariadb-upgrade` sauber durchgelaufen, Daten
  verifiziert. Nur diese lokale Docker-Instanz betroffen, kein Bestandteil
  des Addon-Codes.
- `VectorIndexInstaller` (`lib/Db/`) legt `embedding_vector VECTOR(n) NOT
  NULL` + `VECTOR INDEX` per raw SQL an (bewusster Bypass von
  `rex_sql_table`/`rex_sql_index`, siehe Klassen-Doc) - **wichtige, per Live-
  Test gefundene Einschränkung**: MariaDB verlangt für einen `VECTOR INDEX`
  eine NOT-NULL-Spalte, die sich ohne sinnvollen Default nur auf einer LEEREN
  Tabelle sicher anlegen lässt. Spalte/Index werden deshalb ausschließlich
  aus `IndexerService::setEmbeddingColumns()` heraus verwaltet, aufgerufen
  während `runFull()` (truncated die Tabelle immer zuerst) - ein
  inkrementelles Einzel-Update (Tabelle nicht leer) überspringt den Aufbau
  bewusst und überlässt ihn dem nächsten vollen Reindex-Lauf, statt zu raten.
  `VectorCapability::ensureDimension()` merkt die Dimension erst als erledigt
  vor, wenn der Installer den Aufbau tatsächlich bestätigt (nicht nur
  versucht) - sonst hätte ein einmal fehlgeschlagener Aufbau für immer
  unbemerkt fortbestanden.
- `NativeVectorRetrieval` nutzt `VEC_DISTANCE_COSINE()` (Cosine-Distanz, 0=
  identisch/2=entgegengesetzt) und rechnet über `similarity = 1 - distance`
  auf dieselbe Skala wie `BruteForceRetrieval` um, damit Schwellenwerte
  (`hasSufficientAnswerContext()`) strategieunabhängig funktionieren -
  gegen echte, per Reindex erzeugte Embeddings verifiziert (Selbst-Distanz
  exakt 0 über den tatsächlichen Code-Pfad: JSON-Embedding → `VEC_FromText()`).
  `VectorMath` (`lib/Retrieval/`) buendelt die Brute-Force-Mathematik, geteilt
  zwischen `BruteForceRetrieval` und dem weiterhin brute-force bleibenden
  FAQ-Cache-Treffer-Abgleich in `ChatQueryService`.
- Auf der jetzt aktualisierten FairPlay-Instanz aktiv: `vector_capability_supported=true`,
  aktuelles Embedding-Modell liefert 4096 Dimensionen, voller Reindex
  (472 Aufgaben, 4554 Abschnitte) lief mit dem finalen Code fehlerfrei durch.
- MySQL/HeatWave wird nicht eigens unterstützt (Community Edition hat keine
  echte ANN-Suche) - `VectorCapability` prüft explizit auf "MariaDB" im
  `VERSION()`-String.

## Phase 2 — YForm-Sprachfilter (erledigt)

- [x] Mountpoint-Quelle je Profil (siehe Phase 1 oben).
- [x] Optionaler `clang_ids`-Filter je YForm-Mapping-Profil (`pages/yform.php`,
      neues Feld "Sprach-Spalte" + "Sprachen (clang-IDs)"): nur wirksam, wenn
      eine Sprach-Spalte gewählt ist, sonst unverändertes Verhalten (alle
      Sprachen, wie vor diesem Feature). `YformContentProvider::fetchRows()`
      filtert dann per `WHERE {clang_field} IN (...)`, analog zum bereits
      vorhandenen `status_field`/`status_values`-Muster.

## Phase 3 — JSON-i18n für die Widget-Oberfläche (Grundgerüst erledigt)

Entschieden (ersetzt die früher gewählte „Option C" mit admin-konfigurierbaren
Feldern pro Sprache): JSON-Dateien statt PHP-Sprachdatei, geladen von einem
schlanken PHP- (`WidgetTranslator`) und JS-Loader, gesteuert über das
`ui-language`-Attribut am Widget (kommt aus dem Profil, siehe Phase 1).

- [x] Quelldateien: `assets/i18n/{de,en}.json` (bewusst unter `assets/`, nicht
      `lang/widget/` wie urspr. skizziert - nur `assets/` wird von REDAXO in
      den öffentlichen Webroot kopiert bzw. ist hier direkt per PHP-Dateipfad
      lesbar, kein Duplikat zwischen Server- und Client-Quelle nötig). Neue
      Sprache hinzufügen = neue JSON-Datei mit denselben Schlüsseln anlegen,
      kein Code nötig; fehlende Schlüssel fallen automatisch auf Deutsch
      zurück.
- [x] `FriendsOfRedaxo\AiChat\Service\WidgetTranslator::load($locale)` - liest
      die JSON, merged mit Deutsch als Fallback, wendet den neuen Extension
      Point `AI_CHAT_WIDGET_TRANSLATIONS` an (Dritt-Addons können eigene
      Sprachen/Schlüssel nachliefern).
- [x] `Api\WidgetTranslations` (neuer `rex-api-call`, `ai_chat_widget_translations`,
      `published=true`, gecacht) liefert das Ergebnis als JSON an den Browser -
      nötig, damit auch Dritt-Addon-Beiträge (nur serverseitig zusammenführbar)
      im Client ankommen, nicht nur die eigene JSON-Datei.
- [x] `assets/ai-i18n.js`: gemeinsamer, minimaler Loader für Chat UND Suche
      (globales `window.AiChatI18n`, kein ES-Modul, da `ai-chat.js` als
      `type="module"` läuft, `ai-search.js` aber klassisch - ein globaler
      Namespace funktioniert in beiden). Lädt pro Locale nur einmal, auch
      wenn Chat und Suche gleichzeitig danach fragen. In `boot.php` an allen
      vier Stellen eingebunden, an denen `ai-chat.js`/`ai-search.js` geladen
      werden.
- [x] `ai-chat.js`/`ai-search.js`: statische Template-Texte (Platzhalter,
      aria-labels, Titles) werden NACH dem asynchronen Laden per
      `applyTranslations()` nachträglich gepatcht - `render()`/`mount()`
      selbst bleiben synchron und zeigen bis dahin sofort den deutschen
      Standardtext (kein Warten/Flackern für den deutschsprachigen
      Regelfall). Dynamische Laufzeit-Texte (Fehler, Reset-Bestätigung,
      Copy-Feedback, Retry-Button, Suchtreffer-Titel/leer-Zustand) nutzen
      direkt `this.t(key, fallback, vars)`.
- [x] Suche "leiht" sich `ui-language` vom `<ai-chat>`-Element auf derselben
      Seite (analog zum bereits bestehenden Muster für `api-url`/Branding),
      da sie kein eigenes Custom-Element-Attribut hat. Ohne Chat auf der
      Seite bleibt Deutsch der Standard.
- [x] **Nebenbei entdeckter und behobener Bug**: `window.KlxmSearch` (das
      öffentliche JS-API der Suche) war beim Rebrand nur in der README
      umbenannt worden, im tatsächlichen Code aber noch `window.KlxmSearch` -
      jetzt konsistent `window.AiSearch` in `ai-search.js` UND
      `ai-search-backend-demo.js`.
- [x] **Behoben (war: bewusst nicht übersetzt)**: die komplette
      Personalisierungs-Onboarding-Dialogstrecke (Sie/Du-Frage, Namensfrage,
      beide Bestätigungen, Namens-Begrüßung) war in `ai-chat.js` hartcodiert
      Deutsch, inkl. eines Verlauf-Filters (`applyPersonalizationConfigGuard()`),
      der diese Nachrichten beim Deaktivieren der Personalisierung per Text-/
      Regex-Vergleich gegen deutsche Strings wieder entfernt. Jetzt über
      `addMessage(..., meta)` mit einer sprachunabhängigen Markierung
      (`isPersonalizationPrompt`/`isPersonalizationAnswer`) statt Text-Vergleich
      - Anzeige ist über neue `personalization_ask_mode`/`_ask_name`/
      `_confirm_formal`/`_confirm_informal`/`_greeting_name`-Schlüssel
      (`assets/i18n/{de,en}.json`) übersetzt, der Filter erkennt die Nachrichten
      unabhängig von der angezeigten Sprache korrekt wieder.
- [ ] Nicht alle in der ursprünglichen Recherche gefundenen Strings sind
      übersetzt (z.B. `ai-search-backend-demo.js`s eigene Texte - reines
      Backend-Demo-Werkzeug für i.d.R. deutschsprachige REDAXO-Admins,
      niedrige Priorität) - die Infrastruktur trägt aber jetzt beliebig
      viele weitere Schlüssel ohne Architekturänderung.
Entwickler können neue Sprachen/Strings einfach per zusätzlicher JSON-Datei
ergänzen, ohne den Core anzufassen.

## Phase 4 — Missbrauchsschutz, Upkeep-Anbindung, benannte Sitemap-Bereiche (erledigt, 2026-09-02)

- [x] **Prompt-Injection-/Jailbreak-Guard**: neue `evaluatePromptInjectionGuard()`/
      `containsPromptInjectionAttempt()` in `ChatQueryService.php`, analog zur
      bestehenden Guard-Kette (`evaluateSpamGuard()`/`evaluateCodeInjectionGuard()`/
      `evaluatePrivacyGuard()`), aber auf Muster wie "ignoriere alle vorherigen
      Anweisungen"/"du bist jetzt.../Systemprompt zeigen" statt auf SQLi/XSS.
      Greift für Chat UND Suche gleichermaßen, da beide über `process()` laufen.
- [x] **Konversation wird bei wiederholten Angriffen freundlich beendet statt hart
      geblockt**: neue `combinedAttackStrikes()` zählt Code-Injection- UND
      Prompt-Injection-Treffer im laufenden Verlauf zusammen; ab
      `ATTACK_STRIKES_BEFORE_CLOSING = 3` liefert `conversationClosingMessage()`
      eine höfliche Abschluss-Antwort statt eines weiteren reinen Blocks.
- [x] **Angriffsversuche an `upkeep` melden ("soft Sperre")**: neue
      `reportAttackToUpkeep(string $reason)` ruft
      `\KLXM\Upkeep\IntrusionPrevention::blockIpManually($ip, '1h', $reason)` auf
      (`rex_addon::get('upkeep')->isAvailable() && class_exists(...)`-abgesichert,
      **kein Eingriff in `upkeep` selbst**) - wird von `evaluateCodeInjectionGuard()`
      und `evaluatePromptInjectionGuard()` bei erkanntem Angriff aufgerufen, mit
      `'1h'` statt `'permanent'` (bewusst "soft", da False-Positives bei
      Heuristiken nie ganz auszuschließen sind). `REMOTE_ADDR` wird dafür wie bei
      `checkRateLimit()` direkt über `rex_server()` gelesen.
- [x] **Sinnlose Suchanfragen abgefangen, bevor sie die KI belasten**: neue
      `looksLikeNonsenseQuery()` (leer/nur Sonderzeichen/zu kurz/reines
      Kauderwelsch) - `process()` liefert für `scope=frontend`+`mode=chat` dann
      direkt eine freundliche Rückfrage ohne `security_warning`-Flag (kein
      Angriffsverdacht, nur unklare Eingabe). `search()` setzt bei 0 Treffern
      zusätzlich ein `nonsense_query`-Flag in der Antwort, das `ai-search.js`
      nutzt, um seinen eigenen, bereits bestehenden KI-Fallback
      (`shouldAskChatForEmptyResults()`) bei offensichtlichem Kauderwelsch gar
      nicht erst anzustoßen (spart einen unnötigen `mode=chat`-Folgeaufruf).
- [x] **KI hilft bei Treffer-losen (aber sinnvollen) Suchanfragen**: bereits vor
      dieser Phase über `ai-search.js`s `shouldAskChatForEmptyResults()` →
      `fetchChatAnswer()` gelöst (echter `mode=chat`-Aufruf bei 0 Suchtreffern) -
      ein zusätzlicher serverseitiger Generierungspfad in `search()` wurde
      testweise ergänzt, dann als redundant wieder entfernt.
- [ ] **`search_it`-Feature-Vergleich weiterhin nicht durchgeführt** (eigene
      Ergebnisseite? Trefferhervorhebung? Ausschluss-Konfiguration?) - eigenständige
      Frage, unabhängig von den oben erledigten Schutzmaßnahmen.

### Benannte Sitemap-Bereiche ("source_label")

Aus derselben Anforderungsrunde: mehrere Sitemaps pro Profil sollen in Suche/Chat
nach einem selbst vergebenen Namen (z.B. "Allgemein"/"News") unterscheidbar sein.

- [x] `ChatProfile::$sitemapUrls` (einzelnes Textfeld) ersetzt durch
      `$sitemapGroups` (`list<array{label: string, urls: list<string>}>`,
      `decodeSitemapGroups()`) - mehrere benannte Gruppen statt einer Liste.
      `install.php` migriert bestehende `sitemap_urls`-Werte einmalig in eine
      unbenannte Gruppe (kein Datenverlust für das bestehende "Standard"-Profil).
- [x] `pages/profiles.php`: Textarea ersetzt durch eine JS-Repeater-UI
      (Label-Feld + URL-Liste pro Gruppe, hinzufügen/entfernen per Button,
      Template-Clone-Muster wie bei `pages/yform.php`) - serialisiert beim
      Absenden als JSON in ein verstecktes Feld, kein eigener Save-Handler nötig.
- [x] `IndexerService`: `collectProfileTasks()`/`processTask()`/`indexUrl()`
      stempeln jeden aus einer benannten Gruppe stammenden Task/Chunk mit
      `source_label`, neue Spalte `ai_chat_index.source_label`.
- [x] Retrieval (`BruteForceRetrieval`/`NativeVectorRetrieval`) und
      `ChatQueryService::search()`/`findSimilarContent()` reichen `source_label`
      durch; `search()` liefert zusätzlich zu den bestehenden Typ-Facetten eine
      zweite Facetten-Zeile für Labels (`labelFacets`/`selectedLabels`), `ai-search.js`
      rendert sie als zweite Chip-Reihe neben den Typ-Filtern.
      `extractSourceLabels()` liest `source_labels[]` aus dem Request.
- [x] `PromptBuilder::buildUserPrompt()` sowie die providerspezifischen
      `buildGenerationPayload()`/`buildChatPayload()`/`buildChatCompletionPayload()`
      (Gemini/Cloudflare/OpenAI-kompatibel) prefixen jeden Kontext-Abschnitt mit
      `[Bereich: {label}]`, wenn ein `source_label` gesetzt ist - hilft der KI,
      Relevanz auch themenbezogen einzuordnen, nicht nur nach Ähnlichkeitswert.
- [ ] **Bewusst nicht umgesetzt (offene Nutzerfrage)**: pro-Suchbegriff-Relevanz-
      Tuning ("bei bestimmten Suchbegriffen ist X relevanter") - der Nutzer war sich
      selbst noch unsicher, wie das aussehen soll; erst nach weiterer Klärung
      angehen, nicht auf Basis von Annahmen vorwegnehmen.

## Phase 5 — Such-UX, Antwortsprache je Profil, Theme-Bugfixes (erledigt, 2026-09-02)

- [x] **Suchen-Button**: `ai-search.js` bekommt einen expliziten Submit-Button
      neben der Live-Suche mit Debounce (löst sofort aus, ohne Wartezeit).
- [x] **Suchfenster schloss sich bei Text-Selektion**: Ein Maus-Drag zum
      Markieren von Text im Suchfeld, der beim Loslassen knapp außerhalb des
      Feldes endet, wurde fälschlich als Klick-auf-Hintergrund-schließt-Overlay
      gewertet. Overlay schließt jetzt nur noch, wenn sowohl `mousedown` als
      auch `click` auf dem Hintergrund selbst lagen.
- [x] **KI-Zusammenstellung über mehrere Bereiche async statt blockierend**:
      `search()` liefert sofort `hits` + `summary_available`; die eigentliche
      Zusammenstellung lädt `ai-search.js` per separatem `mode=search_summary`-
      Folgeaufruf nach (nutzt die bereits vorliegenden Treffer, keine erneute
      DB-Suche). Eigene Beschriftung/Gestaltung (`.ai-search-answer-summary`,
      gestrichelter Rahmen, `✦`-Präfix), damit sie nicht mit einer direkten
      Antwort auf eine erkannte Frage verwechselt wird.
- [x] **Antwortsprache je Profil** (`ChatProfile::$answerLanguage`, neue Spalte
      `answer_language`): lässt die KI unabhängig von `ui_language` in einer
      festgelegten Sprache antworten, providerübergreifend, wirkt auch auf
      `generateFollowUpQuestions()`. Eine milde Prompt-Anweisung reichte im
      Test nicht (Modell blieb bei starkem deutschsprachigem Kontext beim
      Deutschen) - Formulierung explizit verstärkt ("AUCH WENN Frage und
      Kontext auf Deutsch sind").
- [x] **Theme-Farbfelder zeigten bei leerem Wert Schwarz statt der globalen
      Farbe**: `input[type=color]` normalisiert einen leeren HTML-`value` beim
      Auslesen immer auf `#000000` (Browser-Verhalten, kein Bug im Addon) -
      Anzeige liest jetzt `getAttribute('value')` statt `.value`. Zusätzlich
      lässt sich `.value` per JS nicht auf einen Leerstring setzen (der
      Browser lehnt das still ab) - das Leeren beim Speichern läuft jetzt über
      ein verstecktes Ersatzfeld statt einer direkten Wertzuweisung.
- [x] **Eckenradius `0` wurde ignoriert**: `ProfileTheme::buildInlineStyle()`
      nutzte `?:`, PHP behandelt den String `"0"` als falsy - auf einen
      expliziten Leerstring-Check umgestellt.
- [x] **`assets/ai-search-backend-demo.js` übersetzbar gemacht**: nutzte bisher
      hartcodiertes Deutsch für alle Demo-UI-Texte (Buttons, Platzhalter,
      ARIA-Labels). Läuft jetzt über denselben `window.AiChatI18n`-Loader wie
      das echte Such-Widget, neue `demo_*`-Schlüssel in
      `assets/i18n/de.json`/`en.json` (bestehende Schlüssel wie
      `search_empty_results`/`search_error`/`search_trigger_label` werden
      wiederverwendet, wo der Text identisch ist). Die Demo-Beispielfragen
      ("Was macht die KLXM?" u.ä.) und die Skin-Namen (Ocean/Sunset/Forest)
      bleiben bewusst unübersetzt (Beispielinhalt bzw. Eigennamen).
- [x] **rexstan auf 0 Findings gebracht** (vorher 462): `variable.undefined`
      (fehlende `@var`-Annotationen nach `require settings.shared.php`),
      `argument.type` (`rex_addon` vs. dokumentiertem `rex_addon_interface`),
      diverse echte Kleinbugs (curl-Typisierung, `pclose()` auf möglichem
      `false`-Handle, `getDataset()` auf möglichem `null`, tote Methoden/
      Codepfade) sowie ca. 110 Fälle, in denen PHPStan PHPDoc-Array-Shapes als
      hundertprozentig sicher behandelt und dadurch echte, aus gutem Grund
      vorhandene Absicherungen (Extension-Point-Rückgabewerte von
      Dritt-Addons, Deployment-Robustheit in `boot.php`) als „immer wahr"
      markiert - dort bewusst per gezieltem `@phpstan-ignore` + Begründung
      stummgeschaltet statt die Absicherung zu entfernen.

## Phase 6 — PDF-Indexierung, Trefferhervorhebung, Hintergrund-Refresh (erledigt, 2026-09-03)

Ausgangspunkt: Vergleich mit `search_it` (dortige PDF-Extraktion ist ein
selbstgeschriebener, fragiler PDF-Byte-Stream-Parser; dortiges Highlighting fehlt
`ai_chat` bisher ganz).

- [x] **`lib/Service/PdfTextExtractor.php`**: `pdftotext` (poppler, falls per
      `shell_exec`/PATH auffindbar) mit `smalot/pdfparser`-Fallback (neue
      Composer-Abhängigkeit, `vendor/` committed wie bei `forcal`/`pdfout` u.a.
      auf dieser Instanz üblich). Extrahierter Text wird auf gültiges UTF-8
      bereinigt (`mb_substitute_character('none')` + Convert-Roundtrip) - ohne
      das brach der Embedding-Request bei mindestens einer real getesteten PDF
      mit „Malformed UTF-8 characters" ab.
- [x] **`lib/ContentProvider/MediaPoolContentProvider.php`**: neuer
      `ContentProviderInterface`-Provider, rein profil-exklusiv
      (`collectTasks()` liefert bewusst `[]`, kein Shared-Pool-Beitrag).
      `resolveAbsoluteUrl()` prefixt `rex_media::getUrl()` mit `rex::getServer()`
      (analog zum bestehenden Muster in `IndexerService::indexArticle()`s
      HTTP-Zweig) - ohne das landeten bei einer per Konsole angestoßenen
      Indexierung relative statt klickbare Such-Ergebnis-Links im Index.
- [x] **`ChatProfile::$pdfMediaIds`/`$pdfCategoryIds`** (neue Spalten
      `pdf_media_ids`/`pdf_category_ids`) + Felder auf `pages/profiles.php`
      (`addMedialistField()`/`rex_media_category_select`, native REDAXO-Widgets,
      dasselbe Muster wie das bestehende `theme_avatar`-Feld).
      `IndexerService::collectProfileTasks()` löst Kategorien zu Dateinamen auf
      und stempelt Tasks wie beim YForm-Pendant mit `chat_profile_id`.
- [x] **Reale Lücke beim Testen gefunden und behoben**: `ChatQueryService::search()`
      filterte `source_type IN (...)` bisher ausschließlich anhand der global
      aktivierten Shared-Pool-Provider (`getEnabledFrontendProviderSourceTypes()`)
      - ein Profil mit exklusiven PDF- oder YForm-Quellen fand diese dadurch zwar
      über den Chat/RAG-Pfad, aber nie über die Live-Suche. Neue
      `getProfileExclusiveSourceTypes(?ChatProfile $profile)` ergänzt die
      Profil-eigenen Source-Types.
- [x] **`index_source`-Auswahl "Keine"-Option-Text aktualisiert**: erwähnte
      bisher nur Shared Pool/YForm, jetzt auch PDF - inkl. Hinweis, wie man ein
      Profil für eine spezialisierte Suche (z.B. "nur PDF") konfiguriert
      (`index_source=none` + `use_shared_scope=0` + eigene YForm-/PDF-Auswahl).
- [x] **Trefferhervorhebung**: `ChatQueryService::highlightSnippetSegment()`
      umschließt Suchbegriff-Treffer im Snippet mit `<mark>`, Rest
      `htmlspecialchars()`-escaped (Positionsermittlung auf dem Rohtext, Escaping
      erst beim Zusammenbauen - kein Regex auf bereits escaptem Text). Client
      (`ai-search.js`) nutzt jetzt `innerHTML` statt `textContent` für Snippets.
- [x] **`IndexerService::sync()` erweitert** um `?callable $onProgress`/
      `?callable $shouldStop` (analog zu `runFull()`) sowie `chunks`/`total`/
      `cancelled`/`error_log` im Rückgabewert - Grundlage für den nächsten Punkt.
- [x] **„Refresh (inkrementell)" läuft jetzt immer über den Hintergrund-Worker**
      (`Api\ReindexWorker` mit neuem `mode=incremental`/`max_items` in
      `IndexRunStore`) statt als einzelner blockierender Request im Browser-Tab -
      selbe Verfügbarkeitsprüfung/Deaktivierung wie „Im Hintergrund indexieren"
      bei fehlendem `shell_exec`/curl/wget.

## Offen / zur Diskussion

- **Cache-Hits + „Quellen anzeigen"**: Bei aktiviertem `show_sources` (Standard
  an) wird bei einem Cache-Treffer trotzdem die komplette `findSimilarContent()`
  ausgeführt, um Quellenlinks aktuell zu halten (siehe
  `ChatQueryService.php` um Zeile 375). Der Cache spart dadurch nur den
  LLM-Aufruf, nicht die Vektorsuche. Bewusster Freshness-vs-Speed-Trade-off —
  nur ändern, wenn Antwortzeit wichtiger ist als Link-Aktualität.
