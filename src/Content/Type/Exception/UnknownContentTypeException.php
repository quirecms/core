<?php

declare(strict_types=1);

namespace Quire\Content\Type\Exception;

/**
 * @api
 */
final class UnknownContentTypeException extends \InvalidArgumentException
{
    /**
     * @param list<string> $knownIds
     */
    public static function forId(string $id, array $knownIds): self
    {
        $message = \sprintf('No content type "%s" is registered. Register it with Extend\ContentType in extend.php, or enable the extension that provides it.', $id);
        $suggestions = array_values(array_filter($knownIds, static fn (string $known): bool => levenshtein($id, $known) <= 2));

        if ([] !== $suggestions) {
            $message .= \sprintf(' Did you mean "%s"?', implode('", "', $suggestions));
        }

        return new self($message);
    }
}
