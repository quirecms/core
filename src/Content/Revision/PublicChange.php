<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * One entry of a published item's public history: when it changed for readers, how, and what they
 * were told.
 *
 * @api
 */
final readonly class PublicChange
{
    public function __construct(
        public PublicChangeKind $kind,
        public string $note,
        public \DateTimeImmutable $publishedAt,
        public int $contentVersion,
    ) {
    }

    public function isCorrection(): bool
    {
        return PublicChangeKind::Correction === $this->kind;
    }
}
