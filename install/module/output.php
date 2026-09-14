<?php
/**
 * AI Chat Suchseite - Modul-Ausgabe.
 *
 * Rendert Suchformular, Treffer, Paginierung und Filter-Facetten server-seitig
 * ueber die Fragments unter fragments/ai_chat/. Ein Fragment kann im Projekt
 * unter dem gleichen Pfad (fragments/ai_chat/...) ueberschrieben werden, um
 * es ohne Aenderung am Addon selbst umzustylen (REDAXOs Fragment-Suchpfad
 * bevorzugt automatisch die Projekt-Kopie).
 *
 * install/module/output.php wird bewusst NICHT statisch analysiert (siehe
 * .tools/rexstan-Konvention des seekr-Addons als Vorbild): REDAXO ersetzt
 * REX_VALUE[n]/REX_INPUT_VALUE[n] textuell durch den gespeicherten Wert,
 * bevor diese Datei ausgefuehrt wird - ein Analyse-Tool sieht nur den
 * literalen Platzhalter-String, nie den tatsaechlichen PHP-Kontext danach.
 */

use FriendsOfRedaxo\AiChat\Service\ChatQueryService;

// CSS wird bewusst hier eingebunden statt ueber einen globalen OUTPUT_FILTER in
// boot.php - so laedt es nur auf Seiten, die dieses Modul tatsaechlich einsetzen,
// nicht auf jeder Frontend-Seite der Installation.
$aiChatAddon = rex_addon::get('ai_chat');
$cssPath = rex_path::addon('ai_chat', 'assets/ai-search-page.css');
$cssVersion = $aiChatAddon->getVersion() . (is_file($cssPath) ? '-' . (string) filemtime($cssPath) : '');
echo '<link rel="stylesheet" href="' . rex_escape($aiChatAddon->getAssetsUrl('ai-search-page.css?v=' . $cssVersion), 'html_attr') . '">' . "\n";

$term = trim(rex_request('q', 'string', ''));
// 'int' auf einen versehentlich als Array uebergebenen Wert (z.B. ?page[]=x, von
// Hand getippt oder von einem Crawler generiert) wuerde eine "Array to int
// conversion"-Warnung werfen - deshalb roh lesen und selbst pruefen statt den
// 'int'-Vartype direkt darauf loszulassen.
$rawPage = rex_request::request('page', '', 1);
$page = max(1, is_scalar($rawPage) ? (int) $rawPage : 1);

// Leeres Feld = "was die Addon-Einstellung sagt", damit die Seitengroesse an
// einer Stelle gepflegt werden kann statt in jedem Modul-Slice erneut.
$perPage = (int) 'REX_VALUE[1]';
if ($perPage <= 0) {
    $perPage = (int) rex_addon::get('ai_chat')->getConfig('search_page_results_per_page', 10);
}
$perPage = max(1, min($perPage, 50));

$showDateRange = '1' === 'REX_VALUE[2]';

// Request-Daten sind beliebig geformt: type[]=a&type[]=b kommt hier als Array an,
// ein einzelner Wert type=a als String - beides auf eine flache String-Liste
// normalisieren, damit ChatQueryService::extractSourceTypes()/extractSourceLabels()
// (die exakt dasselbe list<string>-Format wie das JS-Overlay erwarten) sie
// unveraendert lesen koennen. Direkt gegen $_REQUEST per rex_request::request()
// gelesen (nicht ueber die verschachtelte rex_request('...', 'array', rex_request(...))-
// Kurzform) - so entscheidet ausschliesslich der tatsaechliche is_array()-Zustand des
// Rohwerts ueber den Pfad, ohne von zwei parallelen Casts desselben Parameters abzuhaengen.
$normalizeList = static function (string $paramName): array {
    $raw = rex_request::request($paramName, '', null);
    if (!is_array($raw)) {
        $raw = null !== $raw && '' !== $raw ? [$raw] : [];
    }

    $result = [];
    foreach ($raw as $item) {
        if (is_string($item) && '' !== trim($item)) {
            $result[] = trim($item);
        }
    }

    return array_values(array_unique($result));
};

$typeFilter = $normalizeList('type');
$labelFilter = $normalizeList('label');

$dateFrom = $showDateRange ? trim(rex_request('date_from', 'string', '')) : '';
$dateTo = $showDateRange ? trim(rex_request('date_to', 'string', '')) : '';

$baseUrl = rex_getUrl(REX_ARTICLE_ID);

$formFragment = new rex_fragment();
$formFragment->setVar('action', $baseUrl, false);
$formFragment->setVar('query', $term);
$formFragment->setVar('showDateRange', $showDateRange);
$formFragment->setVar('dateFrom', $dateFrom);
$formFragment->setVar('dateTo', $dateTo);
echo $formFragment->parse('ai_chat/form.php');

if ('' !== $term) {
    $offset = ($page - 1) * $perPage;

    $input = [
        'message' => $term,
        'limit' => $perPage,
        'offset' => $offset,
        'source_types' => $typeFilter,
        'source_labels' => $labelFilter,
    ];
    if ('' !== $dateFrom) {
        $input['date_from'] = $dateFrom;
    }
    if ('' !== $dateTo) {
        $input['date_to'] = $dateTo;
    }

    $service = new ChatQueryService();
    $result = $service->searchPaginated($input);

    $total = is_int($result['total'] ?? null) ? $result['total'] : 0;
    $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

    // Eine handgetippte oder von einem Crawler ueber die Paginierung erreichte
    // Seitenzahl jenseits der letzten Seite liefert sonst eine leere Trefferliste,
    // obwohl es durchaus Treffer gibt - lieber einmal erneut mit der geklemmten
    // Seite anfragen als eine grundlos leere Seite zeigen (identisches Muster wie
    // im seekr-Addon).
    if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
        $input['offset'] = ($page - 1) * $perPage;
        $result = $service->searchPaginated($input);
    }

    $resultsFragment = new rex_fragment();
    $resultsFragment->setVar('result', $result, false);
    $resultsFragment->setVar('page', $page);
    $resultsFragment->setVar('perPage', $perPage);
    $resultsFragment->setVar('totalPages', $totalPages);
    $resultsFragment->setVar('baseUrl', $baseUrl, false);
    $resultsFragment->setVar('showDateRange', $showDateRange);
    echo $resultsFragment->parse('ai_chat/results.php');
}
