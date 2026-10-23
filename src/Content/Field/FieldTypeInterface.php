<?php

declare(strict_types=1);

namespace Quire\Content\Field;

/**
 * A kind of value a content field can hold: how values are checked, stored and compared.
 *
 * Core's types are listed in {@see FieldType}; extensions add their own with Extend\FieldTypes. A type
 * is a plain class, created by Quire with `new` and used while the application is built too (to check
 * declared defaults), so it has no constructor arguments and keeps no state: everything it needs comes
 * from the field's definition. Checks that need services, e.g. that a referenced record exists, belong
 * in listeners of the content events. Extend {@see AbstractFieldType} rather than implementing this.
 *
 * @api
 */
interface FieldTypeInterface
{
    /**
     * The options of Extend\ContentType::field() that apply: any of "choices", "min", "max" and
     * "maxLength", and the names of the type's own options, which are passed as field(options: [...]).
     *
     * @return list<string>
     */
    public function options(): array;

    /**
     * Why a field's declaration doesn't suit this type, e.g. a missing or invalid option; null when it does.
     */
    public function definitionError(FieldDefinition $field): ?string;

    /**
     * How the field's values are stored, compared and indexed.
     */
    public function storage(FieldDefinition $field): FieldStorage;

    /**
     * Converts accepted input to the stored form, e.g. a DateTimeInterface to ISO 8601 text. Values that
     * can't be converted are returned unchanged, for {@see validate()} to report.
     */
    public function normalize(mixed $value, FieldDefinition $field): mixed;

    /**
     * Why the normalized value isn't valid, e.g. "Expected a whole number."; null when it is.
     */
    public function validate(mixed $value, FieldDefinition $field): ?string;

    /**
     * A value a query compares the field with, converted to what the SQL compares (see {@see storage()}),
     * or null when it can't be compared.
     */
    public function queryValue(mixed $value, FieldDefinition $field): string|int|bool|null;

    /**
     * What a query must compare the field with, for errors: e.g. "a date such as "2026-12-24"".
     */
    public function expected(FieldDefinition $field): string;

    /**
     * The widget an admin interface edits the field with, unless the declaration names one: e.g. "text",
     * "textarea", "number", "checkbox", "select", "tags", "date", "datetime", "json", or the type's own.
     */
    public function widget(FieldDefinition $field): string;
}
