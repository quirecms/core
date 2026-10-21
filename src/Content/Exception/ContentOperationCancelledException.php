<?php

declare(strict_types=1);

namespace Quire\Content\Exception;

/**
 * A listener cancelled the change (see {@see \Quire\Content\Event\CancellableEvent}). Nothing was saved.
 *
 * @api
 */
final class ContentOperationCancelledException extends \RuntimeException
{
}
