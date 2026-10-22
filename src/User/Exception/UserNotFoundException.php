<?php

declare(strict_types=1);

namespace Quire\User\Exception;

/**
 * @api
 */
final class UserNotFoundException extends \RuntimeException
{
    public static function for(string $reference): self
    {
        return new self(\sprintf('No user with the ID, email or username "%s".', $reference));
    }
}
