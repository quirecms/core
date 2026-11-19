<?php

declare(strict_types=1);

namespace Quire\Content\Check\Internal;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Finding;
use Quire\Content\Content;
use Quire\Formatter\Internal\ExternalImages;

/**
 * Images from other sites that the page's security policy blocks: visitors see a link to the image
 * rather than the image (the renderer's placeholder). A warning, not a block: copy the image into the
 * site to show it. Allowing the other site in the policy would send it every visitor's address, so
 * that's no owner's switch (default theme A4).
 *
 * @internal
 */
final readonly class ExternalImagesCheck implements ContentCheckInterface
{
    public function __construct(
        private ExternalImages $images,
    ) {
    }

    public function check(Content $item): iterable
    {
        foreach (Markdown::document($item)?->iterator() ?? [] as $node) {
            if (!$node instanceof Image) {
                continue;
            }
            $host = $this->images->blockedHost($node->getUrl());

            if (null !== $host) {
                yield new Finding(\sprintf('The image %s is on another site (%s): visitors see a link to it instead, since loading it would send that site their address. Copy the image into the site to show it.', $node->getUrl(), $host), 'body', Markdown::line($node));
            }
        }
    }
}
