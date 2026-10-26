<?php

declare(strict_types=1);

namespace Quire\Action;

use Quire\Action\Exception\InvalidArgumentsException;

/**
 * The arguments of an action, checked against its parameters: what's required is there, and each value
 * is of its parameter's kind. Values are what JSON can hold; references are IDs or names.
 *
 * @api
 */
final readonly class Arguments
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * @param array<string, mixed> $values By parameter name; null is the same as leaving it out
     *
     * @throws InvalidArgumentsException
     */
    public static function check(string $action, ActionDefinition $definition, array $values): self
    {
        $errors = [];
        $values = array_filter($values, static fn (mixed $value): bool => null !== $value);

        foreach (array_diff_key($values, array_flip(array_map(static fn (Parameter $parameter): string => $parameter->name, $definition->parameters))) as $name => $value) {
            $errors[$name] = 'The action has no such parameter.';
        }

        foreach ($definition->parameters as $parameter) {
            $error = self::error($parameter, $values[$parameter->name] ?? null);

            if (null !== $error) {
                $errors[$parameter->name] = $error;
            }
        }

        if ([] !== $errors) {
            throw new InvalidArgumentsException($action, $errors);
        }

        return new self($values);
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /**
     * A string argument; for an optional one that's left out, use {@see optionalString()}.
     */
    public function string(string $name): string
    {
        return $this->optionalString($name) ?? throw new \LogicException(\sprintf('The argument "%s" is missing.', $name));
    }

    public function optionalString(string $name): ?string
    {
        $value = $this->values[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function bool(string $name): bool
    {
        return true === ($this->values[$name] ?? false);
    }

    /**
     * A list argument (of a parameter with "multiple"), empty when it's left out.
     *
     * @return list<string>
     */
    public function strings(string $name): array
    {
        $value = $this->values[$name] ?? [];

        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    public function dateTime(string $name): ?\DateTimeImmutable
    {
        $value = $this->optionalString($name);

        return null === $value ? null : self::time($value);
    }

    /**
     * A JSON value as it was given, e.g. a setting's new value.
     */
    public function value(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @return array<string, mixed> The arguments that were given, by parameter name
     */
    public function all(): array
    {
        return $this->values;
    }

    private static function error(Parameter $parameter, mixed $value): ?string
    {
        if (null === $value || [] === $value) {
            return $parameter->required ? 'It\'s required.' : null;
        }

        $values = $parameter->multiple ? $value : [$value];

        if (!\is_array($values) || !array_is_list($values)) {
            return 'Give a list.';
        }

        foreach ($values as $item) {
            $error = match (true) {
                ParameterType::Bool === $parameter->type => \is_bool($item) ? null : 'Give true or false.',
                ParameterType::Value === $parameter->type => null,
                !\is_string($item) || '' === $item => 'Give a text.',
                null !== $parameter->choices && !\in_array($item, $parameter->choices, true) => \sprintf('Give one of %s.', implode(', ', $parameter->choices)),
                ParameterType::DateTime === $parameter->type && !self::time($item) instanceof \DateTimeImmutable => 'Give a time with its offset, e.g. "2026-12-24T09:00:00+01:00".',
                default => null,
            };

            if (null !== $error) {
                return $error;
            }
        }

        return null;
    }

    private static function time(string $value): ?\DateTimeImmutable
    {
        // With an offset or zone: the server's time zone isn't the site's, nor the user's.
        foreach ([\DATE_ATOM, 'Y-m-d\TH:i:s.uP'] as $format) {
            $time = \DateTimeImmutable::createFromFormat($format, $value);

            if (false !== $time) {
                return $time;
            }
        }

        return null;
    }
}
