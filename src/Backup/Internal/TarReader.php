<?php

declare(strict_types=1);

namespace Quire\Backup\Internal;

use Quire\Backup\Exception\BackupException;

/**
 * Reads the entries of a tar archive in a file: their names and where their contents are, without
 * reading the contents until they're asked for.
 *
 * @internal
 */
final readonly class TarReader
{
    private const int BLOCK = 512;

    public function __construct(
        private string $file,
    ) {
    }

    /**
     * Whether the file is a tar archive: ustar's magic is at byte 257 of the first header.
     */
    public static function isArchive(string $file): bool
    {
        $handle = @fopen($file, 'r');

        if (false === $handle) {
            return false;
        }

        $header = fread($handle, self::BLOCK);
        fclose($handle);

        return \is_string($header) && self::BLOCK === \strlen($header) && str_starts_with(substr($header, 257, 6), 'ustar');
    }

    /**
     * Regular files only, by path.
     *
     * @return array<string, array{offset: int, size: int}>
     */
    public function entries(): array
    {
        $handle = $this->open();
        $entries = [];

        try {
            while (true) {
                $header = fread($handle, self::BLOCK);

                if (false === $header || self::BLOCK !== \strlen($header) || "\0" === $header[0]) {
                    break;
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                $size = $this->size(substr($header, 124, 12));
                $type = $header[156];
                $offset = (int) ftell($handle);

                if ('0' === $type || "\0" === $type) {
                    $entries['' === $prefix ? $name : $prefix.'/'.$name] = ['offset' => $offset, 'size' => $size];
                }

                fseek($handle, $offset + (int) (ceil($size / self::BLOCK) * self::BLOCK));
            }
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    /**
     * Copies an entry's contents to a stream.
     *
     * @param array{offset: int, size: int} $entry
     * @param resource                      $target
     * @param (\Closure(string): void)|null $inspect Sees each chunk, e.g. to hash it
     */
    public function copy(array $entry, mixed $target, ?\Closure $inspect = null): void
    {
        $handle = $this->open();

        try {
            fseek($handle, $entry['offset']);
            $left = $entry['size'];

            while ($left > 0) {
                $chunk = fread($handle, min(1048576, $left));

                if (false === $chunk || '' === $chunk) {
                    throw new BackupException(\sprintf('"%s" is cut short: the archive is incomplete.', $this->file));
                }

                if ($inspect instanceof \Closure) {
                    $inspect($chunk);
                }

                fwrite($target, $chunk);
                $left -= \strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{offset: int, size: int} $entry
     */
    public function read(array $entry): string
    {
        $memory = fopen('php://memory', 'w+');
        \assert(\is_resource($memory));
        $this->copy($entry, $memory);
        rewind($memory);

        return (string) stream_get_contents($memory);
    }

    /**
     * @return resource
     */
    private function open(): mixed
    {
        $handle = @fopen($this->file, 'r');

        return false === $handle ? throw new BackupException(\sprintf('Can\'t read "%s".', $this->file)) : $handle;
    }

    private function size(string $field): int
    {
        if (0 !== (\ord($field[0]) & 0x80)) {
            $unpacked = unpack('J', substr($field, 4, 8));

            return \is_array($unpacked) && \is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;
        }

        return (int) octdec(trim($field, "\0 "));
    }
}
