<?php

declare(strict_types=1);

namespace Quire\Settings\Exception;

/**
 * A value doesn't fit a setting's type, or the setting can't be changed.
 *
 * @api
 */
final class InvalidSettingValueException extends \InvalidArgumentException
{
}
