<?php

declare(strict_types=1);

namespace Quire\Extension;

/**
 * What an extension (or theme) adds to the site, as {@see ExtensionContributions} finds it in what the
 * site was built with. Names and IDs, untranslated.
 *
 * @api
 */
final readonly class Contributions
{
    /**
     * @param list<string>       $contentTypes Content types it registers, by ID
     * @param list<string>       $fields       Fields it adds to other extensions' types, as "<type>.<field>"
     * @param list<string>       $fieldTypes   Field types, by name
     * @param list<string>       $settings     Settings, by key
     * @param list<string>       $preferences  People's preferences, by name
     * @param list<string>       $abilities    Abilities, by name
     * @param list<string>       $roles        Roles, by name
     * @param array<string, int> $routes       Pages (routes that can be opened, GET), how many in each group ("site", "admin"); a directory of controllers counts once
     * @param list<string>       $checks       Content checks, by ID
     * @param list<string>       $actions      Actions of the catalogue, by ID
     * @param list<string>       $scheduled    Scheduled tasks, by name
     * @param bool               $migrations   Whether it keeps data of its own in the database
     */
    public function __construct(
        public array $contentTypes = [],
        public array $fields = [],
        public array $fieldTypes = [],
        public array $settings = [],
        public array $preferences = [],
        public array $abilities = [],
        public array $roles = [],
        public array $routes = [],
        public array $checks = [],
        public array $actions = [],
        public array $scheduled = [],
        public bool $migrations = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === array_filter(get_object_vars($this), static fn (mixed $value): bool => [] !== $value && false !== $value);
    }
}
