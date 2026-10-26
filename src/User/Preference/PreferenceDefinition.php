<?php

declare(strict_types=1);

namespace Quire\User\Preference;

use Quire\Settings\SettingType;

/**
 * A preference each person sets for themselves, e.g. the admin's language or "dark".
 *
 * @api
 */
final readonly class PreferenceDefinition
{
    /**
     * @param string           $owner   ID of the extension that declared it, or "core"
     * @param list<string|int> $choices Allowed values for {@see SettingType::Enum}
     */
    public function __construct(
        public string $name,
        public SettingType $type,
        public mixed $default,
        public string $owner,
        public array $choices = [],
        public bool $nullable = false,
    ) {
    }

    /**
     * Why $value isn't valid for this preference, or null when it is.
     */
    public function validate(mixed $value): ?string
    {
        if (null === $value) {
            return $this->nullable ? null : 'the preference can\'t be empty (null)';
        }

        return $this->type->validate($value, $this->choices);
    }

    /**
     * @internal Compiled into the container as a parameter
     *
     * @return array{name: string, type: string, default: mixed, owner: string, choices: list<string|int>, nullable: bool}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type->value, 'default' => $this->default, 'owner' => $this->owner, 'choices' => $this->choices, 'nullable' => $this->nullable];
    }

    /**
     * @internal
     *
     * @param array{name: string, type: string, default: mixed, owner: string, choices: list<string|int>, nullable: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], SettingType::from($data['type']), $data['default'], $data['owner'], $data['choices'], $data['nullable']);
    }
}
