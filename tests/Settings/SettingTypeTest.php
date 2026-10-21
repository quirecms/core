<?php

declare(strict_types=1);

namespace Quire\Tests\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Settings\SettingType;

final class SettingTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{SettingType, string, mixed}>
     */
    public static function provideValidText(): iterable
    {
        yield 'string' => [SettingType::String, 'My site', 'My site'];
        yield 'empty string' => [SettingType::String, '', ''];
        yield 'bool true' => [SettingType::Bool, 'true', true];
        yield 'bool yes' => [SettingType::Bool, 'yes', true];
        yield 'bool 0' => [SettingType::Bool, '0', false];
        yield 'bool off' => [SettingType::Bool, 'off', false];
        yield 'int' => [SettingType::Int, '42', 42];
        yield 'negative int' => [SettingType::Int, '-3', -3];
        yield 'float' => [SettingType::Float, '1.5', 1.5];
        yield 'enum' => [SettingType::Enum, 'oldest', 'oldest'];
        yield 'list' => [SettingType::List, '["php","cms"]', ['php', 'cms']];
        yield 'json object' => [SettingType::Json, '{"a":1}', ['a' => 1]];
        yield 'json null' => [SettingType::Json, 'null', null];
    }

    #[DataProvider('provideValidText')]
    public function testParsesText(SettingType $type, string $text, mixed $expected): void
    {
        self::assertSame($expected, $type->parse($text, ['newest', 'oldest']));
    }

    /**
     * @return iterable<string, array{SettingType, string, string}>
     */
    public static function provideInvalidText(): iterable
    {
        yield 'bool' => [SettingType::Bool, 'maybe', 'use true, false, 1, 0, yes, no, on or off'];
        yield 'int' => [SettingType::Int, '4.2', 'use a whole number'];
        yield 'float' => [SettingType::Float, 'abc', 'use a number'];
        yield 'enum' => [SettingType::Enum, 'random', 'expected one of "newest", "oldest"'];
        yield 'list of non-strings' => [SettingType::List, '[1,2]', 'expected a list of strings'];
        yield 'list as object' => [SettingType::List, '{"a":"b"}', 'expected a list of strings'];
        yield 'broken json' => [SettingType::Json, '{', 'use valid JSON'];
    }

    #[DataProvider('provideInvalidText')]
    public function testRejectsInvalidText(SettingType $type, string $text, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('"%s" is not a valid %s value: %s', $text, $type->value, $expectedMessage));

        $type->parse($text, ['newest', 'oldest']);
    }

    public function testValidate(): void
    {
        self::assertNull(SettingType::Float->validate(3), 'Integers are valid numbers.');
        self::assertSame('expected an integer', SettingType::Int->validate('3'));
        self::assertSame('expected true or false', SettingType::Bool->validate(1));
        self::assertNull(SettingType::Enum->validate(2, [1, 2]));
        self::assertSame('expected a JSON-encodable value', SettingType::Json->validate(\NAN));
    }
}
