<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiChat\Service;

use rex_addon;
use rex_yrewrite;
use rex_yrewrite_domain;

/**
 * Kapselt rex_yrewrite::getCurrentDomain() defensiv - siehe
 * https://github.com/FriendsOfREDAXO/ai_chat/issues/1: in bestimmten
 * Multidomain-/Mehrsprachen-Konstellationen (z.B. eine Sprache offline, oder
 * eine Anfrage ohne aufloesbaren aktuellen Artikel) wirft yrewrite dort selbst
 * einen fatalen Fehler ("Call to a member function getId() on null" in
 * yrewrite::getCurrentDomain(), da es intern ungeprueft
 * rex_article::getCurrent()->getId() aufruft), statt wie dokumentiert null
 * zurueckzugeben. Das legte bei einem Nutzer das gesamte Frontend lahm, sobald
 * ai_chat aktiv war - dieser Absturz gehoert zwar zu yrewrite, muss aber hier
 * abgefangen werden, da wir yrewrite nicht kontrollieren/patchen koennen.
 */
final class YrewriteDomainResolver
{
    public static function getCurrentDomain(): ?rex_yrewrite_domain
    {
        if (!rex_addon::get('yrewrite')->isAvailable() || !class_exists(rex_yrewrite::class)) {
            return null;
        }

        try {
            return rex_yrewrite::getCurrentDomain();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
