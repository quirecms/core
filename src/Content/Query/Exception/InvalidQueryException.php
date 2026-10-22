<?php

declare(strict_types=1);

namespace Quire\Content\Query\Exception;

/**
 * A content query asked for something it can't do, e.g. to filter by a field that isn't filterable.
 *
 * @api
 */
final class InvalidQueryException extends \InvalidArgumentException
{
}
