<?php

declare(strict_types=1);

namespace Quire\Database;

use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orders migrations so that what a migration depends on always runs first: Quire core, then
 * extensions in boot order, then everything else (the application's own migrations). Within each
 * group, migrations run in version order.
 *
 * Doctrine's default order compares full class names alphabetically, which would run an extension
 * called "Acme" before Quire core on a fresh install.
 *
 * @internal
 */
final readonly class MigrationOrder implements Comparator
{
    /** Parameter listing the registered migration namespaces, in order */
    public const string PARAMETER = 'quire.migrations.namespaces';

    /** @var list<string> */
    private array $namespaces;

    /**
     * @param list<array{namespace: string, owner: string}> $namespaces
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        array $namespaces,
    ) {
        $this->namespaces = array_column($namespaces, 'namespace');
    }

    public function compare(Version $a, Version $b): int
    {
        return [$this->rank($a), (string) $a] <=> [$this->rank($b), (string) $b];
    }

    /**
     * Position of the version's namespace in the order; unknown namespaces go last.
     */
    private function rank(Version $version): int
    {
        foreach ($this->namespaces as $rank => $namespace) {
            if (str_starts_with((string) $version, $namespace.'\\')) {
                return $rank;
            }
        }

        return \count($this->namespaces);
    }
}
