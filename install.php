<?php

rex_sql_table::get(rex::getTable('ai_chat_index'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('source_type', 'varchar(50)')) // 'article', 'pdf', 'addon_docs'
    ->ensureColumn(new rex_sql_column('source_id', 'varchar(255)')) // Changed to varchar to support file paths/identifiers
    ->ensureColumn(new rex_sql_column('title', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('content', 'text'))
    ->ensureColumn(new rex_sql_column('embedding', 'mediumtext', true)) // JSON string der Vektoren
    ->ensureColumn(new rex_sql_column('embedding_norm', 'double', true)) // Vorberechnete Vektor-Magnitude, spart die Neuberechnung bei jedem Ähnlichkeitsvergleich
    ->ensureColumn(new rex_sql_column('url', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    // NULL = Shared Pool (von jedem Profil sichtbar, das use_shared_scope=1 hat -
    // so bleibt die bestehende globale Indexierung unveraendert nutzbar). Ein
    // gesetzter Wert bindet den Chunk exklusiv an ein Profil (siehe ChatProfile).
    ->ensureColumn(new rex_sql_column('profile_id', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('clang_id', 'int(10) unsigned', true))
    // Frei vergebener Name einer benannten Sitemap-Gruppe (ChatProfile::$sitemapGroups,
    // z.B. "News") - NULL fuer alles, was nicht aus einer benannten Gruppe stammt (Artikel,
    // Addon-Docs, unbenannte Sitemap-Eintraege). Eigene Spalte statt Ueberladen von
    // source_type, weil "Typ" (sitemap_url) und "vom Admin frei vergebener Name" zwei
    // unabhaengige Dimensionen sind - siehe ChatQueryService::search()-Facetten.
    ->ensureColumn(new rex_sql_column('source_label', 'varchar(190)', true))
    ->ensureIndex(new rex_sql_index('source', ['source_type', 'source_id']))
    ->ensureIndex(new rex_sql_index('profile_source', ['profile_id', 'source_type']))
    ->ensure();

rex_sql_table::get(rex::getTable('ai_chat_cache'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('question', 'text'))
    ->ensureColumn(new rex_sql_column('embedding', 'mediumtext', true))
    ->ensureColumn(new rex_sql_column('embedding_norm', 'double', true))
    ->ensureColumn(new rex_sql_column('answer', 'text'))
    ->ensureColumn(new rex_sql_column('scope', 'varchar(50)'))
    // NOT NULL, kein globaler Cache-Topf mehr (siehe Phase 2 der Hauptprofil-
    // Entflechtung) - jeder Eintrag gehoert zu genau dem Profil, das ihn erzeugt hat,
    // da unterschiedliche Profile auf dieselbe Frage unterschiedliche Antworten liefern
    // koennen (eigener Prompt/eigene Anrede/eigenes Wissen). 0 = Migrations-Altlast
    // (Zeilen aus der Zeit vor diesem Feld, siehe Migration unten), kein echtes Profil.
    ->ensureColumn(new rex_sql_column('profile_id', 'int(10) unsigned', false, '0'))
    ->ensureColumn(new rex_sql_column('created_at', 'datetime'))
    ->ensureIndex(new rex_sql_index('scope', ['scope']))
    ->ensureIndex(new rex_sql_index('profile_id', ['profile_id']))
    ->ensure();

// Einmalige Migration: bestehende Cache-Zeilen aus der Zeit vor profile_id kennen ihr
// urspruengliches Profil nicht (Cache war bisher nur nach "scope" getrennt) - der Cache ist
// reine, sich selbst neu aufbauende Zwischenspeicherung, ein einmaliges Leeren ist kein
// Datenverlust (im Gegensatz zu stillschweigend falsch zugeordneten Zeilen mit profile_id=0,
// die dann faelschlich JEDES Profil mit ID 0 treffen wuerden - Profile-IDs starten aber bei 1,
// profile_id=0 ist also ohnehin nie ein echtes Profil).
$cacheMigrationSql = rex_sql::factory();
$cacheMigrationSql->setQuery('DELETE FROM ' . rex::getTable('ai_chat_cache') . ' WHERE profile_id = 0');

rex_sql_table::get(rex::getTable('ai_chat_triggers'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('keyword', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('content', 'text'))
    // NULL = gilt fuer alle Profile (bisheriges, weiterhin unveraendertes Verhalten fuer
    // bestehende Trigger) - ein gesetzter Wert beschraenkt den Trigger auf genau ein Profil.
    ->ensureColumn(new rex_sql_column('profile_id', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('created_at', 'datetime'))
    ->ensureColumn(new rex_sql_column('updated_at', 'datetime'))
    ->ensureIndex(new rex_sql_index('profile_id', ['profile_id']))
    ->ensure();

rex_sql_table::get(rex::getTable('ai_chat_ratelimit'))
    ->ensureColumn(new rex_sql_column('ip', 'varchar(45)'))
    ->ensureColumn(new rex_sql_column('session_id', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('created_at', 'datetime'))
    ->ensureIndex(new rex_sql_index('ip_created', ['ip', 'created_at']))
    ->ensureIndex(new rex_sql_index('ip_session_created', ['ip', 'session_id', 'created_at']))
    ->ensure();

rex_sql_table::get(rex::getTable('ai_chat_stats'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('mode', 'varchar(20)'))
    ->ensureColumn(new rex_sql_column('scope', 'varchar(20)'))
    ->ensureColumn(new rex_sql_column('status', 'varchar(30)'))
    ->ensureColumn(new rex_sql_column('query', 'text'))
    ->ensureColumn(new rex_sql_column('normalized_query', 'varchar(255)'))
    ->ensureColumn(new rex_sql_column('hit_count', 'int', false, '0'))
    // Nullable statt Fremdschluessel: eine Anfrage ohne aufgeloestes Profil (z.B. ohne
    // aktive Profile, reiner globaler Fallback, siehe boot.php) bleibt NULL statt 0 -
    // "kein Profil" ist ein legitimer, dauerhafter Zustand, kein Migrations-Uebergang.
    // Zeilen aus der Zeit vor diesem Feld bleiben ebenfalls NULL ("unbekannt").
    ->ensureColumn(new rex_sql_column('profile_id', 'int', true))
    ->ensureColumn(new rex_sql_column('created_at', 'datetime'))
    ->ensureIndex(new rex_sql_index('mode_scope_status', ['mode', 'scope', 'status']))
    ->ensureIndex(new rex_sql_index('normalized_query_created', ['normalized_query', 'created_at']))
    ->ensureIndex(new rex_sql_index('profile_id', ['profile_id']))
    ->ensure();

// Profile/Scope-Editor: mehrere Chat-"Profile" mit eigenem Wissensstand,
// Zielgruppe (Domain/Sprache/individuell), Sichtbarkeit und Prompt - siehe
// FriendsOfRedaxo\AiChat\Profile\ChatProfile fuer die Feldbedeutung im Detail.
$profileTable = rex_sql_table::get(rex::getTable('ai_chat_profile'));
// Vor ensureColumn() pruefen (die Spalte existiert zu diesem Zeitpunkt noch nicht
// erst NACH ensure() unten) - steuert die einmalige Datenuebernahme weiter unten.
$extraSourceMigrationNeeded = $profileTable->exists() && !$profileTable->hasColumn('extra_source') && $profileTable->hasColumn('index_source');
// Vor ensureColumn() pruefen (die Spalte existiert hier noch nicht) - steuert die
// einmalige Uebernahme der alten GLOBALEN FAQ-Vorcache-Config in jedes Profil weiter unten.
$faqPrecacheMigrationNeeded = $profileTable->exists() && !$profileTable->hasColumn('faq_precache_questions');

// Einmalige Migration: die 6 vormals tri-state Felder (''/NULL = "globale Hauptprofil-
// Einstellung entscheidet") werden NOT NULL mit echtem Default - jedes Profil ist ab jetzt
// vollstaendig eigenstaendig, es gibt kein globales Fallback mehr (Hauptprofil-Seiten
// entfallen). Die aktuell wirksamen globalen Werte werden hier VOR der Spaltenaenderung
// unten in bereits vorhandene, noch leere Zeilen uebernommen, damit sich das Verhalten
// bestehender Installationen durch dieses Update nicht stillschweigend aendert. Muss vor
// dem ensureColumn()-Aufruf unten laufen, der die Spalten NOT NULL macht - danach waeren
// die urspruenglichen "leer = geerbt"-Zeilen nicht mehr von echten Werten zu unterscheiden.
if ($profileTable->exists()) {
    $aiChatAddon = rex_addon::get('ai_chat');
    $profileTableName = rex::getTable('ai_chat_profile');

    $inheritedAddressingMode = trim((string) $aiChatAddon->getConfig('frontend_addressing_mode', 'auto'));
    $inheritedPersonalizationMode = trim((string) $aiChatAddon->getConfig('personalization_mode', 'off'));
    $inheritedSuggestFollowup = ((bool) $aiChatAddon->getConfig('suggest_followup_questions', false)) ? '1' : '0';
    $inheritedShowSources = ((bool) $aiChatAddon->getConfig('show_sources', true)) ? '1' : '0';

    $backfillSql = rex_sql::factory();
    $backfillSql->setQuery(
        "UPDATE {$profileTableName} SET addressing_mode = ? WHERE addressing_mode IS NULL OR TRIM(addressing_mode) = ''",
        [$inheritedAddressingMode],
    );
    $backfillSql->setQuery(
        "UPDATE {$profileTableName} SET personalization_mode = ? WHERE personalization_mode IS NULL OR TRIM(personalization_mode) = ''",
        [$inheritedPersonalizationMode],
    );
    $backfillSql->setQuery(
        "UPDATE {$profileTableName} SET suggest_followup_questions = ? WHERE suggest_followup_questions IS NULL OR TRIM(suggest_followup_questions) = ''",
        [$inheritedSuggestFollowup],
    );
    $backfillSql->setQuery(
        "UPDATE {$profileTableName} SET show_sources = ? WHERE show_sources IS NULL OR TRIM(show_sources) = ''",
        [$inheritedShowSources],
    );
    // chat_enabled/search_enabled hingen nie von einem eigenen globalen Config-Wert ab,
    // sondern defaulteten in boot.php schon bisher auf "aktiv" (siehe $showChat/$showSearch,
    // "?? true") - Backfill uebernimmt genau dieses Verhalten als echten Wert.
    $backfillSql->setQuery(
        "UPDATE {$profileTableName} SET chat_enabled = '1' WHERE chat_enabled IS NULL OR TRIM(chat_enabled) = ''",
    );
    $backfillSql->setQuery(
        "UPDATE {$profileTableName} SET search_enabled = '1' WHERE search_enabled IS NULL OR TRIM(search_enabled) = ''",
    );
}

$profileTable
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('name', 'varchar(190)'))
    ->ensureColumn(new rex_sql_column('status', 'tinyint(1)', false, '1'))
    ->ensureColumn(new rex_sql_column('priority', 'int(10)', false, '0'))
    // frontend|backend|both - "backend"/"both" sind Altlasten aus der Zeit, als Profile auch
    // den Backend-Chat steuern konnten (siehe boot.php: der ist jetzt ausschliesslich global
    // per "backend_enabled" gesteuert, kennt gar kein Profil mehr). Neue Profile sind
    // ausschliesslich Frontend-Profile, daher 'frontend' als Default statt 'both'.
    ->ensureColumn(new rex_sql_column('context', 'varchar(20)', false, 'frontend'))
    // Mehrfachauswahl-Spalten (viewer_roles/domains/clangs/yform_profile_ids) werden
    // ueber rex_form-Mehrfachauswahlfelder gepflegt und daher im REDAXO-eigenen
    // Pipe-Format gespeichert ("|a|b|", rex_form_element::setValue() im Core),
    // NICHT als JSON - siehe ChatProfile::decodeStringList().
    ->ensureColumn(new rex_sql_column('viewer_roles', 'text', true)) // |visitor|editor|admin|
    ->ensureColumn(new rex_sql_column('target_mode', 'varchar(20)', false, 'all')) // all|domains|clangs|domains_clangs|individual
    // Vormals Tri-State (''=globale Hauptprofil-Einstellung entscheidet) - jedes Profil ist
    // jetzt vollstaendig eigenstaendig, kein globaler Fallback mehr (siehe Backfill-Migration
    // oben). varchar(10) bleibt (statt tinyint), um Alt-Daten ohne Typkonflikt zu uebernehmen.
    ->ensureColumn(new rex_sql_column('chat_enabled', 'varchar(10)', false, '1'))
    ->ensureColumn(new rex_sql_column('search_enabled', 'varchar(10)', false, '1'))
    ->ensureColumn(new rex_sql_column('domains', 'text', true)) // |domain1|domain2|
    ->ensureColumn(new rex_sql_column('clangs', 'text', true)) // |1|2|
    // use_shared_scope und extra_source gehoeren konzeptionell zusammen (beide
    // bestimmen den Wissens-Scope des Profils: "globalen Pool nutzen?" und
    // "zusaetzliche eigene Quelle?") - daher hier direkt nebeneinander definiert,
    // siehe auch die entsprechend gruppierten Formularfelder in pages/profiles.php.
    ->ensureColumn(new rex_sql_column('use_shared_scope', 'tinyint(1)', false, '1'))
    // Explizite, mehrfach waehlbare Referenz auf andere Profile, deren Quellen zusaetzlich
    // durchsucht werden sollen ("mit wem teile ich mein Wissen") - eigenstaendig neben
    // use_shared_scope (dem EINEN globalen Pool): erlaubt gezieltes Teilen zwischen zwei
    // bestimmten Profilen, ohne dass gleich der ganze globale Pool dazugehoert. Pipe-Format
    // wie die anderen Mehrfachauswahl-Spalten (siehe ChatProfile::decodeIntList()).
    ->ensureColumn(new rex_sql_column('include_profile_ids', 'text', true)) // |id1|id2|
    // Vormals "index_source" genannt - umbenannt wegen Namenskollision mit der
    // gleichnamigen, aber inhaltlich anderen globalen Einstellung (AI Chat →
    // Einstellungen → Indexierung, steuert was der SHARED POOL selbst indexiert).
    // Alte Spalte "index_source" bleibt unten vorerst bestehen (wie sitemap_urls),
    // wird aber nicht mehr geschrieben/gelesen - Migration direkt im Anschluss.
    ->ensureColumn(new rex_sql_column('extra_source', 'varchar(20)', false, 'none')) // none|sitemap|mountpoint
    ->ensureColumn(new rex_sql_column('yform_profile_ids', 'text', true)) // |profilkey1|profilkey2|, referenziert yform_provider_profiles
    // Eigene PDF-Quellen aus dem Medienpool, ebenfalls rein profil-exklusiv (kein
    // Shared-Pool-Beitrag, siehe MediaPoolContentProvider). pdf_media_ids kommt aus
    // rex_form_base::addMedialistField() und wird daher komma-getrennt gespeichert
    // (nicht im sonst ueblichen Pipe-Format) - siehe ChatProfile::decodeCommaList().
    ->ensureColumn(new rex_sql_column('pdf_media_ids', 'text', true)) // dateiname1,dateiname2
    ->ensureColumn(new rex_sql_column('pdf_category_ids', 'text', true)) // |1|2|, Medienpool-Kategorie-IDs
    ->ensureColumn(new rex_sql_column('index_source', 'varchar(20)', false, 'none')) // veraltet, siehe extra_source oben
    // sitemap_urls (Alt, unbenannte Zeilenliste) bleibt in der DB, wird von neuem Code aber
    // nicht mehr geschrieben - siehe Migration unten und ChatProfile::$sitemapGroups.
    ->ensureColumn(new rex_sql_column('sitemap_urls', 'text', true))
    // JSON-Array benannter Sitemap-Gruppen: [{"label": "News", "urls": ["https://..."]}, ...] -
    // jede Gruppe bekommt beim Indexieren source_label = ihr Label (siehe
    // IndexerService::collectProfileTasks(), ai_chat_index.source_label), dadurch eigene
    // Facetten in der Suche (ChatQueryService::search()) und Kontext-Hinweise fuer den Chat
    // (PromptBuilder). Gepflegt ueber einen JS-Repeater in pages/profiles.php, nicht per Hand.
    ->ensureColumn(new rex_sql_column('sitemap_groups', 'text', true))
    // "mountpoint_category_id" (Alt, eine einzelne, unbenannte Kategorie) bleibt in der DB
    // bestehen (siehe Migration unten), wird von neuem Code aber nicht mehr geschrieben -
    // JSON-Array benannter Mountpoint-Gruppen: [{"label":"Service","description":"...",
    // "is_timely":false,"category_id":5}, ...], strukturell identisch zu sitemap_groups
    // (nur "urls" durch ein einzelnes "category_id" ersetzt) - jetzt GLEICHZEITIG mit
    // Sitemap-Gruppen kombinierbar (kein "Eigene Quelle"-Entweder-Oder-Select mehr, siehe
    // extra_source weiter unten). Gepflegt ueber einen JS-Repeater in pages/profiles.php.
    ->ensureColumn(new rex_sql_column('mountpoint_groups', 'text', true))
    ->ensureColumn(new rex_sql_column('mountpoint_category_id', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('custom_prompt', 'text', true))
    ->ensureColumn(new rex_sql_column('ui_language', 'varchar(10)', false, 'de'))
    // Optional, NULL = unveraendertes Verhalten (KI antwortet wie bisher auf Deutsch, siehe
    // PromptBuilder). Bewusst eigenes Feld statt ui_language mitzuverwenden - ui_language ist
    // explizit als "unabhaengig von der Sprache der KI-Antwort" dokumentiert (pages/profiles.php),
    // ein Profil kann also z.B. eine deutsche Oberflaeche mit englischen KI-Antworten haben.
    ->ensureColumn(new rex_sql_column('answer_language', 'varchar(50)', true))
    ->ensureColumn(new rex_sql_column('greeting', 'text', true))
    // NOT NULL mit echtem Default seit der Hauptprofil-Entflechtung (siehe Backfill-Migration
    // oben) - kein globaler Fallback mehr, auto|formal|informal|neutral.
    ->ensureColumn(new rex_sql_column('addressing_mode', 'varchar(20)', false, 'neutral'))
    // NOT NULL mit echtem Default, gleicher Grund wie oben - off|simple|name.
    ->ensureColumn(new rex_sql_column('personalization_mode', 'varchar(20)', false, 'off'))
    // NOT NULL mit echtem Default seit der Hauptprofil-Entflechtung, gleicher Grund wie oben.
    ->ensureColumn(new rex_sql_column('suggest_followup_questions', 'varchar(10)', false, '1'))
    ->ensureColumn(new rex_sql_column('show_sources', 'varchar(10)', false, '1'))
    ->ensureColumn(new rex_sql_column('chat_reset_countdown', 'int(10)', false, '0'))
    ->ensureColumn(new rex_sql_column('chat_copy_history', 'tinyint(1)', false, '0'))
    // FAQ-Vorcaching war frueher global (eine Fragenliste fuer alle Profile) - jetzt je
    // Profil, weil unterschiedliche Profile auf dieselbe Frage unterschiedliche Antworten
    // liefern koennen (eigener Prompt/eigenes Wissen/eigene Anrede). Migration der alten
    // globalen Werte in jedes bestehende Profil siehe unten.
    ->ensureColumn(new rex_sql_column('faq_precache_enabled', 'tinyint(1)', false, '0'))
    ->ensureColumn(new rex_sql_column('faq_precache_questions', 'text', true))
    // Alle theme_*-Farb-/Avatar-/Radius-Spalten sind Altlasten (siehe Migration unten,
    // "Zentrale Theme-Verwaltung") - abgeloest durch theme_id auf ein Theme aus
    // ai_chat_theme. Bleiben in der DB bestehen (nicht droppen), werden aber nirgends
    // mehr gelesen/geschrieben. theme_position ist NICHT betroffen: die Widget-Position
    // ist bewusst kein Theme-Bestandteil und bleibt weiterhin ein eigenes, unabhaengiges
    // Override je Profil (siehe ProfileTheme::resolvePosition()).
    ->ensureColumn(new rex_sql_column('theme_primary_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('theme_header_bg_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('theme_chat_bg_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('theme_text_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('theme_bot_message_bg_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('theme_border_radius', 'varchar(10)', true))
    ->ensureColumn(new rex_sql_column('theme_position', 'varchar(20)', true)) // bottom-right|bottom-left
    ->ensureColumn(new rex_sql_column('theme_avatar', 'varchar(255)', true))
    // NULL = globales Standard-Theme (Config "default_theme_id") verwenden - siehe
    // ai_chat_theme weiter unten und ProfileTheme.
    ->ensureColumn(new rex_sql_column('theme_id', 'int(10) unsigned', true))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    ->ensureIndex(new rex_sql_index('status_context', ['status', 'context']))
    ->ensure();

// Zentrale Theme-Verwaltung: mehrere benannte, wiederverwendbare Themes statt eines
// Farbfeld-Satzes je Profil (siehe theme_id oben) - Farben/Avatar/Eckenradius, OHNE
// Position (siehe Kommentar oben, bleibt bewusst separat).
rex_sql_table::get(rex::getTable('ai_chat_theme'))
    ->ensurePrimaryIdColumn()
    ->ensureColumn(new rex_sql_column('name', 'varchar(190)'))
    ->ensureColumn(new rex_sql_column('primary_color', 'varchar(20)', true))
    // NULL = folgt weiterhin primary_color, siehe Fallback-Kette in assets/ai-chat.js
    // (showFollowUpQuestions()) - Folgefragen-Chips waren vorher fest an die Akzentfarbe
    // gekoppelt und dadurch nicht unabhaengig davon themebar.
    ->ensureColumn(new rex_sql_column('followup_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('header_bg_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('chat_bg_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('text_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('bot_message_bg_color', 'varchar(20)', true))
    // NULL = folgt weiterhin text_color (Kopfzeile) bzw. dem festen "white" der Bibliothek,
    // siehe Fallback-Kette in assets/ai-chat.js (.message-bot/.message-user color) -
    // eigene Werte hier sind nur fuer den Kontrastfall noetig (z.B. dunkles Theme mit
    // dunkler Bot-Blase, oder eine sehr helle Akzentfarbe, auf der weisser Text kaum
    // lesbar waere).
    ->ensureColumn(new rex_sql_column('bot_message_text_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('user_message_text_color', 'varchar(20)', true))
    // Eingabefeld: --ai-chat-input-bg/-text/-border existierten im Widget-CSS schon vorher
    // (assets/ai-chat.js), waren aber bislang von KEINEM Theme-Feld aus befuellbar - ein
    // dunkles Theme bekam dadurch trotz dunklem Chat-/Kopfzeilen-Hintergrund ein
    // stur weisses Eingabefeld. NULL = Bibliotheks-Default (weiss/#333/#ddd), wie bisher.
    ->ensureColumn(new rex_sql_column('input_bg_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('input_text_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('input_border_color', 'varchar(20)', true))
    ->ensureColumn(new rex_sql_column('border_radius', 'varchar(10)', true))
    ->ensureColumn(new rex_sql_column('avatar', 'varchar(255)', true))
    ->ensureColumn(new rex_sql_column('createdate', 'datetime'))
    ->ensureColumn(new rex_sql_column('updatedate', 'datetime'))
    ->ensure();

// Einmalige Migration: bestehende Profile mit dem alten, unbenannten sitemap_urls-Textfeld
// bekommen eine einzelne, namenlose sitemap_groups-Gruppe mit denselben URLs - ohne das
// wuerde ein Upgrade auf die benannten Sitemap-Gruppen bestehende, produktiv genutzte
// Sitemap-Quellen stillschweigend abschalten (index_source bliebe 'sitemap', aber
// collectProfileTasks() liest ab jetzt nur noch sitemap_groups, nicht mehr sitemap_urls).
$sitemapMigrationSql = rex_sql::factory();
$sitemapMigrationSql->setQuery(
    'SELECT id, sitemap_urls FROM ' . rex::getTable('ai_chat_profile') . " WHERE sitemap_urls IS NOT NULL AND TRIM(sitemap_urls) != '' AND (sitemap_groups IS NULL OR TRIM(sitemap_groups) = '')",
);
foreach ($sitemapMigrationSql as $row) {
    $urls = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $row->getValue('sitemap_urls')) ?: [])));
    if ([] === $urls) {
        continue;
    }

    $updateSql = rex_sql::factory();
    $updateSql->setTable(rex::getTable('ai_chat_profile'));
    $updateSql->setWhere(['id' => (int) $row->getValue('id')]);
    $updateSql->setValue('sitemap_groups', json_encode([['label' => '', 'urls' => $urls]]));
    $updateSql->update();
}

// Einmalige Migration: bestehende Profile mit der alten, einzelnen mountpoint_category_id
// (extra_source='mountpoint') bekommen eine einzelne, namenlose mountpoint_groups-Gruppe mit
// derselben Kategorie - ohne das wuerde der Umstieg auf mehrere benannte, mit Sitemap
// kombinierbare Mountpoint-Gruppen eine bereits produktiv genutzte Struktur-Quelle
// stillschweigend abschalten.
$mountpointMigrationSql = rex_sql::factory();
$mountpointMigrationSql->setQuery(
    'SELECT id, mountpoint_category_id FROM ' . rex::getTable('ai_chat_profile') . " WHERE extra_source = 'mountpoint' AND mountpoint_category_id IS NOT NULL AND (mountpoint_groups IS NULL OR TRIM(mountpoint_groups) = '')",
);
foreach ($mountpointMigrationSql as $row) {
    $categoryId = (int) $row->getValue('mountpoint_category_id');
    if ($categoryId <= 0) {
        continue;
    }

    $updateSql = rex_sql::factory();
    $updateSql->setTable(rex::getTable('ai_chat_profile'));
    $updateSql->setWhere(['id' => (int) $row->getValue('id')]);
    $updateSql->setValue('mountpoint_groups', json_encode([['label' => '', 'description' => '', 'is_timely' => false, 'category_id' => $categoryId]]));
    $updateSql->update();
}

// Einmalige Migration: die Spalte "index_source" wurde in "extra_source" umbenannt
// (Namenskollision mit der gleichnamigen globalen Einstellung, siehe Kommentar oben) -
// bestehende Werte einmalig uebernehmen, statt bereits konfigurierte eigene
// Sitemap-/Mountpoint-Quellen stillschweigend auf den Default "none" zurueckzusetzen.
if ($extraSourceMigrationNeeded) {
    $extraSourceMigrationSql = rex_sql::factory();
    $extraSourceMigrationSql->setQuery('UPDATE ' . rex::getTable('ai_chat_profile') . ' SET extra_source = index_source');
}

// Einmalige Migration: FAQ-Vorcaching war bisher global (eine Fragenliste fuer alle
// Profile, siehe die jetzt entfernten Felder auf "Einstellungen -> Chunking & Retrieval") -
// die alten globalen Werte werden in JEDES bestehende Profil uebernommen, damit sich das
// Verhalten bestehender Installationen nicht stillschweigend aendert.
if ($faqPrecacheMigrationNeeded) {
    $faqAddon = rex_addon::get('ai_chat');
    $faqPrecacheEnabled = (bool) $faqAddon->getConfig('faq_precache_enabled', false);
    $faqPrecacheQuestions = (string) $faqAddon->getConfig('faq_precache_questions', '');

    $faqMigrationSql = rex_sql::factory();
    $faqMigrationSql->setQuery(
        'UPDATE ' . rex::getTable('ai_chat_profile') . ' SET faq_precache_enabled = ?, faq_precache_questions = ?',
        [$faqPrecacheEnabled ? 1 : 0, $faqPrecacheQuestions],
    );
}

// Genau ein Default-Profil, das ohne jede weitere Konfiguration sofort greift
// (context=both, target_mode=all, use_shared_scope=1) - ohne dieses Profil
// wuerde ProfileResolver nach der Installation nirgends ein Profil finden und
// boot.php koennte kein Widget injizieren.
$defaultProfileSql = rex_sql::factory();
$defaultProfileSql->setQuery('SELECT COUNT(*) AS total FROM ' . rex::getTable('ai_chat_profile'));
if (0 === (int) $defaultProfileSql->getValue('total')) {
    $seedSql = rex_sql::factory();
    $seedSql->setTable(rex::getTable('ai_chat_profile'));
    $seedSql->setValue('name', 'Standard');
    $seedSql->setValue('status', 1);
    $seedSql->setValue('priority', 0);
    $seedSql->setValue('context', 'frontend');
    $seedSql->setValue('viewer_roles', '|visitor|editor|admin|');
    $seedSql->setValue('target_mode', 'all');
    $seedSql->setValue('use_shared_scope', 1);
    $seedSql->setValue('extra_source', 'none');
    $seedSql->setValue('ui_language', 'de');
    // Explizit gesetzt statt auf die Spalten-Defaults zu vertrauen - seit der
    // Hauptprofil-Entflechtung gibt es keine globale Einstellung mehr, die das
    // Default-Profil stattdessen uebernehmen koennte.
    $seedSql->setValue('chat_enabled', '1');
    $seedSql->setValue('search_enabled', '1');
    $seedSql->setValue('addressing_mode', 'neutral');
    $seedSql->setValue('personalization_mode', 'off');
    $seedSql->setValue('suggest_followup_questions', '1');
    $seedSql->setValue('show_sources', '1');
    $seedSql->setValue('chat_reset_countdown', 0);
    $seedSql->setValue('chat_copy_history', 0);
    $seedSql->setDateTimeValue('createdate', time());
    $seedSql->setDateTimeValue('updatedate', time());
    $seedSql->insert();
}

// Zentrale Theme-Verwaltung: genau ein Standard-Theme, das ohne jede weitere Konfiguration
// sofort greift - aus den BISHERIGEN globalen Darstellung-Werten erzeugt (nicht aus
// hartcodierten Defaults), damit bereits vorgenommenes Branding einer bestehenden
// Installation beim Umstieg auf Themes automatisch erhalten bleibt.
$themeCountSql = rex_sql::factory();
$themeCountSql->setQuery('SELECT COUNT(*) AS total FROM ' . rex::getTable('ai_chat_theme'));
if (0 === (int) $themeCountSql->getValue('total')) {
    $themeAddon = rex_addon::get('ai_chat');

    $defaultThemeSql = rex_sql::factory();
    $defaultThemeSql->setTable(rex::getTable('ai_chat_theme'));
    $defaultThemeSql->setValue('name', 'Standard');
    $defaultThemeSql->setValue('primary_color', (string) $themeAddon->getConfig('primary_color', '#007bff'));
    $defaultThemeSql->setValue('header_bg_color', (string) $themeAddon->getConfig('header_bg_color', '#f8f9fa'));
    $defaultThemeSql->setValue('chat_bg_color', (string) $themeAddon->getConfig('chat_bg_color', '#ffffff'));
    $defaultThemeSql->setValue('text_color', (string) $themeAddon->getConfig('text_color', '#333333'));
    $defaultThemeSql->setValue('bot_message_bg_color', (string) $themeAddon->getConfig('bot_message_bg_color', '#f1f3f5'));
    // Kein globales Config-Pendant fuer diese vier (kamen erst mit der Theme-Verwaltung
    // dazu, nie als eigene globale Darstellung-Einstellung) - direkt mit denselben
    // Werten befuellen, auf die das Widget-CSS ohnehin zurueckfiele (assets/ai-chat.js,
    // .message-user/.message-bot/.chat-input), damit das Standard-Theme in der
    // Themes-Liste als vollstaendiges, bewusst gesetztes Bündel erscheint statt mit
    // scheinbar leeren/vergessenen Feldern.
    $defaultThemeSql->setValue('bot_message_text_color', (string) $themeAddon->getConfig('text_color', '#333333'));
    $defaultThemeSql->setValue('user_message_text_color', '#ffffff');
    $defaultThemeSql->setValue('input_bg_color', '#ffffff');
    $defaultThemeSql->setValue('input_text_color', '#333333');
    $defaultThemeSql->setValue('input_border_color', '#dddddd');
    $defaultThemeSql->setValue('border_radius', (string) $themeAddon->getConfig('border_radius', '12'));
    $defaultThemeSql->setValue('avatar', (string) $themeAddon->getConfig('avatar', ''));
    $defaultThemeSql->setDateTimeValue('createdate', time());
    $defaultThemeSql->setDateTimeValue('updatedate', time());
    $defaultThemeSql->insert();

    if (!$themeAddon->hasConfig('default_theme_id')) {
        $themeAddon->setConfig('default_theme_id', (int) $defaultThemeSql->getLastId());
    }

    // Einmalige Migration: Profile, die bereits eigene theme_*-Farben/Avatar/Radius gesetzt
    // hatten (vor der zentralen Theme-Verwaltung), bekommen ein eigenes, aus genau diesen
    // Werten erzeugtes Theme zugewiesen - verhindert stillen Branding-Verlust bei bereits
    // individuell eingefaerbten Profilen. theme_position ist bewusst aussen vor (bleibt ein
    // eigenes, von Themes unabhaengiges Profil-Override, siehe Kommentar weiter oben).
    $profilesWithOwnThemeSql = rex_sql::factory();
    $profilesWithOwnThemeSql->setQuery(
        'SELECT id, name, theme_primary_color, theme_header_bg_color, theme_chat_bg_color, theme_text_color, theme_bot_message_bg_color, theme_border_radius, theme_avatar
         FROM ' . rex::getTable('ai_chat_profile') . "
         WHERE COALESCE(theme_primary_color, theme_header_bg_color, theme_chat_bg_color, theme_text_color, theme_bot_message_bg_color, theme_border_radius, theme_avatar, '') != ''",
    );
    foreach ($profilesWithOwnThemeSql as $profileRow) {
        $migratedThemeSql = rex_sql::factory();
        $migratedThemeSql->setTable(rex::getTable('ai_chat_theme'));
        $migratedThemeSql->setValue('name', (string) $profileRow->getValue('name') . ' (migriert)');
        $migratedThemeSql->setValue('primary_color', (string) $profileRow->getValue('theme_primary_color'));
        $migratedThemeSql->setValue('header_bg_color', (string) $profileRow->getValue('theme_header_bg_color'));
        $migratedThemeSql->setValue('chat_bg_color', (string) $profileRow->getValue('theme_chat_bg_color'));
        $migratedThemeSql->setValue('text_color', (string) $profileRow->getValue('theme_text_color'));
        $migratedThemeSql->setValue('bot_message_bg_color', (string) $profileRow->getValue('theme_bot_message_bg_color'));
        $migratedThemeSql->setValue('border_radius', (string) $profileRow->getValue('theme_border_radius'));
        $migratedThemeSql->setValue('avatar', (string) $profileRow->getValue('theme_avatar'));
        $migratedThemeSql->setDateTimeValue('createdate', time());
        $migratedThemeSql->setDateTimeValue('updatedate', time());
        $migratedThemeSql->insert();

        $assignThemeSql = rex_sql::factory();
        $assignThemeSql->setTable(rex::getTable('ai_chat_profile'));
        $assignThemeSql->setWhere(['id' => (int) $profileRow->getValue('id')]);
        $assignThemeSql->setValue('theme_id', (int) $migratedThemeSql->getLastId());
        $assignThemeSql->update();
    }
}

