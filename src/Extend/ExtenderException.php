<?php

declare(strict_types=1);

namespace Quire\Extend;

/**
 * An extension's `extend.php` or one of its extenders is invalid.
 *
 * Messages start with the extension ID, so the source of the problem is obvious.
 *
 * @api
 */
final class ExtenderException extends \LogicException
{
}
