<?php

declare(strict_types=1);

namespace Quire\Backup\Internal;

use Quire\Backup\Exception\BackupException;

/**
 * Writes a tar archive (POSIX ustar) as it's made, so a backup can go to standard output. Entries over
 * 8 GB get their size in base-256, as GNU tar and libarchive read it.
 *
 * @internal
 */
final readonly class TarWriter
{
    private const int BLOCK = 512;

    /**
     * @param \Closure(string): void $output
     */
    public function __construct(
        private \Closure $output,
    ) {
    }

    public function addString(string $name, string $contents, ?int $modified = null): void
    {
        ($this->output)($this->header($name, \strlen($contents), $modified ?? time()));
        ($this->output)($contents);
        $this->pad(\strlen($contents));
    }

    /**
     * Copies exactly $size bytes from the stream into the archive.
     *
     * @param resource                      $stream
     * @param (\Closure(string): void)|null $inspect Sees each chunk, e.g. to hash it
     *
     * @throws BackupException when the stream ends early: the archive is broken then
     */
    public function addStream(string $name, mixed $stream, int $size, ?int $modified = null, ?\Closure $inspect = null): void
    {
        ($this->output)($this->header($name, $size, $modified ?? time()));
        $left = $size;

        while ($left > 0) {
            $chunk = fread($stream, min(1048576, $left));

            if (false === $chunk || '' === $chunk) {
                throw new BackupException(\sprintf('"%s" ended after %d of %d bytes.', $name, $size - $left, $size));
            }

            if ($inspect instanceof \Closure) {
                $inspect($chunk);
            }

            ($this->output)($chunk);
            $left -= \strlen($chunk);
        }

        $this->pad($size);
    }

    public function finish(): void
    {
        ($this->output)(str_repeat("\0", 2 * self::BLOCK));
    }

    private function pad(int $size): void
    {
        $rest = $size % self::BLOCK;

        if (0 !== $rest) {
            ($this->output)(str_repeat("\0", self::BLOCK - $rest));
        }
    }

    private function header(string $path, int $size, int $modified): string
    {
        [$prefix, $name] = $this->split($path);

        $header = str_pad($name, 100, "\0")
            .$this->octal(0o600, 8)
            .$this->octal(0, 8)
            .$this->octal(0, 8)
            .$this->size($size)
            .$this->octal($modified, 12)
            .str_repeat(' ', 8)
            .'0'
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_pad('quire', 32, "\0")
            .str_pad('quire', 32, "\0")
            .$this->octal(0, 8)
            .$this->octal(0, 8)
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);

        $checksum = array_sum(array_map(\ord(...), str_split($header)));

        return substr_replace($header, \sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    /**
     * ustar keeps paths in a 100-byte name and a 155-byte prefix, split at a "/".
     *
     * @return array{string, string}
     */
    private function split(string $path): array
    {
        if (\strlen($path) <= 100) {
            return ['', $path];
        }

        $parts = explode('/', $path);

        for ($at = \count($parts) - 1; $at > 0; --$at) {
            $prefix = implode('/', \array_slice($parts, 0, $at));
            $name = implode('/', \array_slice($parts, $at));

            if (\strlen($prefix) <= 155 && \strlen($name) <= 100) {
                return [$prefix, $name];
            }
        }

        throw new BackupException(\sprintf('The path "%s" is too long for the archive.', $path));
    }

    private function octal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', \STR_PAD_LEFT)."\0";
    }

    private function size(int $size): string
    {
        if ($size <= 0o77777777777) {
            return $this->octal($size, 12);
        }

        // Base-256: the first byte's high bit set, then the size big-endian.
        return "\x80\0\0\0".pack('J', $size);
    }
}
