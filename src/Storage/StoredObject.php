<?php

declare(strict_types=1);

namespace Quire\Storage;

/**
 * A file kept on a storage disk, as {@see ObjectStore::store()} recorded it.
 *
 * Its key is derived from its content, so it never changes: the same bytes on the same disk are one
 * object, and a URL to it can be cached for good. Reference it from your own tables by its ID, with a
 * foreign key to quire.storage_object(id); it's deleted only once nothing references it.
 *
 * @api
 */
final readonly class StoredObject
{
    /**
     * @param string $key    Path on the disk, e.g. "3a/7b/3a7b…e1.jpg"
     * @param string $sha256 Hex-encoded SHA-256 of the content
     */
    public function __construct(
        public string $id,
        public string $disk,
        public string $key,
        public int $size,
        public string $sha256,
        public string $mimeType,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
