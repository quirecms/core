<?php

declare(strict_types=1);

namespace Quire\Action\Exception;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The signed-in user may not do this. Its message says why, for them.
 *
 * It's Symfony's AccessDeniedException, so in a request it ends as a 403 page, or the sign-in page for
 * visitors.
 *
 * @api
 */
final class ActionDeniedException extends AccessDeniedException
{
    public function __construct(public readonly string $action, string $reason)
    {
        parent::__construct($reason);
    }
}
