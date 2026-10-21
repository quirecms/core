<?php

declare(strict_types=1);

namespace Quire\Settings;

use Quire\Settings\Exception\UnknownSettingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every setting declared by core, the enabled extensions and the site.
 *
 * @api
 */
final readonly class SettingsSchema
{
    public const string PARAMETER = 'quire.settings.definitions';

    /** @var array<string, SettingDefinition> */
    private array $definitions;

    /**
     * @param array<string, array<string, mixed>> $definitions Compiled by {@see \Quire\Extend\Settings}
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        array $definitions,
    ) {
        $parsed = array_map(SettingDefinition::fromArray(...), $definitions);
        ksort($parsed);
        $this->definitions = $parsed;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @throws UnknownSettingException
     */
    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key] ?? throw UnknownSettingException::forKey($key, array_keys($this->definitions));
    }

    /**
     * @return array<string, SettingDefinition> Keyed and sorted by key; optionally only keys starting with $prefix
     */
    public function all(string $prefix = ''): array
    {
        return array_filter($this->definitions, static fn (string $key): bool => str_starts_with($key, $prefix), \ARRAY_FILTER_USE_KEY);
    }
}
