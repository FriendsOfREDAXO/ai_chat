<?php

// Vollstaendiger Uninstall - loescht saemtliche Addon-Tabellen inklusive Profile,
// Trigger und Themes (echte Redaktions-Konfiguration, nicht nur regenerierbarer
// Index/Cache). Bewusste Nutzer-Entscheidung: ein Deinstallieren soll wirklich
// alles entfernen, kein "stiller" Datenrest wie vor diesem uninstall.php - siehe
// CHANGELOG. rex_config wird bereits vom REDAXO-Core automatisch geleert.

rex_sql_table::get(rex::getTable('ai_chat_index'))->drop();
rex_sql_table::get(rex::getTable('ai_chat_cache'))->drop();
rex_sql_table::get(rex::getTable('ai_chat_triggers'))->drop();
rex_sql_table::get(rex::getTable('ai_chat_retrieval_log'))->drop();
rex_sql_table::get(rex::getTable('ai_chat_profile'))->drop();
rex_sql_table::get(rex::getTable('ai_chat_theme'))->drop();
rex_sql_table::get(rex::getTable('ai_chat_stats'))->drop();
rex_sql_table::get(rex::getTable('ai_chat_ratelimit'))->drop();
