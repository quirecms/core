<?php

declare(strict_types=1);

namespace Quire\Settings;

/**
 * The kinds of values a setting can hold.
 *
 * @api
 */
enum SettingType: string
{
    case String = 'string';
    case Bool = 'bool';
    case Int = 'int';
    case Float = 'float';
    /** One of a fixed list of choices */
    case Enum = 'enum';
    /** A list of strings */
    case List = 'list';
    /** Any JSON-encodable value */
    case Json = 'json';

    /**
     * Why $value isn't valid for this type, or null when it is.
     *
     * @param list<string|int> $choices Allowed values for {@see self::Enum}
     */
    public function validate(mixed $value, array $choices = []): ?string
    {
        return match ($this) {
            self::String => \is_string($value) ? null : 'expected a string',
            self::Bool => \is_bool($value) ? null : 'expected true or false',
            self::Int => \is_int($value) ? null : 'expected an integer',
            self::Float => \is_float($value) || \is_int($value) ? null : 'expected a number',
            self::Enum => \in_array($value, $choices, true) ? null : \sprintf('expected one of %s', implode(', ', array_map(static fn (string|int $c): string => json_encode($c, \JSON_THROW_ON_ERROR), $choices))),
            self::List => \is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => \is_string($item)) ? null : 'expected a list of strings',
            self::Json => self::isJsonEncodable($value) ? null : 'expected a JSON-encodable value',
        };
    }

    /**
     * Parses a value written as text, e.g. on the command line or in an environment variable.
     *
     * @param list<string|int> $choices
     *
     * @throws \InvalidArgumentException when the text isn't a valid value
     */
    public function parse(string $text, array $choices = []): mixed
    {
        [$parsed, $value] = match ($this) {
            self::String => [true, $text],
            self::Bool => self::filter($text, \FILTER_VALIDATE_BOOL),
            self::Int => self::filter($text, \FILTER_VALIDATE_INT),
            self::Float => self::filter($text, \FILTER_VALIDATE_FLOAT),
            self::Enum => [true, array_find($choices, static fn (string|int $choice): bool => (string) $choice === $text)],
            self::List, self::Json => self::decodeJson($text),
        };

        $error = $parsed ? $this->validate($value, $choices) : $this->hint();

        if (null !== $error) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid %s value: %s.', $text, $this->value, $error));
        }

        return $value;
    }

    /**
     * @return array{bool, mixed} Whether parsing succeeded, and the value
     */
    private static function filter(string $text, int $filter): array
    {
        $value = filter_var($text, $filter, \FILTER_NULL_ON_FAILURE);

        return [null !== $value, $value];
    }

    private function hint(): string
    {
        return match ($this) {
            self::Bool => 'use true, false, 1, 0, yes, no, on or off',
            self::Int => 'use a whole number, e.g. 20',
            self::Float => 'use a number, e.g. 1.5',
            self::Enum => 'use one of the allowed choices',
            self::List => 'use a JSON list of strings, e.g. ["a", "b"]',
            self::Json => 'use valid JSON',
            self::String => 'use any text',
        };
    }

    /**
     * @return array{bool, mixed} Whether decoding succeeded, and the value
     */
    private static function decodeJson(string $text): array
    {
        try {
            return [true, json_decode($text, true, flags: \JSON_THROW_ON_ERROR)];
        } catch (\JsonException) {
            return [false, null];
        }
    }

    private static function isJsonEncodable(mixed $value): bool
    {
        try {
            json_encode($value, \JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException) {
            return false;
        }
    }
}
