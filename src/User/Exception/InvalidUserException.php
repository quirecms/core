<?php

declare(strict_types=1);

namespace Quire\User\Exception;

/**
 * A user can't be saved as given. The violations say why, per property.
 *
 * @api
 */
final class InvalidUserException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $violations Messages keyed by property, e.g. ["email" => "…"]
     */
    public function __construct(
        public readonly array $violations,
    ) {
        $lines = [];

        foreach ($violations as $property => $message) {
            $lines[] = \sprintf('%s: %s', $property, $message);
        }

        parent::__construct('The user is invalid. '.implode(' ', $lines));
    }

    public static function single(string $property, string $message): self
    {
        return new self([$property => $message]);
    }
}
