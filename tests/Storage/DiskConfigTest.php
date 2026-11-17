<?php

declare(strict_types=1);

namespace Quire\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Quire\Storage\Internal\DiskConfig;

/**
 * Where a disk's files are, for people and tools (quire:storage:list, quire:about): never with the
 * credentials an endpoint's address could carry.
 */
final class DiskConfigTest extends TestCase
{
    public function testItsLocationNamesNoCredentials(): void
    {
        $location = static fn (array $config): string => DiskConfig::fromArray('photos', ['adapter' => 's3', 'bucket' => 'jane-files', 'public' => true, ...$config])->location();

        self::assertSame('s3: jane-files/photos at https://s3.example.com', $location(['prefix' => 'photos/', 'endpoint' => 'https://s3.example.com']));
        self::assertSame('s3: jane-files at https://s3.example.com:9000/path', $location(['endpoint' => 'https://AKIA123:sEcReT@s3.example.com:9000/path']));
        self::assertSame('s3: jane-files at http://s3.example.com', $location(['endpoint' => 'http://only-a-user@s3.example.com']));
        self::assertSame('s3: jane-files', $location([]));
    }
}
