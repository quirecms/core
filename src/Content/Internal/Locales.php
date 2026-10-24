<?php

declare(strict_types=1);

namespace Quire\Content\Internal;

/**
 * Language tags of content items, and the PostgreSQL full-text search configuration for each language.
 *
 * @internal
 */
final class Locales
{
    /** Language, optional script and optional region, e.g. "en", "pt-BR", "zh-Hant-TW", "es-419". */
    private const string PATTERN = '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?$/';

    /** PostgreSQL's built-in configurations, which stem words for these languages. */
    private const array SEARCH_CONFIGURATIONS = [
        'ar' => 'arabic', 'ca' => 'catalan', 'da' => 'danish', 'de' => 'german', 'el' => 'greek', 'en' => 'english',
        'es' => 'spanish', 'et' => 'estonian', 'eu' => 'basque', 'fi' => 'finnish', 'fr' => 'french', 'ga' => 'irish',
        'hi' => 'hindi', 'hu' => 'hungarian', 'hy' => 'armenian', 'id' => 'indonesian', 'it' => 'italian',
        'lt' => 'lithuanian', 'nb' => 'norwegian', 'ne' => 'nepali', 'nl' => 'dutch', 'nn' => 'norwegian',
        'no' => 'norwegian', 'pt' => 'portuguese', 'ro' => 'romanian', 'ru' => 'russian', 'sr' => 'serbian',
        'sv' => 'swedish', 'ta' => 'tamil', 'tr' => 'turkish', 'yi' => 'yiddish',
    ];

    /**
     * Every configuration that content can be indexed with.
     *
     * @return non-empty-list<string>
     */
    public static function searchConfigurations(): array
    {
        return array_values(array_unique([...array_values(self::SEARCH_CONFIGURATIONS), 'simple']));
    }

    public static function isValid(string $locale): bool
    {
        return 1 === preg_match(self::PATTERN, $locale);
    }

    /**
     * The configuration that stems words in the locale's language, or "simple" (no stemming) when
     * PostgreSQL has none for it.
     */
    public static function searchConfiguration(string $locale): string
    {
        return self::SEARCH_CONFIGURATIONS[explode('-', $locale, 2)[0]] ?? 'simple';
    }
}
