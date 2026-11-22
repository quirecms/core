<?php

declare(strict_types=1);

namespace Quire\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Translation\VersionLine;

final class VersionLineTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function providePackages(): iterable
    {
        yield 'before 1.0, the minor version' => [['pretty_version' => '0.5.3'], '0.5'];
        yield 'from 1.0, the major version' => [['pretty_version' => '2.1.0'], '2'];
        yield 'with a v' => [['pretty_version' => 'v1.0.0'], '1'];
        yield 'a pre-release' => [['pretty_version' => '0.6.0-rc.1'], '0.6'];
        yield 'a branch with its number' => [['pretty_version' => '0.5.x-dev'], '0.5'];
        yield 'a branch of a major version' => [['pretty_version' => '1.x-dev'], '1'];
        yield 'a branch with an alias' => [['pretty_version' => 'dev-main', 'extra' => ['branch-alias' => ['dev-main' => '0.5.x-dev']]], '0.5'];
        yield 'a branch without one: no line' => [['pretty_version' => 'dev-main'], null];
        yield 'another branch\'s alias doesn\'t count' => [['pretty_version' => 'dev-feature', 'extra' => ['branch-alias' => ['dev-main' => '0.5.x-dev']]], null];
        yield 'no version: no line' => [[], null];
        yield '0.x without its minor: no line' => [['pretty_version' => '0.x-dev'], null];
    }

    /**
     * @param array<string, mixed> $package
     */
    #[DataProvider('providePackages')]
    public function testThePackagesVersionLine(array $package, ?string $line): void
    {
        self::assertSame($line, VersionLine::ofPackage($package));
    }
}
