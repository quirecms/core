<?php

declare(strict_types=1);

namespace Quire\Tests\Database;

use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\TestCase;
use Quire\Database\MigrationOrder;

final class MigrationOrderTest extends TestCase
{
    public function testCoreThenExtensionsInBootOrderThenTheRest(): void
    {
        $order = new MigrationOrder([
            ['namespace' => 'Quire\Migrations', 'owner' => 'core'],
            ['namespace' => 'Zed\Comments\Migrations', 'owner' => 'zed-comments'],
            ['namespace' => 'Acme\Reviews\Migrations', 'owner' => 'acme-reviews'],
        ]);

        $versions = [
            'DoctrineMigrations\Version20260101000000',
            'Acme\Reviews\Migrations\Version20250101000000',
            'Quire\Migrations\Version20261022090000',
            'Zed\Comments\Migrations\Version20270101000000',
            'Quire\Migrations\Version20261021120000',
            'Acme\Reviews\Migrations\Version20240101000000',
        ];

        $sorted = array_map(static fn (string $v): Version => new Version($v), $versions);
        usort($sorted, $order->compare(...));

        self::assertSame([
            'Quire\Migrations\Version20261021120000',
            'Quire\Migrations\Version20261022090000',
            'Zed\Comments\Migrations\Version20270101000000',
            'Acme\Reviews\Migrations\Version20240101000000',
            'Acme\Reviews\Migrations\Version20250101000000',
            'DoctrineMigrations\Version20260101000000',
        ], array_map(strval(...), $sorted));
    }

    public function testNamespacePrefixesMatchWholeSegments(): void
    {
        $order = new MigrationOrder([['namespace' => 'Acme\Reviews', 'owner' => 'acme-reviews']]);

        // "Acme\ReviewsExtra" is not inside "Acme\Reviews", so it sorts with the unknown namespaces.
        self::assertSame(-1, $order->compare(new Version('Acme\Reviews\Version2'), new Version('Acme\ReviewsExtra\Version1')));
    }
}
