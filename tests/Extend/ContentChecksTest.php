<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Finding;
use Quire\Content\Check\Level;
use Quire\Content\Content;
use Quire\Extend\ContentChecks;

final class ContentChecksTest extends ExtenderTestCase
{
    public function testExtensionsAddChecks(): void
    {
        $this->apply(new ContentChecks()->check('acme-reviews.stars', StarsCheck::class, 'Reviews have stars', level: Level::Error, types: ['review']));

        self::assertSame([[
            'id' => 'acme-reviews.stars', 'class' => StarsCheck::class, 'label' => 'Reviews have stars', 'level' => 'error', 'types' => ['review'], 'owner' => 'acme-reviews',
        ]], $this->parameter(ContentChecks::PARAMETER));
        self::assertTrue($this->container->getDefinition(StarsCheck::class)->hasTag(ContentChecks::TAG));
    }

    public function testIdsAndClasses(): void
    {
        $this->assertExtenderFails(new ContentChecks()->check('stars', StarsCheck::class, 'Stars'), 'Check IDs start with "acme-reviews.", followed by lowercase letters');
        $this->assertExtenderFails(new ContentChecks()->check('acme-other.stars', StarsCheck::class, 'Stars'), 'Check IDs start with "acme-reviews."');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new ContentChecks()->check('acme-reviews.stars', \stdClass::class, 'Stars'), 'Content checks are classes implementing Quire\Content\Check\ContentCheckInterface.');
        $this->assertExtenderFails(new ContentChecks()->check('acme-reviews.stars', StarsCheck::class, 'Stars', types: []), 'Give the content types as a list of IDs');

        $this->apply(new ContentChecks()->check('acme-reviews.stars', StarsCheck::class, 'Stars'));
        $this->assertExtenderFails(new ContentChecks()->check('acme-reviews.stars', StarsCheck::class, 'Stars'), 'The check is already added by "acme-reviews".');
    }
}

final class StarsCheck implements ContentCheckInterface
{
    public function check(Content $item): iterable
    {
        yield new Finding('No stars.');
    }
}
