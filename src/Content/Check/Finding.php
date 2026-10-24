<?php

declare(strict_types=1);

namespace Quire\Content\Check;

/**
 * Something a content check found, e.g. an image without a description.
 *
 * @api
 */
final readonly class Finding
{
    /**
     * @param string      $message  What's wrong and what to do, for the editor, e.g. "The image “chart.png” has no description."
     * @param string|null $property Where: "title", "excerpt", "body" or "fields.<name>"
     * @param int|null    $line     For the body: the line it's on, from 1
     */
    public function __construct(
        public string $message,
        public ?string $property = null,
        public ?int $line = null,
    ) {
    }
}
