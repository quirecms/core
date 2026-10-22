<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Query\Internal\QueryRunner;
use Quire\Extend\ContentType;
use Quire\Extend\ExtenderContext;
use Quire\Extend\Query;
use Quire\Tests\Extend\Fixtures\FixtureRelationLoader;
use Quire\Tests\Extend\Fixtures\FixtureScope;
use Quire\Tests\Extend\Fixtures\NotInvokable;

final class QueryTest extends ExtenderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        new ContentType('post')->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));
        new ContentType('page')->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));
    }

    public function testRegistersScopesVisibilityRulesAndRelations(): void
    {
        $this->apply(new Query()
            ->scope('tagged', FixtureScope::class, types: ['post'])
            ->visibility(FixtureScope::class)
            ->relation('reviews', FixtureRelationLoader::class, types: ['post', 'page']));

        self::assertSame([
            'scopes' => ['tagged' => ['class' => FixtureScope::class, 'types' => ['post'], 'owner' => 'acme-reviews']],
            'visibility' => [['class' => FixtureScope::class, 'types' => null, 'owner' => 'acme-reviews']],
            'relations' => ['reviews' => ['class' => FixtureRelationLoader::class, 'types' => ['post', 'page'], 'owner' => 'acme-reviews']],
        ], $this->container->getParameterBag()->all()[QueryRunner::PARAMETER]);
        self::assertTrue($this->container->getDefinition(FixtureScope::class)->isAutowired());
        self::assertTrue($this->container->hasDefinition(FixtureRelationLoader::class));
    }

    public function testExtensionsAddToEachOther(): void
    {
        new Query()->scope('tagged', FixtureScope::class)
            ->extend(new ExtenderContext('acme-tags', __DIR__, $this->container, []));

        $this->assertExtenderFails(new Query()->scope('tagged', FixtureScope::class), 'Extend\Query::scope("tagged"): The scope is already added by "acme-tags".');
    }

    public function testRejectsInvalidRegistrations(): void
    {
        $this->assertExtenderFails(new Query()->scope('Featured', FixtureScope::class), 'Names start with a lowercase letter');
        $this->assertExtenderFails(new Query()->scope('featured', NotInvokable::class), \sprintf('The class "%s" must have a method __invoke(ContentQuery $query, ...): ContentQuery.', NotInvokable::class));
        // @phpstan-ignore argument.type (deliberately not a class)
        $this->assertExtenderFails(new Query()->scope('featured', 'Acme\Missing'), 'The class "Acme\Missing" doesn\'t exist.');
        $this->assertExtenderFails(new Query()->scope('featured', FixtureScope::class, types: ['product']), 'Unknown content type "product". If another extension provides it, require that extension');
        $this->assertExtenderFails(new Query()->visibility(FixtureScope::class, types: []), 'Give at least one content type, or null for all types.');
        $this->assertExtenderFails(new Query()->relation('title', FixtureRelationLoader::class), '"title" is a property of content items; choose another name.');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new Query()->relation('reviews', FixtureScope::class), \sprintf('The class "%s" must implement Quire\Content\Query\RelationLoaderInterface.', FixtureScope::class));
    }
}
