<?php

declare(strict_types=1);

namespace Quire\User\Exception;

/**
 * A listener of {@see \Quire\User\Event\IdentityLinking} refused to link the identity.
 *
 * @api
 */
final class IdentityLinkRefusedException extends \RuntimeException
{
}
