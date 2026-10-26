<?php

declare(strict_types=1);

namespace Quire\User\Preference;

use Doctrine\DBAL\Connection;
use Quire\User\CurrentUser;
use Quire\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Each person's preferences, declared with {@see \Quire\Extend\Preferences}: the admin's language, light or
 * dark, how lists are laid out. They follow the person to every device. Only what someone changed from
 * the default is stored; a value that no longer fits (e.g. a choice an extension took away) reads as the
 * default.
 *
 *     $preferences->mine('acme-reviews.sort');            // the signed-in person's, or the default
 *     $preferences->set($user, 'acme-reviews.sort', 'oldest');
 *
 * @api
 */
final class UserPreferences implements ResetInterface
{
    /** @internal Container parameter: the definitions, by name */
    public const string PARAMETER = 'quire.preferences';

    /** The language a person reads Quire in, e.g. the admin, where it's offered; null for the site's */
    public const string LANGUAGE = 'quire.language';

    /** @var array<string, PreferenceDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, array<string, mixed>> What each user changed, by user ID, as read */
    private array $stored = [];

    /**
     * @param array<string, array{name: string, type: string, default: mixed, owner: string, choices: list<string|int>, nullable: bool}> $declared
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        private readonly array $declared,
        private readonly Connection $connection,
        private readonly CurrentUser $users,
    ) {
    }

    /**
     * @return array<string, PreferenceDefinition> By name
     */
    public function definitions(): array
    {
        return $this->definitions ??= array_map(PreferenceDefinition::fromArray(...), $this->declared);
    }

    /**
     * @throws InvalidPreferenceException when the preference isn't declared
     */
    public function get(User|string $user, string $name): mixed
    {
        $definition = $this->definition($name);
        $stored = $this->stored($user);

        return \array_key_exists($name, $stored) && null === $definition->validate($stored[$name]) ? $stored[$name] : $definition->default;
    }

    /**
     * The signed-in person's preference; the default when nobody (or no Quire user) is signed in.
     */
    public function mine(string $name): mixed
    {
        $user = $this->users->get();

        return $user instanceof User ? $this->get($user, $name) : $this->definition($name)->default;
    }

    /**
     * Every preference of the user, by name.
     *
     * @return array<string, mixed>
     */
    public function all(User|string $user): array
    {
        return array_map(fn (PreferenceDefinition $definition): mixed => $this->get($user, $definition->name), $this->definitions());
    }

    /**
     * What the user changed from the defaults, by name.
     *
     * @return array<string, mixed>
     */
    public function changed(User|string $user): array
    {
        return array_filter(
            $this->stored($user),
            fn (mixed $value, string $name): bool => isset($this->definitions()[$name]) && null === $this->definitions()[$name]->validate($value),
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Changes a preference; set to its default, it's forgotten, so a new default reaches the user too.
     *
     * @throws InvalidPreferenceException when the preference isn't declared or the value doesn't fit
     */
    public function set(User|string $user, string $name, mixed $value): void
    {
        $definition = $this->definition($name);
        $error = $definition->validate($value);

        if (null !== $error) {
            throw new InvalidPreferenceException(\sprintf('The value doesn\'t fit the preference "%s": %s.', $name, $error));
        }

        if ($value === $definition->default) {
            $this->restoreDefault($user, $name);

            return;
        }

        $id = $user instanceof User ? $user->id : $user;
        $this->connection->executeStatement(
            'INSERT INTO quire.user_preference (user_id, name, value) VALUES (?, ?, ?)
             ON CONFLICT (user_id, name) DO UPDATE SET value = EXCLUDED.value, updated_at = statement_timestamp()',
            [$id, $name, json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION)],
        );
        unset($this->stored[$id]);
    }

    /**
     * Back to the default: one preference, or all of them.
     */
    public function restoreDefault(User|string $user, ?string $name = null): void
    {
        $id = $user instanceof User ? $user->id : $user;

        if (null === $name) {
            $this->connection->executeStatement('DELETE FROM quire.user_preference WHERE user_id = ?', [$id]);
        } else {
            $this->definition($name);
            $this->connection->executeStatement('DELETE FROM quire.user_preference WHERE user_id = ? AND name = ?', [$id, $name]);
        }

        unset($this->stored[$id]);
    }

    public function reset(): void
    {
        $this->stored = [];
    }

    private function definition(string $name): PreferenceDefinition
    {
        return $this->definitions()[$name] ?? throw new InvalidPreferenceException(\sprintf('No preference "%s" is declared. Declare it with Extend\Preferences::define() in extend.php.', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(User|string $user): array
    {
        $id = $user instanceof User ? $user->id : $user;

        if (!isset($this->stored[$id])) {
            $values = [];

            foreach ($this->connection->fetchAllKeyValue('SELECT name, value FROM quire.user_preference WHERE user_id = ?', [$id]) as $name => $json) {
                $values[(string) $name] = \is_string($json) ? json_decode($json, true) : null;
            }

            $this->stored[$id] = $values;
        }

        return $this->stored[$id];
    }
}
