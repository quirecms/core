<?php

declare(strict_types=1);

namespace Quire\Content\Permalink;

use Doctrine\DBAL\Connection;
use Quire\Content\Content;
use Quire\Content\Internal\Row;

/**
 * Redirects added by hand, e.g. to keep the URLs of a previous site working.
 *
 * Items whose URL changes need none: Quire remembers their earlier URLs and redirects them by itself.
 * A redirect only applies when no content item answers its URL, so it can't hide live content.
 *
 * @api
 */
final readonly class Redirects
{
    public const array STATUSES = [301, 302, 307, 308];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Turns a path or URL into the form redirects are stored and looked up in: the path only, with a
     * leading slash, no trailing slash, decoded and in lowercase. "https://old.example/Blog/Hello/?p=1"
     * becomes "/blog/hello".
     */
    public static function normalize(string $pathOrUrl): string
    {
        $path = parse_url($pathOrUrl, \PHP_URL_PATH);

        return '/'.trim(mb_strtolower(rawurldecode(\is_string($path) ? $path : '')), '/');
    }

    /**
     * Adds a redirect, or replaces the one with the same source.
     *
     * @param Content|string $target A content item (the redirect follows it when its URL changes), an absolute URL, or a path
     *
     * @throws \InvalidArgumentException when the source, target or status isn't valid
     */
    public function add(string $source, Content|string $target, int $status = 301): Redirect
    {
        $source = self::normalize($source);

        if ('/' === $source) {
            throw new \InvalidArgumentException('The homepage can\'t be redirected.');
        }

        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(\sprintf('The status must be one of %s.', implode(', ', self::STATUSES)));
        }

        if (\is_string($target)) {
            $isUrl = 1 === preg_match('#^https?://[^/\s]+#i', $target);

            if (!$isUrl && !str_starts_with($target, '/')) {
                throw new \InvalidArgumentException(\sprintf('The target "%s" must be a path starting with "/" or a URL starting with "http://" or "https://".', $target));
            }

            if (!$isUrl && self::normalize($target) === $source) {
                throw new \InvalidArgumentException('A redirect can\'t point to its own source.');
            }
        }

        $this->connection->executeStatement(
            'INSERT INTO quire.redirect (source, target_content_id, target_url, status) VALUES (?, ?, ?, ?)
             ON CONFLICT (source) DO UPDATE SET target_content_id = EXCLUDED.target_content_id, target_url = EXCLUDED.target_url, status = EXCLUDED.status, created_at = now()',
            [$source, $target instanceof Content ? $target->id : null, \is_string($target) ? $target : null, $status],
        );

        return $this->find($source) ?? throw new \LogicException('The redirect was just saved.');
    }

    /**
     * @return bool Whether there was a redirect to remove
     */
    public function remove(string $source): bool
    {
        return $this->connection->executeStatement('DELETE FROM quire.redirect WHERE source = ?', [self::normalize($source)]) > 0;
    }

    public function find(string $source): ?Redirect
    {
        $row = $this->connection->fetchAssociative('SELECT source, target_content_id, target_url, status, created_at FROM quire.redirect WHERE source = ?', [self::normalize($source)]);

        return false === $row ? null : $this->hydrate($row);
    }

    /**
     * @return list<Redirect> Sorted by source
     */
    public function all(): array
    {
        return array_map($this->hydrate(...), $this->connection->fetchAllAssociative('SELECT source, target_content_id, target_url, status, created_at FROM quire.redirect ORDER BY source'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Redirect
    {
        $row = new Row($row);

        return new Redirect($row->string('source'), $row->nullableString('target_content_id'), $row->nullableString('target_url'), $row->int('status'), $row->date('created_at'));
    }
}
