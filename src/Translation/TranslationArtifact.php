<?php

declare(strict_types=1);

namespace Quire\Translation;

/**
 * A language artifact: a Composer package of type "quire-translation" with one package's texts in one
 * language, for one version line of it, built by the translation hub. Its manifest, "extra.quire.translation":
 *
 *     {"version": 1, "package": "acme/shop", "locale": "mk", "line": "0.5", "domains": ["acme-shop"],
 *      "provenance": {"source": "https://…", "revision": "…", "built_at": "2026-11-22T18:00:00Z"}, "generated": true}
 *
 * Only what may be loaded can be made. (Provenance is recorded, never fetched.)
 *
 * @api
 */
final readonly class TranslationArtifact
{
    public const string TYPE = 'quire-translation';

    public const int VERSION = 1;

    private const array KEYS = ['version', 'package', 'locale', 'line', 'domains', 'provenance', 'generated'];

    private const array PROVENANCE_KEYS = ['source', 'revision', 'built_at'];

    /**
     * @param string                $name       The artifact's own package, e.g. "acme/shop-mk"
     * @param string                $package    The package it translates, e.g. "acme/shop"
     * @param list<string>          $domains    Its domains, each one of that package's
     * @param array<string, string> $provenance Where the hub built it from: source, revision, built_at
     */
    private function __construct(
        public string $name,
        public string $package,
        public string $locale,
        public string $line,
        public array $domains,
        public array $provenance,
    ) {
    }

    /**
     * @throws \InvalidArgumentException What's wrong with the manifest
     */
    public static function fromManifest(string $name, mixed $manifest): self
    {
        if (!\is_array($manifest) || array_is_list($manifest)) {
            throw new \InvalidArgumentException('"extra.quire.translation" isn\'t a JSON object.');
        }

        $unknown = array_diff(array_keys($manifest), self::KEYS);

        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('"extra.quire.translation.%s" is not a known key.', reset($unknown)));
        }

        if (self::VERSION !== ($manifest['version'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('its manifest is of version %s; this Quire reads version %d.', json_encode($manifest['version'] ?? null), self::VERSION));
        }

        $package = $manifest['package'] ?? null;
        $locale = $manifest['locale'] ?? null;
        $line = $manifest['line'] ?? null;
        $domains = $manifest['domains'] ?? null;
        $provenance = $manifest['provenance'] ?? null;

        if (!\is_string($package) || 1 !== preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $package)) {
            throw new \InvalidArgumentException('"package" isn\'t a Composer package name.');
        }

        if (!\is_string($locale) || !Locales::isCanonical($locale)) {
            throw new \InvalidArgumentException(\sprintf('"locale" is %s, not a canonical language code such as "mk" or "pt_BR".', json_encode($locale)));
        }

        if (!\is_string($line) || 1 !== preg_match('/^(?:0\.\d+|[1-9]\d*)$/', $line)) {
            throw new \InvalidArgumentException(\sprintf('"line" is %s, not a version line such as "0.5" or "2".', json_encode($line)));
        }

        if (!\is_array($domains) || !array_is_list($domains) || [] === $domains || array_filter($domains, static fn (mixed $domain): bool => \is_string($domain) && 1 === preg_match(TranslationsDeclaration::DOMAIN_PATTERN, $domain)) !== $domains) {
            throw new \InvalidArgumentException('"domains" isn\'t a list of translation domains.');
        }

        if (!\is_array($provenance) || array_keys($provenance) !== array_intersect(array_keys($provenance), self::PROVENANCE_KEYS) || \count($provenance) !== \count(self::PROVENANCE_KEYS) || array_filter($provenance, \is_string(...)) !== $provenance) {
            throw new \InvalidArgumentException('"provenance" must have source, revision and built_at, as strings.');
        }

        if (true !== ($manifest['generated'] ?? null)) {
            throw new \InvalidArgumentException('it isn\'t marked "generated": artifacts are built by the hub, never written by hand.');
        }

        $domains = array_values(array_unique(array_filter($domains, \is_string(...))));

        return new self($name, $package, $locale, $line, $domains, ['source' => $provenance['source'], 'revision' => $provenance['revision'], 'built_at' => $provenance['built_at']]);
    }
}
