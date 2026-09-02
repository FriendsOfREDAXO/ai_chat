<?php

namespace FriendsOfRedaxo\AiChat\Cronjob;

use FriendsOfRedaxo\AiChat\Service\IndexerService;
use rex_cronjob;
use rex_i18n;

class IndexCronjob extends rex_cronjob
{
    public function execute(): bool
    {
        // Ohne diesen Reset kann ein Cronjob-Lauf mit vielen/langsamen
        // Embeddings am PHP-eigenen max_execution_time (typischerweise 30-60s
        // bei web-getriggerten Pseudo-Cronjobs) hart abbrechen, BEVOR
        // setMessage()/return jemals erreicht wird - ein Timeout-Kill ist
        // keine catchbare Exception, der Lauf würde also lautlos "verschwinden"
        // statt wenigstens eine Fehlermeldung im Cronjob-Log zu hinterlassen.
        @set_time_limit(0);

        try {
            $service  = new IndexerService();
            $mode     = $this->getParam('mode', 'incremental');
            $github   = (bool) $this->getParam('update_github', 1);
            $maxItems = (int) $this->getParam('max_items', 0);
            if (!in_array($maxItems, [0, 10, 25, 50, 100, 250], true)) {
                $maxItems = 0;
            }
            $maxItemsLabel = $maxItems > 0 ? (string) $maxItems : 'unbegrenzt';

            // 1. Optionally update GitHub sources
            if ($github) {
                $service->updateGithubSources();
            }

            // 2. Full re-index or incremental sync
            if ($mode === 'full') {
                $service->clearIndex();
                $tasks     = $service->collectTasks();
                $processed = 0;
                $errors    = 0;

                foreach ($tasks as $task) {
                    if ($maxItems > 0 && $processed >= $maxItems) {
                        break;
                    }
                    try {
                        $service->processTask($task);
                        ++$processed;
                    } catch (\Throwable $e) {
                        ++$errors;
                        \rex_logger::logException($e);
                    }
                }

                $skipped = count($tasks) - $processed - $errors;
                $this->setMessage(sprintf(
                    'Vollständige Neu-Indexierung (Limit: %s). Verarbeitet: %d, Übersprungen: %d, Fehler: %d',
                    $maxItemsLabel,
                    $processed,
                    max(0, $skipped),
                    $errors
                ));
            } else {
                // Incremental: only process changed/new items
                $stats = $service->sync($maxItems);

                $this->setMessage(sprintf(
                    'Inkrementelle Synchronisation (Limit: %s). Verarbeitet: %d, Übersprungen: %d, Fehler: %d',
                    $maxItemsLabel,
                    $stats['processed'],
                    $stats['skipped'],
                    $stats['errors']
                ));
            }

            return true;
        } catch (\Throwable $e) {
            $this->setMessage($e->getMessage());
            \rex_logger::logException($e);
            return false;
        }
    }

    public function getTypeName(): string
    {
        return rex_i18n::msg('ai_chat_cronjob_index_name');
    }

    public function getParamFields(): array
    {
        return [
            [
                'label'   => rex_i18n::msg('ai_chat_cronjob_mode_label'),
                'name'    => 'mode',
                'type'    => 'select',
                'options' => [
                    'incremental' => rex_i18n::msg('ai_chat_cronjob_mode_incremental'),
                    'full'        => rex_i18n::msg('ai_chat_cronjob_mode_full'),
                ],
                'default' => 'incremental',
            ],
            [
                'label'   => rex_i18n::msg('ai_chat_cronjob_update_github_label'),
                'name'    => 'update_github',
                'type'    => 'checkbox',
                'options' => [1 => rex_i18n::msg('ai_chat_cronjob_update_github_yes')],
                'default' => 1,
            ],
            [
                'label'  => rex_i18n::msg('ai_chat_cronjob_max_items_label'),
                'name'   => 'max_items',
                'type'   => 'select',
                'notice' => rex_i18n::msg('ai_chat_cronjob_max_items_notice'),
                'options' => [
                    0   => rex_i18n::msg('ai_chat_cronjob_max_items_unlimited'),
                    10  => '10',
                    25  => '25',
                    50  => '50',
                    100 => '100',
                    250 => '250',
                ],
                'default' => 0,
            ],
        ];
    }
}

