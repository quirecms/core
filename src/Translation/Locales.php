<?php

declare(strict_types=1);

namespace Quire\Translation;

/**
 * The form of a language code Quire's translations use: canonical, as ICU and Symfony write them.
 *
 * @api
 */
final class Locales
{
    /** "mk", "pt_BR", "sr_Latn", "zh_Hant_TW", "es_419"; never "pt-BR", "MK" or "pt_br" */
    public const string CANONICAL_PATTERN = '/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|\d{3}))?$/';

    private function __construct()
    {
    }

    public static function isCanonical(string $locale): bool
    {
        return 1 === preg_match(self::CANONICAL_PATTERN, $locale);
    }
}
