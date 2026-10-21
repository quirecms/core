<?php

declare(strict_types=1);

namespace Quire\Content\Internal;

/**
 * Typed access to a database row.
 *
 * @internal
 */
final readonly class Row
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private array $values,
    ) {
    }

    public static function isUuid(string $value): bool
    {
        return 1 === preg_match(self::UUID_PATTERN, $value);
    }

    public function string(string $column): string
    {
        $value = $this->values[$column] ?? null;

        return \is_string($value) ? $value : throw $this->unexpected($column, 'a string');
    }

    public function nullableString(string $column): ?string
    {
        return null === ($this->values[$column] ?? null) ? null : $this->string($column);
    }

    public function int(string $column): int
    {
        $value = $this->values[$column] ?? null;

        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && is_numeric($value) => (int) $value,
            default => throw $this->unexpected($column, 'an integer'),
        };
    }

    public function date(string $column): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->string($column));
    }

    public function nullableDate(string $column): ?\DateTimeImmutable
    {
        return null === ($this->values[$column] ?? null) ? null : $this->date($column);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonObject(string $column): array
    {
        $decoded = json_decode($this->string($column), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw $this->unexpected($column, 'a JSON object');
        }

        /** @var array<string, mixed> $object */
        $object = $decoded;

        return $object;
    }

    private function unexpected(string $column, string $expected): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('Expected column "%s" to hold %s, got %s.', $column, $expected, get_debug_type($this->values[$column] ?? null)));
    }
}
