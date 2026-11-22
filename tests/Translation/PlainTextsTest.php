<?php

declare(strict_types=1);

namespace Quire\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Translation\PlainTexts;
use Symfony\Component\Filesystem\Filesystem;

final class PlainTextsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/quire-plain-texts-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testTextsNestedOrNot(): void
    {
        self::assertSame(['Hello' => 'Здраво', 'menu.home' => 'Почетна', 'menu.more.all' => 'Сите'], PlainTexts::read($this->file("Hello: Здраво\nmenu:\n    home: Почетна\n    more: {all: Сите}\n")));
        self::assertSame([], PlainTexts::read($this->file('')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideWhatIsntTexts(): iterable
    {
        yield 'a PHP constant' => ["Hello: !php/const PHP_VERSION\n", 'isn\'t plain texts'];
        yield 'a class constant, which would autoload the class' => ["Hello: !php/const Quire\\QuireBundle::class\n", 'isn\'t plain texts'];
        yield 'a PHP enum' => ["Hello: !php/enum Quire\\Translation\\TranslationDelivery::Hub\n", 'isn\'t plain texts'];
        yield 'a PHP object' => ["Hello: !php/object 'O:8:\"stdClass\":0:{}'\n", 'isn\'t plain texts'];
        yield 'a custom tag' => ["Hello: !my_tag value\n", 'isn\'t plain texts'];
        yield 'a number' => ["Hello: 42\n", '"Hello" is int, not a text'];
        yield 'a list' => ["Hello: [a, b]\n", '"Hello" is array, not a text'];
        yield 'a text alone' => ["Hello\n", 'it isn\'t a map of texts'];
    }

    #[DataProvider('provideWhatIsntTexts')]
    public function testWhatIsntTextsIsRefused(string $yaml, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($why);

        PlainTexts::read($this->file($yaml));
    }

    public function testATooLargeFileOrALinkIsRefused(): void
    {
        $large = $this->file(str_repeat('a', PlainTexts::MAX_BYTES + 1));
        symlink($this->file("Hello: Hi\n"), $link = $this->dir.'/link.yaml');

        foreach ([$large => 'is larger than', $link => 'isn\'t a file'] as $file => $why) {
            try {
                PlainTexts::read($file);
                self::fail($why);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    private function file(string $content): string
    {
        $file = tempnam($this->dir, 'texts');
        file_put_contents($file, $content);

        return $file;
    }
}
