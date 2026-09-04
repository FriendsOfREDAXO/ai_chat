<?php

namespace FriendsOfRedaxo\AiChat\Service;

class SystemToolService
{
    /**
     * Returns a human-readable string of the current date, day of week and time.
     * Useful for AI context to answer questions about opening hours.
     */
    public static function getDateTimeContext(): string
    {
        $days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
        $now = time();
        $dayName = $days[date('w', $now)];
        $date = date('d.m.Y', $now);
        $time = date('H:i', $now);

        return "Aktuelles Datum: $dayName, der $date. Aktuelle Uhrzeit: $time Uhr.";
    }
}
