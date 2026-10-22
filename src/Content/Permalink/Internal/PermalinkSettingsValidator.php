<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Internal;

use Doctrine\DBAL\Connection;
use Quire\Content\Permalink\PermalinkPattern;
use Quire\Content\Permalink\Permalinks;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\Settings;
use Quire\Settings\SettingValidatorInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Checks URL patterns, listing paths and the homepage when they're saved, so a site can't end up with
 * two types competing for the same URLs or a homepage that points nowhere.
 *
 * @internal
 */
final readonly class PermalinkSettingsValidator implements SettingValidatorInterface
{
    private const string LISTING_PATTERN = '#^(?:/[a-z0-9._~-]+)+$#';

    public function __construct(
        private ContentTypeRegistry $types,
        private Permalinks $permalinks,
        private UrlGeneratorInterface $urls,
        private Connection $connection,
    ) {
    }

    public function validate(string $key, mixed $value, Settings $settings): ?string
    {
        return match (true) {
            str_starts_with($key, PermalinkSettings::PERMALINK_PREFIX) => $this->pattern(substr($key, \strlen(PermalinkSettings::PERMALINK_PREFIX)), $value),
            str_starts_with($key, PermalinkSettings::LISTING_PREFIX) => $this->listing(substr($key, \strlen(PermalinkSettings::LISTING_PREFIX)), $value),
            'site.homepage_listing' === $key => $this->homepageListing($value),
            'site.homepage_page' === $key => $this->homepagePage($value),
            'site.homepage_route' === $key => $this->homepageRoute($value),
            default => null,
        };
    }

    /**
     * Pairs of types whose current patterns compete for the same URLs.
     *
     * @return list<string> One message per pair
     */
    public function collisions(): array
    {
        $messages = [];
        $patterns = $this->permalinks->patterns();
        ksort($patterns);

        foreach ($patterns as $type => $pattern) {
            foreach ($patterns as $other => $otherPattern) {
                if ($type < $other && $pattern->signature() === $otherPattern->signature()) {
                    $messages[] = \sprintf('"%s" (%s) and "%s" (%s) compete for the same URLs.', $pattern->pattern, $type, $otherPattern->pattern, $other);
                }
            }
        }

        return $messages;
    }

    private function pattern(string $type, mixed $value): ?string
    {
        if (!\is_string($value) || !$this->types->has($type)) {
            return null;
        }

        try {
            $pattern = PermalinkPattern::parse($value, $this->types->get($type)->has(Capability::HIERARCHICAL));
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        foreach ($this->permalinks->patterns() as $other => $otherPattern) {
            if ($other !== $type && $pattern->signature() === $otherPattern->signature()) {
                return \sprintf('"%s" competes for the same URLs as the %s ("%s"). Change one of them, e.g. add a static part such as "/%s/{slug}".', $value, strtolower($this->types->get($other)->pluralLabel), $otherPattern->pattern, str_replace('_', '-', $type));
            }
        }

        return null;
    }

    private function listing(string $type, mixed $value): ?string
    {
        if (null === $value || '' === $value || !\is_string($value)) {
            return null;
        }

        if (1 !== preg_match(self::LISTING_PATTERN, $value)) {
            return \sprintf('"%s" must start with "/", contain only lowercase letters, digits, "-", "_", ".", "~" and slashes, and not end with "/". The homepage is set with "site.homepage".', $value);
        }

        foreach (array_keys($this->types->withCapability(Capability::LISTABLE)) as $other) {
            if ($other !== $type && $value === $this->permalinks->configuredListingPath($other)) {
                return \sprintf('"%s" is already the list of %s.', $value, strtolower($this->types->get($other)->pluralLabel));
            }
        }

        return null;
    }

    private function homepageListing(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $listable = array_keys($this->types->withCapability(Capability::LISTABLE));

        return \in_array($value, $listable, true) ? null : \sprintf('"%s" isn\'t a content type with a list. Use one of: %s.', $value, implode(', ', $listable));
    }

    private function homepagePage(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $exists = $this->connection->fetchOne(
            "SELECT 1 FROM quire.content WHERE type = 'page' AND deleted_at IS NULL AND (path = ? OR id::text = ?)",
            [trim($value, '/'), $value],
        );

        return false === $exists ? \sprintf('No page with the ID or path "%s" exists.', $value) : null;
    }

    private function homepageRoute(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        if (\in_array($value, [Permalinks::HOME_ROUTE, Permalinks::CONTENT_ROUTE], true)) {
            return 'Use a route of an extension or of the application, not one of Quire\'s content routes.';
        }

        try {
            $this->urls->generate($value);
        } catch (RoutingException $e) {
            return \sprintf('The route "%s" doesn\'t exist or needs parameters: %s', $value, $e->getMessage());
        }

        return null;
    }
}
