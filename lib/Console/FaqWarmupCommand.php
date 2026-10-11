<?php

namespace FriendsOfRedaxo\AiChat\Console;

use FriendsOfRedaxo\AiChat\Service\ChatQueryService;
use rex_console_command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * FAQ-Vorcaching per CLI (`php redaxo/bin/console ai_chat:faq-warmup`) - dieselbe Logik wie
 * der Knopf im Backend (Api\ChatIndex, ChatQueryService::warmupFaqCache()). Fuer Deployments
 * und Cronjobs: eine vollstaendige Neuindizierung leert den Antwort-Cache, die vorbereiteten
 * FAQ-Antworten muessen danach neu erzeugt werden.
 */
class FaqWarmupCommand extends rex_console_command
{
    protected function configure(): void
    {
        $this->setDescription('Erzeugt die FAQ-Antworten aller Profile mit FAQ-Vorcaching (bereits vorhandene werden übersprungen)')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Höchstens so viele Fragen (0 = alle)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getStyle($input, $output);
        @set_time_limit(0);

        try {
            $stats = (new ChatQueryService())->warmupFaqCache((int) $input->getOption('max'));
        } catch (\RuntimeException $e) {
            $io->warning($e->getMessage());
            return 0;
        }

        foreach ($stats['processed_questions'] ?? [] as $question) {
            $io->writeln('✓ ' . $question);
        }
        foreach ($stats['skipped_questions'] ?? [] as $question) {
            $io->writeln('– ' . $question . ' (schon vorhanden oder keine Antwort)');
        }
        foreach ($stats['error_details'] as $error) {
            $io->error($error);
        }
        $io->writeln(sprintf('Vorbereitet: %d, erzeugt: %d, übersprungen: %d, Fehler: %d', $stats['prepared'], $stats['processed'], $stats['skipped'], $stats['errors']));

        return $stats['errors'] > 0 ? 1 : 0;
    }
}
