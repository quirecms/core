<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Database\MigrationOrder;
use Quire\Extend\Migrations;

final class MigrationsTest extends ExtenderTestCase
{
    public function testRegistersThePathAndRecordsTheOwner(): void
    {
        $this->apply(new Migrations('\\Acme\\Reviews\\Migrations\\', __DIR__));

        // Quire's migrations are kept apart from the application's: nothing is added to its configuration.
        self::assertSame([], $this->container->getExtensionConfig('doctrine_migrations'));
        self::assertSame([['namespace' => 'Acme\Reviews\Migrations', 'directory' => __DIR__, 'owner' => 'acme-reviews']], $this->container->getParameterBag()->all()[MigrationOrder::PARAMETER]);
    }

    public function testRejectsInvalidInput(): void
    {
        $this->assertExtenderFails(new Migrations('Acme\\Reviews Migrations', __DIR__), 'not a valid PHP namespace');
        $this->assertExtenderFails(new Migrations('Acme\\Reviews\\Migrations', __DIR__.'/missing'), 'doesn\'t exist');
    }

    public function testNamespacesMustBeUnique(): void
    {
        $this->apply(new Migrations('Acme\\Reviews\\Migrations', __DIR__));

        $this->assertExtenderFails(new Migrations('Acme\\Reviews\\Migrations', __DIR__), 'The namespace is already used by "acme-reviews".');
    }
}
