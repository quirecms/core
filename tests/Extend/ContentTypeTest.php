<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Content\Type\Exception\UnknownContentTypeException;
use Quire\Extend\ContentType;
use Quire\Extend\ExtenderContext;

final class ContentTypeTest extends ExtenderTestCase
{
    public function testRegistersATypeWithDefaults(): void
    {
        $this->apply(new ContentType('product_line'));

        $type = $this->registry()->get('product_line');
        self::assertSame('Product line', $type->label);
        self::assertSame('Product lines', $type->pluralLabel);
        self::assertSame('acme-reviews', $type->owner);
        self::assertSame([], $type->capabilities);
        self::assertSame('/product-line/{slug}', $type->permalink);
        self::assertSame(['published_at', 'desc'], [$type->sortField, $type->sortDirection]);
    }

    public function testHierarchicalDefaults(): void
    {
        $this->apply(new ContentType('doc')->capabilities(Capability::HIERARCHICAL));

        $type = $this->registry()->get('doc');
        self::assertSame('/doc/{path}', $type->permalink);
        self::assertSame(['position', 'asc'], [$type->sortField, $type->sortDirection]);
    }

    public function testRegistersAConfiguredType(): void
    {
        $this->apply(new ContentType('product')
            ->label('Product', 'Products')
            ->capabilities(Capability::REVISIONS, Capability::LISTABLE)
            ->addCapabilities('reviewable', Capability::LISTABLE)
            ->permalink('/shop/{year}/{slug}.html')
            ->sortBy('title'));

        $registry = $this->registry();
        $type = $registry->get('product');
        self::assertSame(['revisions', 'listable', 'reviewable'], $type->capabilities);
        self::assertTrue($type->has('reviewable'));
        self::assertFalse($type->has(Capability::HIERARCHICAL));
        self::assertSame('/shop/{year}/{slug}.html', $type->permalink);
        self::assertSame(['title', 'asc'], [$type->sortField, $type->sortDirection]);
        self::assertSame(['product'], array_keys($registry->withCapability('reviewable')));
        self::assertSame([], $registry->withCapability(Capability::HIERARCHICAL));
    }

    public function testAddsCapabilitiesToAnExistingType(): void
    {
        new ContentType('post')->capabilities(Capability::LISTABLE)
            ->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));

        $this->apply(ContentType::existing('post')->addCapabilities('commentable', Capability::LISTABLE));

        $post = $this->registry()->get('post');
        self::assertSame(['listable', 'commentable'], $post->capabilities);
        self::assertSame('core', $post->owner, 'The type still belongs to whoever registered it.');
    }

    public function testInvalidRegistrations(): void
    {
        $this->assertExtenderFails(new ContentType('Product'), 'Type IDs start with a lowercase letter');
        $this->assertExtenderFails(new ContentType('product-line'), 'Type IDs start with a lowercase letter');
        $this->assertExtenderFails(new ContentType('product')->capabilities('Listable'), '"Listable" isn\'t a valid capability');
        $this->assertExtenderFails(new ContentType('product')->permalink('products/{slug}'), 'must start with "/"');
        $this->assertExtenderFails(new ContentType('product')->permalink('/Products/{slug}'), 'must start with "/"');
        $this->assertExtenderFails(new ContentType('product')->permalink('/products/{name}'), 'uses the unknown placeholder {name}. Use: {slug}, {path}, {id}, {year}, {month}, {day}.');
        $this->assertExtenderFails(new ContentType('product')->permalink('/products/{year}'), 'needs {slug}, {path} or {id}');
        $this->assertExtenderFails(new ContentType('product')->permalink('/{slug}/{slug}'), 'uses a placeholder more than once');
        $this->assertExtenderFails(new ContentType('product')->permalink('/{path}'), 'uses {path}, which only hierarchical types have');
        $this->assertExtenderFails(new ContentType('product')->sortBy('price'), 'Listings can\'t be sorted by "price". Use one of: published_at, created_at, updated_at, title, position.');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new ContentType('product')->sortBy('title', 'up'), 'The sort direction is "asc" or "desc".');
    }

    public function testDuplicateTypesAreRejected(): void
    {
        $this->apply(new ContentType('product'));

        $this->assertExtenderFails(new ContentType('product'), 'The type is already registered by "acme-reviews". To add capabilities to it, use Extend\ContentType::existing("product").');
    }

    public function testInvalidModifications(): void
    {
        $this->assertExtenderFails(ContentType::existing('product')->addCapabilities('reviewable'), 'No content type with this ID is registered yet');

        $this->apply(new ContentType('product'));

        $this->assertExtenderFails(ContentType::existing('product')->permalink('/p/{slug}'), 'Only capabilities can be added to a type registered elsewhere.');
        $this->assertExtenderFails(ContentType::existing('product')->addCapabilities(Capability::HIERARCHICAL), 'Only the extension that registers a type can make it hierarchical.');
    }

    public function testUnknownTypesSuggestCloseMatches(): void
    {
        $this->apply(new ContentType('product'));

        $this->expectException(UnknownContentTypeException::class);
        $this->expectExceptionMessageIsOrContains('No content type "prodcut" is registered. Register it with Extend\ContentType in extend.php, or enable the extension that provides it. Did you mean "product"?');

        $this->registry()->get('prodcut');
    }

    private function registry(): ContentTypeRegistry
    {
        /** @var array<string, array<string, mixed>> $types */
        $types = $this->container->getParameterBag()->all()[ContentTypeRegistry::PARAMETER];

        return new ContentTypeRegistry($types);
    }
}
