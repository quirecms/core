<?php

declare(strict_types=1);

namespace Quire\Database;

use Doctrine\DBAL\Schema\AbstractAsset;

/**
 * Hides Quire's schema from Doctrine's schema tools on the application's connection.
 *
 * Quire's tables are created by Quire's migrations, not from ORM mappings. Without this filter,
 * "doctrine:migrations:diff" or "doctrine:schema:update" in the application would see them as unknown
 * and generate SQL that drops them.
 *
 * @internal
 */
final class SchemaAssetFilter
{
    public const string TAG = 'doctrine.dbal.schema_filter';

    /**
     * @param string|AbstractAsset<\Doctrine\DBAL\Schema\Name> $asset
     */
    public function __invoke(string|AbstractAsset $asset): bool
    {
        $name = $asset instanceof AbstractAsset ? $asset->getName() : $asset;

        return DatabaseSchema::NAME !== $name && !str_starts_with($name, DatabaseSchema::NAME.'.');
    }
}
