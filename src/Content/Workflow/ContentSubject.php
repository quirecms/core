<?php

declare(strict_types=1);

namespace Quire\Content\Workflow;

use Quire\Content\Content;

/**
 * What Symfony's workflow events receive as their subject: the item and the status it's moving to.
 *
 * In a guard listener, read the item from `$event->getSubject()->content`.
 *
 * @api
 */
final class ContentSubject
{
    private string $status;

    public function __construct(
        public readonly Content $content,
    ) {
        $this->status = $content->status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @internal Called by the workflow's marking store
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
