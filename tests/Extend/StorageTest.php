<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\Storage;
use Quire\QuireBundle;
use Quire\Storage\StorageDisks;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class StorageTest extends ExtenderTestCase
{
    public function testDeclaresDisksForTheirExtension(): void
    {
        $this->apply(new Storage()->disk('acme-reviews', public: true, label: 'Review photos')->disk('acme-reviews-private', public: false));

        self::assertSame([
            ['name' => 'acme-reviews', 'public' => true, 'label' => 'Review photos', 'owner' => 'acme-reviews'],
            ['name' => 'acme-reviews-private', 'public' => false, 'label' => null, 'owner' => 'acme-reviews'],
        ], $this->container->getParameter(Storage::PARAMETER));
    }

    public function testRejectsBadAndTakenNames(): void
    {
        $this->assertExtenderFails(new Storage()->disk('Acme Reviews', public: true), 'Disk names have lowercase letters');
        $this->assertExtenderFails(new Storage()->disk('_signed', public: true), 'start with a letter');

        $this->apply(new Storage()->disk('acme-reviews', public: true));
        $this->assertExtenderFails(new Storage()->disk('acme-reviews', public: false), 'already declared by "acme-reviews"');
    }

    public function testDeclaredDisksWorkWithoutConfiguration(): void
    {
        $disks = $this->disks([], [['name' => 'acme-reviews', 'public' => true, 'label' => 'Photos', 'owner' => 'acme-reviews'], ['name' => 'acme-files', 'public' => false, 'label' => null, 'owner' => 'acme-files']]);

        self::assertSame(['acme-files', 'acme-reviews'], array_keys($disks));
        self::assertSame(['local', true, '%kernel.project_dir%/storage/public/acme-reviews', 'acme-reviews', 'Photos'], [$disks['acme-reviews']['adapter'], $disks['acme-reviews']['public'], $disks['acme-reviews']['path'], $disks['acme-reviews']['owner'], $disks['acme-reviews']['label']]);
        self::assertSame('%kernel.project_dir%/storage/private/acme-files', $disks['acme-files']['path'], 'Private disks are apart from public ones, which the web server serves.');
    }

    public function testTheSiteMovesDisksAndAddsItsOwn(): void
    {
        $disks = $this->disks([
            'acme-reviews' => ['adapter' => 's3', 'bucket' => 'photos', 'endpoint' => 'https://s3.example.com', 'path_style' => true, 'url' => 'https://cdn.example.com/'],
            'exports' => ['public' => false, 'path' => '/srv/exports'],
        ], [['name' => 'acme-reviews', 'public' => true, 'label' => null, 'owner' => 'acme-reviews']]);

        self::assertSame(['s3', 'photos', true, true], [$disks['acme-reviews']['adapter'], $disks['acme-reviews']['bucket'], $disks['acme-reviews']['path_style'], $disks['acme-reviews']['public']]);
        self::assertSame(['local', false, '/srv/exports', 'site'], [$disks['exports']['adapter'], $disks['exports']['public'], $disks['exports']['path'], $disks['exports']['owner']]);
    }

    public function testADiskTheSitePlacedStaysPlacedWhileItsExtensionIsDisabled(): void
    {
        $s3 = ['adapter' => 's3', 'bucket' => 'photos', 'prefix' => 'reviews'];

        // Disabled: nothing declares the disk, which used to fail the build.
        [$disks, $dormant] = $this->disksAndDormant(['acme-reviews' => $s3, 'exports' => ['public' => false, 'path' => '/srv/exports']]);

        self::assertSame(['exports'], array_keys($disks), 'Not in use: not served, nothing stored on it.');
        self::assertSame(['acme-reviews'], array_keys($dormant));
        self::assertSame(['s3', 'photos', 'reviews', null], [$dormant['acme-reviews']['adapter'], $dormant['acme-reviews']['bucket'], $dormant['acme-reviews']['prefix'], $dormant['acme-reviews']['public']]);

        // Its place is still its own.
        $this->assertConfigFails(['acme-reviews' => $s3, 'site-photos' => ['public' => true, ...$s3]], [], 'The storage disks "site-photos" and "acme-reviews" are in the same place');
    }

    public function testADisabledExtensionsDiskTheSiteSetPublicForIsServedAsItWasWhileEnabled(): void
    {
        // "public" set as the extension declares it (anything else is refused while it's enabled): the
        // site's disk now, served exactly as before, so disabling the extension serves nothing new.
        $declared = [['name' => 'acme-files', 'public' => false, 'label' => null, 'owner' => 'acme-files']];
        $enabled = $this->disks(['acme-files' => ['public' => false, 'path' => '/srv/files']], $declared);
        $disabled = $this->disks(['acme-files' => ['public' => false, 'path' => '/srv/files']]);

        self::assertSame([false, '/srv/files'], [$enabled['acme-files']['public'], $enabled['acme-files']['path']]);
        self::assertSame([false, '/srv/files', 'site'], [$disabled['acme-files']['public'], $disabled['acme-files']['path'], $disabled['acme-files']['owner']], 'Private still: only signed links, which only code gives out.');
    }

    public function testRejectsContradictionsAndSharedPlaces(): void
    {
        $declared = [['name' => 'acme-reviews', 'public' => true, 'label' => null, 'owner' => 'acme-reviews']];

        $this->assertConfigFails(['acme-reviews' => ['public' => false]], $declared, 'contradicts "acme-reviews", which declares the disk public');
        $this->assertConfigFails(['photos' => ['public' => true, 'adapter' => 's3']], [], 'S3 disks need a "bucket"');
        $this->assertConfigFails(['photos' => ['public' => true, 'adapter' => 'service']], [], 'need the "service" ID');
        $this->assertConfigFails(['a' => ['public' => true, 'path' => '/srv/files'], 'b' => ['public' => true, 'path' => '/srv/files/b']], [], 'The storage disks "a" and "b" are in the same place');
        $this->assertConfigFails(['a' => ['public' => true, 'adapter' => 's3', 'bucket' => 'x'], 'b' => ['public' => false, 'adapter' => 's3', 'bucket' => 'x']], [], 'The storage disks "a" and "b" are in the same place');
        $this->assertConfigFails(['Bad' => ['public' => true]], [], 'disk names have lowercase letters');

        // Prefixes keep disks in one bucket apart.
        $disks = $this->disks(['a' => ['public' => true, 'adapter' => 's3', 'bucket' => 'x', 'prefix' => 'a'], 'b' => ['public' => false, 'adapter' => 's3', 'bucket' => 'x', 'prefix' => 'b']]);
        self::assertSame(['a', 'b'], array_keys($disks));
    }

    /**
     * @param array<string, array<string, mixed>>                                    $configured
     * @param list<array{name: string, public: bool, label: ?string, owner: string}> $declared
     *
     * @return array<string, array<string, mixed>>
     */
    private function disks(array $configured, array $declared = []): array
    {
        /** @var array<string, array<string, mixed>> $disks */
        $disks = $this->built($configured, $declared)->getParameter(StorageDisks::PARAMETER);

        return $disks;
    }

    /**
     * @param array<string, array<string, mixed>>                                    $configured
     * @param list<array{name: string, public: bool, label: ?string, owner: string}> $declared
     */
    private function built(array $configured, array $declared): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', '/srv/site');
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter(Storage::PARAMETER, $declared);

        $extension = new QuireBundle()->getContainerExtension();
        \assert($extension instanceof \Symfony\Component\DependencyInjection\Extension\ExtensionInterface);
        $extension->load([['storage' => ['disks' => $configured]]], $builder);

        return $builder;
    }

    /**
     * @param array<string, array<string, mixed>>                                    $configured
     * @param list<array{name: string, public: bool, label: ?string, owner: string}> $declared
     *
     * @return array{array<string, array<string, mixed>>, array<string, array<string, mixed>>} The disks in use, and the dormant ones
     */
    private function disksAndDormant(array $configured, array $declared = []): array
    {
        $builder = $this->built($configured, $declared);
        /** @var array<string, array<string, mixed>> $disks */
        $disks = $builder->getParameter(StorageDisks::PARAMETER);
        /** @var array<string, array<string, mixed>> $dormant */
        $dormant = $builder->getParameter(StorageDisks::DORMANT_PARAMETER);

        return [$disks, $dormant];
    }

    /**
     * @param array<string, array<string, mixed>>                                    $configured
     * @param list<array{name: string, public: bool, label: ?string, owner: string}> $declared
     */
    private function assertConfigFails(array $configured, array $declared, string $expectedMessage): void
    {
        try {
            $this->disks($configured, $declared);
        } catch (InvalidConfigurationException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());

            return;
        }

        self::fail('The configuration was accepted: '.json_encode($configured));
    }
}
