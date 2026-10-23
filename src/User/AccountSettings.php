<?php

declare(strict_types=1);

namespace Quire\User;

/**
 * The settings of what visitors can do with their accounts themselves. All are off by default.
 *
 * @api
 */
final class AccountSettings
{
    /** Visitors can create an account on the site, as members */
    public const string REGISTRATION = 'quire.auth.registration';

    /** Users who registered confirm their email by opening an emailed link before they can sign in */
    public const string EMAIL_VERIFICATION = 'quire.auth.email_verification';

    /** Users who forgot their password can choose a new one through an emailed link */
    public const string PASSWORD_RESET = 'quire.auth.password_reset';

    /** Who emails come from, e.g. "Tournament news <news@example.com>"; noreply@ the site's domain when empty */
    public const string MAIL_FROM = 'quire.mail.from';

    private function __construct()
    {
    }
}
