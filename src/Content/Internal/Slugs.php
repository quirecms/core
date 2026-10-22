<?php

declare(strict_types=1);

namespace Quire\Content\Internal;

use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Generates slugs and keeps them unique among siblings (same type, parent and language, not in the trash).
 *
 * @internal
 */
final readonly class Slugs
{
    public const int MAX_LENGTH = 200;

    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public static function isValid(string $slug): bool
    {
        return \strlen($slug) <= self::MAX_LENGTH && 1 === preg_match(self::PATTERN, $slug);
    }

    /**
     * Turns a title into a slug, transliterating it for the language: "Über uns" becomes "ueber-uns" in German.
     */
    public static function fromTitle(string $title, string $locale): string
    {
        $slug = new AsciiSlugger()->slug($title, '-', str_replace('-', '_', $locale))->lower()->toString();
        $slug = self::truncate($slug, self::MAX_LENGTH);

        return '' === $slug ? 'untitled' : $slug;
    }

    /**
     * Serialises slug changes among the same siblings until the transaction ends, so two writers can't
     * both claim the same free slug.
     */
    public function lockSiblings(string $type, ?string $parentId, string $locale): void
    {
        $this->connection->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            [\sprintf('quire.content.slug|%s|%s|%s', $type, $parentId ?? '', $locale)],
        );
    }

    public function isTaken(string $slug, string $type, ?string $parentId, string $locale, ?string $exceptId = null): bool
    {
        return \in_array($slug, $this->siblingSlugs($slug, $type, $parentId, $locale, $exceptId), true);
    }

    /**
     * $slug if it's free, otherwise $slug with the lowest free number appended: "hello-2", "hello-3", ….
     */
    public function unique(string $slug, string $type, ?string $parentId, string $locale, ?string $exceptId = null): string
    {
        $taken = $this->siblingSlugs($slug, $type, $parentId, $locale, $exceptId);

        if (!\in_array($slug, $taken, true)) {
            return $slug;
        }

        for ($number = 2;; ++$number) {
            $suffix = '-'.$number;
            $candidate = self::truncate($slug, self::MAX_LENGTH - \strlen($suffix)).$suffix;

            if (!\in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * @return list<string> Sibling slugs equal to $slug or starting with "$slug-"
     */
    private function siblingSlugs(string $slug, string $type, ?string $parentId, string $locale, ?string $exceptId): array
    {
        $base = self::truncate($slug, self::MAX_LENGTH - 4);

        /** @var list<string> $slugs */
        $slugs = $this->connection->fetchFirstColumn(
            'SELECT slug FROM quire.content
             WHERE type = :type AND parent_id IS NOT DISTINCT FROM CAST(:parent AS uuid) AND locale = :locale
               AND deleted_at IS NULL AND (slug = :slug OR slug LIKE :prefix)
               AND id IS DISTINCT FROM CAST(:except AS uuid)',
            // Valid slugs contain neither "%" nor "_", so they need no escaping in LIKE.
            ['type' => $type, 'parent' => $parentId, 'locale' => $locale, 'slug' => $slug, 'prefix' => $base.'%', 'except' => $exceptId],
        );

        return $slugs;
    }

    private static function truncate(string $slug, int $length): string
    {
        return \strlen($slug) <= $length ? $slug : rtrim(substr($slug, 0, $length), '-');
    }
}
