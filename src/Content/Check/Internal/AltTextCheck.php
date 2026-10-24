<?php

declare(strict_types=1);

namespace Quire\Content\Check\Internal;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Finding;
use Quire\Content\Content;

/**
 * Images have a description (alt text), which screen readers read out and search engines use. Purely
 * decorative images can still have none, so this is a warning by default.
 *
 * @internal
 */
final class AltTextCheck implements ContentCheckInterface
{
    public function check(Content $item): iterable
    {
        foreach (Markdown::document($item)?->iterator() ?? [] as $node) {
            if ($node instanceof Image && '' === Markdown::text($node)) {
                $path = parse_url($node->getUrl(), \PHP_URL_PATH);
                $name = \is_string($path) && '' !== $path ? basename($path) : $node->getUrl();

                yield new Finding(\sprintf('The image “%s” has no description. Describe it in the brackets: ![what it shows](%s).', $name, $node->getUrl()), 'body', Markdown::line($node));
            }
        }
    }
}
