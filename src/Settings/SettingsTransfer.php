<?php

declare(strict_types=1);

namespace Quire\Settings;

use Doctrine\DBAL\Connection;
use Quire\Settings\Exception\InvalidSettingValueException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Moves settings between sites as a file: what the site changed, as YAML or JSON, and back, after
 * looking at every difference. Values of environment variables belong to the environment, so they're
 * never exported or written; sensitive settings, e.g. API keys, only when asked.
 *
 *     $file = $transfer->toYaml($transfer->export());
 *     $import = $transfer->plan($transfer->parse($file));   // what would change, or why it can't
 *     $transfer->apply($import);                            // all of it, or none
 *
 * @api
 */
final readonly class SettingsTransfer
{
    public function __construct(
        private SettingsSchema $schema,
        private Settings $settings,
        private Connection $connection,
    ) {
    }

    /**
     * The settings the site changed, by key.
     *
     * @param string $prefix    Only settings whose key starts with it
     * @param bool   $all       Also those at their default
     * @param bool   $sensitive Also sensitive ones, e.g. API keys
     *
     * @return array<string, mixed>
     */
    public function export(string $prefix = '', bool $all = false, bool $sensitive = false): array
    {
        $values = [];

        foreach ($this->schema->all($prefix) as $key => $definition) {
            $source = $this->settings->source($key);

            if ((!$definition->sensitive || $sensitive) && ('database' === $source || ($all && 'default' === $source))) {
                $values[$key] = $this->settings->get($key);
            }
        }

        ksort($values);

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function toYaml(array $values): string
    {
        return [] === $values ? "{}\n" : Yaml::dump($values, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function toJson(array $values): string
    {
        return [] === $values ? '{}' : json_encode($values, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * The settings of a file, as JSON or YAML.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when it's neither, or not settings by key
     */
    public function parse(string $content): array
    {
        try {
            $values = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            try {
                $values = Yaml::parse($content);
            } catch (ParseException $e) {
                throw new \InvalidArgumentException(\sprintf('The file is neither JSON nor YAML: %s', $e->getMessage()), $e->getCode(), previous: $e);
            }
        }

        if (!\is_array($values) || ([] !== $values && array_is_list($values))) {
            throw new \InvalidArgumentException('The file must hold settings by key, e.g. "site.title: News" in YAML or {"site.title": "News"} in JSON.');
        }

        $settings = [];

        foreach ($values as $key => $value) {
            $settings[(string) $key] = $value;
        }

        return $settings;
    }

    /**
     * What importing the values would change. Settings they don't list keep their value.
     *
     * @param array<string, mixed> $values
     * @param bool                 $skipUnknown Leave out keys no enabled extension declares, instead of refusing them
     */
    public function plan(array $values, bool $skipUnknown = false): SettingsImport
    {
        [$changes, $errors, $skipped] = [[], [], []];

        foreach ($values as $key => $value) {
            if (!$this->schema->has($key)) {
                $skipUnknown ? $skipped[] = $key : $errors[] = \sprintf('%s: no enabled extension declares it.', $key);

                continue;
            }

            $definition = $this->schema->get($key);
            $error = 'environment' === $this->settings->source($key) ? \sprintf('it\'s set by the environment variable %s.', $definition->environmentVariable()) : $definition->validate($value);

            if (null !== $error) {
                $errors[] = \sprintf('%s: %s', $key, $error);
            } elseif ($this->settings->get($key) !== $value) {
                $changes[$key] = ['from' => $this->settings->get($key), 'to' => $value, 'sensitive' => $definition->sensitive];
            }
        }

        return new SettingsImport($changes, $errors, $skipped);
    }

    /**
     * Changes the settings as planned: all of them, or none should one be refused.
     *
     * @return int How many changed
     *
     * @throws \LogicException              when the plan has errors
     * @throws InvalidSettingValueException when a validator of an extension refuses a value
     */
    public function apply(SettingsImport $import): int
    {
        if (!$import->canApply()) {
            throw new \LogicException('The settings can\'t be imported: '.implode(' ', $import->errors));
        }

        try {
            $this->connection->transactional(function () use ($import): void {
                foreach ($import->changes as $key => $change) {
                    $this->settings->set($key, $change['to']);
                }
            });
        } catch (InvalidSettingValueException $e) {
            $this->settings->reset();

            throw $e;
        }

        return \count($import->changes);
    }
}
