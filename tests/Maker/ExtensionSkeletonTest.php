<?php

declare(strict_types=1);

namespace Quire\Tests\Maker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Maker\ExtensionSkeleton;
use Symfony\Component\Process\Process;

final class ExtensionSkeletonTest extends TestCase
{
    public function testDerivesNamesFromThePackage(): void
    {
        $skeleton = new ExtensionSkeleton('acme/star-reviews');

        self::assertSame('acme-star-reviews', $skeleton->id);
        self::assertSame('star-reviews', $skeleton->name);
        self::assertSame('Acme\StarReviews', $skeleton->namespace);
        self::assertSame('Star Reviews', $skeleton->title);
        self::assertSame('/star-reviews', $skeleton->path);
        self::assertSame('acme_star_reviews_message', $skeleton->table);
    }

    public function testCustomTitle(): void
    {
        self::assertSame('Reviews & Ratings', new ExtensionSkeleton('acme/reviews', 'Reviews & Ratings')->title);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidNames(): iterable
    {
        yield 'no vendor' => ['reviews'];
        yield 'uppercase' => ['Acme/Reviews'];
        yield 'leading digit' => ['acme/1reviews'];
        yield 'spaces' => ['acme/star reviews'];
        yield 'three parts' => ['acme/star/reviews'];
    }

    #[DataProvider('provideInvalidNames')]
    public function testRejectsInvalidPackageNames(string $package): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('is not a valid package name. Use "vendor/name" in lowercase');

        new ExtensionSkeleton($package);
    }

    public function testRendersEveryFileWithoutLeftoverPlaceholders(): void
    {
        $files = new ExtensionSkeleton('acme/star-reviews', now: new \DateTimeImmutable('2026-11-01 12:00:00'))->files();

        self::assertSame([
            '.gitignore',
            'README.md',
            'composer.json',
            'extend.php',
            'migrations/Version20261101120000.php',
            'phpunit.dist.xml',
            'src/Controller/MessagesController.php',
            'templates/messages.html.twig',
            'tests/MessagesTest.php',
            'tests/bootstrap.php',
        ], array_keys($files));

        foreach ($files as $path => $contents) {
            self::assertStringNotContainsString('@@', str_replace('@acme_star_reviews', '', $contents), \sprintf('%s contains an unreplaced placeholder.', $path));
        }

        self::assertStringContainsString("namespace Acme\StarReviews\Migrations;\n", $files['migrations/Version20261101120000.php']);
        self::assertStringContainsString('final class Version20261101120000 extends AbstractMigration', $files['migrations/Version20261101120000.php']);
        self::assertStringContainsString("->autoload('Acme\\\\StarReviews\\\\', __DIR__.'/src')", $files['extend.php']);
        self::assertStringContainsString("render('@acme_star_reviews/messages.html.twig'", $files['src/Controller/MessagesController.php']);
    }

    public function testGeneratedComposerJsonIsValid(): void
    {
        $json = json_decode(new ExtensionSkeleton('acme/star-reviews')->files()['composer.json'], true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($json);
        self::assertSame('quire-extension', $json['type']);
        self::assertIsArray($json['autoload']);
        self::assertSame(['Acme\StarReviews\\' => 'src/'], $json['autoload']['psr-4']);
        self::assertIsArray($json['extra']);
        self::assertSame(['id' => 'acme-star-reviews', 'title' => 'Star Reviews'], $json['extra']['quire']);
    }

    public function testGeneratedPhpIsValid(): void
    {
        foreach (new ExtensionSkeleton('acme/star-reviews')->files() as $path => $contents) {
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $process = new Process([\PHP_BINARY, '-l']);
            $process->setInput($contents);
            $process->run();

            self::assertSame(0, $process->getExitCode(), \sprintf("%s doesn't parse:\n%s", $path, $process->getOutput()));
        }
    }
}
