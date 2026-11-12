<?php

declare(strict_types=1);

namespace Quire\Storage\Internal;

use Quire\Csp\Internal\FirstPartySourcesInterface;
use Quire\Csp\Policy;
use Quire\Storage\StorageDisks;

/**
 * The site's own files that are elsewhere: public disks on S3 or behind a CDN, whose images and media
 * pages show. Local disks are the site itself.
 *
 * @internal
 */
final class DiskSources implements FirstPartySourcesInterface
{
    private const string PROBE = '00/00/0000000000000000000000000000000000000000000000000000000000000000.png';

    /** @var array<string, list<string>>|null The disks' configuration is fixed: worked out once per process */
    private ?array $sources = null;

    public function __construct(private readonly StorageDisks $disks)
    {
    }

    public function sources(): array
    {
        if (null !== $this->sources) {
            return $this->sources;
        }

        $origins = [];

        foreach ($this->disks->names() as $name) {
            $disk = $this->disks->get($name);

            if (!$disk->public) {
                continue;
            }

            try {
                // Any file's address names the disk's host.
                $parts = parse_url($disk->url(self::PROBE));
            } catch (\Exception) {
                // A disk that can't make addresses breaks no page: its files are what fail to show.
                continue;
            }

            if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                continue;
            }

            $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

            if (Policy::isHost($origin)) {
                $origins[$origin] = true;
            }
        }

        $origins = array_keys($origins);

        return $this->sources = [] === $origins ? [] : ['img-src' => $origins, 'media-src' => $origins];
    }
}
