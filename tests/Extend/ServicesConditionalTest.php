<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\Conditional;
use Quire\Extend\Routes;
use Quire\Extend\Services;
use Quire\Extension\Boot\ExtensionPasses;
use Quire\Tests\Extend\Fixtures\FixtureCommand;
use Quire\Tests\Extend\Fixtures\InvokableController;
use Quire\Tests\Extend\Fixtures\NotInvokable;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ServicesConditionalTest extends ExtenderTestCase
{
    public function testAutoloadRegistersAutowiredAutoconfiguredServices(): void
    {
        $this->apply(new Services()->autoload('Quire\\Tests\\Extend\\Fixtures\\', __DIR__.'/Fixtures', exclude: [__DIR__.'/Fixtures/NotInvokable.php']));

        self::assertTrue($this->container->getDefinition(FixtureCommand::class)->isAutowired());
        self::assertTrue($this->container->getDefinition(FixtureCommand::class)->isAutoconfigured());
        // Symfony keeps excluded classes as abstract definitions tagged "container.excluded", never as services.
        self::assertTrue($this->container->getDefinition(NotInvokable::class)->hasTag('container.excluded'));
    }

    public function testLoadReadsServiceFiles(): void
    {
        $file = sys_get_temp_dir().'/quire-services-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($file, <<<'PHP'
            <?php
            return static function (Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $c): void {
                $c->services()->set('acme.reviews.clock', \stdClass::class);
            };
            PHP);

        try {
            $this->apply(new Services()->load($file));
        } finally {
            unlink($file);
        }

        self::assertTrue($this->container->hasDefinition('acme.reviews.clock'));
    }

    public function testCompilerPassesAreAdded(): void
    {
        $pass = new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
            }
        };

        $this->apply(new Services()->compilerPass($pass));

        self::assertSame([$pass], ExtensionPasses::of($this->container, PassConfig::TYPE_BEFORE_OPTIMIZATION), 'For QuireBundle\'s runner: added to the compiling container, it would never run.');
    }

    public function testAnExtensionsPassRunsBeforeQuiresChecks(): void
    {
        $pass = new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
            }
        };

        foreach ([PassConfig::TYPE_REMOVE, PassConfig::TYPE_AFTER_REMOVING] as $type) {
            try {
                new Services()->compilerPass($pass, $type);
                self::fail(\sprintf('A pass at "%s" was taken.', $type));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Quire checks the container after those', $e->getMessage());
            }
        }
    }

    public function testServicesRejectInvalidInput(): void
    {
        $this->assertExtenderFails(new Services()->autoload('Acme', __DIR__), 'The namespace must end with a backslash.');
        $this->assertExtenderFails(new Services()->autoload('Acme\\', __DIR__.'/missing'), 'doesn\'t exist');
        $this->assertExtenderFails(new Services()->load(__DIR__.'/missing.php'), 'The file doesn\'t exist.');
        $this->assertExtenderFails(new Services()->load(__FILE__.'.txt'), 'doesn\'t exist');
    }

    public function testConditionalAppliesOnlyWhenTheConditionHolds(): void
    {
        $this->apply(new Conditional()
            ->whenExtensionEnabled('acme-seo', static fn (): array => [new Routes()->get('/seo', 'acme_seo', InvokableController::class)])
            ->whenExtensionEnabled('acme-missing', static fn (): array => [new Routes()->get('/missing', 'acme_missing', InvokableController::class)])
            ->when(true, static fn (): array => [new Routes()->get('/always', 'acme_always', InvokableController::class)])
            ->when(false, static fn (): array => [new Routes()->get('/never', 'acme_never', InvokableController::class)]),
            bootOrder: ['acme-seo', 'acme-reviews'],
        );

        /** @var list<array{name: string}> $routes */
        $routes = $this->parameter(Routes::PARAMETER);
        self::assertSame(['acme_seo', 'acme_always'], array_column($routes, 'name'));
    }

    public function testConditionalRejectsNonExtenders(): void
    {
        $this->assertExtenderFails(
            new Conditional()->when(true, static fn (): array => ['not an extender']), // @phpstan-ignore argument.type (deliberately invalid)
            'Item #1 returned by the callback is string',
        );
    }
}
