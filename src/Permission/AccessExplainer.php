<?php

declare(strict_types=1);

namespace Quire\Permission;

use Psr\Container\ContainerInterface;
use Quire\Extend\Permissions as PermissionsExtender;
use Quire\User\Role;
use Quire\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Explains why a user may or may not do something: which of their roles have the ability, by default or
 * because the site changed it, whether they have it as admins, and what the rules of extensions decide.
 * It decides as the voter behind is_granted() does, without a particular item.
 *
 * @api
 */
final readonly class AccessExplainer
{
    /**
     * @param array{rules: list<array{class: string, owner: string}>} $config
     * @param ContainerInterface                                      $rules  The rule services, by class
     */
    public function __construct(
        private Permissions $permissions,
        #[Autowire(param: PermissionsExtender::PARAMETER)]
        private array $config,
        private ContainerInterface $rules,
    ) {
    }

    /**
     * @param list<string>|null $roles The roles to explain for, e.g. with those of the host application
     *                                 mapped in; the user's own by default
     */
    public function explain(User $user, string $ability, ?array $roles = null): AccessExplanation
    {
        if (!$this->permissions->has($ability)) {
            return new AccessExplanation(false, [\sprintf('There\'s no ability "%s".', $ability)]);
        }

        if (!$user->isActive()) {
            return new AccessExplanation(false, ['The user is suspended: suspended users can\'t sign in, and have only what everyone has while signed out.']);
        }

        $roles = [Role::GUEST, ...$roles ?? $user->roles];
        $reasons = [];
        $definition = $this->permissions->abilities()[$ability];
        $changes = $this->permissions->changes();

        // Rules first: a refusal wins.
        $ruled = false;

        foreach ($this->config['rules'] as $rule) {
            $service = $this->rules->get($rule['class']);
            \assert($service instanceof AbilityRuleInterface);
            $decision = $service->decide($ability, $user, null);

            if (false === $decision) {
                return new AccessExplanation(false, [\sprintf('A rule of %s refuses it (%s), whatever roles say.', $rule['owner'], $rule['class'])], perItem: true);
            }

            if (true === $decision) {
                $ruled = true;
                $reasons[] = \sprintf('A rule of %s grants it (%s).', $rule['owner'], $rule['class']);
            }
        }

        $grantedBy = [];

        if (\in_array(Role::ADMIN, $roles, true) && !$definition['strict']) {
            $grantedBy[] = Role::ADMIN;
            $reasons[] = 'They\'re an admin, and admins have every ability that isn\'t strict.';
        }

        foreach ($roles as $role) {
            if (!\in_array($role, $this->permissions->rolesWith($ability), true)) {
                continue;
            }

            $grantedBy[] = $role;
            $label = Role::GUEST === $role ? 'Everyone has it' : \sprintf('Their role "%s" has it', $role);
            $reasons[] = \in_array($role, $changes['grant'][$ability] ?? [], true)
                ? $label.', because the site granted it.'
                : $label.', by default.';
        }

        $granted = $ruled || [] !== $grantedBy;

        if (!$granted) {
            $revoked = array_values(array_intersect($roles, $changes['revoke'][$ability] ?? []));
            $own = array_diff($roles, [Role::GUEST]);
            $none = [] === $own ? 'They have no roles, and Everyone doesn\'t have it.' : \sprintf('None of their roles (%s) has it.', implode(', ', $own));
            $reasons[] = [] === $revoked
                ? $none.($definition['strict'] ? ' It\'s strict: admins have it only when granted.' : '')
                : \sprintf('It was taken from their role "%s", which has it by default.', $revoked[0]);
        }

        $ownOnly = str_ends_with($ability, '.edit_own');

        if ($granted && $ownOnly) {
            $reasons[] = 'Only for items they wrote.';
        }

        if ([] !== $this->config['rules'] && !$ruled) {
            $reasons[] = 'Rules of extensions may decide differently for particular items.';
        }

        return new AccessExplanation($granted, $reasons, $ownOnly || [] !== $this->config['rules'], array_values(array_unique($grantedBy)));
    }
}
