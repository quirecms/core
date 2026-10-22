<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Permission\AbilityRuleInterface;
use Quire\User\Role;

/**
 * Declares roles, abilities and rules.
 *
 *     new Extend\Permissions()
 *         ->role('moderator', 'Moderator')
 *         ->ability('acme-reviews.moderate', 'Moderate reviews', roles: ['moderator', 'editor'])
 *         ->rule(SuspendedReviewersRule::class);
 *
 * An ability is what a role may do, checked with Symfony's is_granted('acme-reviews.moderate'). The roles
 * given are its defaults, which admins can change. Admins may do everything, except abilities declared
 * strict, which they must be granted like any role. Rules decide case by case, e.g. from the subject.
 *
 * Ability names start with the extension's ID and a dot, like settings.
 *
 * @api
 */
final class Permissions implements ExtenderInterface
{
    /** @internal Parameter: {roles: {name: {label, owner}}, abilities: {name: {label, group, roles, strict, owner}}, rules: list<{class, owner}>} */
    public const string PARAMETER = 'quire.permissions';

    private const string ROLE_PATTERN = '/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/';

    private const string ABILITY_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\.[a-z0-9]+(?:[-_][a-z0-9]+)*)+$/';

    /** @var array<string, string> */
    private array $roles = [];

    /** @var array<string, array{label: string, roles: list<string>, group: ?string, strict: bool}> */
    private array $abilities = [];

    /** @var list<string> */
    private array $rules = [];

    public function role(string $name, string $label): self
    {
        $this->roles[$name] = $label;

        return $this;
    }

    /**
     * @param list<string> $roles  The roles that have it by default; "guest" is anyone, signed in or not
     * @param bool         $strict Admins don't have it unless granted, e.g. for destructive actions
     */
    public function ability(string $name, string $label, array $roles = [], ?string $group = null, bool $strict = false): self
    {
        $this->abilities[$name] = ['label' => $label, 'roles' => $roles, 'group' => $group, 'strict' => $strict];

        return $this;
    }

    /**
     * @param class-string<AbilityRuleInterface> $class A service that decides abilities case by case
     */
    public function rule(string $class): self
    {
        $this->rules[] = $class;

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();
        /** @var array{roles: array<string, array{label: string, owner: string}>, abilities: array<string, array{label: string, roles: list<string>, group: ?string, strict: bool, owner: string}>, rules: list<array{class: string, owner: string}>} $config */
        $config = $container->getParameter(self::PARAMETER);

        foreach ($this->roles as $name => $label) {
            $where = \sprintf('Extend\Permissions::role("%s")', $name);

            if (1 !== preg_match(self::ROLE_PATTERN, $name) || Role::GUEST === $name) {
                throw $context->error($where, \sprintf('Role names are lowercase words, e.g. "moderator", and "%s" is reserved for visitors.', Role::GUEST));
            }

            if (isset($config['roles'][$name])) {
                throw $context->error($where, \sprintf('The role is already declared by "%s".', $config['roles'][$name]['owner']));
            }

            $config['roles'][$name] = ['label' => $label, 'owner' => $context->id];
        }

        foreach ($this->abilities as $name => $ability) {
            $where = \sprintf('Extend\Permissions::ability("%s")', $name);
            $prefixes = match ($context->id) {
                ExtenderContext::CORE => ['content.', 'quire.'],
                ExtenderContext::SITE => ['custom.'],
                default => [$context->id.'.'],
            };

            if (1 !== preg_match(self::ABILITY_PATTERN, $name) || !array_any($prefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
                throw $context->error($where, \sprintf('Ability names are lowercase words separated by dots, starting with "%s", e.g. "%s".', implode('" or "', $prefixes), $prefixes[0].'moderate'));
            }

            if (isset($config['abilities'][$name])) {
                throw $context->error($where, \sprintf('The ability is already declared by "%s".', $config['abilities'][$name]['owner']));
            }

            foreach ($ability['roles'] as $role) {
                if (Role::GUEST !== $role && !isset($config['roles'][$role])) {
                    throw $context->error($where, \sprintf('Unknown role "%s". Declare it first, or use one of: %s.', $role, implode(', ', [Role::GUEST, ...array_keys($config['roles'])])));
                }
            }

            $config['abilities'][$name] = [...$ability, 'roles' => array_values(array_unique($ability['roles'])), 'owner' => $context->id];
        }

        foreach ($this->rules as $class) {
            if (!is_a($class, AbilityRuleInterface::class, true)) {
                throw $context->error(\sprintf('Extend\Permissions::rule("%s")', $class), \sprintf('Rules implement %s.', AbilityRuleInterface::class));
            }

            $config['rules'][] = ['class' => $class, 'owner' => $context->id];
        }

        $container->setParameter(self::PARAMETER, $config);
    }
}
