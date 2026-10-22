<?php

declare(strict_types=1);

namespace @@NAMESPACE@@\Tests;

use Quire\Testing\ExtensionTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ThemeTest extends ExtensionTestCase
{
    #[\Override]
    protected static ?string $theme = '@@ID@@';

    public function testPagesUseTheThemesStylesheet(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('head link[rel=stylesheet][href*="/@@ID@@/styles-"]');
    }

    public function testTheThemePassesTheChecks(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('quire:theme:lint'));

        self::assertSame(0, $tester->execute(['id' => '@@ID@@']), $tester->getDisplay());
    }
}
