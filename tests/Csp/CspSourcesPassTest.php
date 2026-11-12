<?php

declare(strict_types=1);

namespace Quire\Tests\Csp;

use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Quire\Csp\Csp;
use Quire\Csp\Internal\CspSourcesPass;
use Quire\Csp\Internal\FirstPartySourcesInterface;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Csp\ThirdPartySourcesInterface;
use Quire\Privacy\Internal\ServiceSources;
use Quire\QuireBundle;
use Quire\Storage\Internal\DiskSources;
use Quire\Storage\StorageDisks;
use Quire\Tests\Csp\Fixtures\OutsideDecorator;
use Quire\Tests\Csp\Fixtures\OutsideFirstPartyDecorator;
use Quire\Tests\Csp\Fixtures\OutsideSources;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Condition C-C (doc 20): other sites' hosts only from quire/privacy, core's own sources only from
 * core; checked on the container as compiled, so decorating a service doesn't get round it.
 */
final class CspSourcesPassTest extends TestCase
{
    private const string PRIVACY = __DIR__.'/Fixtures/privacy';

    public static function setUpBeforeClass(): void
    {
        // quire/privacy isn't installed here: its stand-in, where the pass is told the package is.
        require_once self::PRIVACY.'/src/ServiceSources.php';
    }

    public function testTheBundleChecksOnceDecorationHasRun(): void
    {
        $container = new ContainerBuilder();
        new QuireBundle()->build($container);
        $passes = $container->getCompilerPassConfig();

        self::assertCount(1, array_filter($passes->getBeforeRemovingPasses(), static fn (object $pass): bool => $pass instanceof CspSourcesPass), 'Before decoration, a decorator would get round the check.');
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
        $container->register(ServiceSources::class, ServiceSources::class);
        $container->setAlias(ThirdPartySourcesInterface::class, ServiceSources::class);

        $this->compile($container);
    }

    #[DoesNotPerformAssertions]
    public function testQuirePrivacyMayDecorateTheSlot(): void
    {
        $container = $this->container();
        $container->register(ServiceSources::class, ServiceSources::class)
            ->setDecoratedService(ThirdPartySourcesInterface::class)
            ->setArguments([new Reference('.inner')]);

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
        $container->register(ServiceSources::class, ServiceSources::class);
        $container->setAlias(ThirdPartySourcesInterface::class, ServiceSources::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Quire\Privacy\Internal\ServiceSources" adds other sites\' hosts');

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

        return $container;
    }

    private function compile(ContainerBuilder $container, ?string $privacy = self::PRIVACY): void
    {
        $container->addCompilerPass(new CspSourcesPass($privacy ?? __DIR__.'/Fixtures/no-privacy-here'), PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile(true);
    }
}
