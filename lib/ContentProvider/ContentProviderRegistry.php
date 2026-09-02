<?php

namespace FriendsOfRedaxo\AiChat\ContentProvider;

use rex_addon;
use rex_addon_interface;
use rex_extension;
use rex_extension_point;

class ContentProviderRegistry
{
    /**
     * @var array<string, ContentProviderInterface>
     */
    private array $providers = [];

    public function __construct()
    {
        $providers = [
            'forcal' => new ForcalContentProvider(),
            'yform' => new YformContentProvider(),
            'mediapool' => new MediaPoolContentProvider(),
        ];

        $knowledgebaseProviderClass = 'FriendsOfREDAXO\\Knowledgebase\\ContentProvider\\KnowledgebaseContentProvider';
        if (rex_addon::exists('knowledgebase')
            && rex_addon::get('knowledgebase')->isAvailable()
            && class_exists($knowledgebaseProviderClass)
        ) {
            /** @var object $provider */
            $provider = new $knowledgebaseProviderClass();
            if ($provider instanceof ContentProviderInterface) {
                $providers[$provider->getKey()] = $provider;
            }
        }

        $subject = rex_extension::registerPoint(new rex_extension_point(
            'AI_CHAT_CONTENT_PROVIDERS',
            $providers,
            ['registry' => $this],
        ));

        // Dritt-Addons koennen ueber die Extension Point AI_CHAT_CONTENT_PROVIDERS beliebige
        // Daten zurueckgeben - PHPStan vertraut hier dem generischen rex_extension_point<T>-Typ,
        // der zur Laufzeit von einem Listener verletzt werden kann, daher trotz "immer wahr" nicht entfernen.
        if (is_array($subject)) { // @phpstan-ignore function.alreadyNarrowedType
            foreach ($subject as $key => $provider) {
                if (!$provider instanceof ContentProviderInterface) { // @phpstan-ignore instanceof.alwaysTrue
                    continue;
                }

                $normalizedKey = trim(is_string($key) ? $key : $provider->getKey()); // @phpstan-ignore function.alreadyNarrowedType
                if ($normalizedKey === '') {
                    continue;
                }

                $providers[$normalizedKey] = $provider;
            }
        }

        $this->providers = $providers;
    }

    /**
     * @return array<string, ContentProviderInterface>
     */
    public function getAll(): array
    {
        return $this->providers;
    }

    public function getProvider(string $key): ?ContentProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    public function getSearchIconSvgForSourceType(rex_addon_interface $addon, string $sourceType): string
    {
        $sourceType = trim($sourceType);
        if ($sourceType === '') {
            return '';
        }

        foreach ($this->getEnabledProviders($addon) as $provider) {
            if (!in_array($sourceType, $provider->getSupportedSourceTypes(), true)) {
                continue;
            }

            $svg = trim($provider->getSearchIconSvg($sourceType));
            if ($svg !== '') {
                return $svg;
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    public function getSourceTypeLabels(rex_addon_interface $addon): array
    {
        $labels = [];

        foreach ($this->getEnabledProviders($addon) as $provider) {
            $providerLabels = $provider->getSourceTypeLabels();

            foreach ($providerLabels as $sourceType => $label) {
                $sourceType = trim((string) $sourceType);
                $label = trim((string) $label);
                if ($sourceType === '' || $label === '') {
                    continue;
                }

                $labels[$sourceType] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param list<string> $sourceTypes
     * @return list<string>
     */
    public function getPromptInstructionsForSourceTypes(rex_addon_interface $addon, array $sourceTypes): array
    {
        $instructions = [];
        $enabledProviders = $this->getEnabledProviders($addon);

        foreach ($enabledProviders as $provider) {
            $supported = $provider->getSupportedSourceTypes();
            $matches = array_intersect($supported, $sourceTypes);
            if ($matches === []) {
                continue;
            }

            $instruction = trim($provider->getPromptInstruction());
            if ($instruction !== '') {
                $instructions[] = $instruction;
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($instructions));

        return $unique;
    }

    /**
     * @return list<ContentProviderInterface>
     */
    public function getEnabledProviders(rex_addon_interface $addon): array
    {
        $configured = $addon->getConfig('index_content_providers');
        $enabledKeys = [];

        if (is_array($configured)) {
            $enabledKeys = array_values(array_filter(array_map('strval', $configured)));
        } elseif (is_string($configured) && $configured !== '') {
            // rex_config_form multiple select can be stored as |a|b| or comma separated fallback.
            if (str_contains($configured, '|')) {
                $enabledKeys = array_values(array_filter(explode('|', $configured)));
            } else {
                $parts = preg_split('/[\s,]+/', $configured);
                $enabledKeys = array_values(array_filter(array_map('strval', is_array($parts) ? $parts : [])));
            }
        }

        if ($enabledKeys === []) {
            return [];
        }

        $enabled = [];
        foreach ($enabledKeys as $key) {
            $provider = $this->getProvider($key);
            if ($provider instanceof ContentProviderInterface && $provider->isAvailable()) {
                $enabled[] = $provider;
            }
        }

        return $enabled;
    }
}
