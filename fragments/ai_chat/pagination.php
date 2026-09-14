<?php
/**
 * AI Chat Suchseite - Seiten-Navigation. Jeder Link erhaelt alle aktuell
 * aktiven Parameter (Suchbegriff, Typ-/Label-Filter, Datumsbereich) und
 * aendert nur "page" - das macht jede Seite fuer sich teilbar/bookmarkbar.
 *
 * Vars:
 *   page        int     Aktuelle Seite (1-basiert) (erforderlich).
 *   totalPages  int     Gesamtzahl Seiten (erforderlich).
 *   baseUrl     string  Basis-URL fuer Links. Default: aktuelle Artikel-URL.
 *
 * @var rex_fragment $this
 */
$page = (int) $this->getVar('page', 1);
$totalPages = (int) $this->getVar('totalPages', 0);
if ($totalPages <= 1) {
    return;
}

$page = max(1, min($page, $totalPages));
$baseUrl = (string) $this->getVar('baseUrl', rex_getUrl());

// REDAXO bietet keinen Zugriff auf das komplette GET-Array als Ganzes - die
// aktuellen Parameter werden deshalb aus dem Query-String zurückgewonnen (nie
// $_GET direkt, um assoziative/verschachtelte Werte konsistent mit
// parse_str()s eigener Normalisierung zu behandeln).
parse_str((string) rex_server('QUERY_STRING', 'string', ''), $currentParams);

/**
 * @param array<string, mixed> $params
 */
$buildUrl = static function (int $targetPage) use ($baseUrl, $currentParams): string {
    $params = $currentParams;
    $params['page'] = $targetPage;
    // Separator bewusst '&': REDAXOs arg_separator.output steht auf '&amp;', der
    // Default waere hier also bereits HTML-escaped und rex_escape() auf dem href
    // wuerde daraus '&amp;amp;' machen - der Browser sendet dann buchstaeblich
    // "amp;page" als Parameternamen.
    $qs = http_build_query($params, '', '&');

    return $baseUrl . ('' !== $qs ? '?' . $qs : '');
};

// Fenster von Seiten um die aktuelle Seite, plus immer erste/letzte Seite, mit
// Ellipsis-Luecken dazwischen - identisches Muster zur bekannten seekr-Paginierung.
$window = 2;
$pages = [];
for ($p = 1; $p <= $totalPages; $p++) {
    if (1 === $p || $totalPages === $p || ($p >= $page - $window && $p <= $page + $window)) {
        $pages[] = $p;
    }
}
?>
<nav class="ai-search-page-pagination" aria-label="<?= rex_escape(rex_i18n::msg('ai_chat_search_page_pagination_label'), 'html_attr') ?>">
    <ul class="ai-search-page-pagination__list">
<?php if ($page > 1): ?>
        <li class="ai-search-page-pagination__item">
            <a class="ai-search-page-pagination__link ai-search-page-pagination__link--prev" href="<?= rex_escape($buildUrl($page - 1)) ?>" rel="prev"><?= rex_i18n::msg('ai_chat_search_page_prev') ?></a>
        </li>
<?php else: ?>
        <li class="ai-search-page-pagination__item ai-search-page-pagination__item--disabled">
            <span class="ai-search-page-pagination__link ai-search-page-pagination__link--prev" aria-disabled="true"><?= rex_i18n::msg('ai_chat_search_page_prev') ?></span>
        </li>
<?php endif; ?>
<?php
$previous = 0;
foreach ($pages as $p):
    if ($previous > 0 && $p - $previous > 1):
        ?>
        <li class="ai-search-page-pagination__item ai-search-page-pagination__item--gap" aria-hidden="true">
            <span class="ai-search-page-pagination__ellipsis">&hellip;</span>
        </li>
<?php
    endif;
    $previous = $p;
    if ($p === $page):
        ?>
        <li class="ai-search-page-pagination__item ai-search-page-pagination__item--current">
            <span class="ai-search-page-pagination__link" aria-current="page"><?= $p ?><span class="ai-search-page-visually-hidden"> (<?= rex_i18n::msg('ai_chat_search_page_current_page') ?>)</span></span>
        </li>
<?php else: ?>
        <li class="ai-search-page-pagination__item">
            <a class="ai-search-page-pagination__link" href="<?= rex_escape($buildUrl($p)) ?>"><span class="ai-search-page-visually-hidden"><?= rex_i18n::msg('ai_chat_search_page_page') ?> </span><?= $p ?></a>
        </li>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($page < $totalPages): ?>
        <li class="ai-search-page-pagination__item">
            <a class="ai-search-page-pagination__link ai-search-page-pagination__link--next" href="<?= rex_escape($buildUrl($page + 1)) ?>" rel="next"><?= rex_i18n::msg('ai_chat_search_page_next') ?></a>
        </li>
<?php else: ?>
        <li class="ai-search-page-pagination__item ai-search-page-pagination__item--disabled">
            <span class="ai-search-page-pagination__link ai-search-page-pagination__link--next" aria-disabled="true"><?= rex_i18n::msg('ai_chat_search_page_next') ?></span>
        </li>
<?php endif; ?>
    </ul>
</nav>
