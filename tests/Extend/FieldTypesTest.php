<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Content\Field\AbstractFieldType;
use Quire\Content\Field\FieldDefinition;
use Quire\Content\Field\FieldStorage;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Extend\ContentType;
use Quire\Extend\FieldTypes;

final class FieldTypesTest extends ExtenderTestCase
{
    public function testExtensionsAddTypesThatFieldsUse(): void
    {
        $this->apply(new FieldTypes()->type('acme-reviews.stars', StarsField::class));
        $this->apply(new ContentType('product')->field('rating', 'acme-reviews.stars', filterable: true, options: ['of' => 10]));

        /** @var array<string, array<string, mixed>> $types */
        $types = $this->container->getParameterBag()->all()[ContentTypeRegistry::PARAMETER];
        $rating = new ContentTypeRegistry($types)->get('product')->field('rating');
        \assert($rating instanceof FieldDefinition);

        self::assertSame(['acme-reviews.stars', StarsField::class, ['of' => 10]], [$rating->type, $rating->typeClass, $rating->options]);
        self::assertSame(FieldStorage::Number, $rating->storage());
        self::assertSame(['stars', 4], [$rating->widget(), $rating->normalize('★★★★')]);
        self::assertSame('At most 10 stars.', $rating->validate(11));

        $restored = FieldDefinition::fromArray($rating->toArray());
        self::assertSame([StarsField::class, null], [$restored->typeClass, $restored->validate(7)], 'Compiled into the container, it still knows its type.');
    }

    public function testTypeNamesAndClasses(): void
    {
        $this->assertExtenderFails(new FieldTypes()->type('stars', StarsField::class), 'Type names start with "acme-reviews.", followed by lowercase letters');
        $this->assertExtenderFails(new FieldTypes()->type('acme-other.stars', StarsField::class), 'Type names start with "acme-reviews."');
        $this->assertExtenderFails(new FieldTypes()->type('int', StarsField::class), 'That\'s the name of one of core\'s types.');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new FieldTypes()->type('acme-reviews.x', 'Acme\Missing'), 'The class "Acme\Missing" doesn\'t exist.');
        // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertExtenderFails(new FieldTypes()->type('acme-reviews.x', \stdClass::class), 'Field types implement Quire\Content\Field\FieldTypeInterface.');
        $this->assertExtenderFails(new FieldTypes()->type('acme-reviews.x', NeedsArgumentsField::class), 'without arguments');

        $this->apply(new FieldTypes()->type('acme-reviews.stars', StarsField::class));
        $this->assertExtenderFails(new FieldTypes()->type('acme-reviews.stars', StarsField::class), 'The type is already declared by "acme-reviews".');
    }

    public function testFieldsOfTheTypeAreChecked(): void
    {
        $this->apply(new FieldTypes()->type('acme-reviews.stars', StarsField::class));

        $this->assertExtenderFails(new ContentType('product')->field('rating', 'acme-reviews.stars', options: ['size' => 3]), 'Fields of type "acme-reviews.stars" have no option "size". Their options: of.');
        $this->assertExtenderFails(new ContentType('product')->field('rating', 'acme-reviews.stars', options: ['of' => 0]), 'Give "of" as a number of stars from 1 to 10.');
        $this->assertExtenderFails(new ContentType('product')->field('rating', 'acme-reviews.stars', default: 20), 'The default value doesn\'t fit the field: At most 5 stars.');
        $this->assertExtenderFails(new ContentType('product')->field('rating', 'acme-reviews.stars', choices: [1, 2]), 'Choices only apply to fields of type "enum".');
        $this->assertExtenderFails(new ContentType('product')->field('rating', 'acme-maps.location'), 'Unknown type "acme-maps.location". Use one of: string, text, int, float, bool, enum, list, date, datetime, json, acme-reviews.stars.');
    }
}

/**
 * Up to "of" stars (5 by default), given as a number or as stars.
 */
final class StarsField extends AbstractFieldType
{
    public function options(): array
    {
        return ['of'];
    }

    public function definitionError(FieldDefinition $field): ?string
    {
        $of = $field->options['of'] ?? 5;

        return \is_int($of) && $of >= 1 && $of <= 10 ? null : 'Give "of" as a number of stars from 1 to 10.';
    }

    public function storage(FieldDefinition $field): FieldStorage
    {
        return FieldStorage::Number;
    }

    public function normalize(mixed $value, FieldDefinition $field): mixed
    {
        return \is_string($value) && 1 === preg_match('/^★+$/u', $value) ? mb_strlen($value) : $value;
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        $of = $field->options['of'] ?? 5;

        return match (true) {
            !\is_int($value) || $value < 0 => 'Expected a number of stars.',
            $value > $of => \sprintf('At most %d stars.', \is_int($of) ? $of : 5),
            default => null,
        };
    }

    public function widget(FieldDefinition $field): string
    {
        return 'stars';
    }
}

final class NeedsArgumentsField extends AbstractFieldType
{
    public function __construct(
        public string $service,
    ) {
    }

    public function validate(mixed $value, FieldDefinition $field): ?string
    {
        return null;
    }
}
