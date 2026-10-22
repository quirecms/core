<?php

declare(strict_types=1);

namespace Quire\Tests\Extension\Boot;

use PHPUnit\Framework\TestCase;
use Quire\Extend\ExtenderException;
use Quire\Extend\Routes;
use Quire\Extension\Boot\ExtensionBooter;
use Quire\Extension\InstalledExtensions;
use Quire\Tests\Extension\FakeComposerInstallation;
use Quire\Theme\Themes;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ExtensionBooterTest extends TestCase
{
    /**
     * An extend.php that records, in a parameter, that it ran.
     */
    private const string RECORDING_EXTEND = <<<'PHP'
        <?php
        return [
            new class implements Quire\Extend\ExtenderInterface {
                public function extend(Quire\Extend\ExtenderContext $context): void
                {
                    $context->appendToParameter('test.ran', $context->id);
                }
            },
        ];
        PHP;

    private FakeComposerInstallation $composer;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->composer = new FakeComposerInstallation()
            ->addPackage('acme/reviews', overrides: ['extra' => ['quire' => ['requires' => ['acme-comments']]]])
            ->addFile('acme/reviews', 'extend.php', self::RECORDING_EXTEND)
            ->addPackage('acme/comments')
            ->addFile('acme/comments', 'extend.php', self::RECORDING_EXTEND)
            ->addPackage('acme/no-extend-file')
            ->addPackage('acme/disabled')
            ->addFile('acme/disabled', 'extend.php', self::RECORDING_EXTEND);
        $this->container = new ContainerBuilder();
    }

    public function testRunsEnabledExtensionsInBootOrderThenTheSite(): void
    {
        file_put_contents($this->composer->projectDir().'/extend.php', self::RECORDING_EXTEND);

        $resolved = $this->boot(['acme-reviews', 'acme-comments', 'acme-no-extend-file']);

        self::assertSame(['acme-comments', 'acme-no-extend-file', 'acme-reviews'], $resolved->bootOrderIds());
        self::assertSame(['acme-comments', 'acme-reviews', 'site'], $this->container->getParameter('test.ran'));
        self::assertSame(['acme-comments', 'acme-no-extend-file', 'acme-reviews'], $this->container->getParameter(ExtensionBooter::BOOT_ORDER_PARAMETER));
        self::assertSame([], $this->container->getParameter(Routes::PARAMETER));
    }

    public function testBrokenExtensionsDoNotRun(): void
    {
        // acme-reviews requires acme-comments, which isn't enabled.
        $resolved = $this->boot(['acme-reviews']);

        self::assertSame([], $resolved->bootOrderIds());
        self::assertArrayHasKey('acme-reviews', $resolved->broken);
        self::assertArrayNotHasKey('test.ran', $this->container->getParameterBag()->all());
    }

    public function testExtendFilesAreTrackedForRebuilds(): void
    {
        $this->boot(['acme-comments']);

        $trackedFiles = array_map(
            static fn (FileResource $resource): string => $resource->getResource(),
            array_values(array_filter($this->container->getResources(), static fn (object $r): bool => $r instanceof FileResource)),
        );

        self::assertContains($this->composer->packagePath('acme/comments').'/extend.php', $trackedFiles);
        self::assertNotContains($this->composer->packagePath('acme/disabled').'/extend.php', $trackedFiles);
    }

    public function testExtendFileMustReturnAList(): void
    {
        $this->composer->addFile('acme/comments', 'extend.php', '<?php return "oops";');

        $this->expectException(ExtenderException::class);
        $this->expectExceptionMessageIsOrContains('[acme-comments] "'.$this->composer->packagePath('acme/comments').'/extend.php" must return a list of extenders');

        $this->boot(['acme-comments']);
    }

    public function testEveryItemMustBeAnExtender(): void
    {
        $this->composer->addFile('acme/comments', 'extend.php', '<?php return [new stdClass()];');

        $this->expectException(ExtenderException::class);
        $this->expectExceptionMessageIsOrContains('[acme-comments] Item #1 returned by');

        $this->boot(['acme-comments']);
    }

    public function testErrorsInExtendFilesNameTheExtension(): void
    {
        $this->composer->addFile('acme/comments', 'extend.php', '<?php throw new RuntimeException("Boom");');

        $this->expectException(ExtenderException::class);
        $this->expectExceptionMessageIsOrContains('[acme-comments] Loading "');

        $this->boot(['acme-comments']);
    }

    public function testErrorsInExtendersNameTheExtensionAndExtender(): void
    {
        $this->composer->addFile('acme/comments', 'extend.php', <<<'PHP'
            <?php
            return [
                new class implements Quire\Extend\ExtenderInterface {
                    public function extend(Quire\Extend\ExtenderContext $context): void
                    {
                        throw new RuntimeException('Boom');
                    }
                },
            ];
            PHP);

        try {
            $this->boot(['acme-comments']);
            self::fail('The error was swallowed.');
        } catch (ExtenderException $e) {
            self::assertMatchesRegularExpression('/^\[acme-comments\] .+ \(item #1 in ".+extend\.php"\) failed: Boom$/', $e->getMessage());
        }
    }

    public function testTheDefaultThemeIsOptional(): void
    {
        $this->boot([], Themes::DEFAULT);
        $parameters = $this->container->getParameterBag()->all();
        self::assertNull($parameters[Themes::ACTIVE_PARAMETER], 'Not installed: the built-in templates, without a problem to report.');
        self::assertNull($parameters[Themes::PROBLEM_PARAMETER]);

        $this->boot([], 'acme-missing');
        $parameters = $this->container->getParameterBag()->all();
        self::assertSame('acme-missing', $parameters[Themes::ACTIVE_PARAMETER]);
        self::assertIsString($parameters[Themes::PROBLEM_PARAMETER]);
        self::assertStringStartsWith('No theme with ID "acme-missing" is installed.', $parameters[Themes::PROBLEM_PARAMETER], 'Any other theme that\'s missing is a problem.');
    }

    public function testThemesAndExtensionsBringTheirTranslations(): void
    {
        $this->composer->addPackage('acme/dark', 'quire-theme', ['extra' => ['quire' => ['theme' => ['parent' => 'acme-base']]]])
            ->addFile('acme/dark', 'translations/messages.en.yaml', '')
            ->addPackage('acme/base', 'quire-theme')
            ->addFile('acme/base', 'translations/messages.en.yaml', '')
            ->addFile('acme/reviews', 'translations/messages.en.yaml', '');

        $this->boot(['acme-reviews', 'acme-comments'], 'acme-dark');

        self::assertSame([['translator' => ['paths' => [
            $this->composer->packagePath('acme/reviews').'/translations',
            $this->composer->packagePath('acme/base').'/translations',
            $this->composer->packagePath('acme/dark').'/translations',
        ]]]], $this->container->getExtensionConfig('framework'), 'Later ones win: the active theme\'s over its parent\'s over the extensions\'.');
    }

    /**
     * @param list<string> $enabledIds
     */
    private function boot(array $enabledIds, ?string $activeTheme = null): \Quire\Extension\ResolvedExtensions
    {
        return new ExtensionBooter(new InstalledExtensions($this->composer->installedJsonPath()))
            ->boot($this->container, $enabledIds, $this->composer->projectDir().'/extend.php', activeTheme: $activeTheme);
    }
}
