<?php

declare(strict_types=1);

namespace Quire\Permission;

use Doctrine\DBAL\Connection;
use Quire\Permission\Exception\InvalidRoleException;
use Quire\Settings\Settings;
use Quire\User\Role;

/**
 * Roles the site adds at runtime, e.g. "translator", next to those core and extensions declare. They're
 * kept in the setting "quire.permissions.roles"; their abilities are granted like any role's.
 *
 * @api
 */
final readonly class SiteRoles
{
    private const string NAME_PATTERN = '/^[a-z][a-z0-9]*(?:[-_][a-z0-9]+)*$/';

    private const int LABEL_MAX_LENGTH = 50;

    public function __construct(
        private Permissions $permissions,
        private Settings $settings,
        private Connection $connection,
    ) {
    }

    /**
     * @param string|null $from A role whose abilities the new role starts with
     *
     * @throws InvalidRoleException
     */
    public function create(string $name, string $label, ?string $from = null): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name) || \strlen($name) > 32) {
            throw new InvalidRoleException(\sprintf('"%s" can\'t be a role\'s name: use up to 32 lowercase letters, digits, "-" and "_", starting with a letter, e.g. "translator".', $name));
        }

        if ($this->permissions->hasRole($name)) {
            throw new InvalidRoleException(\sprintf('There\'s a role "%s" already.', $name));
        }

        if (null !== $from && !$this->permissions->hasRole($from)) {
            throw new InvalidRoleException(\sprintf('Unknown role "%s". Roles: %s.', $from, implode(', ', array_keys($this->permissions->roles()))));
        }

        $label = $this->label($label);

        $this->connection->transactional(function () use ($name, $label, $from): void {
            $this->store([...$this->permissions->siteRoles(), $name => $label]);
            // A role of an earlier site role with this name has no grants left; this starts it afresh.
            $this->permissions->forgetRole($name);

            if (null !== $from) {
                $this->permissions->setAbilities($name, $this->permissions->abilitiesOf($from));
            }
        });
    }

    /**
     * @throws InvalidRoleException
     */
    public function relabel(string $name, string $label): void
    {
        $this->assertSiteRole($name);
        $this->store([...$this->permissions->siteRoles(), $name => $this->label($label)]);
    }

    /**
     * Deletes the role, its grants, and the role from the users who have it.
     *
     * @throws InvalidRoleException
     */
    public function delete(string $name): void
    {
        $this->assertSiteRole($name);

        $this->connection->transactional(function () use ($name): void {
            $this->connection->executeStatement('UPDATE quire.app_user SET roles = array_remove(roles, ?), updated_at = now() WHERE ? = ANY(roles)', [$name, $name]);
            $this->permissions->forgetRole($name);
            $this->store(array_diff_key($this->permissions->siteRoles(), [$name => true]));
        });
    }

    /**
     * @throws InvalidRoleException
     */
    private function assertSiteRole(string $name): void
    {
        if (!isset($this->permissions->siteRoles()[$name])) {
            throw new InvalidRoleException($this->permissions->hasRole($name) ? \sprintf('"%s" is declared by %s, so only the site\'s own roles can be changed or deleted.', $name, Role::GUEST === $name || \in_array($name, Role::ALL, true) ? 'Quire' : 'an extension') : \sprintf('Unknown role "%s".', $name));
        }
    }

    private function label(string $label): string
    {
        $label = trim($label);

        if ('' === $label || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new InvalidRoleException(\sprintf('Give the role a name of up to %d characters, e.g. "Translator".', self::LABEL_MAX_LENGTH));
        }

        return $label;
    }

    /**
     * @param array<string, string> $roles
     */
    private function store(array $roles): void
    {
        ksort($roles);
        $this->settings->set(Permissions::ROLES_SETTING, $roles);
        $this->permissions->reset();
    }
}
