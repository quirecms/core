<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Quire\Extension\Exception\ExtensionDiscoveryException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\InstalledExtensions;

final class InstalledExtensionsTest extends TestCase
{
    public function testListsOnlyQuirePackagesSortedById(): void
    {
        $composer = new FakeComposerInstallation()
            ->addPackage('quire/reviews')
            ->addPackage('symfony/console', 'library')
            ->addPackage('acme/dark', 'quire-theme')
            ->addPackage('quire/comments');

        $extensions = new InstalledExtensions($composer->installedJsonPath());

        self::assertSame(['acme-dark', 'quire-comments', 'quire-reviews'], array_keys($extensions->all()));
        self::assertTrue($extensions->has('quire-comments'));
        self::assertFalse($extensions->has('symfony-console'));
        self::assertSame('quire/reviews', $extensions->get('quire-reviews')->packageName);
    }

    public function testNoExtensionsInstalled(): void
    {
        $composer = new FakeComposerInstallation()->addPackage('symfony/console', 'library');

        self::assertSame([], new InstalledExtensions($composer->installedJsonPath())->all());
    }

    public function testUnknownIdSuggestsCloseMatches(): void
    {
        $composer = new FakeComposerInstallation()->addPackage('quire/comments')->addPackage('quire/reviews');

        $this->expectException(UnknownExtensionException::class);
        $this->expectExceptionMessageIsOrContains('No extension with ID "quire-coments" is installed. Did you mean "quire-comments"?');

        new InstalledExtensions($composer->installedJsonPath())->get('quire-coments');
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $composer = new FakeComposerInstallation()
            ->addPackage('acme/reviews')
            ->addPackage('other/reviews', overrides: ['extra' => ['quire' => ['id' => 'acme-reviews']]]);

        $this->expectException(ExtensionDiscoveryException::class);
        $this->expectExceptionMessageIsOrContains('The packages "acme/reviews" and "other/reviews" both use the extension ID "acme-reviews"');

        new InstalledExtensions($composer->installedJsonPath())->all();
    }

    public function testMissingInstalledJsonExplainsTheFix(): void
    {
        $this->expectException(ExtensionDiscoveryException::class);
        $this->expectExceptionMessageIsOrContains('Run "composer install"');

        new InstalledExtensions('/nonexistent/vendor/composer/installed.json')->all();
    }

    public function testResetPicksUpNewlyInstalledPackages(): void
    {
        $composer = new FakeComposerInstallation()->addPackage('quire/comments');
        $path = $composer->installedJsonPath();
        $extensions = new InstalledExtensions($path);
        self::assertCount(1, $extensions->all());

        $composer->addPackage('quire/reviews')->installedJsonPath();
        self::assertCount(1, $extensions->all(), 'The list is cached within a request.');

        $extensions->reset();
        self::assertCount(2, $extensions->all());
    }
}
