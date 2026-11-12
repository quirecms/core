<?php

declare(strict_types=1);

namespace Quire\Tests\Csp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Csp\Csp;
use Quire\Extend\Storage;
use Quire\QuireBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * "quire.csp": the site's own additions to its policy, and report-only mode.
 */
final class CspConfigTest extends TestCase
{
    public function testByDefaultThePolicyIsEnforcedAndAddsNothing(): void
    {
        $builder = $this->load([]);

        // From the bag: PHPStan would type them from the application's own container.
        $parameters = $builder->getParameterBag()->all();

        self::assertSame([false, null, []], [$parameters[Csp::REPORT_ONLY_PARAMETER], $parameters[Csp::REPORT_URI_PARAMETER], $parameters[Csp::SOURCES_PARAMETER]]);
    }

    public function testTheSiteAddsSourcesAndTriesThePolicyOut(): void
    {
        $builder = $this->load(['mode' => 'report-only', 'report_uri' => 'https://reports.example.com/csp', 'sources' => ['img-src' => ['https://images.example.com'], 'frame-src' => ['https://www.youtube-nocookie.com']]]);

        self::assertTrue($builder->getParameter(Csp::REPORT_ONLY_PARAMETER));
        self::assertSame('https://reports.example.com/csp', $builder->getParameter(Csp::REPORT_URI_PARAMETER));
        self::assertSame(['img-src' => ['https://images.example.com'], 'frame-src' => ['https://www.youtube-nocookie.com']], $builder->getParameter(Csp::SOURCES_PARAMETER), 'Directive names keep their "-".');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'an unknown mode' => [['mode' => 'off'], 'The value "off" is not allowed for path "quire.csp.mode"'];
        yield 'an unknown directive' => [['sources' => ['image-src' => ['https://a.example']]], '"quire.csp.sources.image-src": "image-src" isn\'t a Content-Security-Policy directive'];
        yield 'a second directive inside a source' => [['sources' => ['img-src' => ["https://a.example; script-src 'unsafe-inline'"]]], '"quire.csp.sources.img-src": "https://a.example; script-src \'unsafe-inline\'" isn\'t a Content-Security-Policy source'];
        yield 'an unquoted keyword' => [['sources' => ['style-src' => ['unsafe-inline']]], '"unsafe-inline" needs its quotes'];
        yield 'reports in the sources' => [['sources' => ['report-uri' => ['https://reports.example.com']]], 'Use "quire.csp.report_uri".'];
    }

    /**
     * @param array<string, mixed> $csp
     */
    #[DataProvider('invalid')]
    public function testMistakesAreSaidWhenTheContainerIsBuilt(array $csp, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->load($csp);
    }

    /**
     * @param array<string, mixed> $csp
     */
    private function load(array $csp): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', '/srv/site');
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter(Storage::PARAMETER, []);

        $extension = new QuireBundle()->getContainerExtension();
        \assert($extension instanceof ExtensionInterface);
        $extension->load([['csp' => $csp]], $builder);

        return $builder;
    }
}
