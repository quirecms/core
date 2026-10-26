<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Settings\SettingType;
use Quire\User\Preference\PreferenceDefinition;
use Quire\User\Preference\UserPreferences;

/**
 * Declares preferences each person sets for themselves, e.g. how a page of your extension sorts.
 *
 *     new Extend\Preferences()
 *         ->define('acme-reviews.sort', 'enum', 'newest', choices: ['newest', 'oldest'])
 *         ->define('acme-reviews.show_spam', 'bool', false);
 *
 * Names start with the extension's ID and a dot. Read and change them with {@see UserPreferences}; only
 * what someone changed is stored, so a new default reaches everyone who kept the old one.
 *
 * @api
 */
final class Preferences implements ExtenderInterface
{
    private const string NAME_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*(?:\.[a-z0-9]+(?:[-_][a-z0-9]+)*)+$/';

    /** @var list<array{name: string, type: SettingType|string, default: mixed, choices: list<string|int>, nullable: bool}> */
    private array $definitions = [];

    /**
     * @param SettingType|string $type    "string", "bool", "int", "float", "enum", "list" or "json"
     * @param list<string|int>   $choices Allowed values, for the "enum" type
     */
    public function define(string $name, SettingType|string $type, mixed $default, array $choices = [], bool $nullable = false): self
    {
        $this->definitions[] = ['name' => $name, 'type' => $type, 'default' => $default, 'choices' => $choices, 'nullable' => $nullable];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();
        $all = $container->getParameterBag()->all()[UserPreferences::PARAMETER] ?? [];
        $all = \is_array($all) ? $all : [];
        $prefixes = match ($context->id) {
            ExtenderContext::CORE => ['quire.'],
            ExtenderContext::SITE => ['custom.'],
            default => [$context->id.'.'],
        };

        foreach ($this->definitions as $definition) {
            $where = \sprintf('Extend\Preferences::define("%s")', $definition['name']);
            $typeName = $definition['type'] instanceof SettingType ? $definition['type']->value : $definition['type'];
            $type = SettingType::tryFrom($typeName);

            if (null === $type) {
                throw $context->error($where, \sprintf('Unknown type "%s". Use one of: %s.', $typeName, implode(', ', array_map(static fn (SettingType $t): string => $t->value, SettingType::cases()))));
            }

            if (1 !== preg_match(self::NAME_PATTERN, $definition['name'])) {
                throw $context->error($where, 'Names are lowercase words separated by dots, e.g. "acme-reviews.sort".');
            }

            if (!array_any($prefixes, static fn (string $prefix): bool => str_starts_with($definition['name'], $prefix))) {
                throw $context->error($where, \sprintf('Names declared here must start with "%s", so they can\'t clash with other extensions\' preferences.', implode('" or "', $prefixes)));
            }

            if ((SettingType::Enum === $type) !== ([] !== $definition['choices'])) {
                throw $context->error($where, SettingType::Enum === $type ? 'Preferences of type "enum" need a list of choices.' : 'Choices only apply to preferences of type "enum".');
            }

            $preference = new PreferenceDefinition($definition['name'], $type, $definition['default'], $context->id, $definition['choices'], $definition['nullable']);
            $error = $preference->validate($definition['default']);

            if (null !== $error) {
                throw $context->error($where, \sprintf('The default doesn\'t fit: %s.', $error));
            }

            if (isset($all[$definition['name']])) {
                $owner = \is_array($all[$definition['name']]) && \is_string($all[$definition['name']]['owner'] ?? null) ? $all[$definition['name']]['owner'] : 'another extension';

                throw $context->error($where, \sprintf('The preference is already declared by "%s".', $owner));
            }

            $all[$definition['name']] = $preference->toArray();
        }

        $container->setParameter(UserPreferences::PARAMETER, $all);
    }
}
