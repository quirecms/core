<?php

declare(strict_types=1);

namespace Quire\Settings\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Internal\Access;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Settings\SettingDefinition;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Settings\SettingType;

/**
 * "setting.set": change a site setting, or give it its default value again. The values of sensitive
 * settings, e.g. keys, are never on a command line.
 *
 * @internal
 */
final readonly class SetSetting implements ActionInterface
{
    public function __construct(
        private Settings $settings,
        private SettingsSchema $schema,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Change a setting', [
            new Parameter('setting', ParameterType::Setting, 'Setting'),
            new Parameter('value', ParameterType::Value, 'Value', required: false),
            new Parameter('default', ParameterType::Bool, 'Back to the default', required: false),
        ], command: 'quire:settings:set');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::SETTINGS);
    }

    public function run(Arguments $arguments): null
    {
        $key = $arguments->string('setting');

        $arguments->bool('default')
            ? $this->settings->restoreDefault($key)
            : $this->settings->set($key, $arguments->value('value'));

        return null;
    }

    public function commandLine(Arguments $arguments): ?string
    {
        $key = $arguments->string('setting');
        $definition = $this->schema->has($key) ? $this->schema->get($key) : null;

        if (!$definition instanceof SettingDefinition || $definition->sensitive) {
            return null;
        }

        if ($arguments->bool('default')) {
            return CommandLine::of('quire:settings:set', [$key], ['default' => true]);
        }

        $value = $arguments->value('value');
        $text = match (true) {
            SettingType::Bool === $definition->type && \is_bool($value) => $value ? 'true' : 'false',
            SettingType::List === $definition->type, SettingType::Json === $definition->type => json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            \is_scalar($value) => (string) $value,
            default => null,
        };

        if (\is_string($text)) {
            return CommandLine::of('quire:settings:set', [$key, $text]);
        }

        // Null can only be had as the default.
        return null === $value && null === $definition->default ? CommandLine::of('quire:settings:set', [$key], ['default' => true]) : null;
    }
}
