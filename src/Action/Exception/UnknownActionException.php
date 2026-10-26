<?php

declare(strict_types=1);

namespace Quire\Action\Exception;

/**
 * No action has this ID: it's misspelt, or its extension isn't enabled.
 *
 * @api
 */
final class UnknownActionException extends \InvalidArgumentException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct(\sprintf('There is no action "%s". Is the extension that adds it enabled?', $id));
    }
}
