<?php

declare(strict_types=1);

namespace Quire\Tests\Theme;

use PHPUnit\Framework\TestCase;
use Quire\Csp\Csp;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Theme\Internal\OtherSitesCheck;
use Quire\Theme\TemplateUrls;

/**
 * What a template loads from other sites, as written in it, and the warning for what the site's policy
 * blocks.
 */
final class TemplateUrlsTest extends TestCase
{
    public function testWhatLoadsAndWhatDecidesAboutIt(): void
    {
        $source = <<<'TWIG'
            <link rel="stylesheet" href="https://fonts.example/css">
            <link rel="preconnect" href="https://fonts.example">
            <link rel="preload" as="font" href="https://fonts.example/a.woff2" crossorigin>
            <link rel="canonical" href="https://site.example/">
            <script src="//cdn.example/a.js"></script>
            <iframe src='https://player.example/1'></iframe>
            <img src="/local.png" srcset="/a.png 1x, https://images.example/a.png 2x" alt="">
            <picture><source srcset="https://images.example/b.webp"></picture>
            <video poster="https://images.example/p.png"><source src="https://media.example/v.mp4"></video>
            <a href="https://elsewhere.example/">a link loads nothing</a>
            <img src="https://{{ host }}/made-in-twig.png" alt="">
            TWIG;

        self::assertSame([
            ['https://fonts.example/css', 'link', 'style-src', 1],
            ['https://fonts.example', 'link', 'default-src', 2],
            ['https://fonts.example/a.woff2', 'link', 'font-src', 3],
            ['https://cdn.example/a.js', 'script', 'script-src', 5],
            ['https://player.example/1', 'iframe', 'frame-src', 6],
            ['https://images.example/a.png', 'img', 'img-src', 7],
            ['https://images.example/b.webp', 'source', 'img-src', 8],
            ['https://images.example/p.png', 'video', 'media-src', 9],
            ['https://media.example/v.mp4', 'source', 'media-src', 9],
        ], array_map(static fn (array $found): array => [$found['url'], $found['element'], $found['directive'], $found['line']], TemplateUrls::find($source)));
    }

    public function testWhatThePolicyBlocksIsAWarning(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quire-template');
        file_put_contents($file, "<img src=\"https://images.example/a.png\" alt=\"\">\n<script src=\"https://cdn.example/a.js\"></script>");
        $check = new OtherSitesCheck(new Csp(new NoThirdPartySources(), siteSources: ['img-src' => ['https://images.example']]));

        try {
            $problems = $check->check('acme', ['layout.html.twig' => $file]);
        } finally {
            unlink($file);
        }

        self::assertCount(1, $problems);
        self::assertFalse($problems[0]->error);
        self::assertStringStartsWith('layout.html.twig line 2: loads https://cdn.example/a.js, which the site\'s Content-Security-Policy blocks (script-src).', $problems[0]->message);
    }
}
