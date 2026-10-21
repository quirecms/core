<?php

declare(strict_types=1);

namespace Quire\Content\Exception;

/**
 * The status transition doesn't exist from the item's status, or a guard blocked it.
 *
 * @api
 */
final class TransitionNotAllowedException extends \RuntimeException
{
    /**
     * @param list<string> $reasons Why guards blocked the transition, if they did
     * @param list<string> $enabled The transitions that are possible now
     */
    public function __construct(
        public readonly string $transition,
        public readonly string $status,
        public readonly array $reasons,
        public readonly array $enabled,
    ) {
        $message = [] === $reasons
            ? \sprintf('A %s item can\'t take the "%s" transition.', $status, $transition)
            : \sprintf('The "%s" transition is blocked: %s', $transition, implode(' ', $reasons));
        $message .= [] === $enabled ? ' No transitions are possible now.' : \sprintf(' Possible now: %s.', implode(', ', $enabled));

        parent::__construct($message);
    }
}
