<?php

declare(strict_types=1);

namespace Quire\Settings\Event;

/**
 * Dispatched after a setting was changed or reset. Not dispatched for environment variable overrides.
 *
 * @api
 */
final readonly class SettingChanged
{
    public function __construct(
        public string $key,
        public mixed $oldValue,
        public mixed $newValue,
    ) {
    }
}
