<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Content\Field\FieldType;
use Quire\Content\Field\FieldTypeInterface;

/**
 * Adds kinds of values that content fields can hold, next to core's (string, int, date, …):
 *
 *     new Extend\FieldTypes()
 *         ->type('acme-maps.location', Maps\LocationField::class),
 *
 *     Extend\ContentType::existing('post')
 *         ->field('venue', 'acme-maps.location', options: ['zoom' => 12]),
 *
 * A type decides how values are checked, stored and compared, and which admin widget edits them (see
 * {@see FieldTypeInterface}). Declare types before the fields that use them; an extension that uses
 * another extension's type requires that extension.
 *
 * @api
 */
final class FieldTypes implements ExtenderInterface
{
    /** @internal Parameter: list<{name, class, owner}> */
    public const string PARAMETER = 'quire.content.field_types';

    private const string NAME_PATTERN = '/^[a-z][a-z0-9-]*\.[a-z][a-z0-9_]*$/';

    /** @var array<string, class-string> */
    private array $types = [];

    /**
     * @param string                           $name  Starts with your extension's ID and a dot, e.g. "acme-maps.location"
     * @param class-string<FieldTypeInterface> $class A class Quire creates with `new`, without arguments
     */
    public function type(string $name, string $class): self
    {
        $this->types[$name] = $class;

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        /** @var list<array{name: string, class: string, owner: string}> $registered */
        $registered = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];
        $taken = array_column($registered, 'owner', 'name');
        $prefix = ExtenderContext::SITE === $context->id ? 'custom.' : $context->id.'.';

        foreach ($this->types as $name => $class) {
            $where = \sprintf('Extend\FieldTypes::type("%s")', $name);

            $error = match (true) {
                null !== FieldType::tryFrom($name) => 'That\'s the name of one of core\'s types.',
                1 !== preg_match(self::NAME_PATTERN, $name) || !str_starts_with($name, $prefix) => \sprintf('Type names start with "%s", followed by lowercase letters, digits and underscores, e.g. "%slocation".', $prefix, $prefix),
                isset($taken[$name]) => \sprintf('The type is already declared by "%s".', $taken[$name]),
                !class_exists($class) => \sprintf('The class "%s" doesn\'t exist.', $class),
                !is_subclass_of($class, FieldTypeInterface::class) => \sprintf('Field types implement %s.', FieldTypeInterface::class),
                !$this->instantiable($class) => 'Quire creates field types with `new`, without arguments: the class can\'t have required constructor arguments.',
                default => null,
            };

            if (null !== $error) {
                throw $context->error($where, $error);
            }

            $taken[$name] = $context->id;
            $context->appendToParameter(self::PARAMETER, ['name' => $name, 'class' => $class, 'owner' => $context->id]);
        }
    }

    /**
     * @param class-string $class
     */
    private function instantiable(string $class): bool
    {
        $reflection = new \ReflectionClass($class);

        return $reflection->isInstantiable() && 0 === ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0);
    }
}
