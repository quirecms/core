<?php

declare(strict_types=1);

namespace Quire\Permission\Exception;

/**
 * A role can't be created, changed or deleted, e.g. because its name is taken. The message says why.
 *
 * @api
 */
final class InvalidRoleException extends \InvalidArgumentException
{
}
