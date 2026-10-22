<?php

declare(strict_types=1);

namespace Quire\User\Console;

use Quire\User\UserWriter;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gets a new password on the command line: typed twice without being shown, or, without a terminal,
 * generated. Passwords are never options, so they don't end up in the shell history or the process list.
 *
 * @internal
 */
final class Passwords
{
    private const int ATTEMPTS = 3;

    private const string ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return array{string, bool} The password, and whether it was generated (so it has to be shown once)
     */
    public static function ask(SymfonyStyle $io, bool $interactive): array
    {
        if (!$interactive) {
            return [self::generate(), true];
        }

        // A few attempts, not endless ones: when input is piped, a bad password must fail, not loop.
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            $question = new Question(\sprintf('Password (at least %d characters)', UserWriter::PASSWORD_MIN_LENGTH))
                ->setHidden(true)
                ->setMaxAttempts(self::ATTEMPTS)
                ->setValidator(static function (mixed $value): string {
                    if (!\is_string($value) || mb_strlen($value) < UserWriter::PASSWORD_MIN_LENGTH) {
                        throw new \RuntimeException(\sprintf('Use at least %d characters.', UserWriter::PASSWORD_MIN_LENGTH));
                    }

                    return $value;
                });
            $password = $io->askQuestion($question);
            \assert(\is_string($password));

            if ($password === $io->askQuestion(new Question('Repeat the password')->setHidden(true))) {
                return [$password, false];
            }

            $io->error('The passwords don\'t match.');
        }

        throw new \RuntimeException('The passwords didn\'t match. Nothing was changed.');
    }

    /**
     * Like {@see ask()}, but reports a failure (e.g. no valid password was given) and returns null.
     *
     * @return array{string, bool}|null
     */
    public static function askOrReport(SymfonyStyle $io, bool $interactive): ?array
    {
        try {
            return self::ask($io, $interactive);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return null;
        }
    }

    /**
     * 20 characters without ones that are easy to mix up, e.g. "l" and "1": about 117 bits.
     */
    public static function generate(): string
    {
        $password = '';

        for ($i = 0; $i < 20; ++$i) {
            $password .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $password;
    }
}
