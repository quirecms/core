<?php

declare(strict_types=1);

namespace Quire\Theme;

/**
 * Fills a slot with HTML built in PHP, for fragments that are more than a template. Register it with
 * Extend\Views::slot('content.after_body', MyRenderer::class). It's a service, so it can depend on others.
 *
 * @api
 */
interface SlotRendererInterface
{
    /**
     * @param array<string, mixed> $context What the theme passed to the slot, e.g. "content" for content slots
     *
     * @return string HTML, output as it is: escape what comes from users
     */
    public function render(array $context): string;
}
