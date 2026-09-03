<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Profile;

/**
 * Datenobjekt für eine Zeile aus `ai_chat_profile`. Reine Werte-Klasse ohne DB-
 * Zugriff (siehe ProfileRepository für CRUD, ProfileResolver für die Auswahl-
 * Logik).
 */
final class ChatProfile
{
    /**
     * @param list<string> $viewerRoles 'visitor'|'editor'|'admin'
     * @param list<string> $domains yrewrite-Domainnamen
     * @param list<int> $clangs rex_clang-IDs
     * @param list<string> $yformProfileIds Profil-Keys aus yform_provider_profiles (Strings, keine IDs)
     * @param list<string> $pdfMediaIds Medienpool-Dateinamen (rex_media.filename), einzeln ausgewaehlte PDFs
     * @param list<int> $pdfCategoryIds Medienpool-Kategorie-IDs, deren PDF-Dateien mit indexiert werden
     * @param list<array{label: string, description: string, is_timely: bool, urls: list<string>}> $sitemapGroups
     *        Benannte Sitemap-Gruppen (leeres label = unbenannt) - siehe pages/profiles.php (Repeater-UI)
     *        und IndexerService::collectProfileTasks()/ai_chat_index.source_label. $description ist ein
     *        optionaler, kurzer Hinweistext (was steckt in diesem Bereich?), der der KI als
     *        Zusatzkontext mitgegeben wird (siehe ChatQueryService::buildSourceLabelDescriptions()).
     *        $is_timely markiert einen Bereich als "enthält aktuelle/zeitkritische Inhalte" (z.B.
     *        News) - genutzt fürs Score-Boosting bei erkannter Aktualitäts-Anfrage UND als
     *        Prompt-Hinweis (siehe ChatQueryService::looksLikeRecencyQuery()/boostTimelyCandidates()).
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $status,
        public readonly int $priority,
        public readonly string $context,
        public readonly array $viewerRoles,
        public readonly ?bool $chatEnabled,
        public readonly ?bool $searchEnabled,
        public readonly string $targetMode,
        public readonly array $domains,
        public readonly array $clangs,
        public readonly bool $useSharedScope,
        public readonly string $extraSource,
        public readonly array $yformProfileIds,
        public readonly array $pdfMediaIds,
        public readonly array $pdfCategoryIds,
        public readonly array $sitemapGroups,
        public readonly ?int $mountpointCategoryId,
        public readonly ?string $customPrompt,
        public readonly string $uiLanguage,
        public readonly ?string $answerLanguage,
        public readonly ?string $greeting,
        public readonly string $addressingMode,
        public readonly string $personalizationMode,
        public readonly int $chatResetCountdown,
        public readonly bool $chatCopyHistory,
        public readonly ?string $themePrimaryColor,
        public readonly ?string $themeHeaderBgColor,
        public readonly ?string $themeChatBgColor,
        public readonly ?string $themeTextColor,
        public readonly ?string $themeBotMessageBgColor,
        public readonly ?string $themeBorderRadius,
        public readonly ?string $themePosition,
        public readonly ?string $themeAvatar,
    ) {
    }

    /**
     * @param array<string, mixed> $row Rohe Zeile aus rex_sql (Spaltennamen wie in install.php)
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            status: (bool) $row['status'],
            priority: (int) $row['priority'],
            context: (string) $row['context'],
            viewerRoles: self::decodeStringList($row['viewer_roles'] ?? null),
            chatEnabled: self::decodeTriStateBool($row['chat_enabled'] ?? null),
            searchEnabled: self::decodeTriStateBool($row['search_enabled'] ?? null),
            targetMode: (string) $row['target_mode'],
            domains: self::decodeStringList($row['domains'] ?? null),
            clangs: self::decodeIntList($row['clangs'] ?? null),
            useSharedScope: (bool) $row['use_shared_scope'],
            extraSource: (string) $row['extra_source'],
            yformProfileIds: self::decodeStringList($row['yform_profile_ids'] ?? null),
            pdfMediaIds: self::decodeCommaList($row['pdf_media_ids'] ?? null),
            pdfCategoryIds: self::decodeIntList($row['pdf_category_ids'] ?? null),
            sitemapGroups: self::decodeSitemapGroups($row['sitemap_groups'] ?? null),
            mountpointCategoryId: null !== $row['mountpoint_category_id'] ? (int) $row['mountpoint_category_id'] : null,
            customPrompt: self::nullableString($row['custom_prompt'] ?? null),
            uiLanguage: (string) $row['ui_language'],
            answerLanguage: self::nullableString($row['answer_language'] ?? null),
            greeting: self::nullableString($row['greeting'] ?? null),
            addressingMode: (string) $row['addressing_mode'],
            personalizationMode: (string) $row['personalization_mode'],
            chatResetCountdown: (int) $row['chat_reset_countdown'],
            chatCopyHistory: (bool) $row['chat_copy_history'],
            themePrimaryColor: self::nullableString($row['theme_primary_color'] ?? null),
            themeHeaderBgColor: self::nullableString($row['theme_header_bg_color'] ?? null),
            themeChatBgColor: self::nullableString($row['theme_chat_bg_color'] ?? null),
            themeTextColor: self::nullableString($row['theme_text_color'] ?? null),
            themeBotMessageBgColor: self::nullableString($row['theme_bot_message_bg_color'] ?? null),
            themeBorderRadius: self::nullableString($row['theme_border_radius'] ?? null),
            themePosition: self::nullableString($row['theme_position'] ?? null),
            themeAvatar: self::nullableString($row['theme_avatar'] ?? null),
        );
    }

    public function matchesContext(string $context): bool
    {
        return 'both' === $this->context || $this->context === $context;
    }

    public function matchesViewerRole(string $role): bool
    {
        return in_array($role, $this->viewerRoles, true);
    }

    public function matchesDomain(?string $domainName): bool
    {
        if (!in_array($this->targetMode, ['domains', 'domains_clangs'], true)) {
            return true;
        }

        return null !== $domainName && in_array($domainName, $this->domains, true);
    }

    public function matchesClang(int $clangId): bool
    {
        if (!in_array($this->targetMode, ['clangs', 'domains_clangs'], true)) {
            return true;
        }

        return in_array($clangId, $this->clangs, true);
    }

    /**
     * Prueft, ob fuer irgendeine denkbare Anfrage (Kontext/Rolle/Domain/Sprache) sowohl
     * dieses als auch das andere Profil zutreffen koennten - fuer den Kollisions-Hinweis in
     * pages/profiles.php (siehe dort). Reine Bereichs-Ueberschneidung, unabhaengig von
     * priority/status - der Aufrufer filtert vorher auf aktivierte Profile und interessiert
     * sich meist nur fuer Ueberschneidungen bei GLEICHER Prioritaet (dort entscheidet sonst
     * unerwartet die niedrigere ID).
     */
    public function overlapsWith(self $other): bool
    {
        $contextOverlap = 'both' === $this->context || 'both' === $other->context || $this->context === $other->context;
        if (!$contextOverlap) {
            return false;
        }

        if ([] === array_intersect($this->viewerRoles, $other->viewerRoles)) {
            return false;
        }

        // Domain/Sprache sind ein reines Frontend-Konzept (siehe matchesDomain()/matchesClang()).
        // "backend"/"both" als context-Wert sind Altlasten (siehe install.php) - Profile
        // steuern seit boot.php's Backend-Chat-Umbau ausschliesslich das Frontend, ein
        // reiner Backend-Kontext wird nirgends mehr aufgeloest und ist damit inert. Bleibt
        // hier trotzdem konservativ auf "ueberschneidet garantiert" statt fuer diesen
        // toten Fall extra Sonderlogik zu pflegen.
        if ('backend' === $this->context || 'backend' === $other->context) {
            return true;
        }

        return $this->domainRangeOverlaps($other) && $this->clangRangeOverlaps($other);
    }

    private function domainRangeOverlaps(self $other): bool
    {
        $thisRestricted = in_array($this->targetMode, ['domains', 'domains_clangs'], true);
        $otherRestricted = in_array($other->targetMode, ['domains', 'domains_clangs'], true);
        if (!$thisRestricted || !$otherRestricted) {
            return true;
        }

        return [] !== array_intersect($this->domains, $other->domains);
    }

    private function clangRangeOverlaps(self $other): bool
    {
        $thisRestricted = in_array($this->targetMode, ['clangs', 'domains_clangs'], true);
        $otherRestricted = in_array($other->targetMode, ['clangs', 'domains_clangs'], true);
        if (!$thisRestricted || !$otherRestricted) {
            return true;
        }

        return [] !== array_intersect($this->clangs, $other->clangs);
    }

    /**
     * Multiauswahl-Spalten werden ueber ein normales rex_form-Mehrfachauswahlfeld
     * gepflegt (pages/profile.edit.php) - REDAXOs rex_form_select_element
     * speichert das unabhaengig vom Formulartyp immer als von Pipes umschlossenen
     * String ("|a|b|", siehe rex_form_element::setValue() im Core), nicht als
     * JSON. Gleiches Parsing-Prinzip wie boot.php's $parseMultiSelectConfig.
     *
     * @return list<string>
     */
    private static function decodeStringList(mixed $raw): array
    {
        $trimmed = trim((string) $raw, '|');

        return '' === $trimmed ? [] : array_values(array_filter(explode('|', $trimmed), static fn (string $v): bool => '' !== $v));
    }

    /**
     * @return list<int>
     */
    private static function decodeIntList(mixed $raw): array
    {
        return array_map('intval', self::decodeStringList($raw));
    }

    /**
     * rex_form_base::addMedialistField() (genutzt fuer pdf_media_ids in pages/profiles.php)
     * speichert komma-getrennt, nicht im sonst ueblichen Pipe-Format - siehe
     * rex_form_widget_medialist_element/rex_var_medialist im mediapool-Addon.
     *
     * @return list<string>
     */
    private static function decodeCommaList(mixed $raw): array
    {
        $trimmed = trim((string) $raw, ',');

        return '' === $trimmed ? [] : array_values(array_filter(explode(',', $trimmed), static fn (string $v): bool => '' !== $v));
    }

    private static function nullableString(mixed $raw): ?string
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        return $raw;
    }

    /**
     * @return list<array{label: string, description: string, is_timely: bool, urls: list<string>}>
     */
    private static function decodeSitemapGroups(mixed $raw): array
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $groups = [];
        foreach ($decoded as $group) {
            if (!is_array($group)) {
                continue;
            }

            $label = trim((string) ($group['label'] ?? ''));
            $description = trim((string) ($group['description'] ?? ''));
            $isTimely = (bool) ($group['is_timely'] ?? false);
            $rawUrls = $group['urls'] ?? [];
            $urls = [];
            if (is_array($rawUrls)) {
                foreach ($rawUrls as $url) {
                    $url = trim((string) $url);
                    if ('' !== $url) {
                        $urls[] = $url;
                    }
                }
            }

            if ([] === $urls) {
                continue;
            }

            $groups[] = ['label' => $label, 'description' => $description, 'is_timely' => $isTimely, 'urls' => $urls];
        }

        return $groups;
    }

    /**
     * Tri-State: null = geerbt/globale Einstellung entscheidet, sonst der explizite Wert.
     * Spalte ist varchar statt tinyint, damit "geerbt" ein echtes leeres Feld sein kann.
     */
    private static function decodeTriStateBool(mixed $raw): ?bool
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        return '1' === $raw;
    }
}
