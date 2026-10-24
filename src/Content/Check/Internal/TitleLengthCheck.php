<?php

declare(strict_types=1);

namespace Quire\Content\Check\Internal;

use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Finding;
use Quire\Content\Content;

/**
 * Titles fit in search results, which show about 60 characters of them.
 *
 * @internal
 */
final class TitleLengthCheck implements ContentCheckInterface
{
    public const int LENGTH = 60;

    public function check(Content $item): iterable
    {
        $length = mb_strlen($item->title);

        if ($length > self::LENGTH) {
            yield new Finding(\sprintf('The title has %d characters; search results show about %d. Put what matters first.', $length, self::LENGTH), 'title');
        }
    }
}
