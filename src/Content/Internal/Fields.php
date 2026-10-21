<?php

declare(strict_types=1);

namespace Quire\Content\Internal;

use Quire\Content\Type\ContentTypeDefinition;

/**
 * Applies a content type's field declarations to the fields of an item being saved.
 *
 * Only values that are being set are checked. Values an item already has are left alone, even when no
 * enabled extension declares them any more: disabling an extension must not make its content unsaveable.
 *
 * @internal
 */
final class Fields
{
    /**
     * Converts declared fields to their stored form (see {@see \Quire\Content\Field\FieldDefinition::normalize()}).
     * Null values are removed.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public static function normalize(ContentTypeDefinition $type, array $fields): array
    {
        $normalized = [];

        foreach ($fields as $name => $value) {
            if (null !== $value) {
                $normalized[$name] = $type->field($name)?->normalize($value) ?? $value;
            }
        }

        return $normalized;
    }

    /**
     * Adds the declared defaults for fields a new item doesn't set.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public static function withDefaults(ContentTypeDefinition $type, array $fields): array
    {
        foreach ($type->fields as $name => $field) {
            if (!\array_key_exists($name, $fields) && null !== $field->default) {
                $fields[$name] = $field->default;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $before The item's current fields, or null for a new item
     * @param array<string, mixed>      $after  The fields to save, normalized
     *
     * @return array<string, string> Messages keyed by "fields.<name>"
     */
    public static function violations(ContentTypeDefinition $type, ?array $before, array $after): array
    {
        $violations = [];

        foreach ($after as $name => $value) {
            if (null !== $before && \array_key_exists($name, $before) && $before[$name] === $value) {
                continue;
            }

            $field = $type->field($name);

            $message = $field instanceof \Quire\Content\Field\FieldDefinition
                ? $field->validate($value)
                : \sprintf('%s have no field "%s".%s', $type->pluralLabel, $name, [] === $type->fields ? '' : \sprintf(' Their fields are: %s.', implode(', ', array_keys($type->fields))));

            if (null !== $message) {
                $violations['fields.'.$name] = $message;
            }
        }

        foreach ($type->fields as $name => $field) {
            if (!$field->required || \array_key_exists($name, $after)) {
                continue;
            }

            if (null === $before) {
                $violations['fields.'.$name] = 'A value is required.';
            } elseif (\array_key_exists($name, $before)) {
                $violations['fields.'.$name] = 'A value is required, so it can\'t be removed.';
            }
        }

        return $violations;
    }
}
