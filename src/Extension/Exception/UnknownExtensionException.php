<?php

declare(strict_types=1);

namespace Quire\Extension\Exception;

/**
 * @api
 */
final class UnknownExtensionException extends \InvalidArgumentException
{
    /**
     * @param list<string> $installedIds
     */
    public static function forId(string $id, array $installedIds): self
    {
        $message = \sprintf('No extension with ID "%s" is installed.', $id);

        $suggestions = array_values(array_filter(
            $installedIds,
            static fn (string $installed): bool => levenshtein($id, $installed) <= 3 || str_contains($installed, $id),
        ));

        if ([] !== $suggestions) {
            $message .= \sprintf(' Did you mean "%s"?', implode('", "', $suggestions));
        } elseif ([] !== $installedIds) {
            $message .= \sprintf(' Installed: "%s".', implode('", "', $installedIds));
        } else {
            $message .= ' No extensions are installed. Install one with "composer require <package>".';
        }

        return new self($message);
    }
}
