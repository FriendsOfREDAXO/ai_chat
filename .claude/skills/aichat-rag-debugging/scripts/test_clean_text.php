<?php
/**
 * Isoliertes Testskript für IndexerService::cleanText() (und optional
 * extractJsonLdFacts()/jsonLdNodeToFacts()/jsonLdScalar()).
 *
 * Vorgehen:
 * 1. Kopiere die aktuellen Methoden aus lib/Service/IndexerService.php unten in die
 *    Klasse CleanTextHarness (Methoden 1:1 übernehmen, private bleibt private).
 * 2. Trage das exakt gemeldete HTML-Snippet in $html ein.
 * 3. Ausführen: php scripts/test_clean_text.php
 * 4. Ausgabe zeilenweise prüfen - jede erwartete "Faktengrenze" muss ein eigener
 *    Zeilenumbruch sein, keine zwei Fakten dürfen ohne Trennung zusammenlaufen.
 *
 * Dieses Skript ist bewusst kein Unit-Test mit Framework-Anbindung, sondern ein
 * schnelles Wegwerf-Werkzeug für die Fehlersuche - nach der Diagnose einfach die
 * kopierten Methoden wieder aktuell halten oder die Datei löschen/neu befüllen.
 */

class CleanTextHarness
{
    // --- Hier die aktuelle Implementierung aus IndexerService.php einfügen ---

    private function cleanText(string $text): string
    {
        throw new RuntimeException('Bitte die aktuelle cleanText()-Implementierung aus IndexerService.php hier einfügen.');
    }

    // Optional, falls JSON-LD-Verhalten getestet werden soll:
    // private function extractJsonLdFacts(string $html): string { ... }
    // private function jsonLdNodeToFacts(array $node, int $depth = 0): array { ... }
    // private function jsonLdScalar($value): string { ... }

    public function run(string $html): string
    {
        return $this->cleanText($html);
    }
}

// --- Hier das exakt gemeldete HTML-Snippet eintragen ---
$html = <<<HTML
<p><strong>Beispiel GmbH</strong><br>Musterstraße 10<br>(vormals Alte Straße 10)<br>47055 Musterstadt</p>
HTML;

$harness = new CleanTextHarness();
$result = $harness->run($html);

echo "=== Ergebnis (Zeile für Zeile) ===\n";
foreach (explode("\n", $result) as $i => $line) {
    echo sprintf("[%2d] %s\n", $i, $line);
}
