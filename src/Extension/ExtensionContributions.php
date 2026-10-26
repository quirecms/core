<?php

declare(strict_types=1);

namespace Quire\Extension;

use Quire\Action\Actions;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Database\MigrationOrder;
use Quire\Extend\ContentChecks;
use Quire\Extend\FieldTypes;
use Quire\Extend\Jobs;
use Quire\Extend\Routes;
use Quire\Permission\Permissions;
use Quire\Settings\SettingsSchema;
use Quire\User\Preference\UserPreferences;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

/**
 * What each running extension adds to the site: content types and fields, settings, abilities and roles,
 * pages, checks, actions, scheduled tasks, and whether it keeps data of its own. Read from what the site
 * was built with, so it's what really runs, not what a manifest says.
 *
 * @api
 */
final readonly class ExtensionContributions
{
    public function __construct(
        private ContentTypeRegistry $types,
        private SettingsSchema $settings,
        private UserPreferences $preferences,
        private Permissions $permissions,
        private Actions $actions,
        private ContainerBagInterface $parameters,
    ) {
    }

    /**
     * @param string $id An extension's or theme's ID, or "core"
     */
    public function of(string $id): Contributions
    {
        $fields = [];
        $types = [];

        foreach ($this->types->all() as $type) {
            if ($type->owner === $id) {
                $types[] = $type->id;
            }

            foreach ($type->fields as $field) {
                if ($field->owner === $id && $type->owner !== $id) {
                    $fields[] = $type->id.'.'.$field->name;
                }
            }
        }

        $routes = [];

        foreach ($this->list(Routes::PARAMETER) as $route) {
            if (($route['extension'] ?? null) === $id) {
                $group = \is_string($route['group'] ?? null) ? $route['group'] : Routes::SITE;
                $routes[$group] = ($routes[$group] ?? 0) + 1;
            }
        }

        return new Contributions(
            contentTypes: $types,
            fields: $fields,
            fieldTypes: $this->owned(FieldTypes::PARAMETER, $id, 'name'),
            settings: array_keys(array_filter($this->settings->all(), static fn (\Quire\Settings\SettingDefinition $definition): bool => $definition->owner === $id)),
            preferences: array_keys(array_filter($this->preferences->definitions(), static fn (\Quire\User\Preference\PreferenceDefinition $definition): bool => $definition->owner === $id)),
            abilities: array_keys(array_filter($this->permissions->abilities(), static fn (array $ability): bool => $ability['owner'] === $id)),
            roles: array_values(array_filter(array_keys($this->permissions->roles()), fn (string $role): bool => $this->permissions->roleOwner($role) === $id)),
            routes: $routes,
            checks: $this->owned(ContentChecks::PARAMETER, $id, 'id'),
            actions: array_values(array_filter($this->actions->ids(), fn (string $action): bool => $this->actions->owner($action) === $id)),
            scheduled: $this->owned(Jobs::PARAMETER, $id, 'name'),
            migrations: [] !== $this->owned(MigrationOrder::PARAMETER, $id, 'namespace'),
        );
    }

    /**
     * The given field of the entries of a parameter that the extension owns.
     *
     * @return list<string>
     */
    private function owned(string $parameter, string $id, string $field): array
    {
        $owned = [];

        foreach ($this->list($parameter) as $entry) {
            if (($entry['owner'] ?? null) === $id && \is_string($entry[$field] ?? null)) {
                $owned[] = $entry[$field];
            }
        }

        return $owned;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(string $parameter): array
    {
        $value = $this->parameters->has($parameter) ? $this->parameters->get($parameter) : [];

        $list = [];

        foreach (\is_array($value) ? $value : [] as $entry) {
            if (\is_array($entry)) {
                $list[] = array_filter($entry, \is_string(...), \ARRAY_FILTER_USE_KEY);
            }
        }

        return $list;
    }
}
