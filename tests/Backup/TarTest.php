<?php

declare(strict_types=1);

namespace Quire\Tests\Backup;

use PHPUnit\Framework\TestCase;
use Quire\Backup\Exception\BackupException;
use Quire\Backup\Internal\TarReader;
use Quire\Backup\Internal\TarWriter;
use Symfony\Component\Process\Process;

final class TarTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/quire-tar-'.bin2hex(random_bytes(4)).'.tar';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testArchivesReadBackAndAreStandard(): void
    {
        $long = 'files/quire-fixture-hello-private/3a/7b/'.str_repeat('3a7b', 16).'.jpeg';
        $this->write(static function (TarWriter $tar) use ($long): void {
            $tar->addString('manifest.json', '{"format":"quire-backup"}');
            $stream = fopen('php://memory', 'w+');
            \assert(\is_resource($stream));
            fwrite($stream, str_repeat('x', 1000));
            rewind($stream);
            $tar->addStream($long, $stream, 1000);
            $tar->addString('empty.txt', '');
        });

        self::assertTrue(TarReader::isArchive($this->file));
        $reader = new TarReader($this->file);
        $entries = $reader->entries();
        self::assertSame(['manifest.json', $long, 'empty.txt'], array_keys($entries), 'Paths over 100 bytes use the prefix field.');
        self::assertSame('{"format":"quire-backup"}', $reader->read($entries['manifest.json']));
        self::assertSame(str_repeat('x', 1000), $reader->read($entries[$long]));
        self::assertSame('', $reader->read($entries['empty.txt']));

        $tar = new Process(['tar', '-tvf', $this->file]);
        $tar->mustRun();
        self::assertStringContainsString($long, $tar->getOutput(), 'GNU tar reads it.');
        self::assertStringContainsString(' 1000 ', $tar->getOutput());
    }

    public function testAStreamThatEndsEarlyFails(): void
    {
        $this->expectException(BackupException::class);
        $this->expectExceptionMessageIsOrContains('"short.txt" ended after 3 of 10 bytes.');

        $this->write(static function (TarWriter $tar): void {
            $stream = fopen('php://memory', 'w+');
            \assert(\is_resource($stream));
            fwrite($stream, 'abc');
            rewind($stream);
            $tar->addStream('short.txt', $stream, 10);
        });
    }

    public function testSizesOver8GBAreWrittenInBase256(): void
    {
        $header = new \ReflectionMethod(TarWriter::class, 'header')->invoke(new TarWriter(static function (string $data): void {}), 'video.mp4', 10 * 1024 ** 3, 0);
        \assert(\is_string($header));

        self::assertSame("\x80", $header[124], 'Base-256 marker.');
        self::assertSame(10 * 1024 ** 3, new \ReflectionMethod(TarReader::class, 'size')->invoke(new TarReader($this->file), substr($header, 124, 12)));
    }

    public function testOtherFilesArentArchives(): void
    {
        file_put_contents($this->file, "PGDMP\x01\x10");
        self::assertFalse(TarReader::isArchive($this->file));
        self::assertFalse(TarReader::isArchive($this->file.'.missing'));
    }

    /**
     * @param \Closure(TarWriter): void $write
     */
    private function write(\Closure $write): void
    {
        $handle = fopen($this->file, 'w');
        \assert(\is_resource($handle));

        try {
            $tar = new TarWriter(static function (string $data) use ($handle): void {
                fwrite($handle, $data);
            });
            $write($tar);
            $tar->finish();
        } finally {
            fclose($handle);
        }
    }
}
