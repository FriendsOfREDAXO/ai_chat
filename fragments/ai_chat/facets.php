<?php
/**
 * AI Chat Suchseite - Typ-/Label-Filter-Chips. Jeder Chip ist ein Link, der
 * sich selbst an-/abwaehlt und dabei Suchbegriff, Datumsbereich und den
 * jeweils anderen Filter unveraendert laesst; ein Filterwechsel setzt "page"
 * zurueck auf 1 (eine gefilterte Trefferliste hat i.d.R. weniger Seiten).
 *
 * Zwei optisch bewusst unterschiedene Gruppen (siehe Klassen "--type"/"--label"):
 * "Typ" (source_type, z.B. Artikel/PDF) und "Bereich" (benannte Sitemap-Gruppen,
 * z.B. "News") sind unabhaengige Facetten - ein Treffer kann in beiden
 * gleichzeitig auftauchen.
 *
 * Vars:
 *   sourceTypes  list<array{value:string,label:string,count:int,active:bool}>  Default: [].
 *   labels       list<array{value:string,label:string,count:int,active:bool,description:?string}>  Default: [].
 *   baseUrl      string  Basis-URL fuer Links. Default: aktuelle Artikel-URL.
 *
 * @var rex_fragment $this
 */
$sourceTypes = $this->getVar('sourceTypes', []);
$labels = $this->getVar('labels', []);
if (!is_array($sourceTypes)) {
    $sourceTypes = [];
}
if (!is_array($labels)) {
    $labels = [];
}
if ([] === $sourceTypes && [] === $labels) {
    return;
}

$baseUrl = (string) $this->getVar('baseUrl', rex_getUrl());

parse_str((string) rex_server('QUERY_STRING', 'string', ''), $currentParams);

/**
 * @param array<string, mixed> $params
 */
$buildUrl = static function (array $params) use ($baseUrl): string {
    // Separator bewusst '&', siehe pagination.php fuer die ausfuehrliche Begruendung
    // (arg_separator.output ist hier '&amp;', Doppel-Escaping wuerde Links brechen).
    $qs = http_build_query($params, '', '&');

    return $baseUrl . ('' !== $qs ? '?' . $qs : '');
};

/**
 * @param array<string, mixed> $currentParams
 * @return list<string>
 */
$currentValuesFor = static function (array $currentParams, string $paramName): array {
    $raw = $currentParams[$paramName] ?? null;
    $list = is_array($raw) ? $raw : (null !== $raw && '' !== $raw ? [$raw] : []);

    return array_values(array_filter(array_map('strval', $list), static fn (string $v): bool => '' !== $v));
};

/**
 * "Aktiv" ist hier bewusst NICHT das vom Server mitgelieferte filter.active
 * (das bedeutet dort "wird gerade mit angezeigt", was bei leerem Filter fuer
 * JEDEN Typ true ist - identische Semantik wie im JS-Overlay, siehe
 * renderToolbar() in ai-search.js, das dieses Feld ebenfalls ignoriert und
 * stattdessen seinen eigenen selectedTypes-Zustand gegen den Wert prueft).
 * Hier ist die Quelle der Wahrheit die tatsaechlich in der URL gesetzte
 * Filterauswahl.
 *
 * @param array<string, mixed> $currentParams
 * @return array<string, mixed>
 */
$toggleParam = static function (array $currentParams, string $paramName, string $value, array $currentValues): array {
    if (in_array($value, $currentValues, true)) {
        $next = array_values(array_filter($currentValues, static fn (string $v): bool => $v !== $value));
    } else {
        $next = $currentValues;
        $next[] = $value;
    }

    $newParams = $currentParams;
    if ([] === $next) {
        unset($newParams[$paramName]);
    } else {
        $newParams[$paramName] = $next;
    }
    unset($newParams['page']);

    return $newParams;
};
?>
<div class="ai-search-page-facets">
<?php if ([] !== $sourceTypes): ?>
    <div class="ai-search-page-facet-group ai-search-page-facet-group--type">
        <span class="ai-search-page-facet-group__label"><?= rex_i18n::msg('ai_chat_search_page_filter_type_label') ?></span>
        <ul class="ai-search-page-facet-group__values">
<?php foreach ($sourceTypes as $filter): ?>
<?php
    if (!is_array($filter)) {
        continue;
    }
    $value = (string) ($filter['value'] ?? '');
    if ('' === $value) {
        continue;
    }
    $label = (string) ($filter['label'] ?? $value);
    $count = (int) ($filter['count'] ?? 0);
    $currentTypes = $currentValuesFor($currentParams, 'type');
    $active = in_array($value, $currentTypes, true);
    $url = $buildUrl($toggleParam($currentParams, 'type', $value, $currentTypes));
    $class = 'ai-search-page-chip ai-search-page-chip--type' . ($active ? ' is-active' : '');
    ?>
            <li>
                <a class="<?= $class ?>" href="<?= rex_escape($url) ?>"<?= $active ? ' aria-current="true"' : '' ?>><?= rex_escape($label) ?> (<?= $count ?>)</a>
            </li>
<?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if ([] !== $labels): ?>
    <div class="ai-search-page-facet-group ai-search-page-facet-group--label">
        <span class="ai-search-page-facet-group__label"><?= rex_i18n::msg('ai_chat_search_page_filter_area_label') ?></span>
        <ul class="ai-search-page-facet-group__values">
<?php foreach ($labels as $filter): ?>
<?php
    if (!is_array($filter)) {
        continue;
    }
    $value = (string) ($filter['value'] ?? '');
    if ('' === $value) {
        continue;
    }
    $label = (string) ($filter['label'] ?? $value);
    $count = (int) ($filter['count'] ?? 0);
    $currentLabels = $currentValuesFor($currentParams, 'label');
    $active = in_array($value, $currentLabels, true);
    $description = isset($filter['description']) && is_string($filter['description']) ? $filter['description'] : null;
    $url = $buildUrl($toggleParam($currentParams, 'label', $value, $currentLabels));
    $class = 'ai-search-page-chip ai-search-page-chip--label' . ($active ? ' is-active' : '');
    ?>
            <li>
                <a class="<?= $class ?>" href="<?= rex_escape($url) ?>"<?= $active ? ' aria-current="true"' : '' ?><?= null !== $description ? ' title="' . rex_escape($description, 'html_attr') . '"' : '' ?>><?= rex_escape($label) ?> (<?= $count ?>)</a>
            </li>
<?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
</div>
