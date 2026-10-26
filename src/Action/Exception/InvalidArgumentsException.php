<?php

declare(strict_types=1);

namespace Quire\Action\Exception;

/**
 * The arguments don't fit the action's parameters: one is missing, unknown or of the wrong kind.
 *
 * @api
 */
final class InvalidArgumentsException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $errors What's wrong, by parameter
     */
    public function __construct(public readonly string $action, public readonly array $errors)
    {
        parent::__construct(\sprintf('Invalid arguments for "%s": %s', $action, implode(' ', array_map(static fn (string $name, string $error): string => \sprintf('%s: %s', $name, $error), array_keys($errors), $errors))));
    }
}
