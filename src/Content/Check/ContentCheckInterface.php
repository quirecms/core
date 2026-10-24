<?php

declare(strict_types=1);

namespace Quire\Content\Check;

use Quire\Content\Content;

/**
 * A check of content before it's published, added with {@see \Quire\Extend\ContentChecks}: e.g. that
 * images have a description, or that a product has a price. It's run while editing (by admin
 * interfaces), when an item is published (its errors block that) and by `quire:content:check`.
 *
 *     final readonly class PriceCheck implements ContentCheckInterface
 *     {
 *         public function check(Content $item): iterable
 *         {
 *             if (null === $item->field('price')) {
 *                 yield new Finding('Products need a price before they\'re sold.', 'fields.price');
 *             }
 *         }
 *     }
 *
 * The item may not be saved: it's what would be published, e.g. with the changes being written. Checks
 * should be quick, and must not change anything.
 *
 * @api
 */
interface ContentCheckInterface
{
    /**
     * @return iterable<Finding> Nothing when the item is fine
     */
    public function check(Content $item): iterable;
}
