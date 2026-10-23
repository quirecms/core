<?php

declare(strict_types=1);

namespace Quire\Storage;

use League\Flysystem\FilesystemOperator;

/**
 * A storage disk: where files of one kind are kept, e.g. on a local volume or in an S3 bucket.
 *
 * Store files with {@see ObjectStore}, which names them after their content and records them, so they
 * can be referenced and are cleaned up when nothing uses them. {@see $files} is the disk itself, for
 * files you manage on your own.
 *
 * @api
 */
final readonly class Disk
{
    /**
     * @param string                                       $adapter      "local", "s3" or "service"
     * @param string                                       $owner        ID of the extension that declared the disk, or "site"
     * @param string                                       $location     Where the files are, for people, e.g. "s3: photos at https://…"
     * @param \Closure(string, bool): string               $publicUrl    Builds the URL of a file on a public disk
     * @param \Closure(string, \DateTimeInterface): string $temporaryUrl Builds a signed, expiring URL
     */
    public function __construct(
        public string $name,
        public bool $public,
        public string $adapter,
        public string $owner,
        public ?string $label,
        public string $location,
        public FilesystemOperator $files,
        private \Closure $publicUrl,
        private \Closure $temporaryUrl,
    ) {
    }

    /**
     * The URL of a file on a public disk, for everyone; absolute (with the site's address) for use
     * outside its pages, e.g. in emails and feeds.
     *
     * @throws \LogicException for private disks, whose files need {@see temporaryUrl()}
     */
    public function url(string $key, bool $absolute = false): string
    {
        if (!$this->public) {
            throw new \LogicException(\sprintf('The disk "%s" is private: its files have no public URL. Use temporaryUrl() for a signed one.', $this->name));
        }

        return ($this->publicUrl)($key, $absolute);
    }

    /**
     * A URL that works until it expires, e.g. for a download that only members may get: check that the
     * viewer may have the file, then send them there. Signed by the service for S3 disks, by Quire
     * otherwise.
     */
    public function temporaryUrl(string $key, \DateTimeInterface $expiresAt): string
    {
        return ($this->temporaryUrl)($key, $expiresAt);
    }
}
