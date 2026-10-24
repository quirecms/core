<?php

declare(strict_types=1);

namespace Quire\Content\Query\Internal;

use Quire\Content\Internal\Locales;

/**
 * The SQL of full-text search on the content table's "search" column.
 *
 * Each item's search vector is built with its own language's configuration (see {@see Locales}), so terms
 * must be parsed with the same one to match its stems. A query parsed per row can't use the GIN index,
 * though. So items are found through one index scan for the terms as every configuration parses them,
 * then each one is checked against the terms as its own configuration parses them.
 *
 * Kept apart from {@see \Quire\Content\Query\ContentQuery}, so that other ways to search (e.g. with
 * embeddings, merged by reciprocal rank fusion) can build on the same pieces.
 *
 * @internal
 *
 * @phpstan-import-type Condition from \Quire\Content\Query\ContentQuery
 */
final class SearchSql
{
    /** Marks the terms in snippets until they're escaped; never in text */
    public const string START = "\u{2}";

    public const string STOP = "\u{3}";

    /**
     * @return array{query: string, terms: string}|null The text search query, as SQL with the
     *                                                  placeholder "%s" for the configuration, and the terms
     *                                                  to bind; null when the terms have no words
     */
    public static function parse(string $terms, bool $prefix): ?array
    {
        if (!$prefix) {
            return '' === trim($terms) ? null : ['query' => 'websearch_to_tsquery(%s, ?)', 'terms' => $terms];
        }

        // Words only, each quoted, so nothing in them is read as an operator; the last one matches as a
        // prefix, for search as you type.
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($terms), flags: \PREG_SPLIT_NO_EMPTY);

        if (false === $words || [] === $words) {
            return null;
        }

        $query = implode(' & ', array_map(static fn (string $word): string => "'".$word."'", $words)).':*';

        return ['query' => 'to_tsquery(%s, ?)', 'terms' => $query];
    }

    /**
     * Items whose search vector matches the terms.
     *
     * @param array{query: string, terms: string} $parsed
     *
     * @return Condition
     */
    public static function condition(array $parsed): array
    {
        $configurations = Locales::searchConfigurations();
        $any = implode(' || ', array_map(static fn (string $configuration): string => \sprintf($parsed['query'], "'".$configuration."'::regconfig"), $configurations));

        return [
            'sql' => \sprintf('(c.search @@ (%s) AND c.search @@ %s)', $any, self::ownQuery($parsed)),
            'params' => [...array_fill(0, \count($configurations), $parsed['terms']), $parsed['terms']],
        ];
    }

    /**
     * How well each item matches, from 0 to 1: terms in the title count most, then the excerpt, then the
     * body, and long texts don't win by length alone.
     *
     * @param array{query: string, terms: string} $parsed
     *
     * @return Condition
     */
    public static function rank(array $parsed): array
    {
        return ['sql' => \sprintf('ts_rank(c.search, %s, 1 | 32)', self::ownQuery($parsed)), 'params' => [$parsed['terms']]];
    }

    /**
     * Passages of the excerpt and body around the terms, without Markdown's markup, with the terms between
     * {@see START} and {@see STOP}.
     *
     * @param array{query: string, terms: string} $parsed
     *
     * @return array{0: string, 1: list<mixed>} SQL selecting "id" and "snippet" of the items with the IDs
     *                                          bound as a uuid[] literal
     */
    public static function snippets(array $parsed): array
    {
        // Links and images keep their text; markers of emphasis and code go, and of headings and quotes at
        // the start of a line.
        $text = "regexp_replace(regexp_replace(regexp_replace(concat_ws(E'\\n\\n', c.excerpt, c.body), '!?\\[([^\\]]*)\\]\\([^)]*\\)', '\\1', 'g'), '[*`~]+', '', 'g'), '^[ \\t]*[#>]+[ \\t]*', '', 'gn')";

        return [
            \sprintf("SELECT c.id, ts_headline(c.search_config, %s, %s, 'StartSel=%s, StopSel=%s, MaxWords=30, MinWords=12, MaxFragments=2, FragmentDelimiter=\" … \"') AS snippet FROM quire.content c WHERE c.id = ANY(CAST(? AS uuid[]))", $text, self::ownQuery($parsed), self::START, self::STOP),
            [$parsed['terms']],
        ];
    }

    /**
     * Turns a snippet into HTML: escaped, with the terms in <mark>.
     */
    public static function html(string $snippet): string
    {
        return str_replace([self::START, self::STOP], ['<mark>', '</mark>'], htmlspecialchars(trim($snippet), \ENT_QUOTES | \ENT_SUBSTITUTE));
    }

    /**
     * @param array{query: string, terms: string} $parsed
     */
    private static function ownQuery(array $parsed): string
    {
        return \sprintf($parsed['query'], 'c.search_config');
    }
}
