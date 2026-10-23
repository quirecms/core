<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Field\FieldType;
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

    public function testDeclaresFields(): void
    {
        $this->apply(new ContentType('product')
            ->field('price', 'float', default: 10, required: true, min: 0, sortable: true, label: 'Price', group: 'pricing')
            ->field('sku', FieldType::String, filterable: true)
            ->field('notes', 'text', widget: 'textarea', order: -10)
            ->field('launch', 'datetime', default: new \DateTimeImmutable('2026-01-01 12:00', new \DateTimeZone('Europe/Berlin')))
            ->sortBy('fields.price', 'desc'));

        $type = $this->registry()->get('product');
        self::assertSame(['notes', 'price', 'sku', 'launch'], array_keys($type->fields), 'Declaration order, unless an order is given.');

        $price = $type->field('price');
        self::assertNotNull($price);
        self::assertSame(['float', 10.0, true, 0, 'acme-reviews', 'Price', 'pricing'], [$price->type, $price->default, $price->required, $price->min, $price->owner, $price->label, $price->group]);
        self::assertTrue($price->isIndexed());
        self::assertSame(255, $type->field('sku')?->maxLength, 'Indexed strings get a length limit, so index entries fit.');
        self::assertNull($type->field('notes')?->maxLength);
        self::assertSame('2026-01-01T11:00:00+00:00', $type->field('launch')?->default);
        self::assertSame(['fields.price', 'desc'], [$type->sortField, $type->sortDirection]);
    }

    public function testAddsFieldsToAnExistingType(): void
    {
        new ContentType('post')->field('subtitle', 'string')
            ->extend(new ExtenderContext(ExtenderContext::CORE, __DIR__, $this->container, []));

        $this->apply(ContentType::existing('post')->field('rating', 'int', min: 1, max: 5));

        $post = $this->registry()->get('post');
        self::assertSame(['subtitle', 'rating'], array_keys($post->fields));
        self::assertSame(['core', 'acme-reviews'], [$post->field('subtitle')?->owner, $post->field('rating')?->owner]);
        self::assertSame([0, 10], [$post->field('subtitle')?->order, $post->field('rating')?->order]);
    }

    public function testInvalidFields(): void
    {
        $fails = fn (ContentType $extender, string $message) => $this->assertExtenderFails($extender, $message);

        $fails(new ContentType('product')->field('Price', 'float'), 'Field names start with a lowercase letter');
        $fails(new ContentType('product')->field('price', 'money'), 'Unknown type "money". Use one of: string, text, int, float, bool, enum, list, date, datetime, json.');
        $fails(new ContentType('product')->field('size', 'enum'), 'Fields of type "enum" need a list of choices.');
        $fails(new ContentType('product')->field('size', 'string', choices: ['s']), 'Choices only apply to fields of type "enum".');
        $fails(new ContentType('product')->field('size', 'enum', choices: ['s', 's']), 'The choices must be different from each other.');
        $fails(new ContentType('product')->field('name', 'string', min: 1), 'min and max only apply to "int" and "float" fields.');
        $fails(new ContentType('product')->field('price', 'int', min: 5, max: 1), 'min can\'t be larger than max.');
        $fails(new ContentType('product')->field('price', 'int', maxLength: 5), 'maxLength only applies to "string", "text" and "list" fields.');
        $fails(new ContentType('product')->field('notes', 'text', sortable: true), 'Fields of type "text" can\'t be sortable.');
        $fails(new ContentType('product')->field('tags', 'list', sortable: true), 'Fields of type "list" can\'t be sortable.');
        $fails(new ContentType('product')->field('data', 'json', filterable: true), 'Fields of type "json" can\'t be filterable.');
        $fails(new ContentType('product')->field('name', 'string', maxLength: 2000, filterable: true), 'Indexed string fields can have a maxLength of at most 1000');
        $fails(new ContentType('product')->field('price', 'int', default: 'free'), 'The default value doesn\'t fit the field: Expected a whole number.');
        $fails(new ContentType('product')->field('price', 'int', default: 0, min: 1), 'The default value doesn\'t fit the field: At least 1.');
        $fails(new ContentType('product')->field('price', 'int')->sortBy('fields.price'), 'Listings can only be sorted by fields declared with "sortable: true", which "fields.price" isn\'t.');
        $fails(new ContentType('product')->field('a', 'int')->field('a', 'int'), 'The field is already declared by "acme-reviews".');
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
        $this->assertExtenderFails(new ContentType('product')->sortBy('price'), 'Listings can\'t be sorted by "price". Use one of: published_at, created_at, updated_at, title, position, or a sortable field as "fields.<name>".');
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

        $this->assertExtenderFails(ContentType::existing('product')->permalink('/p/{slug}'), 'Only capabilities and fields can be added to a type registered elsewhere.');
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
