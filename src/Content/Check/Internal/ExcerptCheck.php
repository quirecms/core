<?php

declare(strict_types=1);

namespace Quire\Content\Check\Internal;

use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Finding;
use Quire\Content\Content;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeRegistry;

/**
 * Items of types with excerpts have one, which lists, feeds and search engines show.
 *
 * @internal
 */
final readonly class ExcerptCheck implements ContentCheckInterface
{
    public function __construct(
        private ContentTypeRegistry $types,
    ) {
    }

    public function check(Content $item): iterable
    {
        if ($this->types->has($item->type) && $this->types->get($item->type)->has(Capability::EXCERPT) && '' === trim($item->excerpt ?? '')) {
            yield new Finding('There\'s no excerpt: lists and search engines then show the start of the text, which may not summarise it.', 'excerpt');
        }
    }
}
