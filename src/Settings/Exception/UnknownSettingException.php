<?php

declare(strict_types=1);

namespace Quire\Settings\Exception;

/**
 * @api
 */
final class UnknownSettingException extends \InvalidArgumentException
{
    /**
     * @param list<string> $knownKeys
     */
    public static function forKey(string $key, array $knownKeys): self
    {
        $message = \sprintf('No setting "%s" is declared. Declare it with Extend\Settings::define() in extend.php.', $key);
        $suggestions = array_values(array_filter($knownKeys, static fn (string $known): bool => levenshtein($key, $known) <= 3));

        if ([] !== $suggestions) {
            $message .= \sprintf(' Did you mean "%s"?', implode('", "', $suggestions));
        }

        return new self($message);
    }
}
