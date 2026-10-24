<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use PHPUnit\Framework\TestCase;
use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderException;
use Quire\Extend\ExtenderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

abstract class ExtenderTestCase extends TestCase
{
    protected ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.environment', 'test');
    }

    /**
     * @param list<string> $bootOrder
     */
    protected function apply(ExtenderInterface $extender, array $bootOrder = ['acme-reviews']): void
    {
        $extender->extend(new ExtenderContext('acme-reviews', __DIR__, $this->container, $bootOrder));
    }

    protected function assertExtenderFails(ExtenderInterface $extender, string $expectedMessage): void
    {
        try {
            $this->apply($extender);
        } catch (ExtenderException $e) {
            self::assertStringStartsWith('[acme-reviews] ', $e->getMessage());
            self::assertStringContainsString($expectedMessage, $e->getMessage());

            return;
        }

        self::fail('The extender accepted an invalid configuration.');
    }

    /**
     * A parameter the extenders set. (Read through all(), so static analysis doesn't take its type from the
     * application's container.).
     */
    protected function parameter(string $name): mixed
    {
        return $this->container->getParameterBag()->all()[$name] ?? null;
    }
}
