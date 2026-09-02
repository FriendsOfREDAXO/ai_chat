<?php

namespace FriendsOfRedaxo\AiChat\ContentProvider;

interface ContentProviderInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    /**
     * @return list<string>
     */
    public function getSupportedSourceTypes(): array;

    public function getPromptInstruction(): string;

    /**
     * @return array<string, string>
     */
    public function getSourceTypeLabels(): array;

    public function getSearchIconSvg(string $sourceType): string;

    public function isAvailable(): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function collectTasks(): array;

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>|null
     */
    public function prepareDocument(array $task): ?array;
}
