<?php

declare(strict_types=1);

namespace Quire\Tests\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Quire\Storage\Exception\UnknownDiskException;
use Quire\Storage\Internal\DiskFactory;
use Quire\Storage\StorageDisks;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

final class StorageDisksTest extends TestCase
{
    public function testDisksOfTheApplicationsOwnFilesystems(): void
    {
        $files = new Filesystem(new LocalFilesystemAdapter(sys_get_temp_dir()));
        $disks = $this->disks(['uploads' => $this->config('service', true, ['url' => 'https://files.example.com/'])], ['uploads' => $files]);

        self::assertSame($files, $disks->get('uploads')->files, 'E.g. a storage of flysystem-bundle, which the application already has.');
        self::assertSame('https://files.example.com/ab/cd/x.png', $disks->get('uploads')->url('ab/cd/x.png'));

        $this->expectExceptionMessageIsOrContains('must be a League\Flysystem\FilesystemOperator');
        $this->disks(['broken' => $this->config('service', true)], ['broken' => new \stdClass()])->get('broken');
    }

    public function testBucketsAreLinkedAtTheirAddress(): void
    {
        $s3 = ['bucket' => 'photos', 'region' => 'eu-central-1', 'prefix' => '', 'path_style' => false, 'endpoint' => null];
        $disks = $this->disks([
            'aws' => $this->config('s3', true, $s3),
            'prefixed' => $this->config('s3', true, [...$s3, 'prefix' => '/site one/media/']),
            'hosted' => $this->config('s3', true, [...$s3, 'endpoint' => 'https://s3.example.com']),
            'path' => $this->config('s3', true, [...$s3, 'endpoint' => 'http://s3:8333/', 'path_style' => true]),
            'cdn' => $this->config('s3', true, [...$s3, 'url' => 'https://cdn.example.com']),
        ]);

        self::assertSame('https://photos.s3.eu-central-1.amazonaws.com/k.png', $disks->get('aws')->url('k.png'));
        self::assertSame('https://photos.s3.eu-central-1.amazonaws.com/site%20one/media/k.png', $disks->get('prefixed')->url('k.png'));
        self::assertSame('https://photos.s3.example.com/k.png', $disks->get('hosted')->url('k.png'));
        self::assertSame('http://s3:8333/photos/k.png', $disks->get('path')->url('k.png'));
        self::assertSame('https://cdn.example.com/k.png', $disks->get('cdn')->url('k.png'));
    }

    public function testUnknownAndPrivateDisks(): void
    {
        $disks = $this->disks(['downloads' => $this->config('local', false, ['path' => sys_get_temp_dir().'/quire-disks-test'])]);

        self::assertSame(['downloads'], $disks->names());

        try {
            $disks->get('downloads')->url('k.txt');
            self::fail('A private disk gave a public URL.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('The disk "downloads" is private', $e->getMessage());
        }

        $this->expectExceptionObject(UnknownDiskException::named('photos', ['downloads']));
        $disks->get('photos');
    }

    public function testADisabledExtensionsPlacedDiskIsntInUseButIsReachedWhereItIs(): void
    {
        $s3 = ['adapter' => 's3', 'public' => null, 'bucket' => 'photos', 'region' => 'eu-central-1', 'prefix' => 'reviews', 'path_style' => false, 'endpoint' => null, 'url' => null, 'path' => null, 'service' => null];
        $disks = new StorageDisks([], new DiskFactory(new UrlGenerator(new RouteCollection(), new RequestContext()), new UriSigner('test')), new ServiceLocator([]), '/srv/storage', ['acme-reviews' => $s3]);

        // Nothing is served from it, and nothing stores on it: Quire's routes and ObjectStore find disks with has() and get().
        self::assertSame([[], false, ['acme-reviews']], [$disks->names(), $disks->has('acme-reviews'), $disks->dormant()]);

        try {
            $disks->get('acme-reviews');
            self::fail('A dormant disk was in use.');
        } catch (UnknownDiskException) {
        }

        $reached = $disks->withDeclarations([['name' => 'acme-reviews', 'public' => true, 'label' => 'Photos', 'owner' => 'acme-reviews'], ['name' => 'acme-files', 'public' => false, 'label' => null, 'owner' => 'acme-files']]);

        self::assertSame('s3: photos/reviews', $reached->get('acme-reviews')->location, 'Where the site placed it, for backups and restores.');
        self::assertSame([true, 'acme-reviews'], [$reached->get('acme-reviews')->public, $reached->get('acme-reviews')->owner]);
        self::assertSame('local: /srv/storage/private/acme-files', $reached->get('acme-files')->location, 'One the site didn\'t place is where it is without configuration.');
        self::assertSame([], $reached->dormant());
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private function config(string $adapter, bool $public, array $more = []): array
    {
        return ['adapter' => $adapter, 'public' => $public, 'owner' => 'site', 'label' => null, 'url' => null, 'path' => null, 'service' => null, ...$more];
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @param array<string, object>               $services
     */
    private function disks(array $config, array $services = []): StorageDisks
    {
        $locator = new ServiceLocator(array_map(static fn (object $service): \Closure => static fn (): object => $service, $services));

        return new StorageDisks($config, new DiskFactory(new UrlGenerator(new RouteCollection(), new RequestContext()), new UriSigner('test')), $locator);
    }
}
