<?php

declare(strict_types=1);

namespace Quire\User\Preference;

/**
 * A preference that isn't declared, or a value that doesn't fit it.
 *
 * @api
 */
final class InvalidPreferenceException extends \InvalidArgumentException
{
}
