<?php
/**
 * AI Chat Suchseite - Ergebnisansicht: Trefferzahl, Trefferliste, Paginierung,
 * Filter-Facetten, oder eine "keine Treffer" Meldung.
 *
 * Vars:
 *   result         array<string, mixed>  Rueckgabe von ChatQueryService::searchPaginated() (erforderlich).
 *   page           int                    Aktuelle Seite (1-basiert). Default: 1.
 *   perPage        int                    Treffer pro Seite. Default: 10.
 *   totalPages     int                    Gesamtzahl Seiten. Default: 0.
 *   baseUrl        string                 Basis-URL fuer Facetten-/Paginierungs-Links. Default: aktuelle Artikel-URL.
 *   showDateRange  bool                   Ob die Datumsfelder oben angezeigt wurden (fuer Link-Erhalt). Default: false.
 *
 * @var rex_fragment $this
 */
$result = $this->getVar('result', null);
if (!is_array($result)) {
    return;
}

$page = (int) $this->getVar('page', 1);
$perPage = (int) $this->getVar('perPage', 10);
$totalPages = (int) $this->getVar('totalPages', 0);
$baseUrl = (string) $this->getVar('baseUrl', rex_getUrl());
$showDateRange = (bool) $this->getVar('showDateRange', false);

$hits = is_array($result['hits'] ?? null) ? $result['hits'] : [];
$total = is_int($result['total'] ?? null) ? $result['total'] : count($hits);

// Guards in ChatQueryService::searchPaginated() (Rate-Limit, Spam-/Nonsense-Erkennung,
// Frontend-Zugriffskontrolle) liefern bei einer Blockierung dieselbe Antwortform wie ein
// echtes "keine Treffer", zusaetzlich eine privacy_warning_message - die wird hier
// bevorzugt gezeigt statt der generischen "keine Treffer"-Meldung, damit ein blockierter
// Zugriff nicht wie ein zufaelliges leeres Ergebnis wirkt.
$warningMessage = isset($result['privacy_warning_message']) ? (string) $result['privacy_warning_message'] : '';
?>
<div class="ai-search-page-results">
<?php if ('' !== $warningMessage): ?>
    <p class="ai-search-page-empty"><?= rex_escape($warningMessage) ?></p>
<?php elseif ([] === $hits): ?>
    <p class="ai-search-page-empty"><?= rex_i18n::msg('ai_chat_search_page_no_results') ?></p>
<?php else: ?>
    <p class="ai-search-page-count"><?= 1 === $total ? rex_i18n::msg('ai_chat_search_page_results_count_one') : rex_i18n::msg('ai_chat_search_page_results_count', (string) $total) ?></p>

    <?php
    $facets = is_array($result['filters'] ?? null) ? $result['filters'] : [];
    if ([] !== $facets) {
        $facetsFragment = new rex_fragment();
        $facetsFragment->setVar('sourceTypes', is_array($facets['source_types'] ?? null) ? $facets['source_types'] : [], false);
        $facetsFragment->setVar('labels', is_array($facets['labels'] ?? null) ? $facets['labels'] : [], false);
        $facetsFragment->setVar('baseUrl', $baseUrl, false);
        echo $facetsFragment->parse('ai_chat/facets.php');
    }
    ?>

    <div class="ai-search-page-list">
    <?php foreach ($hits as $hit) {
        if (!is_array($hit)) {
            continue;
        }
        $hitFragment = new rex_fragment();
        $hitFragment->setVar('hit', $hit, false);
        echo $hitFragment->parse('ai_chat/hit.php');
    } ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <?php
        $paginationFragment = new rex_fragment();
        $paginationFragment->setVar('page', $page);
        $paginationFragment->setVar('totalPages', $totalPages);
        $paginationFragment->setVar('baseUrl', $baseUrl, false);
        echo $paginationFragment->parse('ai_chat/pagination.php');
        ?>
    <?php endif; ?>
<?php endif; ?>
</div>
