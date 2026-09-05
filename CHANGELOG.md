# Changelog

## [Unreleased]

### Geändert
- **README erheblich erweitert**: die neuen Retrieval-Features (Re-Ranking,
  Kategorie-Pfad, Metainfo-Felder, erweitertes JSON-LD) dokumentiert, dazu
  drei neue Abschnitte - "Best Practices" (Chunk-Größe, Reindex-Zeitpunkte,
  Provider-Wechsel, …), "FAQ" (häufige Stolperfallen) und "Datenschutz und
  Besucherschutz" (welche Daten an welchen KI-Provider gehen, der bestehende
  Eingabe-Schutz gegen sensible Daten, was serverseitig gespeichert wird,
  Empfehlungen für sensible Website-Inhalte).

### Neu
- **Re-Ranking**: die Top-Kandidaten der Ähnlichkeitssuche (Standard: 20)
  werden vor der finalen Auswahl zusätzlich nach Stichwort-Überdeckung mit
  der Frage neu sortiert - korrigiert Fälle, in denen die reine
  Cosine-Similarity einen thematisch zufälligen, aber embedding-technisch
  naheliegenden Treffer vor eine tatsächlich passendere Seite stellt. Ohne
  zusätzlichen KI-Aufruf, keine spürbare Verzögerung. Einstellbar unter
  "Chunking & Cache" (an/aus, Kandidatenzahl).
- **Kategorie-Pfad als Embedding-Metadaten**: jeder Kontext-Abschnitt bekommt
  vor dem Embedding jetzt zusätzlich seine Einordnung mitgegeben - bei
  Struktur-Inhalten die echte REDAXO-Kategorie-Hierarchie (z.B. "Agentur >
  Leistungen > Webentwicklung"), bei Sitemap-/YForm-/Provider-Inhalten ohne
  REDAXO-Kategorie ersatzweise die Ordnerstruktur aus der URL. Abschaltbar,
  Standard: an. Wirkt erst nach einer erneuten Indexierung.
- **Konfigurierbare Metainfo-Felder als Zusatzkontext**: eigene Metainfo-Felder
  (z.B. eine "Meta-Keywords"-Spalte) können jetzt als zusätzlicher
  Kontext-Hinweis vor dem Embedding jedes Artikels eingefügt werden - Feldnamen
  sind pro Installation frei vergeben, deshalb konfigurierbar statt fest
  verdrahtet. Nur für Struktur-Inhalte wirksam.
- **JSON-LD-Extraktion erweitert**: neben `Person`/`Organization`/
  `ContactPoint`/`LocalBusiness` (bereits vorhanden) werden jetzt auch
  `BreadcrumbList` (Kategorie-Pfad aus echten Schema.org-Daten, zuverlässiger
  als geratene URL-Segmente), `FAQPage` (Frage/Antwort-Paare als einzelne
  Fakten) und `openingHours`/`OpeningHoursSpecification` ausgewertet.

### Behoben
- **Indexierungs-Übersicht zeigte für YForm/Medienpool einen irreführenden
  globalen "aktiv/deaktiviert"-Status.** Überbleibsel aus der Zeit vor der
  Hauptprofil-Entflechtung: beide Quellen werden seitdem ausschließlich je
  Profil gewählt, der geprüfte globale Schalter existiert im Formular gar
  nicht mehr (oder ein Upgrade brachte einen längst bedeutungslosen alten
  Wert mit) - ein Profil mit eigenen PDFs wurde trotzdem als "deaktiviert"
  angezeigt. Zeigt jetzt für beide, wie viele Profile die Quelle tatsächlich
  nutzen ("genutzt von N Profil(en)"/"von keinem Profil genutzt"), und die
  Liste "Aktivierte Content-Provider" richtet sich nach real vorhandenem
  Indexinhalt statt nach derselben veralteten globalen Auswahl.
- **Chat-Antworten begannen teils mit dem sichtbaren Präfix "[Bereich: Name —
  Beschreibung]".** Diese Markierung ist nur eine interne Einordnungshilfe für
  die KI (aus welchem benannten Sitemap-/Struktur-Bereich ein Kontext-Abschnitt
  stammt) und soll nie in der Antwort selbst auftauchen - seit Profile eigene
  Bereiche benennen/beschreiben können, steht sie vor praktisch jedem
  Kontext-Abschnitt und wurde von manchen Modellen wörtlich übernommen. Der
  System-Prompt weist jetzt ausdrücklich darauf hin, sie nicht zu wiederholen,
  zusätzlich wird ein versehentlich übernommenes Präfix am Anfang der Antwort
  serverseitig entfernt.

## [2.0.0-beta1] - 2026-09-04

Der große Umbau: reine, eigenständige Profile statt eines globalen
Hauptprofils, kein Developer-Chat mehr. Breaking Changes, siehe unten -
wer eine der entfernten Sachen konkret braucht, kann sie sich über die
bestehenden Erweiterungspunkte selbst nachbauen.

### Entfernt
- **Hauptprofil.** Kein globaler Fallback mehr für Chat/Suche-Verhalten -
  jedes Profil ist jetzt komplett eigenständig konfiguriert. Bestehende
  Installationen übernehmen beim Update automatisch die zuletzt wirksamen
  globalen Werte in jedes Profil, damit sich erstmal nichts ändert.
- **Globaler Wissens-Pool.** Jedes Profil wählt seine Quellen jetzt selbst
  (Sitemap, Struktur, YForm, PDFs - beliebig kombinierbar, Struktur-Bereiche
  können jetzt auch mehrfach benannt werden). Wissen gezielt zwischen zwei
  Profilen teilen geht über das neue Feld "Wissen teilen mit Profil(en)".
- **Developer-Chat.** Backend-Auto-Chat, REDAXO-Systemwerkzeuge und die
  GitHub-/AddOn-Docs-Indexierung sind komplett raus. Eigene Wissensquellen
  gibt's weiterhin über den `AI_CHAT_CONTENT_PROVIDERS`-Erweiterungspunkt.

### Neu
- Zentrale Theme-Verwaltung: mehrere benannte, wiederverwendbare Themes mit
  Live-Vorschau und alpha-fähigem Colorpicker statt Theme-Feldern in jedem
  Profil einzeln. Folgefragen-Chips haben jetzt außerdem eine eigene,
  unabhängig von der Akzentfarbe einstellbare Farbe.
- FAQ-Vorcaching und Trigger & Antworten sind jetzt je Profil konfigurierbar
  bzw. einschränkbar, statt nur global zu gelten.
- YForm-Mappings werden jetzt wie Profile/Themes als übersichtliche Liste
  verwaltet statt als Wand aus aufgeklappten Formularen.
- Neue Navigation: Profile ist jetzt die Startseite, Trigger/Cache/YForm
  sind Unterseiten von Indexierung, neue Einfach-Einstellungsseite und
  eigene Suche-Einstellungsseite.
- Globaler IP-Testmodus: eigene IP eintragen und die komplette Seite (auch
  eingeschränkte Profile) nur für dich sehen, ganz ohne Login.
- Chunking-Einblick auf der Indexierungs-Übersicht mit konkreter
  Größenempfehlung bei auffälliger Fragmentierung.
- Ladeanzeige für die KI-Zusammenstellung in der Suche.
- Live-Suche filtert jetzt Frage-/Füllwörter (wer/was/macht/tut/...) aus den
  Suchbegriffen raus - weniger thematisch zufällige Treffer.

### Behoben
- Chat-Antworten hängten teils themenfremde Quellen-Links an, sobald die Frage
  den eigenen Domain-/Firmennamen enthielt (z.B. "wann kann ich die KLXM
  erreichen" zog neben dem Kontakt-Link auch zwei unabhängige Blogartikel an,
  einfach weil "klxm" in jeder einzelnen URL steckt). Die Quellen-Relevanzprüfung
  zählt den Domainnamen jetzt nicht mehr mit, der URL-Pfad bleibt aber weiterhin
  ein Signal.
- Boilerplate-Widgets ("Ähnliche Beiträge"/"Links:") landeten im Suchindex
  und wurden von der KI wörtlich nachgeplappert.
- Suchergebnis-Snippets markierten den Domainnamen doppelt in der
  angezeigten URL.
- Schmale Mehrfachauswahl-Felder im Profil-Formular.
- Theme-Live-Vorschau lud das Widget-Skript nicht zuverlässig.
- "Profil testen" zeigte teils Verlauf/Begrüßung eines anderen Profils.
- Hängende Hintergrund-Indexierung auf manchen Servern, dazu ein
  WAF-bedingtes 403 bei manchen Hosting-/Plesk-Setups.
- Statistik-Filter landete nach dem Absenden auf der falschen Seite.

Kompletter technischer Feinschliff wie gewohnt im Git-Log.

## [1.2.0] - 2026-09-03

### Behoben (Indexierung-Seite: doppelte Buttons, komplett überarbeitetes Design)
- Die Buttons "Jetzt indexieren"/"Im Hintergrund indexieren"/"Refresh"/"Abbrechen" standen
  doppelt auf der Seite (einmal in einer "Toolbar" oben, einmal in der Aktions-Box weiter
  unten - zwei parallel gepflegte, nie zusammengeführte Button-Sätze). Die obere Toolbar
  entfällt ersatzlos, inklusive ihrer doppelten JS-Verdrahtung in
  `assets/ai-chat-indexer.js`.
- Neuer, animierter Kopfbereich (Aurora-Farbverlauf + durchlaufender "Scan"-Lichtstrahl,
  passend zum Thema Indexieren/Scannen von Inhalten, reines CSS ohne Bild-/Lottie-Datei -
  gleiche Technik wie der Header des `cke5`-Addons, hier neu umgesetzt).
- Der über 250 Zeilen lange `<style>`-Block direkt im PHP-Output wandert in eine eigene
  Datei (`assets/ai-chat-indexing-backend.css`), analog zu den bereits ausgelagerten
  Settings-Styles.
- Die drei bisher unstyled `<ul>`-Listen (Fundstellen je Quellentyp, aktivierte/verfügbare
  Content-Provider) stehen jetzt als einheitliches Kachel-Raster nebeneinander statt lose
  untereinander gestapelt zu wirken. Der GitHub-Sync-Hinweis nutzt jetzt eine
  theme-/dark-mode-fähige CSS-Klasse statt hartcodierter, nur für helles Theme passender
  Inline-Farben.

### Geändert (Demos & Beispiele auf den aktuellen Stand gebracht)
- Verweis auf die globale Streaming-Einstellung zeigt jetzt auf ihren tatsächlichen,
  aktuellen Ort (Einstellungen → KI-Provider & Parameter, vorher noch der alte Pfad).
- Der Hinweis zum Profil-Feature erwähnt jetzt auch die neuen, ebenfalls je Profil
  einstellbaren Folgefragen/Quellenanzeige sowie die zentrale Theme-Verwaltung (AI Chat →
  Themes) als Quelle für Farben/Avatar/Eckenradius statt pauschal "aus dem Profil".
- CSS-Variablen-Tabelle ergänzt um `--ai-chat-bot-msg-text`/`--ai-chat-user-msg-text`
  (siehe zentrale Theme-Verwaltung weiter oben).

### Geändert (Navigation: Hauptprofil als eigener Reiter, YForm-Tabellen und Systemcheck unter Einstellungen)
- **Hauptprofil** ist jetzt ein eigener Hauptreiter (vorher zwei Unterseiten innerhalb von
  "Einstellungen") mit seinen beiden Seiten "Verhalten & Antworten" und "Erscheinungsbild &
  Suche" - macht deutlicher, dass es sich um profilspezifische Fallback-Werte handelt statt
  um instanzweite Einstellungen.
- **YForm-Tabellen** ist jetzt eine Unterseite von "Einstellungen" statt ein eigener
  Hauptreiter - eine reine Konfigurationsseite (welche YForm-Tabellen indexiert werden),
  kein täglich genutzter Arbeitsbereich wie Profile/Themes/Indexierung.
- **Systemcheck** (Server-/Voraussetzungs-Diagnose: PHP-/REDAXO-Version, PDF-Extraktion,
  Hintergrund-Indexierung, native Vektorsuche, KI-Provider) ist jetzt eine eigene Unterseite
  von "Einstellungen" statt Teil der Statistiken-Seite - eine Diagnose-Frage ("läuft die
  Umgebung korrekt?"), keine Nutzungsauswertung. Die Statistiken-Seite zeigt seitdem
  ausschließlich die Nutzungsstatistik.
- Da "Einstellungen" nach dem Auslagern von Hauptprofil/YForm-Tabellen nur noch echte
  instanzweite Einstellungen enthält, entfällt das bisherige "Global:"-Präfix in den
  Reiter-Titeln (z.B. "Global: Zugriff & Sicherheit" → "Zugriff & Sicherheit") - es wäre ab
  jetzt auf jeder einzelnen Unterseite redundant.

### Geändert (Streaming ist global, Folgefragen/Quellenanzeige sind jetzt je Profil einstellbar)
- **"Live-Antworten streamen (SSE)"** stand bisher auf der "Hauptprofil: Verhalten"-Seite,
  obwohl es schon immer ausnahmslos für alle Profile gleichermaßen galt (reine
  Technik-Einstellung der Antwortauslieferung, keine Profil-Override-Möglichkeit) - die
  Platzierung suggerierte fälschlich, nur das Hauptprofil sei betroffen. Jetzt bei
  **Global: KI-Provider & Parameter**, direkt neben Timeout/Temperature/Token-Limit
  derselben Antwortgenerierung. Reine Verschiebung, keine Verhaltensänderung.
- **"Vorgeschlagene Folgefragen anzeigen"** und **"Quellen/Links in Antworten anzeigen"**
  waren bisher rein global, ohne jede Möglichkeit, sie für ein einzelnes Profil abweichend
  zu setzen. Beide sind jetzt zusätzlich als Profil-Feld verfügbar (Box "Verhalten" in
  einem Profil): "Globale Einstellung übernehmen" (Standard, unverändertes Verhalten)
  oder explizit "An"/"Aus" je Profil - z.B. um bei einem reinen FAQ-Profil Folgefragen
  abzuschalten, ohne das für alle anderen Profile mit zu deaktivieren.

### Neu (Zentrale Theme-Verwaltung statt Theme-Editor je Profil)
- Farben/Avatar/Eckenradius für das Chat-Widget wurden bisher in JEDEM Profil einzeln
  gepflegt (identische Felder mehrfach, keine Wiederverwendung, native
  `<input type="color">`-Felder ohne Transparenz). Jetzt gibt es einen neuen Reiter
  **AI Chat → Themes**: mehrere benannte, wiederverwendbare Themes mit Live-Vorschau, die
  Profile nur noch aus einem Dropdown auswählen (`theme_id`, `NULL` = globales
  Standard-Theme verwenden). Die alten profil-eigenen Theme-Felder bleiben in der
  Datenbank erhalten (keine Datenmigration nötig, still gelegt), bestehende Profile ohne
  eigene Farben verwenden ab sofort automatisch das globale Standard-Theme.
- Neuer, alpha-fähiger Colorpicker (`skerbis/pickit_color`) statt des nativen
  Browser-Farbfelds - Farben können jetzt auch teiltransparent gewählt werden (z.B.
  `#007bffcc`), inklusive Hex-Eingabe, Farbfläche/Regler und Voreinstellungen.
- Die Widget-Position (unten rechts/links) bleibt bewusst unabhängig von Themes -
  weiterhin sowohl global als auch je Profil überschreibbar.
- Jedes Theme hat jetzt eigene Textfarben für Bot- und Nutzer-Sprechblase, statt die
  Bot-Blase zwangsläufig dieselbe Farbe wie die Kopfzeile tragen zu lassen und die
  Nutzer-Blase fest auf Weiß zu belassen - wichtig z.B. bei einer hellen Akzentfarbe
  (Weiß darauf ist kaum lesbar) oder einem dunklen Theme. Zusätzlich lassen sich jetzt
  auch Hintergrund, Textfarbe und Rahmen des Eingabefelds pro Theme setzen - vorher blieb
  das Eingabefeld unabhängig vom gewählten Theme immer weiß, was bei einem dunklen Theme
  wie ein vergessenes UI-Element wirkte. Das bei einer frischen Installation automatisch
  angelegte "Standard"-Theme bekommt für diese fünf neuen Felder jetzt ebenfalls sinnvolle,
  zum Widget-Standardverhalten passende Werte statt leerer Felder.
- Die Live-Vorschau bettet dafür die echte `<ai-chat>`-Widget-Komponente ein (dieselbe
  Technik wie beim bestehenden "Profil testen"-Fenster), statt sie nachzubauen - die
  Vorschau sieht dadurch exakt wie der echte Chat aus (inkl. Kopfzeile, Sprechblasen mit
  korrektem Eckenradius, Eingabefeld und Senden-Button) und bleibt automatisch korrekt,
  auch wenn sich das Widget-Design künftig ändert. Ein Absenden in der Vorschau ist
  bewusst wirkungslos (keine echten Anfragen, kein zugeordnetes Profil).

### Behoben (Theme-Editor: doppelter Colorpicker, Live-Vorschau ohne Wirkung, leere Felder wurden überschrieben)
- War auf einer Instanz noch ein weiteres Addon aktiv, das dieselbe Colorpicker-
  Bibliothek ebenfalls global lädt (z.B. `uikit_theme_builder`), initialisierte sich pro
  Farbfeld eine zweite, überlappende Picker-Oberfläche - jede geladene Kopie der
  Bibliothek scannt unabhängig nach `[data-colorpicker]`-Feldern. Das Attribut wird jetzt
  erst durch ein eigenes Init-Script gesetzt, nachdem alle automatischen Scans bereits
  gelaufen sind, und die Initialisierung danach gezielt genau einmal ausgelöst.
- Das Vorschau-/Init-Script landete durch eine falsche Reihenfolge nie im ausgegebenen
  HTML (wurde dem Formular hinzugefügt, nachdem dessen HTML bereits gerendert war) -
  weder Live-Vorschau noch Colorpicker liefen dadurch jemals automatisch an.
- Ein per Hex-Eingabe getippter Farbwert (z.B. für einen exakten Transparenzwert) wurde
  von der Bibliothek nur in der eigenen Vorschau übernommen, aber nie in das tatsächlich
  gespeicherte Formularfeld geschrieben (nur Ziehen an Farbfläche/Reglern committete
  sofort) - ein Enter/Verlassen des Hex-Feldes übernimmt den Wert jetzt zuverlässig.
- Ein neu angelegtes Theme, bei dem nur eine Farbe bewusst geändert wurde, bekam für alle
  übrigen, unberührten Farbfelder automatisch dieselbe generische Bibliotheks-Vorgabe
  (Blau) statt sinnvoller, feldspezifischer Startwerte - jedes Farbfeld startet jetzt mit
  seinem eigenen passenden Vorgabewert.

### Geändert (Einstellungs-Reiter neu gruppiert: Global vs. Hauptprofil)
- Die sechs Einstellungs-Reiter waren thematisch uneinheitlich gruppiert - z.B. lag
  "Chunking & Feinsteuerung Embeddings" (gilt für ALLE Quellen/Profile) mitten in der
  quellenspezifischen Indexierungs-Seite. Jeder Reiter heißt jetzt explizit „Global: …"
  oder „Hauptprofil: …", damit sofort klar ist, ob eine Einstellung instanzweit gilt oder
  nur der überschreibbare Standardwert ist, den ein Profil für sich ersetzen kann:
  - **Global: Zugriff & Sicherheit** - wie vorher, zusätzlich Rate-Limit,
    Nachrichtenlängen-Limits, Datenschutz-Whitelist und Spam-Begriffe (kamen vorher aus
    "Verhalten" - sind Abuse-/Kosten-Schutz, keine Chat-Persönlichkeit).
  - **Global: KI-Provider & Parameter** - Provider-Auswahl/Zugangsdaten, zusätzlich
    Timeout/Temperature/Token-Limit und die Statistik-Erfassung (kamen aus "Erweiterte
    KI-Parameter").
  - **Global: Indexierungs-Quellen** - welche Quellen überhaupt indexiert werden, ohne
    die jetzt ausgelagerte Chunking-Box.
  - **Global: Chunking & Retrieval** (neuer Reiter) - Chunking, Embedding-Kontext,
    RAG-Abruf und Antwort-Cache/FAQ-Vorcaching an einem Ort, weil das für jede Quelle und
    jedes Profil gleichermaßen gilt. Ersetzt die bisherige Seite "Erweiterte
    KI-Parameter", die komplett entfällt.
  - **Hauptprofil: Verhalten & Antworten** - nur noch Begrüßung/Prompt/Anrede/
    Personalisierung/Quellen-Anzeige (die eigentliche "Persönlichkeit" des
    Standard-Chats).
  - **Hauptprofil: Erscheinungsbild & Suche** - Darstellung wie vorher, zusätzlich die
    Such-Feineinstellungen (kamen aus "Verhalten").
  Alle Config-Werte bleiben unverändert unter ihrem bisherigen Schlüssel gespeichert -
  reine Neusortierung der Formulare, kein Datenverlust.

### Behoben (Hintergrund-Indexierung blieb an einzelnen Dokumenten sehr lange hängen)
- Embedding-Anfragen (für JEDES indexierte Dokument nötig) liefen über denselben,
  konfigurierbaren Timeout wie die Chat-Antwortgenerierung (Standard 120s, für ein
  langsames lokales Modell dort sinnvoll) - ein einzelnes Dokument, bei dem der
  KI-Provider hängt statt zu antworten (z.B. bei einem instabilen Embedding-Gateway),
  konnte die Hintergrund-Indexierung dadurch bis zu 2× diesen Timeout pro betroffener
  Datei blockieren (Haupt-URL + Fallback-URL), bevor die Aufgabe als fehlgeschlagen galt
  und übersprungen wurde - wirkte wie ein Hänger, der "nicht aufgibt". Embedding-Anfragen
  haben jetzt ihren eigenen, deutlich kürzeren Timeout (30s) unabhängig von der
  Chat-Einstellung, da eine Embedding-Antwort so gut wie immer in wenigen Sekunden kommt.

### Neu (Globaler IP-Testmodus: gesamte Website unkompliziert nur für dich freischalten)
- „Erlaubte IPs" (Einstellungen → Zugriff) war rein kosmetisch: steuerte nur, ob das Widget
  ins Markup injiziert wird, nicht aber, ob der API-Endpunkt selbst direkt aufrufbar war -
  wer die URL kannte, kam auch mit falscher IP an Antworten. Jetzt serverseitig
  durchgesetzt, für Chat UND Suche.
- Zusätzlich hebt eine zugelassene IP jetzt die "Sichtbar für"-Einschränkung einzelner
  Profile auf (Domain-/Sprachfilterung bleibt unangetastet) - damit lässt sich die gesamte
  Website mit einem einzigen globalen Feld in einen Testmodus versetzen, in dem nur du (per
  IP, ganz ohne Login) alle Profile inkl. bewusst auf "nur Redakteure/Admins" beschränkter
  siehst, während alle anderen Besucher komplett ausgesperrt bleiben.
- Das Feld zeigt jetzt direkt die eigene aktuelle IP mit Kopieren-Button und einen Live-
  Status, ob diese IP bereits in der Liste steht - kein externes "Wie lautet meine IP"-Tool
  mehr nötig.

### Behoben (Sitemap-Test: irreführende Fehlermeldung bei HTML statt XML)
- Der "Sitemaps prüfen"-Button auf der Indexierungs-Seite zeigte bei einer falschen URL
  (z.B. die Domain-Startseite statt der eigentlichen sitemap.xml) nur die rohe, wenig
  hilfreiche libxml-Fehlermeldung "String could not be parsed as XML" an. Erkennt diesen
  häufigsten Fall jetzt und gibt stattdessen einen konkreten Hinweis, dass die URL wohl auf
  eine normale HTML-Seite statt auf die Sitemap-Datei zeigt.

### Behoben (Zwei stille Regressions aus der „globale Einstellung übernehmen"-Umstellung)
- Die neue nullable Handhabung von `addressing_mode`/`personalization_mode` je Profil (siehe
  vorheriger Eintrag) hatte zwei Stellen übersehen, die den alten, jetzt falschen
  Leer-String-Vergleich noch verwendeten (`boot.php`, „Profil testen"-Vorschau in
  `pages/profiles.php`) - dadurch griff die globale Fallback-Einstellung dort nicht mehr,
  sondern es wurde stillschweigend der (jetzt mögliche) `null`-Wert direkt weitergereicht.
  Beide korrigiert.
- `mb_convert_encoding(..., 'HTML-ENTITIES', ...)` (seit PHP 8.2 deprecated, erzeugt bei
  jedem HTTP-Crawler-Indexierungslauf eine Deprecation-Meldung) durch dasselbe
  XML-Prolog-Präfix-Muster ersetzt, das an anderer Stelle im Code bereits verwendet wird.

## [1.1.0] - 2026-09-03

### Geändert (Admin-Oberfläche: verständlicheres Wording, klarer wo eine Einstellung wirkt)
- Durchgang über alle Einstellungs-/Profil-Seiten mit Fokus auf zwei Fragen: ist die
  Formulierung auch für Admins ohne KI-Hintergrund verständlich, und ist immer klar, ob eine
  Einstellung global oder nur für ein einzelnes Profil gilt? Ergebnis u.a.:
  - „Eigene YForm-Quellen"/„Eigene PDF-Dokumente" heißen nicht mehr unbedingt „exklusiv" -
    ist dieselbe Tabelle/Kategorie zusätzlich global aktiviert, wird sie doppelt indexiert;
    das steht jetzt im Hinweistext, plus eine Live-Warnung im Profil-Formular bei YForm.
  - „Gemeinsamer Wissens-Pool" verweist jetzt konsistent auf „alles unter Einstellungen →
    Indexierung" statt einer unvollständigen Aufzählung einzelner Quellen.
  - Die globalen Darstellung-Einstellungen erwähnen jetzt, dass ein Profil sie über seine
    eigene „Theme"-Box vollständig überschreiben kann.
  - Global „Indexierungs-Quelle" (Struktur/Sitemap) und die profil-eigene „Eigene Sitemap"
    sind zwei komplett getrennte Mechanismen mit ähnlichem Namen - jetzt an beiden Stellen
    als solche benannt statt stillschweigend nebeneinander zu existieren.
  - YForm-Mappings heißen im UI jetzt „Mapping" statt „Profil" - kollidierte vorher
    begrifflich mit den eigentlichen (Chat-Sichtbarkeits-)Profilen.
  - Fehlende Hinweistexte ergänzt (Backend-Chat-Schalter, Reset-Countdown, Verlauf
    kopieren/downloaden, Trigger-Reichweite), veraltete/irreführende korrigiert (der
    „Scope-Switch"-Schalter steuert den Chat, nicht die „Suche", wie der alte Text
    behauptete), interne Implementierungsdetails aus Admin-Texten entfernt (`TODO.md`,
    `assets/i18n/`-Pfad).
  - `addressing_mode`/`personalization_mode` je Profil waren NOT-NULL-Spalten mit
    erzwungenem Default - „leer = globale Einstellung übernehmen" stand zwar im
    Beschreibungstext der globalen Seite, konnte aber nie eintreten, da jedes Profil
    immer einen eigenen konkreten Wert hatte. Beide Felder sind jetzt echt nullable mit
    einer „Globale Einstellung übernehmen"-Option, die auch den aktuell aktiven globalen
    Wert anzeigt.

### Behoben (Backend-Chat: Scope-Wechsel setzte das Gespräch nicht zurück)
- Nach dem Umschalten des Backend-Chat-Fensters (z.B. von "Developer" auf ein Profil wie
  "Standard") beantwortete die KI weiterhin erkennbar im Kontext/Ton des vorherigen Scopes -
  Ursache: der mitgeschickte Konversationsverlauf enthielt weiterhin die letzten Nachrichten
  UNABHÄNGIG vom gerade gewählten Scope, obwohl Scope/Profil-ID für die nächste Anfrage
  serverseitig längst korrekt gewechselt waren. Ein Scope-Wechsel setzt das sichtbare
  Gespräch jetzt zurück (wie der "Verlauf löschen"-Button) - ein Wechsel ist ein Wechsel zu
  einem anderen Assistenten/Wissens-Scope, kein Fortsetzen desselben Gesprächs.

### Behoben (Doppelte/falsch aussehende Quellen-Links, generische Anfragen ohne echte Treffer)
- Dieselbe Seite konnte als 2-4 fast identisch aussehende "Quellen"-Links hintereinander
  erscheinen - z.B. weil eine Seite sowohl über "Struktur" als auch über "Sitemap"
  indexiert wurde (falls beide Indexierungs-Quellen aktiv sind) und dadurch mit leicht
  unterschiedlicher URL zweimal im Index landet, oder weil eine wortgleiche Seite
  tatsächlich mehrfach existiert (z.B. eine jährlich wiederholte Ankündigung). Links werden
  jetzt zusätzlich per normalisierter URL (ohne Query-String/Fragment/trailing Slash) UND
  per Titel entdoppelt - und dieselbe Titel-Dedupe greift jetzt auch schon bei der
  RAG-Kontext-Auswahl selbst, nicht erst bei der Anzeige: mehrere fast identische Seiten
  belegten vorher oft ALLE Top-Treffer-Plätze und ließen dadurch keinen Platz mehr für
  inhaltlich verschiedene, eigentlich passendere Quellen.
- Bei kurzen, generischen Anfragen mit nur einem aussagekräftigen Wort (z.B. "Referenzen",
  "Team", "Leistungen") konnte die reine Vektorsuche mehrere thematisch zufällige Seiten in
  die Top-Treffer heben, obwohl viele tatsächlich passende Seiten das gesuchte Wort wörtlich
  im URL-Pfad tragen (z.B. "/agentur/referenzen/..."). Ein neuer Stichwort-Fallback prüft
  jetzt zusätzlich Titel/Inhalt/URL auf einen direkten Treffer des Suchworts und darf damit
  gezielt schwache Vektor-Treffer (Ähnlichkeit < 0.6) ersetzen - ein bereits stark
  passender Vektor-Treffer bleibt unangetastet. Live gegen echte KLXM-Referenzseiten
  getestet: "Kannst du mir Referenzen nennen" lieferte vorher dieselbe thematisch falsche
  Seite drei- bis vierfach, jetzt drei tatsächlich unterschiedliche, korrekt beschriebene
  Projekt-Referenzen.

### Behoben (Trigger-Zusatzinhalt: Markdown wurde nicht gerendert, KI widersprach dem Trigger)
- Ein per Trigger angehängter Zusatzinhalt (z. B. eine Öffnungszeiten-Tabelle) landete als
  unformatierter Markdown-Rohtext in der Antwort (Überschriften/Tabellen-Syntax wie `##`
  und `| Tag | Öffnungszeiten |` blieb sichtbar statt zu rendern). Ursache:
  `removeUnwantedGreetingPrefix()` fasste mit `\s{2,}` versehentlich nicht nur doppelte
  Leerzeichen, sondern auch Leerzeilen im GESAMTEN Antworttext zusammen - der Trigger-Inhalt
  verschmolz dadurch mit dem Fließtext zu einem einzigen Absatz, bevor Parsedown ihn sah, und
  konnte weder Überschrift noch Tabelle mehr als eigenen Block erkennen. Betrifft jetzt nur
  noch echten horizontalen Whitespace (Leerzeichen/Tabs), Zeilenumbrüche/Absätze bleiben erhalten.
- Zusätzlich behauptete die KI teils fälschlich, ihr fehle die Information, obwohl direkt im
  Anschluss der exakt passende Trigger-Inhalt angezeigt wurde - die KI wusste beim Formulieren
  ihrer eigenen Antwort schlicht nichts vom gleich folgenden Trigger-Block. Ein Trigger-Treffer
  wird jetzt VOR der Antwortgenerierung geprüft: die KI bekommt einen Prompt-Hinweis, dass
  gleich zusätzlicher Inhalt folgt (ohne dessen Inhalt vorwegzunehmen oder zu wiederholen), und
  ein Trigger-Treffer verhindert außerdem den separaten "dazu liegen mir keine Informationen
  vor"-Fallback, der sonst bei dünnem RAG-Kontext zur eigentlichen KI-Antwort gar nicht erst
  gekommen wäre.

### Behoben (Interner Renderer indexierte Editor-/Bearbeitungsansicht statt echter Frontend-Ausgabe)
- Bei der Indexierungs-Methode „Intern (REDAXO-Renderer)" landete teils sichtbare
  Editor-Oberfläche im Suchindex (z. B. Grid-/Container-Bearbeitungshinweise wie
  „Containereinstellungen", „Abstand oben", Modul-interne Dev-/Zurück-Links) statt
  der tatsächlichen Frontend-Ausgabe. Ursache: die Simulation "Artikel wie im
  Frontend rendern" setzte die REDAXO-Property `is_backend`, tatsächlich prüfen
  `rex::isBackend()`/`isFrontend()` aber die Property `redaxo` - die Simulation war
  dadurch wirkungslos, `rex::isBackend()` blieb während des gesamten internen
  Renderings `true`. Module, die abhängig davon zwischen echter Frontend-Ausgabe
  und einer Editor-Vorschau unterscheiden, lieferten entsprechend ihre
  Editor-Variante, die dann mit indexiert und in Suchergebnissen sichtbar wurde.

### Behoben (Domain-/Sprach-eingeschränkte Profile zeigten Chat/Suche nie oder falsch an)
- Sobald ein Profil im „Anzeigebereich" auf bestimmte Domains eingeschränkt
  wurde, verschwand das Chat-/Such-Widget komplett - auch auf der
  eigentlich passenden Domain. Ursache: die Domain-/Profil-Auflösung lief
  bislang auf oberster `boot.php`-Ebene, zu einem Zeitpunkt, an dem
  yrewrite seine eigene Domain-/Pfad-Zuordnung noch nicht fertig
  aufgebaut hat - `rex_yrewrite::getCurrentDomain()` lieferte dort
  zuverlässig `null`, obwohl dieselbe Anfrage beim tatsächlichen
  Seiten-Rendering korrekt auflöst. Die komplette Profil-Auflösung
  (inkl. `rex_clang::getCurrentId()`) läuft jetzt innerhalb der
  `OUTPUT_FILTER`-Callback, zum richtigen, späten Zeitpunkt - behebt
  daneben auch, dass eine Sprach-Einschränkung das Icon fälschlich auf
  allen Sprachen zeigte (derselbe Ursache: `rex_clang::getCurrentId()`
  wurde ebenfalls zu früh gelesen).

### Geändert (Backend-Chat unabhängig von Profilen, Profile sind reine Frontend-Sache)
- Der Backend-Chat (Developer-Chat) hing bisher indirekt an einem Profil mit
  Kontext „Backend"/„Beides" - fehlte ein solches Profil, blieb der Chat
  trotz aktiviertem globalem Schalter unsichtbar. Er hängt jetzt an gar
  keinem Profil mehr: einzige Kriterien sind der globale Schalter
  „Backend-Chat aktivieren" und die Berechtigung des Nutzers.
- Der Umschalter im Backend-Chat-Fenster bietet jetzt neben „Developer" auch
  jedes aktive Profil einzeln zur Auswahl an - ein Wechsel simuliert live,
  wie der Chat für dieses Profil im Frontend antworten würde, ohne die
  Backend-Seite zu verlassen.
- Profile sind damit ausschließlich ein Frontend-Konzept: das „Kontext"-
  Auswahlfeld (Frontend/Backend/Beides) ist aus dem Profil-Formular
  entfernt, neue Profile gelten immer fürs Frontend. Bereits bestehende
  Profile mit Kontext „Backend" werden dadurch inert (galten für den
  Backend-Chat ohnehin nicht mehr) - beim nächsten Speichern wird der
  Kontext automatisch auf „Frontend" migriert.

### Geändert (YForm-Mapping: URL-Profil als Auswahl statt Freitext)
- „URL-Profil" (der Namespace fürs url-Addon) ist jetzt ein Auswahlfeld mit
  den tatsächlich registrierten URL-Profilen statt eines Freitextfelds -
  verhindert Tippfehler und zeigt nur existierende Profile. Fällt auf ein
  Textfeld zurück, wenn das url-Addon nicht verfügbar ist oder keine
  Profile registriert hat, damit ein bereits gespeicherter Namespace nicht
  einfach verschwindet.

### Geändert (YForm-Mapping: URL-Modus zeigt nur das passende Feld)
- „URL-Feld", „URL-Profil" und „URL-Template" standen bisher immer alle drei
  gleichzeitig nebeneinander, obwohl sich der gewählte „URL-Modus" für genau
  eines davon entscheidet - die anderen beiden hatten dann schlicht keine
  Wirkung, ohne dass das erkennbar war. Zeigt jetzt nur noch das zum
  gewählten Modus passende Feld, sowohl bei bestehenden Mappings als auch
  bei neu hinzugefügten.

### Geändert (Profil-Formular logisch gruppiert)
- Die Abschnitte auf der Profil-Bearbeitungsseite heißen jetzt „Allgemeine
  Einstellungen" → „Sichtbarkeit" (Kontext, Sichtbar für, Anzeigebereich/
  Domain/Sprache, Chat/Suche automatisch einbinden) → „Individuelle
  Einstellungen (Indizierung)" (Wissens-Scope, eigene Quellen) →
  „Verhalten" → „Theme" - in genau der Reihenfolge, in der man ein Profil
  sinnvollerweise durcharbeitet. Vorher hieß der Sichtbarkeits-Block
  „Kontext & Sichtbarkeit", was leicht überlesen wurde.
- Das Feld „Zielgruppe" heißt jetzt „Anzeigebereich (Domain/Sprache)" -
  „Zielgruppe" klang nach Personen/Rollen (das steuert bereits „Sichtbar
  für" darüber), gemeint ist aber, auf welcher Domain/in welcher Sprache
  das Profil erscheint.
- Domain-/Sprach-Auswahl unter „Anzeigebereich" verschwindet nicht mehr
  ersatzlos, wenn nur eine Domain/Sprache konfiguriert ist (vorher: das
  Auswahlfeld wurde komplett weggelassen, obwohl die Optionen „Nur
  bestimmte Domains"/"-Sprachen" weiterhin wählbar blieben) - stattdessen
  erscheint ein Hinweis, warum es dort nichts auszuwählen gibt.

### Neu (Systemcheck, zusammengeführt mit den Statistiken)
- Die Statistik-Seite (jetzt Landing-Page von AI Chat) zeigt oben einen
  Systemcheck: PHP-/REDAXO-Version, PDF-Textextraktion (pdftotext vs.
  smalot/pdfparser-Fallback), Voraussetzungen für die Hintergrund-Indexierung,
  natives Vektor-Retrieval, KI-Provider-Konfiguration - vorher verstreut
  (Vektor-Status nur auf der Indexierung-Seite) oder nur als Fehlermeldung im
  Versuch sichtbar. `SystemCheckService` bündelt das an einer Stelle;
  `resolveBinary()`/`backgroundRunnerDiagnostics()` (vorher privat in
  `Api\ChatIndex`) und `PdfTextExtractor`s eigene Binary-Suche nutzen jetzt
  dieselbe Stelle statt eigener Kopien.
- Die Nutzungsstatistik kennt jetzt Profile: neuer `profile_id`-Spalte an
  `rex_ai_chat_stats`, ein Profil-Filter neben dem Zeitraum-Filter, und eine
  "Anfragen je Profil"-Übersicht. Zeilen von vor diesem Feld (und Anfragen
  ganz ohne aufgelöstes Profil) laufen unter "Kein Profil (global)".

## [1.0.1] - 2026-09-03

### Verbessert (Such-Widget: KI-Antwortbox)
- Eine lange KI-Antwort im Such-Overlay schiebt nicht mehr die eigentliche
  Trefferliste weit nach unten - die Antwortbox begrenzt ihre Höhe jetzt und
  bekommt bei Bedarf einen „Mehr anzeigen"/„Weniger anzeigen"-Umschalter. Ein
  eventueller Quellen-Block („Links:") bleibt dabei immer vollständig
  sichtbar, auch eingeklappt.
- Code-Beispiele (Inline und Blöcke) in der Antwort sehen jetzt unabhängig
  vom Website-Theme konsistent aus - das Such-Overlay läuft anders als
  `<ai-chat>` (Shadow DOM) im Light DOM der Seite und übernahm bisher
  ungewollt globale `<code>`-Styles der jeweiligen Website (z.B. UIkits
  pinkes Standard-Inline-Code), inklusive Abschneiden bei langen einzeiligen
  Code-Schnipseln ohne Zeilenumbruch.

### Behoben (Fataler Fehler im Frontend bei bestimmten yrewrite-Multidomain-Konstellationen)
- `rex_yrewrite::getCurrentDomain()` wirft in manchen Multidomain-/
  Mehrsprachen-Setups (z.B. eine Sprache offline, siehe [#1](https://github.com/FriendsOfREDAXO/ai_chat/issues/1))
  selbst einen fatalen Fehler statt wie dokumentiert `null` zurückzugeben
  (`Call to a member function getId() on null` in yrewrite intern) - legte
  bislang das komplette Frontend lahm, sobald ai_chat aktiv war. Alle vier
  Aufrufstellen laufen jetzt über die neue, defensive
  `YrewriteDomainResolver::getCurrentDomain()` (fängt das ab und liefert
  `null`), inklusive eines zusätzlichen fehlenden Null-Checks bei der
  PDF-URL-Auflösung (`MediaPoolContentProvider`/`IndexerService`).

### Neu (Globale PDF-Indexierung, gleichwertig zur profil-eigenen)
- Die Indexierung-Einstellungen (AI Chat → Indexierung & Chunking) haben jetzt
  dieselben zwei PDF-Auswahlfelder wie ein Profil („PDF-Dokumente" +
  „PDFs aus Medienpool-Kategorien") – global ausgewählte PDFs tragen zum
  gemeinsamen Wissens-Pool bei, exakt nach demselben Muster wie die
  profil-exklusive Auswahl. Kein separates Aktivieren-Häkchen in der
  „Content-Provider aktivieren"-Liste – wie beim Profil ist die Auswahl selbst
  die Aktivierung.
- `MediaPoolContentProvider` ist damit kein rein profil-exklusiver Provider mehr;
  `renderSourceFields()` rendert die beiden Felder für globale Einstellungen UND
  Profile aus derselben Stelle, damit beide nie auseinanderlaufen.

### Geändert (Profile ersetzen die globalen Sichtbarkeits-Schalter vollständig)
- Sobald mindestens ein aktives, frontend-fähiges Profil existiert, sind die globalen
  Schalter „Chat im Frontend anzeigen"/„Suche im Frontend aktivieren" komplett
  wirkungslos und in den Einstellungen entsprechend deaktiviert – jedes Profil
  entscheidet dann eigenständig über die Felder „Chat/Suche automatisch einbinden"
  (Standard dort: aktiv, kein „globale Einstellung entscheidet" mehr). Ohne aktive
  Profile bleiben die globalen Schalter die alleinige Instanz – Profile sind
  weiterhin komplett optional. Vorher konnten globaler Schalter und Profil
  gleichzeitig um dieselbe Entscheidung „konkurrieren", was zu widersprüchlichem
  Verhalten führte (Chat/Suche mal an, mal aus, je nachdem welche Einstellung
  zuletzt geändert wurde).
- `ProfileTheme::resolve*()`/`buildInlineStyle()` akzeptieren jetzt ein nullbares
  Profil und fallen dann direkt auf die globale Darstellung zurück – nötig, damit
  der Fall „keine aktiven Profile" (reiner globaler Fallback) nicht auf einen
  Methodenaufruf mit `null` crasht.

### Neu (PDF-/Dokument-Indexierung aus dem Medienpool)
- Profile können jetzt PDF-Dateien aus dem Medienpool indexieren – einzeln ausgewählte
  Dokumente und/oder ganze Medienpool-Kategorien, exklusiv für das jeweilige Profil (kein
  globaler „alle PDFs indexieren"-Modus). Neue Felder „Eigene PDF-Dokumente" (nativer
  REDAXO-Mehrfach-Datei-Picker) und „PDFs aus Medienpool-Kategorien" auf der Profil-Seite.
- Textextraktion nutzt, falls auf dem Server vorhanden, `pdftotext` (poppler-utils) für
  bessere Qualität bei komplexem Layout, sonst die reine PHP-Bibliothek
  `smalot/pdfparser` (kein Systembinary nötig, läuft auf jedem Hosting). Kein OCR –
  gescannte/reine Bild-PDFs liefern leeren Text und werden übersprungen statt einen
  Fehler auszulösen.
- Extrahierter Text wird vor der Weiterverarbeitung auf gültiges UTF-8 bereinigt –
  manche PDFs (v.a. mit ungewöhnlichen internen Font-/Encoding-Deklarationen) liefern
  sonst Byte-Sequenzen, an denen der anschließende Embedding-Request scheitern würde.
- Die Live-Suche (`search()`) berücksichtigt jetzt auch exklusiv indexierte Quellen
  (PDFs UND YForm-Tabellen) unabhängig davon, ob der jeweilige Content-Provider zusätzlich
  für den globalen Shared Pool aktiviert ist – vorher tauchten profil-exklusive Inhalte im
  Chat/RAG-Pfad zwar auf, waren aber für die Live-Suche unsichtbar.

### Neu (Trefferhervorhebung in der Suche)
- Suchergebnis-Snippets markieren den gefundenen Suchbegriff jetzt optisch
  (`<mark>`-Hervorhebung mit der Akzentfarbe des Widgets). Serverseitig sauber
  HTML-escaped, damit ein Suchbegriff mit `<`/`&`/Anführungszeichen die Darstellung nicht
  durcheinanderbringen oder Markup einschleusen kann.

### Geändert (Inkrementelles Refresh läuft jetzt immer im Hintergrund)
- „Refresh (inkrementell)" lief bisher als einzelner, synchroner Request im Browser-Tab
  (Timeout-Risiko bei größerem Index, Tab musste offen bleiben). Läuft jetzt wie „Im
  Hintergrund indexieren" über den entkoppelten Server-Worker (`Api\ReindexWorker`, jetzt
  mit `mode=incremental`) – Fortschritt kommt live aus `IndexRunStore`, der Tab kann
  geschlossen werden. Braucht dieselbe Server-Voraussetzung (shell_exec + curl/wget) wie
  der Hintergrund-Button und ist entsprechend deaktiviert, wenn die nicht verfügbar ist.

### Behoben (Live-Suche ignorierte den Profil-Scope komplett)
- **`search()` filterte nie nach `profile_id`**: Ein Profil mit „Nicht nutzen" bei
  „Gemeinsamer Wissens-Pool" (isolierter Wissensstand) bekam in der Live-Suche trotzdem
  Treffer aus dem Shared Pool und aus fremden Profilen zu sehen, sobald der Quellentyp
  passte – der Chat/RAG-Pfad (`findSimilarContent()`) hatte diese Profil-Grenze schon
  immer, die Suche kannte sie gar nicht. Jetzt dieselbe Regel: `use_shared_scope=1` sieht
  Shared Pool + eigene Quellen, `use_shared_scope=0` ausschließlich die eigenen.

### Behoben (Profil-Erzwingung von Chat/Suche wurde serverseitig ignoriert)
- **`resolveFrontendAccessDenial()` prüfte nur die globalen Schalter**
  („Chat im Frontend anzeigen"/„Suche im Frontend aktivieren"), nicht das
  Tri-State-Override eines Profils (`chat_enabled`/`search_enabled`). Waren
  beide globalen Schalter aus, blockte dieser Endpoint jede Anfrage mit einer
  leeren, fehlerfreien Antwort – selbst wenn `boot.php` das Widget wegen
  eines Profil-Force-On längst korrekt angezeigt hatte. Prüft jetzt dieselbe
  Tri-State-Logik wie `boot.php`.
- `pages/settings.access.php` warnt jetzt direkt neben den globalen
  Sichtbarkeits-Schaltern, wenn ein oder mehrere aktive Profile Chat/Suche
  per Tri-State erzwingen, samt Link zur Profil-Seite.

### Behoben (Markdown-Formatierung von KI-Antworten ging teilweise verloren)
- Mehrzeilige Code-Beispiele kamen vom Modell gelegentlich als einzelne
  Inline-Backticks statt eines Codeblocks zurück, und Listen direkt nach
  einem Absatz ohne Leerzeile wurden nicht als Liste erkannt (CommonMark/
  Parsedown-Regel) – beides ließ die Antwort als ein einziger,
  unformatierter Absatz erscheinen. System-Prompt aller vier Provider weist
  das Modell jetzt explizit auf saubere Markdown-Formatierung hin
  (`PromptBuilder::markdownFormattingInstruction()`); zusätzlich fügt
  `normalizeAnswerMarkdown()` als Sicherheitsnetz eine Leerzeile vor
  Listenzeilen ein, die direkt auf Fließtext folgen.

## [1.0.0] - 2026-09-02

Kompletter Neuanfang: Das Addon heißt jetzt `ai_chat` (vormals `klxmchat`/„KLXM
Chat & Search"), ist als FriendsOfREDAXO-AddOn aufgesetzt und beginnt seine
Versionierung bei 1.0.0. Keine automatische Migration bestehender
`klxmchat`-Installationen (Daten/Config bleiben unangetastet, `ai_chat` startet
leer) – siehe README für Details.

### Rebrand
- Verzeichnis, PHP-Namespace (`FriendsOfRedaxo\AiChat`), DB-Tabellen
  (`ai_chat_*`), Config-Namespace, Permission (`ai_chat[...]`),
  `rex-api-call`-Endpunktnamen (`ai_chat_query`/`_index`/`_test`/
  `_cloudflare_models`/`_reindex_worker`), Custom-Element-Tags
  (`<ai-chat>`/`<ai-search>`) und Asset-Dateinamen einheitlich umbenannt.
- Tote Legacy-Widget-Dateien entfernt (`klxm-chat.js`, `-v2.js`, `-v3.js`,
  `-bundle.js` – geladen wurde ohnehin nur die vormalige `-v4.js`, jetzt
  `ai-chat.js`).
- Umstellung auf MIT-Lizenz (vormals kommerziell lizenziert).

### Profile statt globaler Konfiguration
- **Mehrere Chat-Profile statt einer globalen Konfiguration**: Jedes Profil
  hat eigenen Kontext (Frontend/Backend/Beides), eigene Sichtbarkeit
  (Besucher/Redakteure/Admins), eigene Zielgruppe (alle/bestimmte
  Domains/bestimmte Sprachen), eigenen Prompt, eigene Begrüßung/Anrede/
  Personalisierung/Reset-Countdown/Verlauf-kopieren-Einstellung sowie eine
  eigene Oberflächen-Sprache (`ui_language`) und optionale KI-Antwortsprache.
  Ein Default-Profil wird bei der Installation automatisch angelegt, sodass
  ein frischer Install ohne weitere Konfiguration sofort einen
  funktionierenden Chat zeigt.
- **Wissens-Scope pro Profil**: Die globale Indexierung bleibt als „Shared
  Pool" bestehen. Ein Profil sieht diesen Pool zusätzlich zu seinen eigenen
  Quellen, wenn „Nutze Shared Scope" aktiviert ist – oder ausschließlich
  seine eigenen, exklusiv zugeordneten Inhalte, wenn nicht. Profile können
  eigene Quellen indexieren: eine Auswahl bestimmter YForm-Mappings, eine
  eigene Sitemap (in benannte Bereiche wie „Allgemein"/„News" gruppierbar),
  oder ein Struktur-Mountpoint.
- **Eigener Prompt pro Profil**, providerübergreifend – wirkt nur im
  Frontend-Zweig; der feste Developer-Systemprompt bleibt unverändert.
- **„Profil testen"**: Jede Profil-Bearbeitungsseite zeigt ein echtes,
  sticky positioniertes `<ai-chat>`-Widget fest gebunden an genau dieses
  Profil – testet den gespeicherten Stand inkl. Prompt, Anrede,
  Wissens-Scope und Theme.
- **Theme je Profil**: Akzentfarbe, weitere Farben, Eckenradius, Position
  und Avatar überschreiben die globale Darstellung nur für dieses Profil –
  leer gelassen gilt die globale Einstellung. Live-Vorschau beim Tippen,
  „Theme zurücksetzen"-Button.
- **Chat/Suche je Profil erzwingbar**, unabhängig von der globalen
  Einstellung für andere Profile.
- **Backend-Seite „Profile"** zum Anlegen/Bearbeiten/Löschen – interaktiv
  (kontext-/zielgruppe-/quelle-abhängige Felder blenden sich automatisch
  ein/aus) mit Info-Panel und Tooltips. Warnt vor Prioritäts-Kollisionen
  zwischen zwei Profilen mit gleicher Priorität.
- `boot.php` löst pro Aufruf EIN Profil auf (`ProfileResolver`) und steuert
  damit Chat UND Suche im Frontend gemeinsam.
- Serverseitige Zugriffskontrolle: Die vom Client mitgeschickte `profile_id`
  wird bei echten Frontend-Anfragen ignoriert und serverseitig neu
  zugeordnet – nur Backend-Nutzer dürfen eines explizit wählen (für „Profil
  testen" und den Backend-Chat).
- Optionaler Sprachfilter je YForm-Mapping (Sprach-Spalte + clang-IDs).

### Suche
- Eigener „Suchen"-Button neben der Live-Suche mit Debounce.
- Trefferliste erscheint sofort; eine optionale KI-Zusammenstellung über
  mehrere Bereiche lädt asynchron nach (eigene Beschriftung „Überblick über
  die Bereiche" und eigene Gestaltung, damit sie nicht mit einer direkten
  Antwort verwechselt wird).
- Benannte Sitemap-Bereiche erscheinen als eigene Facette neben den
  bestehenden Typ-Filtern.
- Das Suchfenster schließt sich nicht mehr versehentlich, wenn eine
  Text-Selektion per Maus-Drag über den Rand des Suchfelds hinausgeht.

### Übersetzbare Widget-Oberfläche
- Chat- und Such-Widget sind übersetzbar (Buttons, Platzhalter,
  Statusmeldungen – nicht die KI-Antwort selbst): JSON-Sprachdateien
  (`assets/i18n/de.json`, `en.json`), PHP-Loader (`WidgetTranslator`, mit
  Extension Point `AI_CHAT_WIDGET_TRANSLATIONS`) sowie ein gemeinsamer,
  minimaler JS-Loader für Chat und Suche (`assets/ai-i18n.js`). Gesteuert
  über das `ui-language`-Feld des Profils. Neue Sprache = neue JSON-Datei,
  kein Code nötig; fehlende Schlüssel fallen auf Deutsch zurück.

### Natives Vektor-Retrieval
- MariaDB `VECTOR`/`VECTOR INDEX` wird automatisch genutzt, wenn verfügbar
  (ab 11.7 Preview/11.8 GA) – deutlich schnellere Ähnlichkeitssuche als die
  PHP-Brute-Force-Berechnung bei großem Index. Capability-detected
  (`VectorCapability`, „Neu prüfen"-Button auf AI Chat → Indexierung), die
  PHP-Berechnung bleibt als Fallback für ältere MariaDB/MySQL-Versionen
  erhalten – beide stehen hinter einem gemeinsamen
  `RetrievalStrategyInterface`. Spalte/Index werden beim vollen Reindex
  automatisch angelegt/aktualisiert, inkl. sauberem Neuaufbau bei
  gewechseltem Embedding-Modell.

### Indexierung
- Chunk-Titel tragen keinen technischen „(N)"-Suffix mehr in
  Suchergebnissen.
- Ausschluss-Selektoren (`index_http_exclude_selectors`) werden jetzt auch
  für Sitemap-URLs ausgewertet, nicht nur für den HTTP-Crawl-Zweig; mehrere
  kommagetrennte Haupt-Selektoren mit mehreren Treffern statt nur des
  ersten Treffers des ersten Selektors.

### KI-Provider
- **Neuer Provider: `ai_platform`-Addon** – nutzt dessen zentrale,
  Symfony-AI-basierte Profilverwaltung (OpenAI/Anthropic/Google/
  Ollama/OpenAI-kompatibel) statt eigener API-Keys; eigene Profile für Text
  und Embedding je Zweck auswählbar. Nur sichtbar, wenn `ai_platform`
  installiert ist. `PromptBuilder` als gemeinsame Stelle für System-/
  User-Prompt-Aufbau eingeführt, damit der neue Provider die bestehende
  Logik von Gemini/OpenAI-kompatibel/Cloudflare nicht dupliziert.
- **Antwortsprache je Profil**: lässt die KI unabhängig von der
  Oberflächen-Sprache in einer festgelegten Sprache antworten,
  providerübergreifend – wirkt auch auf vorgeschlagene Folgefragen.

### Missbrauchsschutz
- Prompt-Injection-/Jailbreak-Erkennung (z.B. „ignoriere alle vorherigen
  Anweisungen") – wirkt für Chat UND Suche.
- Wiederholte Angriffsversuche beenden die Konversation ab dem dritten
  erkannten Versuch freundlich statt sie hart abzublocken.
- Erkannte Angriffe lösen eine temporäre IP-Sperre über das
  `upkeep`-Addon aus, sofern installiert.
- Sinnlose Such-/Chat-Anfragen (leer, nur Sonderzeichen, zu kurz,
  Kauderwelsch) werden erkannt, bevor sie die KI belasten.

### Robustere API-Responses
- Absicherung gegen von PHP-Warnings korrumpierte JSON-/SSE-Antworten
  (`ob_start()` + Buffer-Clean vor jedem Response) in einem gemeinsamen
  `JsonResponseTrait` konsolidiert und auf alle API-Endpunkte angewendet.
- Ein neuer Sende-Vorgang oder ein Reset bricht eine noch laufende (auch
  streamende) Antwort sauber per `AbortController` ab; ein einzelner,
  echter Netzwerkfehler löst automatisch einen einmaligen Retry aus, bevor
  die „Verbindungsproblem"-Meldung erscheint.
- Alle API-Endpunkte geben die PHP-Session sofort frei, damit
  nebeneinanderlaufende, session-gebundene Anfragen sich nicht gegenseitig
  blockieren.

### Erweiterbarkeit
Konsequent auf austauschbare Bausteine übertragen statt hart verdrahtet –
jeweils Interface/Registry + Extension Point:
- `AI_CHAT_CONTENT_PROVIDERS` – eigene Content-Provider registrieren.
- `AI_CHAT_REGISTER_PROVIDERS` – eigene KI-Provider registrieren.
- `AI_CHAT_PROFILE_CANDIDATES` – Profil-Auswahl vor der finalen Entscheidung
  filtern/umsortieren.
- `AI_CHAT_WIDGET_TRANSLATIONS` – zusätzliche Sprachen/Schlüssel für die
  Widget-Oberfläche nachliefern.

## Ältere Historie

Vor `ai_chat` hieß dieses Addon `klxmchat`/„KLXM Chat & Search" und wurde
unter eigenem Namen bei [KLXM/klxmchat](https://github.com/KLXM/klxmchat)
entwickelt. Diese Historie ist im alten Repository weiterhin einsehbar und
wird hier nicht fortgeführt.
