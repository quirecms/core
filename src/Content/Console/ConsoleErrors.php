<?php

declare(strict_types=1);

namespace Quire\Content\Console;

use Quire\Content\Exception\ContentConflictException;
use Quire\Content\Exception\ContentNotFoundException;
use Quire\Content\Exception\ContentOperationCancelledException;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Exception\TransitionNotAllowedException;
use Quire\Formatter\Exception\UnknownFormatException;
use Quire\User\UserRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs a content command's work and reports the errors users can fix.
 *
 * @internal
 */
final class ConsoleErrors
{
    /**
     * @param \Closure(): int $work
     */
    public static function handle(SymfonyStyle $io, \Closure $work): int
    {
        try {
            return $work();
        } catch (InvalidContentException $e) {
            $io->error(array_map(static fn (string $property, string $message): string => \sprintf('%s: %s', $property, $message), array_keys($e->violations), $e->violations));
        } catch (ContentNotFoundException|ContentConflictException|TransitionNotAllowedException|ContentOperationCancelledException|UnknownFormatException|\JsonException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());
        }

        return Command::FAILURE;
    }

    /**
     * The body from --body, or from --body-file ("-" reads standard input).
     */
    public static function body(?string $body, ?string $file): ?string
    {
        if (null !== $body && null !== $file) {
            throw new \InvalidArgumentException('Pass either --body or --body-file, not both.');
        }

        if (null === $file) {
            return $body;
        }

        $contents = @file_get_contents('-' === $file ? 'php://stdin' : $file);

        return false === $contents ? throw new \InvalidArgumentException(\sprintf('Can\'t read "%s".', $file)) : $contents;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fields(string $json): array
    {
        $fields = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($fields) || ([] !== $fields && array_is_list($fields))) {
            throw new \InvalidArgumentException('--fields takes a JSON object, e.g. \'{"rating": 5}\'.');
        }

        /** @var array<string, mixed> $object */
        $object = $fields;

        return $object;
    }

    /**
     * The ID of the user given by their ID, email or username, like the user commands take them.
     */
    public static function userId(UserRepository $users, string $reference, string $option): string
    {
        return $users->findByReference($reference)->id ?? throw new \InvalidArgumentException(\sprintf('%s: no user is "%s".', $option, $reference));
    }

    public static function date(string $value, string $option): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(\sprintf('%s takes a date, e.g. "2026-12-24 18:00" or "+2 days".', $option));
        }
    }
}
