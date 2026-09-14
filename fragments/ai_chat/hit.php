<?php
/**
 * AI Chat Suchseite - einzelne Trefferzeile.
 *
 * Vars:
 *   hit  array<string, mixed>  Ein Element aus ChatQueryService::searchPaginated()s
 *                              "hits"-Array (id, type, type_label, icon_svg, title,
 *                              url, snippet, updatedate, image_url) (erforderlich).
 *
 * @var rex_fragment $this
 */
$hit = $this->getVar('hit', null);
if (!is_array($hit)) {
    return;
}

$url = trim((string) ($hit['url'] ?? ''));
$title = (string) ($hit['title'] ?? '');
$title = '' !== trim($title) ? $title : rex_i18n::msg('ai_chat_search_page_untitled');
$typeLabel = trim((string) ($hit['type_label'] ?? ''));
$updatedAt = trim((string) ($hit['updatedate'] ?? ''));
// snippet ist bereits htmlspecialchars()-escaped mit <mark> als einzigem erlaubten
// Tag (siehe ChatQueryService::createSnippet()/highlightSnippetSegment()) - roh
// ausgeben, NICHT nochmal escapen (identische Konvention wie in ai-search.js'
// renderHits(), das denselben Wert per innerHTML setzt).
$snippet = (string) ($hit['snippet'] ?? '');
$imageUrl = isset($hit['image_url']) && is_string($hit['image_url']) && '' !== trim($hit['image_url']) ? trim($hit['image_url']) : null;

$typeSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($hit['type'] ?? '')));
?>
<article class="ai-search-page-hit ai-search-page-hit--<?= rex_escape($typeSlug) ?>">
<?php if (null !== $imageUrl): ?>
    <div class="ai-search-page-hit__thumb">
        <img src="<?= rex_escape($imageUrl) ?>" alt="" loading="lazy">
    </div>
<?php endif; ?>
    <div class="ai-search-page-hit__body">
        <h3 class="ai-search-page-hit__title">
<?php if ('' !== $url): ?>
            <a class="ai-search-page-hit__link" href="<?= rex_escape($url) ?>"><?= rex_escape($title) ?></a>
<?php else: ?>
            <?= rex_escape($title) ?>
<?php endif; ?>
        </h3>
        <p class="ai-search-page-hit__meta">
<?php if ('' !== $typeLabel): ?>
            <span class="ai-search-page-hit__type"><?= rex_escape($typeLabel) ?></span>
<?php endif; ?>
<?php if ('' !== $updatedAt): ?>
            <span class="ai-search-page-hit__date"><?= rex_escape($updatedAt) ?></span>
<?php endif; ?>
        </p>
<?php if ('' !== trim($snippet)): ?>
        <p class="ai-search-page-hit__snippet"><?= $snippet ?></p>
<?php endif; ?>
    </div>
</article>
