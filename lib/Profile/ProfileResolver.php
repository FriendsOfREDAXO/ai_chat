<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Profile;

use rex_extension;
use rex_extension_point;
use rex_user;
use rex_yrewrite_domain;

/**
 * Wählt pro Anfrage genau EIN Profil aus (Routing), nicht mehrere gleichzeitige
 * Widgets auf einer Seite. `getEnabled()` liefert bereits nach priority DESC,
 * id ASC sortiert - der erste Treffer in der gefilterten Liste gewinnt.
 */
class ProfileResolver
{
    public function __construct(
        private readonly ProfileRepository $repository = new ProfileRepository(),
    ) {
    }

    public function resolveForFrontend(?rex_yrewrite_domain $domain, int $clangId, ?rex_user $backendUser): ?ChatProfile
    {
        $role = self::deriveRole($backendUser);
        $domainName = $domain?->getName();

        $candidates = array_values(array_filter(
            $this->repository->getEnabled(),
            static fn (ChatProfile $profile): bool => $profile->matchesContext('frontend')
                && $profile->matchesViewerRole($role)
                && $profile->matchesDomain($domainName)
                && $profile->matchesClang($clangId),
        ));

        return $this->applyExtensionPoint($candidates, 'frontend')[0] ?? null;
    }

    private static function deriveRole(?rex_user $user): string
    {
        if (null === $user) {
            return 'visitor';
        }

        return $user->isAdmin() ? 'admin' : 'editor';
    }

    /**
     * Erlaubt Dritt-Addons, die Kandidatenliste vor der endgültigen Auswahl zu
     * filtern/umzusortieren (z.B. eigene Kundengruppen-Logik über Domain/Sprache
     * hinaus), ohne den Resolver zu patchen.
     *
     * @param list<ChatProfile> $candidates
     * @return list<ChatProfile>
     */
    private function applyExtensionPoint(array $candidates, string $context): array
    {
        $subject = rex_extension::registerPoint(new rex_extension_point(
            'AI_CHAT_PROFILE_CANDIDATES',
            $candidates,
            ['context' => $context],
        ));

        // Ein Dritt-Addon-Listener an diesem Extension Point koennte theoretisch einen
        // Nicht-Array oder eine Nicht-Liste zurueckgeben - PHPStan vertraut hier dem
        // generischen rex_extension_point<T>-Typ, der zur Laufzeit verletzt werden kann.
        return is_array($subject) ? array_values($subject) : $candidates; // @phpstan-ignore function.alreadyNarrowedType, arrayValues.list
    }
}
