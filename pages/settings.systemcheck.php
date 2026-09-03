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
