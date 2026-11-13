<?php

declare(strict_types=1);

namespace Quire\Tests\Csp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Quire\Csp\Csp;
use Quire\Csp\Internal\CspSourcesPass;
use Quire\Csp\Internal\FirstPartySourcesInterface;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Csp\ThirdPartySourcesInterface;
use Quire\Extension\Boot\ExtensionPasses;
use Quire\Formatter\EmbedGuardInterface;
use Quire\Formatter\Formatter;
use Quire\Formatter\Internal\EmbedGuardPass;
use Quire\Formatter\Internal\NoEmbedGuard;
use Quire\Privacy\Fixture\FixtureGuard;
use Quire\Privacy\Fixture\FixtureSources;
use Quire\QuireBundle;
use Quire\Storage\Internal\DiskSources;
use Quire\Storage\StorageDisks;
use Quire\Tests\Csp\Fixtures\OutsideDecorator;
use Quire\Tests\Csp\Fixtures\OutsideFirstPartyDecorator;
use Quire\Tests\Csp\Fixtures\OutsideGuard;
use Quire\Tests\Csp\Fixtures\OutsideSources;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Conditions C-C and C-B (doc 20): other sites' hosts only from quire/privacy, core's own sources only
 * from core, and embeds guarded only by quire/privacy; checked on the container as compiled, so
 * decorating a service doesn't get round it.
 */
final class CspSourcesPassTest extends TestCase
{
    private const string PRIVACY = __DIR__.'/Fixtures/privacy';

    public static function setUpBeforeClass(): void
    {
        // quire/privacy isn't installed here: its stand-in, where the pass is told the package is.
        require_once self::PRIVACY.'/src/FixtureSources.php';
        require_once self::PRIVACY.'/src/FixtureGuard.php';
    }

    public function testTheBundleChecksOnceDecorationHasRun(): void
    {
        $container = new ContainerBuilder();
        new QuireBundle()->build($container);
        $passes = $container->getCompilerPassConfig();

        self::assertCount(1, array_filter($passes->getBeforeRemovingPasses(), static fn (object $pass): bool => $pass instanceof CspSourcesPass), 'Before decoration, a decorator would get round the check.');
        self::assertCount(1, array_filter($passes->getBeforeRemovingPasses(), static fn (object $pass): bool => $pass instanceof EmbedGuardPass));
        self::assertSame([], array_filter([...$passes->getBeforeOptimizationPasses(), ...$passes->getOptimizationPasses()], static fn (object $pass): bool => $pass instanceof CspSourcesPass));
    }

    #[DoesNotPerformAssertions]
    public function testCoresDefaultBuilds(): void
    {
        $this->compile($this->container());
    }

    #[DoesNotPerformAssertions]
    public function testQuirePrivacyMayFillTheSlot(): void
    {
        $container = $this->container();
        $container->register(FixtureSources::class, FixtureSources::class);
        $container->setAlias(ThirdPartySourcesInterface::class, FixtureSources::class);
        $this->guardEmbeds($container);

        $this->compile($container);
    }

    #[DoesNotPerformAssertions]
    public function testQuirePrivacyMayDecorateTheSlot(): void
    {
        $container = $this->container();
        $container->register(FixtureSources::class, FixtureSources::class)
            ->setDecoratedService(ThirdPartySourcesInterface::class)
            ->setArguments([new Reference('.inner')]);
        $this->guardEmbeds($container);

        $this->compile($container);
    }

    /**
     * With quire/privacy's hosts in the policy, its guard must hold their embeds: every way of leaving
     * the formatter without it.
     *
     * @return iterable<string, array{\Closure(ContainerBuilder): void}>
     */
    public static function noGuardWithPrivacy(): iterable
    {
        yield 'core\'s default in the slot' => [static function (ContainerBuilder $container): void {}];
        yield 'the slot removed' => [static function (ContainerBuilder $container): void {
            $container->removeAlias(EmbedGuardInterface::class);
        }];
        yield 'the slot and core\'s default removed' => [static function (ContainerBuilder $container): void {
            $container->removeAlias(EmbedGuardInterface::class);
            $container->removeDefinition(NoEmbedGuard::class);
        }];
        yield 'the formatter given core\'s default' => [static function (ContainerBuilder $container): void {
            $container->register(FixtureGuard::class, FixtureGuard::class);
            $container->setAlias(EmbedGuardInterface::class, FixtureGuard::class);
            $container->getDefinition(Formatter::class)->setArgument('$embeds', new Reference(NoEmbedGuard::class));
        }];
        yield 'the formatter given core\'s default inline' => [static function (ContainerBuilder $container): void {
            $container->register(FixtureGuard::class, FixtureGuard::class);
            $container->setAlias(EmbedGuardInterface::class, FixtureGuard::class);
            $container->getDefinition(Formatter::class)->setArgument('$embeds', new Definition(NoEmbedGuard::class));
        }];
    }

    /**
     * @param \Closure(ContainerBuilder): void $change
     */
    #[DataProvider('noGuardWithPrivacy')]
    public function testWithQuirePrivacyOnlyItsGuardWillDo(\Closure $change): void
    {
        $container = $this->container();
        $container->register(FixtureSources::class, FixtureSources::class);
        $container->setAlias(ThirdPartySourcesInterface::class, FixtureSources::class);
        $change($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('the formatter isn\'t given its guard of embeds');

        $this->compile($container);
    }

    public function testAnExtensionCantAddHostsOfItsOwn(): void
    {
        $container = $this->container();
        $container->register('acme', OutsideDecorator::class)->setArguments([new Reference(NoThirdPartySources::class)]);
        $container->setAlias(ThirdPartySourcesInterface::class, 'acme');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideDecorator" adds other sites\' hosts to the Content-Security-Policy. Only quire/privacy may');

        $this->compile($container);
    }

    public function testNorByDecoratingTheSlot(): void
    {
        $container = $this->container();
        $container->register('acme', OutsideDecorator::class)
            ->setDecoratedService(ThirdPartySourcesInterface::class)
            ->setArguments([new Reference('.inner')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideDecorator" adds other sites\' hosts');

        $this->compile($container);
    }

    public function testNorByDecoratingCoresOwnSources(): void
    {
        $container = $this->container();
        $container->register('acme', OutsideFirstPartyDecorator::class)
            ->setDecoratedService(DiskSources::class)
            ->setArguments([new Reference('.inner')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideFirstPartyDecorator" adds');

        $this->compile($container);
    }

    public function testNorWithQuirePrivacysNamespaceFromElsewhere(): void
    {
        $container = $this->container();
        $container->register(FixtureSources::class, FixtureSources::class);
        $container->setAlias(ThirdPartySourcesInterface::class, FixtureSources::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Privacy\Fixture\FixtureSources" adds other sites\' hosts');

        $this->compile($container, privacy: null);
    }

    public function testOnlyCoresPartsAddFirstPartySources(): void
    {
        $container = $this->container();
        $container->register(OutsideSources::class, OutsideSources::class)->addTag(FirstPartySourcesInterface::TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideSources" adds sources to the Content-Security-Policy through an interface of Quire\'s own: only core\'s parts may');

        $this->compile($container);
    }

    public function testNorByHandingCspAServiceDirectly(): void
    {
        $container = $this->container();
        $container->register(OutsideSources::class, OutsideSources::class);
        $container->getDefinition(Csp::class)->setArgument('$firstParty', [new Reference(OutsideSources::class)]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideSources" adds other sites\' hosts');

        $this->compile($container);
    }

    public function testNorWithAnInlineService(): void
    {
        $container = $this->container();
        $container->getDefinition(Csp::class)->setArgument('$thirdParty', new Definition(OutsideDecorator::class, [new Reference(NoThirdPartySources::class)]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideDecorator" adds other sites\' hosts');

        $this->compile($container);
    }

    public function testNorWithAnInlineServiceAmongCoresOwn(): void
    {
        $container = $this->container();
        $container->getDefinition(Csp::class)->setArgument('$firstParty', [new Reference(DiskSources::class), new Definition(OutsideSources::class)]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideSources" adds other sites\' hosts');

        $this->compile($container);
    }

    public function testNorWithAFactoryOfItsOwn(): void
    {
        $container = $this->container();
        $container->getDefinition(NoThirdPartySources::class)->setFactory([OutsideDecorator::class, 'make']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Tests\Csp\Fixtures\OutsideDecorator" adds other sites\' hosts');

        $this->compile($container);
    }

    public function testNorByReplacingThePolicyItself(): void
    {
        $container = $this->container();
        $container->getDefinition(Csp::class)->setClass(OutsideSources::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The Content-Security-Policy service is "Quire\Tests\Csp\Fixtures\OutsideSources"');

        $this->compile($container);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function extensionPassTypes(): iterable
    {
        foreach (ExtensionPasses::TYPES as $type) {
            yield $type => [$type];
        }
    }

    /**
     * An extension's pass runs before the check, whatever its type, so what it does is checked.
     */
    #[DataProvider('extensionPassTypes')]
    public function testNorFromAnExtensionsPass(string $type): void
    {
        $container = $this->container();
        ExtensionPasses::add($container, new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(Csp::class)->setArgument(0, new Definition(OutsideDecorator::class, [new Reference(NoThirdPartySources::class)]));
            }
        }, $type, 0);

        foreach (ExtensionPasses::runners() as $runnerType => $runner) {
            $container->addCompilerPass($runner, $runnerType, PassConfig::TYPE_BEFORE_REMOVING === $runnerType ? 100 : -100);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('OutsideDecorator');

        $this->compile($container);
    }

    #[DoesNotPerformAssertions]
    public function testQuirePrivacyMayGuardEmbeds(): void
    {
        $container = $this->container();
        $container->register(FixtureGuard::class, FixtureGuard::class);
        $container->setAlias(EmbedGuardInterface::class, FixtureGuard::class);

        $this->compile($container);
    }

    #[DoesNotPerformAssertions]
    public function testQuirePrivacyMayDecorateTheGuard(): void
    {
        $container = $this->container();
        $container->register(FixtureGuard::class, FixtureGuard::class)
            ->setDecoratedService(EmbedGuardInterface::class)
            ->setArguments([new Reference('.inner')]);

        $this->compile($container);
    }

    /**
     * Every way of putting another guard of embeds in place, which would let embeds load before consent.
     *
     * @return iterable<string, array{\Closure(ContainerBuilder): void, ?string}>
     */
    public static function otherGuards(): iterable
    {
        yield 'in the slot' => [static function (ContainerBuilder $container): void {
            $container->register('acme', OutsideGuard::class);
            $container->setAlias(EmbedGuardInterface::class, 'acme');
        }, self::PRIVACY];
        yield 'decorating the slot' => [static function (ContainerBuilder $container): void {
            $container->register('acme', OutsideGuard::class)->setDecoratedService(EmbedGuardInterface::class);
        }, self::PRIVACY];
        yield 'decorating core\'s default' => [static function (ContainerBuilder $container): void {
            $container->register('acme', OutsideGuard::class)->setDecoratedService(NoEmbedGuard::class);
        }, self::PRIVACY];
        yield 'handed to the formatter by reference' => [static function (ContainerBuilder $container): void {
            $container->register('acme', OutsideGuard::class);
            $container->getDefinition(Formatter::class)->setArgument('$embeds', new Reference('acme'));
        }, self::PRIVACY];
        yield 'handed to the formatter inline' => [static function (ContainerBuilder $container): void {
            $container->getDefinition(Formatter::class)->setArgument('$embeds', new Definition(OutsideGuard::class));
        }, self::PRIVACY];
        yield 'made by a factory' => [static function (ContainerBuilder $container): void {
            $container->getDefinition(NoEmbedGuard::class)->setFactory([OutsideGuard::class, 'make']);
        }, self::PRIVACY];
        yield 'changed by a configurator' => [static function (ContainerBuilder $container): void {
            $container->getDefinition(NoEmbedGuard::class)->setConfigurator([OutsideGuard::class, 'configure']);
        }, self::PRIVACY];
        yield 'quire/privacy\'s namespace from elsewhere' => [static function (ContainerBuilder $container): void {
            $container->register(FixtureGuard::class, FixtureGuard::class);
            $container->setAlias(EmbedGuardInterface::class, FixtureGuard::class);
        }, null];
    }

    /**
     * @param \Closure(ContainerBuilder): void $change
     */
    #[DataProvider('otherGuards')]
    public function testNoOneElseGuardsEmbeds(\Closure $change, ?string $privacy): void
    {
        $container = $this->container();
        $change($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('guards the embeds of trusted shortcodes. Only quire/privacy may');

        $this->compile($container, $privacy);
    }

    public function testNorByReplacingTheFormatter(): void
    {
        $container = $this->container();
        $container->getDefinition(Formatter::class)->setClass(OutsideGuard::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The formatter service is "Quire\Tests\Csp\Fixtures\OutsideGuard"');

        $this->compile($container);
    }

    /**
     * An extension's pass runs before the check, whatever its type, so what it does to the guard is checked.
     */
    #[DataProvider('extensionPassTypes')]
    public function testNorGuardsFromAnExtensionsPass(string $type): void
    {
        $container = $this->container();
        ExtensionPasses::add($container, new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(Formatter::class)->setArgument('$embeds', new Definition(OutsideGuard::class));
            }
        }, $type, 0);

        foreach (ExtensionPasses::runners() as $runnerType => $runner) {
            $container->addCompilerPass($runner, $runnerType, PassConfig::TYPE_BEFORE_REMOVING === $runnerType ? 100 : -100);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('OutsideGuard');

        $this->compile($container);
    }

    private function guardEmbeds(ContainerBuilder $container): void
    {
        $container->register(FixtureGuard::class, FixtureGuard::class);
        $container->setAlias(EmbedGuardInterface::class, FixtureGuard::class);
    }

    /**
     * Core's services as core registers them (config/services.php, QuireBundle).
     */
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(Csp::REPORT_ONLY_PARAMETER, false);
        $container->setParameter(Csp::REPORT_URI_PARAMETER, null);
        $container->setParameter(Csp::SOURCES_PARAMETER, []);
        $container->register(StorageDisks::class)->setSynthetic(true);
        $container->register(DiskSources::class, DiskSources::class)->setAutowired(true)->addTag(FirstPartySourcesInterface::TAG);
        $container->register(NoThirdPartySources::class, NoThirdPartySources::class);
        $container->setAlias(ThirdPartySourcesInterface::class, NoThirdPartySources::class);
        $container->register(Csp::class, Csp::class)->setAutowired(true)->setPublic(true);
        $container->setParameter(Formatter::PARAMETER, ['formats' => [], 'profiles' => [], 'shortcodes' => [], 'commonmark' => [], 'pre' => [], 'post' => []]);
        $container->setParameter('kernel.debug', false);
        $container->register(NoEmbedGuard::class, NoEmbedGuard::class);
        $container->setAlias(EmbedGuardInterface::class, NoEmbedGuard::class);
        $container->register(Formatter::class, Formatter::class)->setAutowired(true)->setPublic(true);

        return $container;
    }

    private function compile(ContainerBuilder $container, ?string $privacy = self::PRIVACY): void
    {
        $container->addCompilerPass(new CspSourcesPass($privacy ?? __DIR__.'/Fixtures/no-privacy-here'), PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new EmbedGuardPass($privacy ?? __DIR__.'/Fixtures/no-privacy-here'), PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile(true);
    }
}
