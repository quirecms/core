<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Settings\SettingDefinition;
use Quire\Settings\SettingsSchema;
use Quire\Settings\SettingType;

/**
 * Declares site settings that admins can change at runtime.
 *
 *     (new Extend\Settings())
 *         ->define('acme-reviews.require_approval', 'bool', true, label: 'Require approval')
 *         ->define('acme-reviews.per_page', 'int', 20)
 *         ->define('acme-reviews.sort', 'enum', 'newest', choices: ['newest', 'oldest']);
 *
 * Keys start with the extension's ID and a dot; a theme's start with "theme.", its ID and a dot, e.g.
 * "theme.acme-dark.accent". Read values with {@see \Quire\Settings\Settings}, and in templates with
 * quire_setting() when the setting is exposed. A theme's settings are always exposed.
 *
 * @api
 */
final class Settings implements ExtenderInterface
{
    private const string KEY_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*(?:\.[a-z0-9]+(?:[-_][a-z0-9]+)*)+$/';

    /** @var list<array{key: string, type: SettingType|string, default: mixed, label: ?string, help: ?string, group: ?string, widget: ?string, choices: list<string|int>, nullable: bool, sensitive: bool, exposed: bool}> */
    private array $definitions = [];

    /**
     * @param SettingType|string $type      "string", "bool", "int", "float", "enum", "list" or "json"
     * @param list<string|int>   $choices   Allowed values, for the "enum" type
     * @param string|null        $widget    Hint for admin interfaces, e.g. "textarea" or "color"
     * @param bool               $sensitive Hide the value in listings, e.g. for API keys
     * @param bool               $exposed   Let templates read it with quire_setting(), e.g. a footer text
     */
    public function define(
        string $key,
        SettingType|string $type,
        mixed $default,
        ?string $label = null,
        ?string $help = null,
        ?string $group = null,
        ?string $widget = null,
        array $choices = [],
        bool $nullable = false,
        bool $sensitive = false,
        bool $exposed = false,
    ): self {
        $this->definitions[] = [
            'key' => $key,
            'type' => $type,
            'default' => $default,
            'label' => $label,
            'help' => $help,
            'group' => $group,
            'widget' => $widget,
            'choices' => $choices,
            'nullable' => $nullable,
            'sensitive' => $sensitive,
            'exposed' => $exposed,
        ];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();
        $all = $container->getParameterBag()->all()[SettingsSchema::PARAMETER] ?? [];
        $all = \is_array($all) ? $all : [];

        foreach ($this->definitions as $definition) {
            $where = \sprintf('Extend\Settings::define("%s")', $definition['key']);
            $typeName = $definition['type'] instanceof SettingType ? $definition['type']->value : $definition['type'];
            $type = SettingType::tryFrom($typeName);

            if (null === $type) {
                throw $context->error($where, \sprintf('Unknown type "%s". Use one of: %s.', $typeName, implode(', ', array_map(static fn (SettingType $t): string => $t->value, SettingType::cases()))));
            }

            if (1 !== preg_match(self::KEY_PATTERN, $definition['key'])) {
                throw $context->error($where, 'Keys are lowercase words separated by dots, e.g. "acme-reviews.per_page".');
            }

            $prefixes = $this->ownedPrefixes($context);

            if (!array_any($prefixes, static fn (string $prefix): bool => str_starts_with($definition['key'], $prefix))) {
                throw $context->error($where, \sprintf('Keys declared here must start with "%s", so they can\'t clash with other extensions\' settings.', implode('" or "', $prefixes)));
            }

            if ($definition['exposed'] && $definition['sensitive']) {
                throw $context->error($where, 'Sensitive settings can\'t be exposed to templates.');
            }

            if (SettingType::Enum === $type && [] === $definition['choices']) {
                throw $context->error($where, 'Settings of type "enum" need a list of choices.');
            }

            if (SettingType::Enum !== $type && [] !== $definition['choices']) {
                throw $context->error($where, 'Choices only apply to settings of type "enum".');
            }

            $setting = new SettingDefinition(
                key: $definition['key'],
                type: $type,
                default: $definition['default'],
                owner: $context->id,
                label: $definition['label'],
                help: $definition['help'],
                group: $definition['group'],
                widget: $definition['widget'],
                choices: $definition['choices'],
                nullable: $definition['nullable'],
                sensitive: $definition['sensitive'],
                // A theme's settings exist for its templates.
                exposed: $definition['exposed'] || $context->theme,
            );

            $error = $setting->validate($definition['default']);

            if (null !== $error) {
                throw $context->error($where, \sprintf('The default value doesn\'t fit the setting: %s.', $error));
            }

            $existing = $all[$definition['key']] ?? null;

            if (\is_array($existing)) {
                throw $context->error($where, \sprintf('The setting is already declared by "%s".', \is_string($existing['owner'] ?? null) ? $existing['owner'] : 'another extension'));
            }

            $all[$definition['key']] = $setting->toArray();
        }

        $container->setParameter(SettingsSchema::PARAMETER, $all);
    }

    /**
     * @return list<string>
     */
    private function ownedPrefixes(ExtenderContext $context): array
    {
        return match (true) {
            ExtenderContext::CORE === $context->id => ['site.', 'quire.', 'theme.'],
            ExtenderContext::SITE === $context->id => ['custom.'],
            $context->theme => ['theme.'.$context->id.'.'],
            default => [$context->id.'.'],
        };
    }
}
