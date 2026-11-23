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

        self::assertSame(['quire-comments', 'quire-reviews'], array_keys($extensions->all()));
        self::assertSame(['acme-dark'], array_keys($extensions->themes()), 'Themes are listed apart.');
        self::assertTrue($extensions->has('quire-comments'));
        self::assertFalse($extensions->has('acme-dark'));
        self::assertTrue($extensions->hasTheme('acme-dark'));
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
        $this->expectExceptionMessageIsOrContains('The packages "acme/reviews" and "other/reviews" both use the ID "acme-reviews"');

        new InstalledExtensions($composer->installedJsonPath())->all();
    }

    public function testATranslationDomainIsOnePackages(): void
    {
        $declares = static fn (string ...$domains): array => ['extra' => ['quire' => ['translations' => ['version' => 1, 'delivery' => 'hub', 'domains' => $domains]]]];

        // The default (its ID) counts too
        $composer = new FakeComposerInstallation()
            ->addPackage('acme/reviews')
            ->addPackage('other/stars', overrides: $declares('other-stars', 'acme-reviews'));

        try {
            new InstalledExtensions($composer->installedJsonPath())->all();
            self::fail('Two packages with one domain.');
        } catch (ExtensionDiscoveryException $e) {
            self::assertStringContainsString('The packages "acme/reviews" and "other/stars" both declare the translation domain "acme-reviews"', $e->getMessage());
        }

        $composer = new FakeComposerInstallation()
            ->addPackage('quire/stars', overrides: $declares('quire'));

        $this->expectException(ExtensionDiscoveryException::class);
        $this->expectExceptionMessageIsOrContains('The packages "quire/core" and "quire/stars" both declare the translation domain "quire"');

        new InstalledExtensions($composer->installedJsonPath())->all();
    }

    public function testNoPackageHasTheSitesDomain(): void
    {
        // An ID can't be "site" either (a name core uses), so only a declaration could claim it, and it's refused
        $this->expectException(\Quire\Extension\Exception\InvalidManifestException::class);
        $this->expectExceptionMessageIsOrContains('lists "site", which is the site\'s own');

        $composer = new FakeComposerInstallation()->addPackage('acme/names', overrides: ['extra' => ['quire' => ['translations' => ['version' => 1, 'delivery' => 'hub', 'domains' => ['site']]]]]);

        new InstalledExtensions($composer->installedJsonPath())->all();
    }

    public function testPackagesWithTheirOwnDomainsAreFine(): void
    {
        $composer = new FakeComposerInstallation()
            ->addPackage('acme/reviews')
            ->addPackage('other/stars', overrides: ['extra' => ['quire' => ['translations' => ['version' => 1, 'delivery' => 'hub', 'domains' => ['other-stars', 'other-ratings']]]]]);

        self::assertCount(2, new InstalledExtensions($composer->installedJsonPath())->all());
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
