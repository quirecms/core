<?php

declare(strict_types=1);

namespace Quire\Storage;

use Doctrine\DBAL\Connection;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Quire\Content\Internal\Row;

/**
 * Stores files on storage disks, named after their content, and records them in quire.storage_object.
 *
 *     $object = $objects->store('acme-gallery', $request->files->get('photo')->getContent());
 *     $connection->insert('quire.acme_gallery_photo', ['object_id' => $object->id, …]);
 *     $objects->url($object);   // "/storage/acme-gallery/3a/7b/3a7b…e1.jpg"
 *
 * - **Content-addressed:** the key is the content's SHA-256, so the same file is stored once per disk,
 *   a stored file never changes, and its URL can be cached for good.
 * - **Referenced, then collected:** reference objects from your tables with a foreign key to
 *   quire.storage_object(id). `quire:storage:gc` deletes objects nothing references (after a grace
 *   period), and files no object records, e.g. from a transaction that was rolled back.
 * - **Transactional:** the row is written on the current connection, so it commits or rolls back with
 *   your own rows. The file is written first; if the transaction fails, it's collected later.
 *
 * @api
 */
final readonly class ObjectStore
{
    /** Types that a browser would run as a page or script: never trusted from the caller */
    private const array ACTIVE_TYPES = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/javascript', 'application/javascript', 'text/xml', 'application/xml'];

    private FinfoMimeTypeDetector $types;

    public function __construct(
        private StorageDisks $disks,
        private Connection $connection,
    ) {
        $this->types = new FinfoMimeTypeDetector();
    }

    /**
     * @param string|resource $contents The file's bytes, or a stream to read them from
     * @param string|null     $mimeType What the caller believes the file is; used only when its content
     *                                  doesn't tell (e.g. plain text), and never for HTML, SVG or scripts
     */
    public function store(string $disk, mixed $contents, ?string $mimeType = null): StoredObject
    {
        $files = $this->disks->get($disk);
        [$file, $size, $sha256, $head] = $this->buffer($contents);

        try {
            $type = $this->mimeType($head, $mimeType);
            $key = \sprintf('%s/%s/%s.%s', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256, $this->types->lookupExtension($type) ?? 'bin');

            return $this->connection->transactional(function () use ($files, $disk, $key, $file, $size, $sha256, $type): StoredObject {
                // The garbage collector takes the same lock, so it can't delete the file between our
                // check and our row (held until the outermost transaction ends).
                $this->connection->executeStatement('SELECT pg_advisory_xact_lock(?)', [self::lockKey($disk, $key)]);

                if (!$files->files->fileExists($key) || $files->files->fileSize($key) !== $size) {
                    $this->write($files, $key, $file);
                }

                $this->connection->executeStatement(
                    'INSERT INTO quire.storage_object (disk, key, size, sha256, mime_type) VALUES (?, ?, ?, ?, ?) ON CONFLICT (disk, key) DO UPDATE SET stored_at = now()',
                    [$disk, $key, $size, $sha256, $type],
                );

                return $this->findBy('disk = ? AND key = ?', [$disk, $key]) ?? throw new \LogicException('The stored object disappeared.');
            });
        } finally {
            fclose($file);
        }
    }

    /**
     * @internal The advisory lock that guards one object, shared with the garbage collector
     */
    public static function lockKey(string $disk, string $key): int
    {
        $unpacked = unpack('q', hash('xxh64', $disk.'/'.$key, true));

        return \is_array($unpacked) && \is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;
    }

    public function find(string $id): ?StoredObject
    {
        return Row::isUuid($id) ? $this->findBy('id = ?', [$id]) : null;
    }

    /**
     * @return resource
     */
    public function read(StoredObject $object): mixed
    {
        return $this->disks->get($object->disk)->files->readStream($object->key);
    }

    /**
     * @see Disk::url()
     */
    public function url(StoredObject $object, bool $absolute = false): string
    {
        return $this->disks->get($object->disk)->url($object->key, $absolute);
    }

    /**
     * @see Disk::temporaryUrl()
     */
    public function temporaryUrl(StoredObject $object, \DateTimeInterface $expiresAt): string
    {
        return $this->disks->get($object->disk)->temporaryUrl($object->key, $expiresAt);
    }

    /**
     * Copies the contents to a temporary file, hashing them on the way, so streams of any size work.
     *
     * @param string|resource $contents
     *
     * @return array{resource, int, string, string} The file, its size, its SHA-256 and its first bytes
     */
    private function buffer(mixed $contents): array
    {
        $file = tmpfile();

        if (false === $file) {
            throw new \RuntimeException('Can\'t create a temporary file.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        $head = '';

        $chunks = \is_string($contents) ? [$contents] : (\is_resource($contents) ? $this->chunks($contents) : throw new \InvalidArgumentException('Pass the contents as a string or a stream.'));

        foreach ($chunks as $chunk) {
            hash_update($hash, $chunk);
            fwrite($file, $chunk);
            $size += \strlen($chunk);

            if (\strlen($head) < 8192) {
                $head .= substr($chunk, 0, 8192 - \strlen($head));
            }
        }

        rewind($file);

        return [$file, $size, hash_final($hash), $head];
    }

    /**
     * @param resource $stream
     *
     * @return \Generator<string>
     */
    private function chunks(mixed $stream): \Generator
    {
        while (!feof($stream)) {
            $chunk = fread($stream, 1048576);

            if (false === $chunk) {
                throw new \RuntimeException('Can\'t read the contents.');
            }

            if ('' !== $chunk) {
                yield $chunk;
            }
        }
    }

    private function mimeType(string $head, ?string $claimed): string
    {
        $detected = '' === $head ? null : $this->types->detectMimeTypeFromBuffer($head);

        if (null !== $detected && !\in_array($detected, ['application/octet-stream', 'text/plain'], true)) {
            return $detected;
        }

        $claimed = null === $claimed ? null : strtolower(trim(explode(';', $claimed)[0]));

        if (null !== $claimed && 1 === preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $claimed) && !\in_array($claimed, self::ACTIVE_TYPES, true)) {
            return $claimed;
        }

        return $detected ?? 'application/octet-stream';
    }

    /**
     * @param resource $file
     */
    private function write(Disk $disk, string $key, mixed $file): void
    {
        if ('local' !== $disk->adapter) {
            // An upload to S3 appears whole or not at all.
            $disk->files->writeStream($key, $file);

            return;
        }

        // Locally, a crash mid-write must not leave a partial file under the final name: write next to
        // it, then rename, which is atomic.
        $partial = $key.'.part-'.bin2hex(random_bytes(6));
        $disk->files->writeStream($partial, $file);
        $disk->files->move($partial, $key);
    }

    /**
     * @param list<mixed> $params
     */
    private function findBy(string $condition, array $params): ?StoredObject
    {
        $row = $this->connection->fetchAssociative('SELECT id, disk, key, size, sha256, mime_type, created_at FROM quire.storage_object WHERE '.$condition, $params);

        if (false === $row) {
            return null;
        }

        $row = new Row($row);

        return new StoredObject(
            id: $row->string('id'),
            disk: $row->string('disk'),
            key: $row->string('key'),
            size: $row->int('size'),
            sha256: $row->string('sha256'),
            mimeType: $row->string('mime_type'),
            createdAt: $row->date('created_at'),
        );
    }
}
