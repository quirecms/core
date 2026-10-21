<?php

declare(strict_types=1);

namespace Quire\Settings;

use Doctrine\DBAL\Connection;
use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Settings\Event\SettingChanged;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Exception\UnknownSettingException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads and changes site settings.
 *
 * A setting's value comes from, in order of precedence:
 * 1. an environment variable (see {@see SettingDefinition::environmentVariable()}), which can't be changed here;
 * 2. the value stored in the database;
 * 3. the default it was declared with.
 *
 * @api
 */
final class Settings implements ResetInterface
{
    /** @var array<string, mixed>|null Stored values, loaded once per request */
    private ?array $stored = null;

    /**
     * @param array<string, mixed>|null $environment Environment variables; null reads the real environment
     */
    public function __construct(
        private readonly SettingsSchema $schema,
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?array $environment = null,
    ) {
    }

    /**
     * @throws UnknownSettingException
     */
    public function get(string $key): mixed
    {
        $definition = $this->schema->get($key);
        $override = $this->environmentValue($definition);

        if (null !== $override) {
            return $override[0];
        }

        $stored = $this->stored();

        if (\array_key_exists($key, $stored) && null === $definition->validate($stored[$key])) {
            return $stored[$key];
        }

        // Missing, or stored before the declaration changed: fall back to the default.
        return $definition->default;
    }

    public function getString(string $key): string
    {
        $value = $this->get($key);

        return \is_string($value) ? $value : throw $this->wrongType($key, 'string');
    }

    public function getBool(string $key): bool
    {
        $value = $this->get($key);

        return \is_bool($value) ? $value : throw $this->wrongType($key, 'bool');
    }

    public function getInt(string $key): int
    {
        $value = $this->get($key);

        return \is_int($value) ? $value : throw $this->wrongType($key, 'int');
    }

    public function getFloat(string $key): float
    {
        $value = $this->get($key);

        return \is_float($value) || \is_int($value) ? (float) $value : throw $this->wrongType($key, 'float');
    }

    /**
     * @return list<string>
     */
    public function getList(string $key): array
    {
        $value = $this->get($key);

        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->wrongType($key, 'list');
        }

        $list = [];

        foreach ($value as $item) {
            $list[] = \is_string($item) ? $item : throw $this->wrongType($key, 'list');
        }

        return $list;
    }

    /**
     * Where a setting's current value comes from: "environment", "database" or "default".
     */
    public function source(string $key): string
    {
        $definition = $this->schema->get($key);

        return match (true) {
            null !== $this->environmentValue($definition) => 'environment',
            \array_key_exists($key, $this->stored()) && null === $definition->validate($this->stored()[$key]) => 'database',
            default => 'default',
        };
    }

    /**
     * Current values of every declared setting, or only those whose key starts with $prefix.
     *
     * @return array<string, mixed>
     */
    public function all(string $prefix = ''): array
    {
        $values = [];

        foreach (array_keys($this->schema->all($prefix)) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * @throws UnknownSettingException
     * @throws InvalidSettingValueException when the value doesn't fit, or an environment variable overrides the setting
     */
    public function set(string $key, mixed $value): void
    {
        $definition = $this->schema->get($key);
        $this->assertNotOverridden($definition);

        $error = $definition->validate($value);

        if (null !== $error) {
            throw new InvalidSettingValueException(\sprintf('Invalid value for setting "%s": %s, got %s.', $key, $error, get_debug_type($value)));
        }

        $old = $this->get($key);

        $this->connection->executeStatement(
            'INSERT INTO setting (name, value, updated_at) VALUES (:name, :value, now())
             ON CONFLICT (name) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at',
            ['name' => $key, 'value' => json_encode($value, \JSON_THROW_ON_ERROR)],
        );
        $this->stored = null;

        if ($old !== $value) {
            $this->dispatcher->dispatch(new SettingChanged($key, $old, $value));
        }
    }

    /**
     * Removes the stored value, so the setting falls back to its default.
     *
     * @throws UnknownSettingException
     * @throws InvalidSettingValueException when an environment variable overrides the setting
     */
    public function restoreDefault(string $key): void
    {
        $definition = $this->schema->get($key);
        $this->assertNotOverridden($definition);

        $old = $this->get($key);
        $this->connection->executeStatement('DELETE FROM setting WHERE name = :name', ['name' => $key]);
        $this->stored = null;

        if ($old !== $definition->default) {
            $this->dispatcher->dispatch(new SettingChanged($key, $old, $definition->default));
        }
    }

    /**
     * Forgets the values loaded for this request, so long-running workers see changes made elsewhere.
     */
    public function reset(): void
    {
        $this->stored = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if (null !== $this->stored) {
            return $this->stored;
        }

        $stored = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT name, value FROM setting') as $name => $json) {
            $stored[(string) $name] = \is_string($json) ? json_decode($json, true, flags: \JSON_THROW_ON_ERROR) : null;
        }

        return $this->stored = $stored;
    }

    /**
     * @return array{mixed}|null The overriding value (wrapped, since it may be null), or null when not overridden
     */
    private function environmentValue(SettingDefinition $definition): ?array
    {
        $name = $definition->environmentVariable();
        $text = null !== $this->environment
            ? $this->environment[$name] ?? null
            : $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        if (!\is_string($text)) {
            return null;
        }

        try {
            return [$definition->type->parse($text, $definition->choices)];
        } catch (\InvalidArgumentException $e) {
            throw new InvalidSettingValueException(\sprintf('The environment variable %s, which overrides setting "%s", is invalid: %s', $name, $definition->key, $e->getMessage()), $e->getCode(), previous: $e);
        }
    }

    private function assertNotOverridden(SettingDefinition $definition): void
    {
        if (null !== $this->environmentValue($definition)) {
            throw new InvalidSettingValueException(\sprintf('The setting "%s" is set by the environment variable %s, so it can\'t be changed here. Change or remove the environment variable instead.', $definition->key, $definition->environmentVariable()));
        }
    }

    private function wrongType(string $key, string $expected): \LogicException
    {
        return new \LogicException(\sprintf('The setting "%s" is a %s setting, so it has no %s value. Use get(), or the getter matching its type.', $key, $this->schema->get($key)->type->value, $expected));
    }
}
