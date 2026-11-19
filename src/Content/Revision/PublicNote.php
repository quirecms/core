<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Quire\Content\Exception\InvalidContentException;

/**
 * What readers are told about a change when it's published: its kind, and a sentence an author
 * chose to make public (never the editors' own revision message). A theme shows it as "Updated" or
 * "Corrected" with the note, and in the item's history.
 *
 * @api
 */
final readonly class PublicNote
{
    public const int MAX_LENGTH = 500;

    public string $note;

    /**
     * @throws InvalidContentException when the note is empty or too long
     */
    public function __construct(
        public PublicChangeKind $kind,
        string $note,
    ) {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if ('' === $note) {
            throw InvalidContentException::single('public_note', PublicChangeKind::Correction === $kind ? 'A correction says what was wrong and what it says now.' : 'Say what changed, in a sentence for readers.');
        }
        if (mb_strlen($note) > self::MAX_LENGTH) {
            throw InvalidContentException::single('public_note', \sprintf('A note for readers is %d characters at most.', self::MAX_LENGTH));
        }

        $this->note = $note;
    }
}
