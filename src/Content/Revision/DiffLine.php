<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * A line of a text diff.
 *
 * @api
 */
final readonly class DiffLine
{
    public const string SAME = 'same';
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';

    /**
     * @param self::SAME|self::ADDED|self::REMOVED $operation
     * @param string                               $text      The line, without its line ending
     * @param int|null                             $oldNumber Its number in the old text (from 1), unless added
     * @param int|null                             $newNumber Its number in the new text, unless removed
     */
    public function __construct(
        public string $operation,
        public string $text,
        public ?int $oldNumber,
        public ?int $newNumber,
    ) {
    }
}
