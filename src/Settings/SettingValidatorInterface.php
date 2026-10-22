<?php

declare(strict_types=1);

namespace Quire\Settings;

/**
 * Checks setting values beyond their type, e.g. that a URL pattern doesn't clash with another one.
 *
 * Implementations are found automatically. Each is asked about every value saved with
 * {@see Settings::set()}, and answers only for the keys it knows.
 *
 * @api
 */
interface SettingValidatorInterface
{
    public const string TAG = 'quire.setting_validator';

    /**
     * @param mixed    $value    Already checked against the setting's type
     * @param Settings $settings For reading other settings the check depends on
     *
     * @return string|null Why the value isn't allowed, or null when it is (or the key isn't this validator's)
     */
    public function validate(string $key, mixed $value, Settings $settings): ?string;
}
