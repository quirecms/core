<?php

declare(strict_types=1);

namespace Quire\Translation;

/**
 * What a package says about its texts ("extra.quire.translations"), a format the translation hub reads too:
 *
 *     {"version": 1, "source": "en", "delivery": "hub", "domains": ["acme-shop"]}
 *
 * Every key but "version" may be left out: the texts are in English, bundled, in the domain of the
 * package's ID. A package whose texts come from the hub names its domains.
 *
 * @api
 */
final readonly class TranslationsDeclaration
{
    /** The version of this format Quire reads */
    public const int VERSION = 1;

    /** Symfony's own domains: no package declares them, and no language artifact reaches them */
    public const array SYMFONY_DOMAINS = ['messages', 'security', 'validators'];

    /** A domain, e.g. "acme-shop" */
    public const string DOMAIN_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Only what a package may declare can be made: so code trusting a declaration needn't check it again.
     *
     * @param string       $packageName The package it's of, e.g. "acme/shop"
     * @param list<string> $domains     The package's translation domains
     * @param string       $source      The language its texts are written in
     *
     * @throws \InvalidArgumentException What it may not declare, worded for the package's author
     */
    public function __construct(
        public string $packageName,
        public array $domains,
        public TranslationDelivery $delivery = TranslationDelivery::Bundled,
        public string $source = 'en',
        public int $version = self::VERSION,
    ) {
        $example = '{"version": 1, "delivery": "hub", "domains": ["acme-shop"]}';

        if (self::VERSION !== $version) {
            throw new \InvalidArgumentException(\sprintf('"extra.quire.translations.version" must be %d, the version of the format this Quire reads; got %d. A newer format needs a newer Quire.', self::VERSION, $version));
        }

        if (!Locales::isCanonical($source)) {
            throw new \InvalidArgumentException(\sprintf('"extra.quire.translations.source" must be the language the texts are written in, as a code such as "en" or "pt_BR"; got %s.', json_encode($source)));
        }

        if ([] === $domains) {
            throw new \InvalidArgumentException(TranslationDelivery::Hub === $delivery ? \sprintf('"extra.quire.translations.domains" must list the domains of a package whose texts come from the hub, e.g. %s.', $example) : '"extra.quire.translations.domains" must be a list of translation domains, e.g. ["acme-shop"].');
        }

        foreach ($domains as $domain) {
            if (1 !== preg_match(self::DOMAIN_PATTERN, $domain)) {
                throw new \InvalidArgumentException(\sprintf('"extra.quire.translations.domains" lists %s, which is not a domain: lowercase letters, digits and single hyphens, e.g. "acme-shop".', json_encode($domain)));
            }

            if (!self::mayHave($packageName, $domain)) {
                throw new \InvalidArgumentException(\sprintf('"extra.quire.translations.domains" lists "%s", which is %s.', $domain, match (true) {
                    \in_array($domain, self::SYMFONY_DOMAINS, true) => 'one of Symfony\'s own domains', self::SITE_DOMAIN === $domain => 'the site\'s own', default => 'reserved for Quire\'s own packages',
                }));
            }
        }
    }

    /** The site's own texts, in its translations/ directory: the names of its own types, for one */
    public const string SITE_DOMAIN = 'site';

    /**
     * Whether a package may have the domain: "quire" and "quire-…" are Quire's own packages', "site" the
     * site's, and Symfony's domains no package's.
     */
    public static function mayHave(string $packageName, string $domain): bool
    {
        $quires = 'quire' === $domain || str_starts_with($domain, 'quire-');

        return !\in_array($domain, self::SYMFONY_DOMAINS, true) && self::SITE_DOMAIN !== $domain && (!$quires || str_starts_with($packageName, 'quire/'));
    }
}
