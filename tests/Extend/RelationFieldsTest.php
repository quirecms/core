<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Internal\RelationFieldsPass;
use Quire\Extend\ContentType;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

final class RelationFieldsTest extends ExtenderTestCase
{
    public function testDeclarationsAreChecked(): void
    {
        $this->assertRelationFails([], 'Relations need "types": the list of content types they link to');
        $this->assertRelationFails(['types' => 'tag'], 'Relations need "types"');
        $this->assertRelationFails(['types' => ['Tag']], 'Relations need "types"');
        $this->assertRelationFails(['types' => ['tag'], 'multiple' => 'yes'], '"multiple" is true (a list) or false (one item).');
        $this->assertRelationFails(['types' => ['tag'], 'multiple' => false, 'limit' => 2], '"limit" is the most items of a list');
        $this->assertRelationFails(['types' => ['tag'], 'limit' => 0], '"limit" is the most items of a list');
        $this->assertRelationFails(['types' => ['tag'], 'inverse' => 'Products'], '"inverse" is a name such as "posts"');
        $this->assertRelationFails(['types' => ['tag'], 'size' => 3], 'Fields of type "relation" have no option "size". Their options: types, multiple, limit, inverse.');
        $this->assertExtenderFails(new ContentType('product')->field('links', 'relation', sortable: true, options: ['types' => ['tag']]), 'Fields of type "relation" can\'t be sortable.');
    }

    public function testInversesAreCompiledForTheLinkedTypes(): void
    {
        $this->apply(new ContentType('tag'));
        $this->apply(new ContentType('product')->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'products']));
        $this->apply(new ContentType('offer')->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'offers']));
        new RelationFieldsPass()->process($this->container);

        self::assertSame(['tag' => [
            'products' => [['type' => 'product', 'field' => 'tags']],
            'offers' => [['type' => 'offer', 'field' => 'tags']],
        ]], $this->container->getParameter(RelationFieldsPass::INVERSES_PARAMETER));
    }

    public function testLinkedTypesMustExistAndInversesBeFree(): void
    {
        $this->assertPassFails(new ContentType('product')->field('tags', 'relation', options: ['types' => ['tag']]), 'Extend\ContentType("product")::field("tags") links to the type "tag", which isn\'t registered.');

        $this->setUp();
        $this->apply(new ContentType('tag')->field('products', 'string'));
        $this->assertPassFails(new ContentType('product')->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'products']), 'the inverse "products" is a field of the type "tag"');

        $this->setUp();
        $this->apply(new ContentType('tag'));
        $this->assertPassFails(new ContentType('product')->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'children']), 'the inverse "children" is a relation of every type, including the type "tag"');

        $this->setUp();
        $this->apply(new ContentType('tag'));
        $this->apply(new ContentType('offer')->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'items']));
        $this->assertPassFails(new ContentType('product')->field('tags', 'relation', options: ['types' => ['tag'], 'inverse' => 'items']), 'the inverse "items" is already the inverse of another relation of the type "tag"');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertRelationFails(array $options, string $message): void
    {
        $this->assertExtenderFails(new ContentType('product')->field('links', 'relation', options: $options), $message);
    }

    private function assertPassFails(ContentType $extender, string $message): void
    {
        $this->apply($extender);

        try {
            new RelationFieldsPass()->process($this->container);
            self::fail('The pass accepted it.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
