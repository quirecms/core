<?php

declare(strict_types=1);

namespace Quire\Permission;

use Quire\Extend\Permissions as PermissionsExtender;
use Quire\Settings\Event\SettingChanged;
use Quire\Settings\Settings;
use Quire\User\Role;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The roles, the abilities, and which roles have which ability.
 *
 * Each ability has default roles, declared with it. What admins change is kept as grants and revocations
 * on top of the defaults (the setting "quire.permissions.grants"), so an extension enabled later brings its
 * defaults while every change admins made stays. Check abilities with Symfony's is_granted(), which also
 * applies the rules of extensions; this tells what roles alone allow.
 *
 * @phpstan-type Ability array{label: string, group: ?string, roles: list<string>, strict: bool, owner: string}
 *
 * @api
 */
#[AsEventListener(event: SettingChanged::class, method: 'reset')]
final class Permissions implements ResetInterface
{
    public const string SETTING = 'quire.permissions.grants';

    /** @var array<string, list<string>>|null Ability => roles, for this request */
    private ?array $grants = null;

    /**
     * @param array{roles: array<string, array{label: string, owner: string}>, abilities: array<string, Ability>, rules: list<array{class: string, owner: string}>} $config
     */
    public function __construct(
        private readonly Settings $settings,
        #[Autowire(param: PermissionsExtender::PARAMETER)]
        private readonly array $config,
    ) {
    }

    /**
     * @return array<string, string> Name => label, "guest" first
     */
    public function roles(): array
    {
        return [Role::GUEST => 'Everyone', ...array_map(static fn (array $role): string => $role['label'], $this->config['roles'])];
    }

    public function hasRole(string $role): bool
    {
        return isset($this->roles()[$role]);
    }

    /**
     * @return array<string, Ability> By name
     */
    public function abilities(): array
    {
        return $this->config['abilities'];
    }

    public function has(string $ability): bool
    {
        return isset($this->config['abilities'][$ability]);
    }

    /**
     * The roles that have the ability now, defaults and changes included.
     *
     * @return list<string>
     */
    public function rolesWith(string $ability): array
    {
        return $this->grants()[$ability] ?? [];
    }

    /**
     * Whether any of the roles has the ability. Admins have every ability that isn't strict.
     *
     * @param list<string> $roles
     */
    public function allows(array $roles, string $ability): bool
    {
        if (!$this->has($ability)) {
            return false;
        }

        if (\in_array(Role::ADMIN, $roles, true) && !$this->config['abilities'][$ability]['strict']) {
            return true;
        }

        return [] !== array_intersect($roles, $this->rolesWith($ability));
    }

    /**
     * @param list<string> $abilities
     */
    public function grant(string $role, array $abilities): void
    {
        $this->change($role, $abilities, 'grant', 'revoke');
    }

    /**
     * @param list<string> $abilities
     */
    public function revoke(string $role, array $abilities): void
    {
        $this->change($role, $abilities, 'revoke', 'grant');
    }

    /**
     * Back to the declared defaults, for the given abilities or all.
     *
     * @param list<string>|null $abilities
     */
    public function restoreDefaults(?array $abilities = null): void
    {
        if (null === $abilities) {
            $this->settings->restoreDefault(self::SETTING);

            return;
        }

        $changes = $this->changes();

        foreach ($abilities as $ability) {
            unset($changes['grant'][$ability], $changes['revoke'][$ability]);
        }

        $this->store($changes);
    }

    /**
     * What admins changed, relative to the defaults.
     *
     * @return array{grant: array<string, list<string>>, revoke: array<string, list<string>>}
     */
    public function changes(): array
    {
        $value = $this->settings->get(self::SETTING);
        $changes = ['grant' => [], 'revoke' => []];

        foreach (['grant', 'revoke'] as $kind) {
            foreach (\is_array($value) && \is_array($value[$kind] ?? null) ? $value[$kind] : [] as $ability => $roles) {
                if (\is_string($ability) && \is_array($roles)) {
                    $changes[$kind][$ability] = array_values(array_filter($roles, \is_string(...)));
                }
            }
        }

        return $changes;
    }

    /**
     * @return array<string, list<string>> Ability => roles
     */
    private function grants(): array
    {
        if (null !== $this->grants) {
            return $this->grants;
        }

        $changes = $this->changes();
        $grants = [];

        foreach ($this->config['abilities'] as $name => $ability) {
            $roles = array_diff([...$ability['roles'], ...$changes['grant'][$name] ?? []], $changes['revoke'][$name] ?? []);
            $grants[$name] = array_values(array_unique($roles));
        }

        return $this->grants = $grants;
    }

    /**
     * @param list<string>     $abilities
     * @param 'grant'|'revoke' $kind
     * @param 'grant'|'revoke' $opposite
     */
    private function change(string $role, array $abilities, string $kind, string $opposite): void
    {
        if (!$this->hasRole($role)) {
            throw new \InvalidArgumentException(\sprintf('Unknown role "%s". Roles: %s.', $role, implode(', ', array_keys($this->roles()))));
        }

        $changes = $this->changes();

        foreach ($abilities as $ability) {
            if (!$this->has($ability)) {
                throw new \InvalidArgumentException(\sprintf('Unknown ability "%s". See "bin/console quire:permissions:list".', $ability));
            }

            $changes[$opposite][$ability] = array_values(array_diff($changes[$opposite][$ability] ?? [], [$role]));
            $isDefault = \in_array($role, $this->config['abilities'][$ability]['roles'], true);

            // Only differences from the defaults are kept.
            if (('grant' === $kind) !== $isDefault) {
                $changes[$kind][$ability] = array_values(array_unique([...$changes[$kind][$ability] ?? [], $role]));
            }
        }

        $this->store($changes);
    }

    /**
     * @param array{grant: array<string, list<string>>, revoke: array<string, list<string>>} $changes
     */
    private function store(array $changes): void
    {
        foreach (['grant', 'revoke'] as $kind) {
            $changes[$kind] = array_filter($changes[$kind], static fn (array $roles): bool => [] !== $roles);
            ksort($changes[$kind]);
        }

        $this->settings->set(self::SETTING, $changes);
        $this->grants = null;
    }

    /**
     * Forgets what was computed for this request, e.g. after a setting changed.
     */
    public function reset(): void
    {
        $this->grants = null;
    }
}
