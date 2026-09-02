<?php
$addon = rex_addon::get('ai_chat');

$apiUrl = rex_url::frontendController(['rex-api-call' => 'ai_chat_query']);
if (!str_contains($apiUrl, 'rex-api-call')) {
  $apiUrl = '/index.php?rex-api-call=ai_chat_query';
}
if (!str_starts_with($apiUrl, 'http')) {
  $server = rtrim(rex::getServer(), '/');
  if ($server !== '') {
    $apiUrl = $server . '/' . ltrim($apiUrl, '/');
  }
}

?>
<style>
/* Nur fuer diese Demo-Seite - die .ai-chat-demo-*-Klassen (vormals klxm-demo-*) hatten nie
   eine eigene CSS-Regel in einer der Asset-Dateien, die Karten waren dadurch faktisch
   unstyled. */
.ai-chat-demo-card { border: 1px solid #ddd; border-radius: 6px; padding: 15px; background: #fff; height: 100%; }
.ai-chat-demo-card h3 { margin-top: 0; }
.ai-chat-demo-code { background: #f8f9fa; border: 1px solid #eee; border-radius: 4px; padding: 8px 10px; margin: 10px 0; overflow-x: auto; }
.ai-chat-demo-code pre { margin: 0; }
.ai-chat-demo-chat-wrapper { border: 1px solid #ddd; border-radius: 6px; overflow: hidden; margin-top: 10px; }
</style>
<div class="row">
  <div class="col-md-12">
    <p class="lead">Die Demo ist jetzt klar getrennt in Suche und Chat. Starten Sie oben mit der Inline-Suche.</p>
    <div class="alert alert-info">
      <strong>Hinweis für Entwickler:</strong>
      Die Komponenten <code>&lt;ai-chat&gt;</code> und die Spotlight-Suche sind getrennt nutzbar.
      Die Suche verwendet die API <code><?= rex_escape($apiUrl, 'html') ?></code>.
    </div>
    <div class="alert alert-warning">
      <strong>Seit dem Profil-Feature:</strong> Begrüßung, Prompt, Theme-Farben, Anrede/Personalisierung,
      Reset-Countdown/Verlauf-kopieren und ob Chat/Suche überhaupt automatisch eingebunden werden, kommen
      im echten Betrieb aus dem aufgelösten <a href="<?= rex_escape(rex_url::backendPage('ai_chat/profiles'), 'html_attr') ?>">Profil</a>
      (AI Chat → Profile), nicht mehr aus globaler Konfiguration. Die Attribute auf dieser Demo-Seite zeigen
      trotzdem die Web-Component-Schnittstelle selbst - dieselben Attribute setzt <code>boot.php</code> dann
      automatisch aus dem Profil.
    </div>
  </div>
</div>

<div class="row" style="margin-top: 20px;">
  <div class="col-md-12">
    <h3 style="margin-top: 0;">Suche</h3>
  </div>
</div>

<div class="row" style="margin-top: 10px;">
  <div class="col-md-12">
    <div class="panel panel-default">
      <div class="panel-heading">Inline-Suche (direkt sichtbar)</div>
      <div class="panel-body">
        <p>
          Diese Variante ist als erstes sichtbar und ideal für Header, Hero-Bereiche oder Seitenleisten.
          Sie unterstützt beides: direkte Treffer inline ohne Modal und optionales Öffnen als Modal.
        </p>
        <div id="ai-search-demo-inline">
          <div class="ai-search-demo-row">
            <input type="text" class="form-control ai-search-demo-input" placeholder="Inline-Suche wird geladen...">
            <button class="btn btn-primary" type="button" disabled>Inline suchen</button>
            <button class="btn btn-default" type="button" disabled>Als Modal öffnen</button>
          </div>
        </div>
        <pre style="margin-top: 10px;"><code>Inline-Demo: Enter/Inline suchen = ohne Modal, Als Modal öffnen = Spotlight-Overlay.</code></pre>
      </div>
    </div>
  </div>
</div>

<div class="row" style="margin-top: 10px;">
  <div class="col-md-4">
    <div class="panel panel-default">
      <div class="panel-heading">Spotlight-Overlay</div>
      <div class="panel-body">
        <p>Vollständige Suche mit Filter-Chips, Treffern und optionaler KI-Zusammenfassung.</p>
        <div id="ai-search-demo" data-api-url="<?= rex_escape($apiUrl, 'html_attr') ?>"></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="panel panel-default">
      <div class="panel-heading">Icon-Launcher mit Popup</div>
      <div class="panel-body">
        <p>Dropdown-Variante mit Live-Treffern direkt im kleinen Popup.</p>
        <div id="ai-search-demo-dropdown"></div>
        <pre style="margin-top: 10px;"><code>&lt;button aria-haspopup='dialog' aria-controls='search-popup'&gt;⌕&lt;/button&gt;</code></pre>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="panel panel-default">
      <div class="panel-heading">Skinning per CSS-Variablen</div>
      <div class="panel-body">
        <p>Theme-Presets überschreiben Farben für Suche und Ergebnispanel.</p>
        <div id="ai-search-demo-skinning"></div>
        <pre style="margin-top: 10px;"><code>#ai-search-root { --ai-search-accent: #e0551b; }</code></pre>
      </div>
    </div>
  </div>
</div>

<div class="row" style="margin-top: 30px;">
  <div class="col-md-12">
    <h3 style="margin-top: 0;">Chat</h3>
    <p class="text-muted">Neu: Der Chat unterstützt optionale UX-Helfer wie einen animierten Reset-Countdown und ein Kopieren bzw. Downloaden des Verlaufes.</p>
  </div>
</div>

<div class="row" style="margin-top: 10px;">
  <div class="col-md-12">
    <div class="panel panel-default">
      <div class="panel-heading">Optionale Chat-Features</div>
      <div class="panel-body">
        <p>
          Die Funktionen sind bewusst optional. So kann ein Backend-Chat zum Beispiel nach einer gewissen Zeit automatisch zurücksetzen,
          ohne den Verlauf zu verlieren, und der Dialogverlauf lässt sich bequem kopieren oder als Textdatei exportieren.
        </p>
        <pre style="margin-top: 10px;"><code>&lt;ai-chat
  mode="bubble"
  reset-countdown="5"
  copy-history="true"
  title="REDAXO Chat"&gt;
&lt;/ai-chat&gt;</code></pre>
      </div>
    </div>
  </div>
</div>

<div class="row" style="margin-top: 10px;">
  <div class="col-md-12">
    <div class="panel panel-default">
      <div class="panel-heading">Streaming ("Tippen" der KI-Antwort)</div>
      <div class="panel-body">
        <p>
          Ist <code>stream-enabled="true"</code> gesetzt UND die globale Einstellung „Streaming" (AI Chat →
          Verhalten) aktiv, baut sich die Antwort Wort für Wort auf statt auf einmal zu erscheinen -
          serverseitig per Server-Sent Events, providerübergreifend (außer <code>ai_platform</code>, siehe
          TODO.md). Ohne das Attribut bzw. bei deaktivierter globaler Einstellung liefert derselbe Chat die
          fertige Antwort in einem Stück, ohne Fehler oder Unterschied im Markup.
        </p>
        <pre style="margin-top: 10px;"><code>&lt;ai-chat mode="bubble" stream-enabled="true"&gt;&lt;/ai-chat&gt;</code></pre>
      </div>
    </div>
  </div>
</div>

<div class="row">
  <div class="col-md-4" style="margin-bottom: 20px;">
    <div class="ai-chat-demo-card">
      <h3>1. Bubble Mode</h3>
      <p>Standard-Modus mit schwebender Bubble unten rechts.</p>
      <div class="ai-chat-demo-code"><pre><code>&lt;ai-chat mode='bubble' position='bottom-right'&gt;&lt;/ai-chat&gt;</code></pre></div>
      <div class="text-muted"><small><i>Vorschau lokal auf dieser Seite:</i></small></div>
      <ai-chat id="demo-bubble-chat" mode="bubble" position="bottom-right" title="Demo Chat"></ai-chat>
    </div>
  </div>

  <div class="col-md-4" style="margin-bottom: 20px;">
    <div class="ai-chat-demo-card">
      <h3>2. Inline Mode</h3>
      <p>Chat fest eingebettet, z. B. im Content-Bereich.</p>
      <div class="ai-chat-demo-code"><pre><code>&lt;ai-chat mode='inline' style='--ai-chat-width: 100%'&gt;&lt;/ai-chat&gt;</code></pre></div>
      <div class="ai-chat-demo-chat-wrapper">
        <ai-chat mode="inline" style="--ai-chat-width: 100%; --ai-chat-height: 300px;" title="Inline Chat Demo"></ai-chat>
      </div>
    </div>
  </div>

  <div class="col-md-4" style="margin-bottom: 20px;">
    <div class="ai-chat-demo-card">
      <h3>3. Individuelles Design</h3>
      <p>Branding über CSS-Variablen ohne Änderung am Core-CSS.</p>
      <div class="ai-chat-demo-code"><pre><code>&lt;ai-chat mode='inline' style='--ai-chat-primary: #e91e63; ...'&gt;&lt;/ai-chat&gt;</code></pre></div>
      <div class="ai-chat-demo-chat-wrapper" style="background: #333;">
        <ai-chat mode="inline" style="--ai-chat-primary: #e91e63; --ai-chat-header-bg: #222; --ai-chat-text: #fff; --ai-chat-bg: #333; --ai-chat-bot-msg-bg: #444; --ai-chat-input-bg: #444; --ai-chat-input-text: #fff; --ai-chat-input-border: #555; --ai-chat-width: 100%; --ai-chat-height: 200px;" title="Dark Theme Demo"></ai-chat>
      </div>
    </div>
  </div>

  <div class="col-md-4" style="margin-bottom: 20px;">
    <div class="ai-chat-demo-card">
      <h3>4. Ohne Bubble (eigener Auslöser)</h3>
      <p>
        Kein schwebender Button - öffnet sich an einem eigenen Element mit Klasse/ID
        <code>aichat</code> und klappt automatisch nach oben oder unten auf, je nachdem wo
        gerade Platz ist.
      </p>
      <div class="ai-chat-demo-code"><pre><code>&lt;button id="aichat"&gt;Chat öffnen&lt;/button&gt;
&lt;ai-chat mode="anchored"&gt;&lt;/ai-chat&gt;</code></pre></div>
      <button id="aichat" class="btn btn-primary" type="button">Chat öffnen (anchored)</button>
      <ai-chat mode="anchored" title="Anchored Chat Demo"></ai-chat>
    </div>
  </div>
</div>

<div class="row" style="margin-top: 20px;">
  <div class="col-md-12">
    <div class="panel panel-default">
      <div class="panel-heading">CSS-Dokumentation (Chat)</div>
      <div class="panel-body">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>CSS-Variable</th>
              <th>Standard</th>
              <th>Beschreibung</th>
            </tr>
          </thead>
          <tbody>
            <tr><td><code>--ai-chat-primary</code></td><td><code>#007bff</code></td><td>Hauptfarbe (User-Messages, Toggles)</td></tr>
            <tr><td><code>--ai-chat-bg</code></td><td><code>white</code></td><td>Hintergrund des Chat-Fensters</td></tr>
            <tr><td><code>--ai-chat-text</code></td><td><code>#333</code></td><td>Standard-Textfarbe</td></tr>
            <tr><td><code>--ai-chat-header-bg</code></td><td><code>#f8f9fa</code></td><td>Hintergrund des Kopfbereichs</td></tr>
            <tr><td><code>--ai-chat-bot-msg-bg</code></td><td><code>#f1f3f5</code></td><td>Hintergrund für KI-Antworten</td></tr>
            <tr><td><code>--ai-chat-input-bg</code></td><td><code>white</code></td><td>Hintergrund des Eingabefeldes</td></tr>
            <tr><td><code>--ai-chat-input-text</code></td><td><code>#333</code></td><td>Textfarbe im Eingabefeld</td></tr>
            <tr><td><code>--ai-chat-input-border</code></td><td><code>#ddd</code></td><td>Rahmenfarbe des Eingabefeldes</td></tr>
            <tr><td><code>--ai-chat-width</code></td><td><code>350px</code></td><td>Breite (nur Bubble Mode)</td></tr>
            <tr><td><code>--ai-chat-height</code></td><td><code>500px</code></td><td>Höhe (nur Bubble Mode)</td></tr>
            <tr><td><code>--ai-chat-radius</code></td><td><code>12px</code></td><td>Ecken-Radius</td></tr>
            <tr><td><code>--ai-chat-side</code></td><td><code>20px</code></td><td>Seitenabstand (Bubble Mode)</td></tr>
            <tr><td><code>--ai-chat-bottom</code></td><td><code>20px</code></td><td>Bodenabstand (Bubble Mode)</td></tr>
            <tr><td><code>--ai-chat-zindex</code></td><td><code>1000000</code></td><td>Ebene über anderen Inhalten</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
