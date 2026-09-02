# AI Chat & Search für REDAXO

Ein FriendsOfREDAXO-AddOn für KI-gestützte Suche und Chat: Inhalte aus Artikeln, Addon-Dokumentation, GitHub-Repos, Sitemaps und weiteren Quellen werden indexiert, per Vektorsuche durchsucht und wahlweise per KI zu einer Antwort verarbeitet. Mehrere Profile erlauben unterschiedliche Wissensstände, Zielgruppen, Prompts und Optiken nebeneinander – von einer einzelnen globalen Konfiguration bis zu mehreren parallel laufenden, komplett unabhängigen Bereichen.

> Vormals `klxmchat`/„KLXM Chat & Search" – ab Version 1.0.0 als `ai_chat` neu aufgesetzt (neuer Addon-Key, neuer Namespace `FriendsOfRedaxo\AiChat`, keine automatische Migration alter Installationen).

## Einleitung

Kern des Addons ist eine klassische RAG-Pipeline (Retrieval-Augmented Generation): Inhalte werden in Textabschnitte zerlegt, als Vektoren gespeichert und bei einer Anfrage nach semantischer Ähnlichkeit durchsucht – nicht nach exakten Wörtern. Darüber liegen zwei nutzbare Oberflächen: eine reine Trefferliste (Suche) und eine KI-generierte Antwort mit Quellenangaben (Chat). Beide teilen sich denselben Index und laufen wahlweise kombiniert oder unabhängig voneinander.

Darüber hinaus bringt das Addon ein Profil-System mit, über das sich mehrere thematisch, sprachlich oder nach Zielgruppe getrennte „Instanzen" des Chats parallel betreiben lassen – inklusive eigenem Wissensausschnitt, eigenem Prompt, eigener Anrede, eigener Antwortsprache und eigenem Erscheinungsbild je Profil. Ein einzelnes Standard-Profil reicht für die meisten Installationen völlig aus; wer mehr braucht, kann beliebig viele weitere anlegen.

## Features

- Frontend-Suche und Frontend-Chat für Website-Besucher, Backend-Developer-Chat für angemeldete REDAXO-User
- Mehrere Profile mit eigenem Wissens-Scope, eigener Zielgruppe (Rolle/Domain/Sprache), eigenem Prompt, eigener Anrede/Antwortsprache und eigenem Theme
- Google Gemini, Cloudflare Workers AI, OpenAI-kompatible Endpunkte sowie optional das FriendsOfREDAXO-Addon `ai_platform` als gemeinsame Provider-Verwaltung
- Natives Vektor-Retrieval auf MariaDB 11.7+/11.8+, mit automatischem Fallback auf PHP-seitige Berechnung auf älteren Versionen oder MySQL
- Indexierung lokaler Inhalte, GitHub-Repos, Addon-Dokumentation, Sitemaps (auch mehrere, benannt und gruppiert) und weiterer Content-Provider per Extension Point
- Missbrauchsschutz: Erkennung von Prompt-Injection/Jailbreak-Versuchen, freundliche Gesprächsbeendigung bei wiederholten Angriffen, optionale Meldung an das Intrusion-Prevention-Addon `upkeep`
- Übersetzbare Widget-Oberfläche (JSON-Sprachdateien, unabhängig von der Sprache der KI-Antworten)
- FAQ-Vorcaching für wiederkehrende Fragen
- Statistik zu Suchbegriffen, häufigen Fragen und Treffer-losen Anfragen, je Scope getrennt auswertbar

## Einzelfeatures

### Profile

Ein Profil bestimmt drei Dinge gleichzeitig: **wer** den Chat/die Suche sieht, **was** er weiß und **wie** er antwortet.

- **Sichtbarkeit**: Kontext (Frontend, Backend-Developer-Chat oder beides), Rolle (Besucher, angemeldete Redakteure, Admins) und Zielgruppe (alle, bestimmte Domains, bestimmte Sprachen, oder beides). Passen mehrere Profile auf dieselbe Anfrage, gewinnt die höhere Priorität.
- **Wissens-Scope**: Neben dem globalen „Shared Pool" (die normale Indexierung aus den Einstellungen) kann jedes Profil zusätzlich eigene Quellen mitbringen – ausgewählte YForm-Tabellen, eine eigene Sitemap oder ein Struktur-Mountpoint. Ein Profil kann den Shared Pool zusätzlich nutzen oder komplett isoliert nur seine eigenen Quellen sehen.
- **Prompt, Anrede, Antwortsprache**: Ein eigener System-Prompt ersetzt bei Bedarf die globale Einstellung. Anrede (Du/Sie/neutral/automatisch per Personalisierungsdialog) und Antwortsprache lassen sich je Profil festlegen – Letztere unabhängig von der Oberflächen-Sprache des Widgets, leer = weiterhin Deutsch.
- **Theme**: Akzentfarbe, Kopfzeile-/Chat-/Text-/Bot-Sprechblasen-Hintergrund, Eckenradius, Position und Avatar lassen sich je Profil überschreiben. Leer gelassene Felder übernehmen automatisch die global konfigurierte Optik – die Backend-Bearbeitungsseite zeigt dafür direkt die aufgelöste globale Farbe an, kein irreführendes Schwarz bei leeren Feldern.
- **Live-Vorschau**: Jede Profil-Seite zeigt ein echtes, an genau dieses Profil gebundenes Test-Widget – Theme-Änderungen erscheinen dort sofort beim Tippen, noch vor dem Speichern.

Ein Standard-Profil (alle Domains/Sprachen, alle Rollen) wird bei der Installation automatisch angelegt, sodass Chat und Suche ohne weitere Konfiguration sofort funktionieren.

### Suche

- Vektorbasierte semantische Suche über den Index, nicht auf exakte Wörter angewiesen
- Live-Suche mit kurzer Verzögerung beim Tippen, plus expliziter Suchen-Button für alle, die lieber gezielt auslösen
- Facetten-Filterung nach Quellentyp (Artikel, Sitemap-Seite, Addon-/GitHub-Doku, …) sowie nach benannten Sitemap-Gruppen (siehe unten)
- Erkennung von Fragen auch ohne Fragezeichen – bei erkannter Frage ergänzt eine KI-Antwort automatisch die Trefferliste, ohne die Trefferliste selbst zu verzögern
- Optionale, separat nachgeladene KI-Zusammenstellung über mehrere Bereiche hinweg, wenn Treffer aus mehreren benannten Sitemap-Gruppen stammen (deaktiviert per Standard, da ein zusätzlicher KI-Aufruf pro Suche)
- Datenschutz-Guard für sensible Eingaben (E-Mail, IBAN, Passwörter/PINs) sowie Schutz gegen Prompt-Injection- und Code-Injection-Versuche

### Chat

- Frontend-Chat für Besucher, Backend-Developer-Chat für angemeldete Nutzer, jeweils mit eigenem System-Prompt
- Automatisch als Bubble eingebunden oder wahlweise „ohne Bubble" an einem selbst platzierten Trigger-Element (Klasse/ID `aichat`) – klappt automatisch nach oben oder unten auf, je nach verfügbarem Platz
- Personalisierung (Du/Sie erfragen, optional mit Namen) und konfigurierbare Anrede
- Reset-Countdown mit sichtbarer Anzeige, optionales Kopieren/Herunterladen des Verlaufs
- SSE-Streaming für laufend eintreffende Antworten statt einer Wartezeit bis zum kompletten Text (siehe Server-Voraussetzungen weiter unten)
- Quellenangaben unter der Antwort, mit Schwellenwerten gegen offensichtlich unpassende Links

### Indexierung

- Artikel-/Content-Indexierung für REDAXO, Addon-Dokumentation, GitHub-Repos, Sitemaps und weitere Provider per Extension Point
- Zwei Indexierungs-Methoden für Artikel: **Intern** (Standard, schnell, nutzt REDAXOs eigenen Renderer) oder **HTTP Crawler** (ruft die Artikel-URL wie ein Browser ab – sinnvoll, wenn Cache/Proxy/Ausgabelogik den finalen Frontend-Inhalt gegenüber dem internen Renderer verändern). Für den HTTP Crawler lassen sich ein CSS-Hauptselektor (Standard `body`, mehrere durch Komma möglich) sowie Ausschluss-Selektoren (z. B. `nav, footer, .cookie-banner`) konfigurieren, um wiederkehrende Navigations-/Footer-Texte draußen zu halten.
- Inkrementelle und vollständige Neuindizierung, wahlweise als Hintergrund-Job (Button „Im Hintergrund indexieren" oder `php redaxo/bin/console ai_chat:reindex`) – läuft serverseitig weiter, auch wenn der Browser-Tab geschlossen wird
- Chunking mit konfigurierbarer Größe (Standard 1000 Zeichen) und Überlappung (Standard 200 Zeichen), damit zusammengehörige Informationen nicht mitten durchgeschnitten werden
- RAG-Kandidatenfenster (`rag_candidate_limit`): bestimmt, wie viele Chunks vor dem Ähnlichkeitsvergleich geladen werden. Bei nativem Vektor-Retrieval (siehe unten) unkritisch, da die Datenbank ohnehin über den gesamten gefilterten Index sortiert; ohne natives Vektor-Retrieval sollte der Wert größer als die Gesamtzahl indexierter Chunks sein, sonst bleiben manche Inhalte beim Vergleich unberücksichtigt. Ein Button „RAG-Kandidatenfenster automatisch optimieren" schlägt einen passenden Wert vor.
- Optionale Embedding-Kontext-Hinweise und Fokus-Regeln (Format `Label|Begriff1|Begriff2`), um bestimmten Themen zusätzliches Gewicht zu geben, sowie automatische Auswertung von JSON-LD (`Person`, `Organization`, `ContactPoint`, `LocalBusiness`) als strukturierte, eindeutige Faktenquelle – hilfreich, damit der Chat Zuständigkeiten/Ansprechpartner nicht versehentlich verwechselt oder kombiniert
- Cache-Warmup für häufig gestellte Fragen (siehe FAQ-Vorcaching)

### Natives Vektor-Retrieval

Ab MariaDB 11.7 (Preview) bzw. 11.8 (GA/LTS) nutzt das Addon automatisch die native `VECTOR`-Spalte samt `VECTOR INDEX` und `VEC_DISTANCE_COSINE()` – die Datenbank kombiniert dabei Ähnlichkeitssuche und SQL-Filterung (Profil, Sprache, aktuelle Seite) in einer einzigen, indexgestützten Abfrage, deutlich schneller als der PHP-seitige Vergleich bei größeren Indizes. Erkennung läuft automatisch per Versions-Check (mit „Neu prüfen"-Button, falls die Datenbank nach der Installation aktualisiert wird); auf älteren MariaDB-Versionen oder MySQL bleibt die PHP-Brute-Force-Berechnung als dauerhafter, gleichwertiger Fallback erhalten – ein öffentliches Addon muss schließlich auch dort funktionieren.

### Benannte Sitemap-Bereiche

Zusätzliche Sitemaps lassen sich je Profil in mehrere benannte Gruppen aufteilen statt in einer einzigen Liste (z. B. „Allgemein" und „News" mit jeweils eigenen URLs). Jede Gruppe kann zusätzlich:

- eine kurze **Beschreibung** bekommen, die der KI als Zusatzkontext mitgeteilt wird und ihr hilft, den Bereich thematisch einzuordnen,
- als **aktuell/zeitkritisch** markiert werden (z. B. eine News-Gruppe) – Fragen mit Signalwörtern wie „aktuell", „neu" oder „zuletzt" bevorzugen dann gezielt Treffer aus dieser Gruppe, sowohl im Ranking als auch per Hinweis an die KI.

In der Suche erscheinen benannte Gruppen als eigene Filter-Facette neben den Quellentyp-Filtern. Ohne aktiven Filter werden Treffer aus mehreren Bereichen durchmischt angezeigt, damit ein großer Bereich einen kleineren nicht komplett verdrängt.

### Übersetzbare Widget-Oberfläche

Buttons, Platzhalter und Statusmeldungen des Chat-/Such-Widgets lassen sich unabhängig von der Sprache der KI-Antworten übersetzen – gesteuert über das `ui-language`-Feld des jeweiligen Profils, geladen aus JSON-Sprachdateien (`assets/i18n/`). Eine neue Sprache hinzufügen bedeutet: eine neue JSON-Datei mit denselben Schlüsseln anlegen, kein Code nötig. Fehlende Schlüssel fallen automatisch auf Deutsch zurück, sodass eine unvollständige Übersetzung nichts kaputt macht.

### Missbrauchsschutz

Neben dem bestehenden Schutz gegen SQL-/Code-Injection erkennt ein zusätzlicher Guard Prompt-Injection- und Jailbreak-Muster (z. B. „ignoriere alle vorherigen Anweisungen") – wirkt für Chat und Suche gleichermaßen. Häufen sich Angriffsversuche im selben Gesprächsverlauf, wird die Konversation ab einer Schwelle nicht mehr hart blockiert, sondern höflich beendet. Ist das Intrusion-Prevention-Addon `upkeep` installiert, lassen sich erkannte Angriffe zusätzlich mit einer temporären („weichen", nicht dauerhaften) IP-Sperre melden. Offensichtlich sinnloses Kauderwelsch wird zusätzlich erkannt, bevor es überhaupt bei der KI landet.

### FAQ-Vorcaching

Optional (Standard: deaktiviert) lassen sich vorberechnete Antworten für wiederkehrende erste Fragen einer Konversation cachen, um wiederholte KI-Aufrufe zu vermeiden. Betrifft ausschließlich die erste Nachricht eines Gesprächs, keine Folgefragen im Kontext des bisherigen Verlaufs.

### Statistik

Eigene Backend-Seite mit Auswertung zu Such- und Chat-Aktivitäten: Überblick je Scope, Top-Suchbegriffe, häufig gestellte Fragen, No-Result-Situationen, zeitliche Filterung und ein Reset für neue Messzyklen. Die Logik liegt in einer eigenen Service-Klasse, nicht im Page-Template.

## Alles andere

### Installation

1. Addon installieren.
2. Provider konfigurieren: API-Key, Base-URL, Modell und Embedding-Modell.
3. Index initialisieren.
4. Frontend-/Backend-Chat gemäß den Einstellungen aktivieren.
5. Optional weitere Profile anlegen, Statistikseite und Demo-/Beispielbereich prüfen.

### API-Provider

**Google Gemini** – API-Key aus Google AI Studio, passendes Modell und Embedding-Variante wählen.

**Cloudflare Workers AI** – Cloudflare-Account mit aktiviertem Workers AI, Account-ID und Token konfigurieren.

**OpenAI-kompatibel** – funktioniert mit Ollama, OpenWebUI, LM Studio oder selbstgehosteten OpenAI-ähnlichen APIs. Wichtig: korrekte Base-URL (typisch `https://ai.domain.tld/api/` oder `.../api/v1/`), API-Key nur falls vom Dienst gefordert, passendes Chat- und Embedding-Modell, und nach einem Provider-Wechsel den Index neu aufbauen (unterschiedliche Modelle liefern unterschiedliche Vektor-Dimensionen).

**`ai_platform`** – ist dieses FriendsOfREDAXO-Addon zusätzlich installiert, lässt sich dessen zentrale Provider-Verwaltung nutzen statt eigener API-Keys je Addon.

### Wichtige Settings

Global konfigurierbar sind unter anderem: Frontend/Backend aktiviert, Reset-Countdown und Copy-Button je Scope, Suche nur auf aktuelle Seite begrenzt, Personalisierung/Anrede als globaler Standard, öffentlicher API-Endpunkt, Indexierungsquellen und aktivierte Content-Provider. Vieles davon lässt sich zusätzlich je Profil überschreiben (siehe Einzelfeature „Profile" oben).

### SSE-Streaming: Server-Voraussetzungen

Ist „Live-Antworten streamen (SSE)" aktiviert, sendet `rex-api-call=ai_chat_query` die Antwort als `text/event-stream` und schreibt laufend per `flush()`. Damit das beim Browser auch fortlaufend statt am Ende komplett auf einmal ankommt, darf keine Schicht auf dem Weg zwischenpuffern:

```
Browser  ⇄  (CDN/Proxy)  ⇄  Webserver (nginx/Apache)  ⇄  PHP (PHP-FPM)
```

Das Addon setzt bereits die nötigen Header (`Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`, `X-Accel-Buffering: no`) und deaktiviert PHP-seitig Output-Buffering. Der Webserver/Proxy muss mitspielen:

- **PHP**: `output_buffering = Off`, `zlib.output_compression = Off`.
- **Kein Gzip auf dem Endpunkt** – in der Praxis meist unkritisch, `text/event-stream` steht normalerweise nicht in `gzip_types`.
- **Kein Reverse-Proxy-/CDN-Buffering oder -Caching** auf dem API-Pfad.
- **Timeouts erhöhen** – mindestens auf den Wert von `openai_timeout` (Standard 120 s), eher mehr.

**Plesk**: Empfohlener, praxisbestätigter Weg – unter „Hosting-Einstellungen → PHP ausführen als" auf „FPM-Anwendung (nginx)" stellen (bei aktiviertem „Proxymodus", dem Standard-Setup mit nginx vor Apache). Apache bleibt dabei weiterhin für Routing und `.htaccess`-Auswertung zuständig (Friendly URLs, sicherheitskritische Sperren funktionieren unverändert weiter) – „FPM-Anwendung (nginx)" greift nur in die eigentliche PHP-Ausführung ein: nginx spricht PHP-FPM direkt an, statt über Apache → `mod_proxy_fcgi` umzuleiten. Genau dieser Umweg war in der Praxis die Ursache für „häppchenweise statt flüssig". Nach der Umstellung kurz gegentesten: Funktioniert eine bekannte Friendly-URL weiterhin? Liefert `https://DOMAIN/redaxo/src/.htaccess` weiterhin `403 Forbidden`?

Muss die Domain bei „FPM-Anwendung (Apache)" bleiben (kein Proxymodus verfügbar), helfen unter „Hosting & DNS → Apache & nginx-Einstellungen → Zusätzliche nginx-Direktiven":

```nginx
proxy_buffering off;
proxy_read_timeout 300s;
proxy_send_timeout 300s;
```

Kein `if`-Block dabei verwenden – nginx erlaubt `proxy_buffering` dort nicht. Die Direktiven gelten dann für die ganze Domain, für normale Seiten unkritisch. Diese Variante behebt nur die nginx-seitige Pufferung, nicht die zwischen Apache und PHP-FPM – dafür bleibt „FPM-Anwendung (nginx)" mit Proxymodus der zuverlässigere Weg. Das gesendete `X-Accel-Buffering: no` reicht bei nginx oft bereits aus, auch ohne die Direktiven oben.

**Generisches nginx direkt vor PHP-FPM** (ohne Plesk):

```nginx
location /index.php {
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_read_timeout 300s;
    fastcgi_buffering off;
}
```

**CDN/Cloudflare**: Cache-Bypass-Regel für den Pfad mit `rex-api-call=ai_chat_query` anlegen (Cache Level: Bypass). Kommt weiterhin nur die komplette Antwort statt einzelner Chunks, hilft ein Test mit der Domain auf „DNS only", um das CDN als Ursache auszuschließen.

**Prüfen, ob Streaming wirklich ankommt**:

```bash
curl -N -sS \
  -H "Accept: text/event-stream" \
  -H "X-AiChat-Stream: true" \
  -H "Content-Type: application/json" \
  -X POST "https://DOMAIN/index.php?rex-api-call=ai_chat_query" \
  -d '{"message":"Testfrage","scope":"frontend","mode":"chat","stream":true}'
```

Kommen die `event: chunk`-Zeilen sichtbar nacheinander mit Pausen an, funktioniert das Streaming durch die gesamte Kette. Kommt alles gebündelt, puffert noch eine Schicht.

### Sicherheit und Zugriff

Der Developer-Chat ist ausschließlich für angemeldete Backend-User zugänglich. Der öffentliche API-Endpunkt lässt sich bei Bedarf komplett deaktivieren. Ein Datenschutz-Guard verhindert, dass sensible Eingaben (E-Mail-Adressen, IBAN-/Kontonummern, Passwort-/PIN-/TAN-Kontexte) an das KI-Modell weitergegeben werden – stattdessen erscheint eine passende Warnung. Details zum weitergehenden Missbrauchsschutz (Prompt-Injection, Upkeep-Anbindung) siehe Einzelfeature oben.

### Server-/Docker-Anforderungen

- **PHP ≥ 8.1** (Mindestanforderung laut `package.yml`), entwickelt/getestet wird gegen PHP 8.5.
- **`shell_exec()` nicht deaktiviert** sowie `curl` oder `wget` im PATH des PHP-Prozesses (auch für PHP-FPM-Pools mit geleertem `$PATH` – Standardpfade wie `/usr/bin/`, `/usr/local/bin/`, `/opt/homebrew/bin/` werden als Fallback geprüft). Wird ausschließlich für die Hintergrund-Indizierung gebraucht; ohne diese Voraussetzung bleibt „Im Hintergrund indexieren" deaktiviert (mit Grund als Tooltip), „Jetzt indexieren" im Browser funktioniert davon unabhängig immer.
- **In containerisierten Setups mit Portmapping** (z. B. Docker, externer Port ≠ interner Port): Der Self-Request der Hintergrund-Indizierung versucht zuerst die öffentliche URL, dann Loopback auf Port 443/80.
- **MariaDB** wird gegenüber MySQL bevorzugt – siehe „Natives Vektor-Retrieval" oben.
- Für SSE-Streaming: `output_buffering = Off`, `zlib.output_compression = Off`, kein bufferndes Reverse-Proxy-/CDN-Layer.
- **Optional, aber empfohlen für die Entwicklung**: das FriendsOfREDAXO-Addon [`rexstan`](https://github.com/FriendsOfRedaxo/rexstan) (PHPStan für REDAXO) – dieses Addon wird gegen PHPStan Level 8 auf PHP 8.5 entwickelt.
- **Optional, je nach gewünschtem Funktionsumfang**: `yrewrite` (Domain-Scoping), `api` (authentifizierter statt öffentlicher API-Zugriff), `ai_platform` (gemeinsame KI-Provider-Verwaltung), `upkeep` (IP-Sperren bei erkannten Angriffen), `cronjob` (planmäßige Neuindizierung).

### Manuelle Einbindung inkl. Assets

Wird das Addon nicht automatisch über die REDAXO-Backend-Ausgabe eingebunden, lassen sich die Assets auch selbst laden und die Komponenten direkt auf einer Seite platzieren.

**Grundgerüst – Assets laden:**

```html
<link rel="stylesheet" href="/redaxo/src/addons/ai_chat/assets/ai-search.css">
<script src="/redaxo/src/addons/ai_chat/assets/ai-search.js"></script>
<script type="module" src="/redaxo/src/addons/ai_chat/assets/ai-chat.js"></script>
```

`ai-search.css`/`ai-search.js` werden für die Suche benötigt, `ai-chat.js` für das Chat-Web-Component.

**Variante 1: Chat allein**

```html
<ai-chat
  api-url="/index.php?rex-api-call=ai_chat_query"
  scope="frontend"
  title="Website Chat"
  greeting="Hallo! Wie kann ich Ihnen helfen?"
  position="bottom-right"
  primary-color="#0d6efd"
  mode="bubble"
  allow-scope-switch="false"
  personalization-mode="off"
  reset-countdown="10"
  copy-history="true">
</ai-chat>
```

Backend/Developer-Chat entsprechend mit `scope="developer"`.

**Variante 2: Suche allein**

```html
<div id="ai-search-root"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.AiSearch) {
    console.warn('AI Search nicht geladen');
    return;
  }

  window.AiSearch.getInstance({
    apiUrl: '/index.php?rex-api-call=ai_chat_query',
    autoButton: true,
    placeholder: 'Suchen oder fragen ...',
    searchScope: 'frontend',
    chatScope: 'search',
    chatActionBehavior: 'widget'
  });
});
</script>
```

Mit eigenem Suchfeld statt Trigger-Button:

```html
<input id="search-input" type="text" value="Öffnungszeiten">
<button id="search-open">Suchen</button>

<script>
document.getElementById('search-open').addEventListener('click', function () {
  window.AiSearch.openWithQuery(document.getElementById('search-input').value, {
    searchScope: 'frontend',
    chatScope: 'search',
    chatActionBehavior: 'widget'
  });
});
</script>
```

**Variante 3: Suche und Chat kombiniert**

```html
<link rel="stylesheet" href="/redaxo/src/addons/ai_chat/assets/ai-search.css">
<script src="/redaxo/src/addons/ai_chat/assets/ai-search.js"></script>
<script type="module" src="/redaxo/src/addons/ai_chat/assets/ai-chat.js"></script>

<div id="ai-search-root"></div>

<ai-chat
  api-url="/index.php?rex-api-call=ai_chat_query"
  scope="frontend"
  title="Website Chat"
  greeting="Hallo! Ich bin der Website-Chat."
  position="bottom-right"
  primary-color="#ff6b35"
  mode="bubble"
  personalization-mode="off"
  reset-countdown="15"
  copy-history="true">
</ai-chat>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (window.AiSearch) {
    window.AiSearch.getInstance({
      apiUrl: '/index.php?rex-api-call=ai_chat_query',
      autoButton: false,
      placeholder: 'Suchen oder Frage stellen ...',
      searchScope: 'frontend',
      chatScope: 'search',
      chatActionBehavior: 'widget'
    });
  }
});
</script>
```

**Variante 4: Spotlight-Overlay mit Übergabe an den Chat per Button**

Entspricht dem Demo-Muster: Ein Overlay zeigt Treffer, ein eigener Button startet anschließend den Chat mit derselben Suchanfrage.

```html
<link rel="stylesheet" href="/redaxo/src/addons/ai_chat/assets/ai-search.css">
<script src="/redaxo/src/addons/ai_chat/assets/ai-search.js"></script>
<script type="module" src="/redaxo/src/addons/ai_chat/assets/ai-chat.js"></script>

<button id="open-spotlight" class="btn btn-primary">Suchen oder fragen</button>

<ai-chat
  api-url="/index.php?rex-api-call=ai_chat_query"
  scope="frontend"
  title="Website Chat"
  greeting="Hallo! Ich kann weiterhelfen."
  position="bottom-right"
  primary-color="#1d4ed8"
  mode="bubble"
  personalization-mode="off"
  allow-scope-switch="false">
</ai-chat>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.AiSearch) {
    console.warn('AI Search nicht geladen');
    return;
  }

  const spotlight = window.AiSearch.getInstance({
    apiUrl: '/index.php?rex-api-call=ai_chat_query',
    autoButton: false,
    placeholder: 'Suchen oder Frage stellen ...',
    searchScope: 'frontend',
    chatScope: 'search',
    chatActionBehavior: 'widget'
  });

  document.getElementById('open-spotlight').addEventListener('click', function () {
    spotlight.open('');
  });

  const runDemoFlow = function (query) {
    if (!query || !query.trim()) {
      return;
    }

    // 1) Spotlight-Overlay mit Suchergebnissen öffnen
    spotlight.open(query.trim());

    // 2) Danach per Button in den Chat übergehen
    setTimeout(function () {
      const btn = document.querySelector('.ai-search-action-chat');
      if (btn) {
        btn.click();
      } else {
        window.AiSearch.openWithQuery(query.trim(), {
          searchScope: 'frontend',
          chatScope: 'search',
          chatActionBehavior: 'widget'
        });
      }
    }, 150);
  };

  // Beispielaufruf: runDemoFlow('Wie kann ich Kontakt aufnehmen?');
});
</script>
```

Kern-Logik, um den Button direkt aus dem Search-Overlay zu verwenden:

```js
window.AiSearch.openWithQuery('Was kostet das?', {
  searchScope: 'frontend',
  chatScope: 'search',
  chatActionBehavior: 'widget'
});
```

Das öffnet die Suche als Overlay und startet den Chat anschließend im Widget-Modus mit derselben Anfrage.

**Variante 5: Direkter API-Call ohne UI-Komponenten**

```js
fetch('/index.php?rex-api-call=ai_chat_query', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  },
  body: JSON.stringify({
    mode: 'search',
    scope: 'frontend',
    message: 'Wie kann ich Kontakt aufnehmen?',
    limit: 8
  })
})
  .then((response) => response.json())
  .then((data) => {
    console.log('Treffer:', data.hits);
    console.log('Filter:', data.filters);
  });
```

KI-Antwort direkt auslösen:

```js
fetch('/index.php?rex-api-call=ai_chat_query', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  },
  body: JSON.stringify({
    mode: 'chat',
    scope: 'frontend',
    message: 'Wer ist Ansprechpartner für technische Fragen?',
    include_followups: false
  })
})
  .then((response) => response.json())
  .then((data) => {
    console.log('Antwort:', data.answer);
  });
```

**Empfehlung**: Nur Chat → `ai-chat.js` plus `<ai-chat>`. Nur Suche → `ai-search.js` + `ai-search.css` + `window.AiSearch`. Beides → beide Assets laden und kombinieren. Backend/Developer-Scope → `scope="developer"` und ggf. `allow-scope-switch="true"`.

### Erweiterbarkeit

Dritt-Addons können eigene Indexierungs-Quellen registrieren, ohne den Core zu patchen: `ContentProviderRegistry` ruft den Extension Point `AI_CHAT_CONTENT_PROVIDERS` auf (`subject` ist ein `array<string, ContentProviderInterface>`, Key = Provider-Schlüssel).

```php
rex_extension::register('AI_CHAT_CONTENT_PROVIDERS', function (rex_extension_point $ep) {
    $providers = $ep->getSubject();
    $providers['mein_provider'] = new MeinContentProvider();
    return $providers;
});
```

Weitere Extension Points: `AI_CHAT_REGISTER_PROVIDERS` (eigene KI-Provider-Implementierung unter einem eigenen Schlüssel), `AI_CHAT_PROFILE_CANDIDATES` (Profil-Auswahl vor der finalen Entscheidung filtern/umsortieren) und `AI_CHAT_WIDGET_TRANSLATIONS` (zusätzliche Sprachen/Schlüssel für die Widget-Oberfläche nachliefern, ohne den Core-Ordner anzufassen).

### Architekturhinweise

Styling und Scripts liegen als eigene Asset-Dateien vor und werden über `boot.php` registriert, nicht inline im PHP-Template. Statistik-Logik ist in einer eigenen Service-Klasse organisiert, nicht im Page-Template.

### Weitere Ressourcen

- Addon-Settings im REDAXO-Backend
- Statistikseite im Backend
- Demo-/Beispielseiten im Addon
- [CHANGELOG.md](CHANGELOG.md) und [TODO.md](TODO.md) im Addon-Verzeichnis

## Fazit

AI Chat & Search ist ein konfigurierbares FriendsOfREDAXO-AddOn für semantische Suche, Chat-Interaktion und KI-gestützte Wissensabfrage – kein reiner Chatbot, sondern ein komplettes Such- und Analyse-Tool mit Profil-System, Provider-Fallbacks, Missbrauchsschutz und anpassbarer Frontend-/Backend-Integration.

## Lizenz

AI Chat steht unter der MIT-Lizenz. Der vollständige Lizenztext liegt in [LICENSE.md](LICENSE.md).

## Credits

Entstanden ist das Addon ursprünglich als „KLXM Chat & Search" bei der KLXM Crossmedia GmbH, ab Version 1.0.0 als `ai_chat` neu aufgesetzt und in die FriendsOfREDAXO-Organisation überführt. Ein besonderer Dank gilt dem Fußballverband Niederrhein e.V., der die Entwicklung maßgeblich unterstützt hat, sowie Oliver Kreischer ([@olien](https://github.com/olien)) für ausführliches Testing.
