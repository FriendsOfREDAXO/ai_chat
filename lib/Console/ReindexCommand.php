<?php

namespace FriendsOfRedaxo\AiChat\Console;

use FriendsOfRedaxo\AiChat\Service\IndexerService;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Echter CLI-Weg für eine vollständige Neuindizierung (`php redaxo/bin/console
 * ai_chat:reindex`) - unabhängig vom Hintergrundlauf, den der "Start"-Button
 * im Backend per shell_exec()+curl/wget anstößt (siehe Api\ChatIndex::
 * handleStartBackground()). Beide nutzen dieselbe IndexerService::runFull()-
 * Pipeline, nur die Fortschrittsausgabe unterscheidet sich (hier direkt auf
 * die Konsole statt in die State-Datei für den Browser-Poll).
 */
class ReindexCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this->setDescription('Baut den ai_chat-Index vollständig neu auf (GitHub-Quellen aktualisieren, Index leeren, alle Quellen neu einlesen)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        $io->title('AI Chat Neuindizierung');

        $service = new IndexerService();
        $result = $service->runFull(static function (array $progress) use ($io): void {
            if ($progress['current_label'] === null) {
                return;
            }
            $io->writeln(sprintf(
                '[%d/%d] %s',
                $progress['processed'] + 1,
                $progress['total'],
                $progress['current_label'],
            ));
        });

        $io->newLine();
        $io->writeln(sprintf(
            'Verarbeitet: %d von %d Aufgaben, %d Abschnitte indiziert, %d Fehler.',
            $result['processed'],
            $result['total'],
            $result['chunks'],
            $result['errors'],
        ));

        if ($result['errors'] > 0) {
            foreach ($result['error_log'] as $error) {
                $io->error($error['label'] . ': ' . $error['error']);
            }
            return 1;
        }

        $io->success('Neuindizierung abgeschlossen.');
        return 0;
    }
}
