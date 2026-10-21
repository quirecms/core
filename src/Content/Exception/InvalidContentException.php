<?php

declare(strict_types=1);

namespace Quire\Content\Exception;

/**
 * The requested change breaks one or more rules. {@see $violations} says which, per property, so forms
 * can show each message next to its input.
 *
 * @api
 */
final class InvalidContentException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $violations Messages keyed by property, e.g. ["slug" => "…"]
     */
    public function __construct(
        public readonly array $violations,
    ) {
        $lines = [];

        foreach ($violations as $property => $message) {
            $lines[] = \sprintf('%s: %s', $property, $message);
        }

        parent::__construct('The content item is invalid. '.implode(' ', $lines));
    }

    public static function single(string $property, string $message): self
    {
        return new self([$property => $message]);
    }
}
