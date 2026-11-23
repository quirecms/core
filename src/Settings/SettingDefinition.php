<?php

declare(strict_types=1);

namespace Quire\Settings;

/**
 * A declared setting: its type, default and how admin interfaces should present it.
 *
 * @api
 */
final readonly class SettingDefinition
{
    /**
     * @param string                $owner       ID of the extension that declared it, "core" or "site"
     * @param list<string|int>      $choices     Allowed values for {@see SettingType::Enum}
     * @param bool                  $sensitive   Hidden in listings, e.g. API keys
     * @param bool                  $exposed     Readable by templates with quire_setting()
     * @param string|null           $phrase      The setting in a sentence, with {value}, e.g. "This site is called {value}."
     * @param array<string, string> $valueLabels How values read in the sentence: by choice for "enum", "on" and "off" for "bool"
     * @param string|null           $ability     The ability changing it takes besides managing settings, for a setting another
     *                                           part of the admin owns, e.g. the active theme ("quire.themes.manage")
     * @param string|null           $contentType For core's settings of one content type: the type, whose name people read
     *                                           next to $typeLabel ("Item URLs (Posts)"). Its label and help are core's texts.
     * @param string|null           $typeLabel   The label without the type's name, e.g. "Item URLs"; $label stays the
     *                                           English name in full ("URLs of posts"), for the CLI and the intent bar
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default,
        public string $owner,
        public ?string $label = null,
        public ?string $help = null,
        public ?string $group = null,
        public ?string $widget = null,
        public array $choices = [],
        public bool $nullable = false,
        public bool $sensitive = false,
        public bool $exposed = false,
        public ?string $phrase = null,
        public array $valueLabels = [],
        public ?string $ability = null,
        public ?string $contentType = null,
        public ?string $typeLabel = null,
    ) {
    }

    /**
     * How a value reads in the setting's sentence, untranslated: its label, or null to show the value itself.
     */
    public function valueLabel(mixed $value): ?string
    {
        $key = match (true) {
            \is_bool($value) => $value ? 'on' : 'off',
            \is_string($value), \is_int($value) => (string) $value,
            default => null,
        };

        return null === $key ? null : ($this->valueLabels[$key] ?? null);
    }

    /**
     * Why $value isn't valid for this setting, or null when it is.
     */
    public function validate(mixed $value): ?string
    {
        if (null === $value) {
            return $this->nullable ? null : 'the setting can\'t be empty (null)';
        }

        return $this->type->validate($value, $this->choices);
    }

    /**
     * Name of the environment variable that overrides this setting:
     * "acme-reviews.per_page" becomes QUIRE_SETTING__ACME_REVIEWS__PER_PAGE.
     */
    public function environmentVariable(): string
    {
        return 'QUIRE_SETTING__'.strtoupper(str_replace(['.', '-'], ['__', '_'], $this->key));
    }

    /**
     * @internal Compiled into the container as a parameter
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'default' => $this->default,
            'owner' => $this->owner,
            'label' => $this->label,
            'help' => $this->help,
            'group' => $this->group,
            'widget' => $this->widget,
            'choices' => $this->choices,
            'nullable' => $this->nullable,
            'sensitive' => $this->sensitive,
            'exposed' => $this->exposed,
            'phrase' => $this->phrase,
            'value_labels' => $this->valueLabels,
            'ability' => $this->ability,
            'content_type' => $this->contentType,
            'type_label' => $this->typeLabel,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $data As produced by {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        /** @var array{key: string, type: string, default: mixed, owner: string, label: ?string, help: ?string, group: ?string, widget: ?string, choices: list<string|int>, nullable: bool, sensitive: bool, exposed?: bool, phrase?: ?string, value_labels?: array<string, string>, ability?: ?string, content_type?: ?string, type_label?: ?string} $definition */
        $definition = $data;

        return new self(
            key: $definition['key'],
            type: SettingType::from($definition['type']),
            default: $definition['default'],
            owner: $definition['owner'],
            label: $definition['label'],
            help: $definition['help'],
            group: $definition['group'],
            widget: $definition['widget'],
            choices: $definition['choices'],
            nullable: $definition['nullable'],
            sensitive: $definition['sensitive'],
            exposed: $definition['exposed'] ?? false,
            phrase: $definition['phrase'] ?? null,
            valueLabels: $definition['value_labels'] ?? [],
            ability: $definition['ability'] ?? null,
            contentType: $definition['content_type'] ?? null,
            typeLabel: $definition['type_label'] ?? null,
        );
    }
}
