<?php

use FriendsOfRedaxo\AiChat\Service\SystemCheckService;

// Vorher Teil der Statistiken-Seite (siehe Git-Historie) - dort standen Server-/
// Voraussetzungs-Diagnose und Nutzungsstatistik nebeneinander, obwohl das zwei
// verschiedene Fragen sind ("laeuft die Umgebung korrekt?" vs. "wie wird der Chat
// genutzt?"). Ein Systemcheck ist inhaltlich eine Einstellungen-/Diagnose-Angelegenheit,
// keine Nutzungsauswertung - gehoert deshalb hierher statt zu den Statistiken.

$statusBadgeClass = [
    'ok' => 'label-success',
    'warning' => 'label-warning',
    'error' => 'label-danger',
];
$statusLabel = [
    'ok' => 'OK',
    'warning' => 'Hinweis',
    'error' => 'Fehler',
];

echo '<p class="help-block">Prüft, ob der Server die Voraussetzungen für dieses Addon erfüllt (PHP-/REDAXO-Version, PDF-Extraktion, Hintergrund-Indexierung, native Vektorsuche, KI-Provider-Zugangsdaten) - hilfreich als erster Blick, bevor man aus einem Fehlerbild rückwärts debuggt.</p>';

$systemCheckBody = '<table class="table table-striped klxmchat-stat-table">'
    . '<thead><tr><th style="width:180px;">Prüfung</th><th style="width:90px;">Status</th><th>Details</th></tr></thead><tbody>';
foreach (SystemCheckService::runChecks() as $check) {
    $systemCheckBody .= '<tr>'
        . '<td>' . rex_escape($check['label']) . '</td>'
        . '<td><span class="label ' . $statusBadgeClass[$check['status']] . '">' . $statusLabel[$check['status']] . '</span></td>'
        . '<td>' . rex_escape($check['message']) . '</td>'
        . '</tr>';
}
$systemCheckBody .= '</tbody></table>';

$fragment = new rex_fragment();
$fragment->setVar('content', $systemCheckBody, false);
echo $fragment->parse('core/page/section.php');

// Diagnose fuer "Im Hintergrund indexieren" (pages/content.php): der Mechanismus dahinter
// ist ein self-curl/wget-Aufruf gegen die eigene, oeffentliche URL (siehe
// ChatIndex::handleStartBackground()), KEIN PHP-CLI-Aufruf - auf Plesk & Co. betrifft ein
// "falscher PHP-Pfad"-Problem diesen Weg deshalb nicht. Woran es stattdessen haengen kann
// (unterschiedlich je nach Server/Hosting, deshalb "laeuft bei mir auf einer Website,
// haengt auf der anderen"): shell_exec()/curl/wget gesperrt, der Selbstaufruf erreicht den
// Server nicht (Firewall/Proxy/Portmapping), oder - der haeufigste Fall - die
// Serverantwort kam nicht innerhalb der 15-20s-Zeitgrenze eines einzelnen Versuchs.
$debugPanelBody = '<p class="help-block">"Im Hintergrund indexieren" ruft den eigenen Server per <code>curl</code>/<code>wget</code> selbst auf (drei Versuche: öffentliche URL, dann Loopback über Port 443/80) - <strong>kein</strong> PHP-CLI-Aufruf, ein falscher PHP-Pfad (häufig bei Plesk) spielt hier also keine Rolle. Dieser Test spielt denselben Mechanismus einmal harmlos durch (ohne echte Indexierung anzustoßen) und zeigt für jeden Versuch Dauer, HTTP-Status und Rohausgabe.</p>';
$debugPanelBody .= '<button type="button" class="btn btn-default" id="ai-chat-test-selfcall-btn"><i class="rex-icon fa-bug"></i> Hintergrund-Selbstaufruf testen</button>';
$debugPanelBody .= '<div id="ai-chat-test-selfcall-result" style="margin-top:15px;"></div>';

$debugFragment = new rex_fragment();
$debugFragment->setVar('title', 'Hintergrund-Indexierung testen', false);
$debugFragment->setVar('body', $debugPanelBody, false);
echo $debugFragment->parse('core/page/section.php');

echo '
<script>
(function() {
    function initAiChatSelfcallTest() {
        var btn = document.getElementById("ai-chat-test-selfcall-btn");
        var result = document.getElementById("ai-chat-test-selfcall-result");
        if (!btn || !result || btn.dataset.aiChatWired) return;
        btn.dataset.aiChatWired = "1";

        function escapeHtml(value) {
            var div = document.createElement("div");
            div.textContent = value == null ? "" : String(value);
            return div.innerHTML;
        }

        btn.addEventListener("click", function() {
            btn.disabled = true;
            result.innerHTML = "<p><i class=\'rex-icon fa-spinner fa-spin\'></i> Teste Selbstaufruf (kann bis zu ~45s dauern) ...</p>";

            fetch("index.php?rex-api-call=ai_chat_index&action=test_background_selfcall", { method: "POST" })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    btn.disabled = false;
                    if (!data.success) {
                        result.innerHTML = "<div class=\'alert alert-danger\'>Test fehlgeschlagen: " + escapeHtml(data.error || "Unbekannter Fehler") + "</div>";
                        return;
                    }

                    var html = "";
                    if (!data.runner_available) {
                        html += "<div class=\'alert alert-danger\'>Hintergrundausführung grundsätzlich nicht verfügbar: " + escapeHtml(data.runner_reason) + "</div>";
                    }
                    html += "<p><strong>curl:</strong> " + escapeHtml(data.curl_path || "nicht gefunden") + "<br>";
                    html += "<strong>wget:</strong> " + escapeHtml(data.wget_path || "nicht gefunden") + "<br>";
                    html += "<strong>Test-URL:</strong> " + escapeHtml(data.ping_url) + "</p>";

                    if (Array.isArray(data.attempts) && data.attempts.length > 0) {
                        html += "<table class=\'table table-striped\'><thead><tr><th>Versuch</th><th>Dauer</th><th>HTTP-Status</th><th>Response-Header</th><th>Ausgabe</th></tr></thead><tbody>";
                        data.attempts.forEach(function(attempt) {
                            var ok = attempt.http_status === 200;
                            html += "<tr>"
                                + "<td>" + escapeHtml(attempt.label) + "</td>"
                                + "<td>" + escapeHtml(attempt.duration_ms) + " ms</td>"
                                + "<td><span class=\'label " + (ok ? "label-success" : "label-danger") + "\'>" + escapeHtml(attempt.http_status !== null ? attempt.http_status : "kein Status") + "</span></td>"
                                + "<td><pre style=\'margin:0;max-height:150px;overflow:auto;font-size:11px;\'>" + escapeHtml(attempt.headers || "(keine)") + "</pre></td>"
                                + "<td><code>" + escapeHtml(attempt.output || "(leer)") + "</code></td>"
                                + "</tr>";
                        });
                        html += "</tbody></table>";
                    } else {
                        html += "<div class=\'alert alert-warning\'>Kein curl gefunden - Test konnte nicht ausgeführt werden.</div>";
                    }

                    html += "<p><strong>Zuletzt gemerkter Hintergrundlauf:</strong> ";
                    html += "PID-Datei " + (data.pid_file_present ? ("vorhanden, Prozess " + (data.pid_still_running ? "läuft noch" : "läuft nicht mehr")) : "nicht vorhanden") + "</p>";

                    if (data.last_run_log) {
                        html += "<p><strong>Letztes Log von \'Im Hintergrund indexieren\' (curl/wget-Rohausgabe, letzte 4000 Zeichen):</strong></p>";
                        html += "<pre style=\'max-height:300px;overflow:auto;\'>" + escapeHtml(data.last_run_log) + "</pre>";
                    }

                    result.innerHTML = html;
                })
                .catch(function(error) {
                    btn.disabled = false;
                    result.innerHTML = "<div class=\'alert alert-danger\'>Fehler: " + escapeHtml(error) + "</div>";
                });
        });
    }

    if (typeof jQuery !== "undefined") {
        jQuery(document).on("rex:ready", initAiChatSelfcallTest);
    } else {
        document.addEventListener("DOMContentLoaded", initAiChatSelfcallTest);
    }
    if (document.readyState !== "loading") {
        initAiChatSelfcallTest();
    }
})();
</script>';
