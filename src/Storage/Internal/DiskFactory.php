<?php

declare(strict_types=1);

namespace Quire\Storage\Internal;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Container\ContainerInterface;
use Quire\Http\Controller\StorageController;
use Quire\Storage\Disk;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Makes a {@see Disk} from its configuration.
 *
 * @internal
 */
final readonly class DiskFactory
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private UriSigner $signer,
    ) {
    }

    public function create(DiskConfig $config, ContainerInterface $services): Disk
    {
        $files = match ($config->adapter) {
            'local' => new Filesystem(new LocalFilesystemAdapter($config->path, lazyRootCreation: true)),
            's3' => $this->s3($config),
            'service' => $this->service($config, $services),
        };
        $baseUrl = null !== $config->url ? rtrim($config->url, '/') : ('s3' === $config->adapter ? $this->bucketUrl($config) : null);
        $name = $config->name;

        return new Disk(
            name: $name,
            public: $config->public,
            adapter: $config->adapter,
            owner: $config->owner,
            label: $config->label,
            location: $config->location(),
            files: $files,
            publicUrl: fn (string $key, bool $absolute): string => match (true) {
                null !== $baseUrl => $baseUrl.'/'.$key,
                // Served by the web server (Caddy) or, without it, by Quire.
                'local' === $config->adapter => $this->urls->generate(StorageController::FILE_ROUTE, ['disk' => $name, 'key' => $key], $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH),
                default => $files->publicUrl($key),
            },
            temporaryUrl: fn (string $key, \DateTimeInterface $expiresAt): string => 's3' === $config->adapter
                ? $files->temporaryUrl($key, $expiresAt)
                : $this->signer->sign($this->urls->generate(StorageController::SIGNED_ROUTE, ['disk' => $name, 'key' => $key], UrlGeneratorInterface::ABSOLUTE_URL), $expiresAt),
        );
    }

    private function s3(DiskConfig $config): FilesystemOperator
    {
        if (!class_exists(AsyncAwsS3Adapter::class)) {
            throw new \LogicException(\sprintf('The disk "%s" is on S3, which needs the adapter: composer require league/flysystem-async-aws-s3', $config->name));
        }

        $client = new S3Client(array_filter([
            'region' => $config->region,
            'endpoint' => $config->endpoint,
            'accessKeyId' => $config->accessKey,
            'accessKeySecret' => $config->secretKey,
            'pathStyleEndpoint' => $config->pathStyle ? 'true' : null,
        ], static fn (?string $value): bool => null !== $value));

        // No ACLs: many services and buckets refuse them; who may read the bucket is its policy's job.
        return new Filesystem(new AsyncAwsS3Adapter($client, (string) $config->bucket, $config->prefix));
    }

    /**
     * The address of the files in a bucket, with the disk's prefix: "<endpoint>/<bucket>/<prefix>" in path
     * style, "https://<bucket>.<host>/<prefix>" otherwise, and AWS's own address without an endpoint.
     */
    private function bucketUrl(DiskConfig $config): string
    {
        $bucket = rawurlencode((string) $config->bucket);
        $endpoint = null === $config->endpoint ? null : rtrim($config->endpoint, '/');
        $prefix = trim($config->prefix, '/');

        $base = match (true) {
            null === $endpoint => \sprintf('https://%s.s3.%s.amazonaws.com', $bucket, rawurlencode($config->region)),
            $config->pathStyle => $endpoint.'/'.$bucket,
            default => (string) preg_replace('#^(https?://)#', '$1'.$bucket.'.', $endpoint),
        };

        return '' === $prefix ? $base : $base.'/'.implode('/', array_map(rawurlencode(...), explode('/', $prefix)));
    }

    private function service(DiskConfig $config, ContainerInterface $services): FilesystemOperator
    {
        $files = $services->get($config->name);

        if (!$files instanceof FilesystemOperator) {
            throw new \LogicException(\sprintf('The service of the disk "%s" must be a %s.', $config->name, FilesystemOperator::class));
        }

        return $files;
    }
}
