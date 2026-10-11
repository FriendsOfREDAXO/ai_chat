<?php

namespace FriendsOfRedaxo\AiChat\Console;

use FriendsOfRedaxo\AiChat\Service\IndexerService;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inkrementeller Abgleich per CLI (`php redaxo/bin/console ai_chat:sync`): neue und geänderte
 * Inhalte einlesen, verwaiste Einträge entfernen - ohne den ganzen Index neu aufzubauen
 * (IndexerService::sync(), wie der Cronjob im Modus „inkrementell“). Für Deployments, z. B.
 * nach geänderten Quellen eines Profils.
 */
class SyncCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this->setDescription('Gleicht den ai_chat-Index ab: neue/geänderte Inhalte einlesen, verwaiste Einträge entfernen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        @set_time_limit(0);
        $stats = (new IndexerService())->sync();
        $io->writeln(sprintf(
            'Aufgaben: %d, eingelesen: %d, unverändert: %d, entfernt: %d Abschnitte, Fehler: %d',
            $stats['total'],
            $stats['processed'],
            $stats['skipped'],
            (int) ($stats['removed'] ?? 0),
            $stats['errors'],
        ));
        foreach (array_slice($stats['error_log'], 0, 10) as $error) {
            $io->error($error['label'] . ': ' . $error['error']);
        }

        return $stats['errors'] > 0 ? 1 : 0;
    }
}
