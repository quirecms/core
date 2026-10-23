<?php

declare(strict_types=1);

namespace Quire\User\Mail;

use Quire\Settings\Settings;
use Quire\Settings\SettingValidatorInterface;
use Quire\User\AccountSettings;
use Symfony\Component\Mime\Address;

/**
 * Accepts only addresses as the sender of emails, e.g. "news@example.com" or "News <news@example.com>".
 *
 * @internal
 */
final readonly class MailFromValidator implements SettingValidatorInterface
{
    public function validate(string $key, mixed $value, Settings $settings): ?string
    {
        if (AccountSettings::MAIL_FROM !== $key || null === $value) {
            return null;
        }

        try {
            Address::create(\is_string($value) ? $value : '');
        } catch (\Throwable) {
            return 'Use an email address, e.g. "news@example.com" or "News <news@example.com>".';
        }

        return null;
    }
}
