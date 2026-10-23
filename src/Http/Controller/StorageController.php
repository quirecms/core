<?php

declare(strict_types=1);

namespace Quire\Http\Controller;

use League\Flysystem\FilesystemException;
use League\MimeTypeDetection\GeneratedExtensionToMimeTypeMap;
use Quire\Storage\Disk;
use Quire\Storage\StorageDisks;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves stored files: those of public disks to everyone, and any disk's through signed URLs.
 *
 * With Quire's Docker setup, Caddy serves local public disks itself and this never runs for them; it's
 * for applications Quire is embedded in, other web servers, and CDNs that pull from the site.
 *
 * @internal
 */
#[AsController]
final readonly class StorageController
{
    public const string FILE_ROUTE = 'quire_storage_file';

    public const string SIGNED_ROUTE = 'quire_storage_signed';

    /** The keys that {@see \Quire\Storage\ObjectStore} gives files */
    public const string KEY_PATTERN = '[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}\.[a-z0-9]+';

    public function __construct(
        private StorageDisks $disks,
        private UriSigner $signer,
    ) {
    }

    public function file(Request $request, string $disk, string $key): StreamedResponse
    {
        $found = $this->disks->has($disk) ? $this->disks->get($disk) : null;

        if (!$found instanceof Disk || !$found->public) {
            throw new NotFoundHttpException(\sprintf('There is no public disk "%s".', $disk));
        }

        // Named after its content: it never changes, so browsers and CDNs keep it for good.
        $response = $this->stream($found, $key, 'public, max-age=31536000, immutable');
        $response->setEtag(substr(basename($key), 0, 64));
        $response->isNotModified($request);

        return $response;
    }

    public function signed(Request $request, string $disk, string $key): StreamedResponse
    {
        if (!$this->disks->has($disk) || !$this->signer->checkRequest($request)) {
            throw new AccessDeniedHttpException('The link is invalid or has expired.');
        }

        return $this->stream($this->disks->get($disk), $key, 'private, no-store');
    }

    private function stream(Disk $disk, string $key, string $cacheControl): StreamedResponse
    {
        try {
            if (!$disk->files->fileExists($key)) {
                throw new NotFoundHttpException(\sprintf('The disk "%s" has no file "%s".', $disk->name, $key));
            }

            $size = $disk->files->fileSize($key);
        } catch (FilesystemException $e) {
            throw new NotFoundHttpException('The file can\'t be read.', $e);
        }

        $type = new GeneratedExtensionToMimeTypeMap()->lookupMimeType(pathinfo($key, \PATHINFO_EXTENSION)) ?? 'application/octet-stream';

        return new StreamedResponse(static function () use ($disk, $key): void {
            $stream = $disk->files->readStream($key);
            $out = fopen('php://output', 'w');

            if (false !== $out) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
            }

            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $type,
            'Content-Length' => (string) $size,
            'Cache-Control' => $cacheControl,
            // Uploaded files are someone's content, not the site's: never sniffed into another type, and
            // never able to run scripts with the site's origin, even when they're HTML or SVG.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'; sandbox",
        ]);
    }
}
