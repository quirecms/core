<?php

declare(strict_types=1);

namespace Quire\Csp;

/**
 * A Content-Security-Policy: its directives and their sources, in the order they were added.
 *
 * Immutable: {@see with()} returns a new policy. Every directive and source is checked, so nothing
 * from configuration or an extension can end a directive early or add one of its own:
 *
 *     $policy->with('img-src', 'https://images.example.com')
 *     // "… img-src 'self' data: https://images.example.com; …"
 *
 * A fetch directive the policy doesn't have yet starts from `default-src`'s sources, as browsers read
 * a missing one: adding a font host keeps `'self'` for fonts.
 *
 * @api
 */
final readonly class Policy implements \Stringable
{
    /** Directives a policy may have: fetch directives, then the document and navigation ones */
    public const array DIRECTIVES = [
        'default-src', 'script-src', 'script-src-elem', 'script-src-attr', 'style-src', 'style-src-elem', 'style-src-attr',
        'img-src', 'font-src', 'connect-src', 'media-src', 'object-src', 'frame-src', 'child-src', 'worker-src', 'manifest-src',
        'base-uri', 'form-action', 'frame-ancestors', 'sandbox', 'upgrade-insecure-requests', 'report-uri', 'report-to',
    ];

    /** Directives that fall back to `default-src` when a policy doesn't have them */
    private const array FETCH = [
        'script-src', 'script-src-elem', 'script-src-attr', 'style-src', 'style-src-elem', 'style-src-attr', 'img-src', 'font-src',
        'connect-src', 'media-src', 'object-src', 'frame-src', 'child-src', 'worker-src', 'manifest-src',
    ];

    /** Keywords a source list may have, quoted as CSP writes them */
    private const array KEYWORDS = [
        "'self'", "'none'", "'unsafe-inline'", "'unsafe-eval'", "'wasm-unsafe-eval'", "'unsafe-hashes'", "'strict-dynamic'", "'report-sample'", "'inline-speculation-rules'",
    ];

    /**
     * A scheme ("https:", "data:"), a host with an optional scheme, port and path ("https://*.example.com",
     * "cdn.example.com:8443/fonts/"), a nonce or a hash. No spaces, ";", ",", quotes or controls.
     */
    private const string SOURCE = '~^(?:[a-z][a-z0-9+.-]*:|(?:[a-z][a-z0-9+.-]*://)?(?:\*|(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*)(?::(?:\d{1,5}|\*))?(?:/[A-Za-z0-9._\~!$&()*+=:@%/-]*)?|\'(?:nonce-[A-Za-z0-9+/_-]+={0,2}|sha(?:256|384|512)-[A-Za-z0-9+/_-]+={0,2})\')$~';

    /**
     * @param array<string, list<string>> $directives Directive => sources (empty for "sandbox" and
     *                                                "upgrade-insecure-requests", which take none)
     */
    public function __construct(private array $directives = [])
    {
        foreach ($directives as $directive => $sources) {
            self::assertDirective($directive);

            foreach ($sources as $source) {
                self::assertSource($directive, $source);
            }
        }
    }

    /**
     * The policy with these sources added to a directive, each once, after its own.
     */
    public function with(string $directive, string ...$sources): self
    {
        self::assertDirective($directive);
        $directives = $this->directives;

        $directives[$directive] ??= \in_array($directive, self::FETCH, true) ? ($directives['default-src'] ?? []) : [];

        foreach ($sources as $source) {
            self::assertSource($directive, $source);

            if ("'none'" !== $source && !\in_array($source, $directives[$directive], true)) {
                // "'none'" with any other source is ignored by browsers: the other sources replace it.
                $directives[$directive] = [...array_values(array_diff($directives[$directive], ["'none'"])), $source];
            }
        }

        return new self($directives);
    }

    public function has(string $directive): bool
    {
        return isset($this->directives[$directive]);
    }

    /**
     * @return list<string>
     */
    public function sources(string $directive): array
    {
        return $this->directives[$directive] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function directives(): array
    {
        return $this->directives;
    }

    /**
     * The header's value, e.g. "default-src 'self'; frame-ancestors 'none'".
     */
    public function header(): string
    {
        $parts = [];

        foreach ($this->directives as $directive => $sources) {
            $parts[] = [] === $sources ? $directive : $directive.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }

    public function __toString(): string
    {
        return $this->header();
    }

    /**
     * Whether a directive is a fetch directive ("img-src", "script-src"…), which falls back to
     * `default-src`.
     */
    public static function isFetchDirective(string $directive): bool
    {
        return 'default-src' === $directive || \in_array($directive, self::FETCH, true);
    }

    /**
     * Whether a source names hosts of one site, e.g. "https://cdn.example.com" or
     * "https://*.google-analytics.com": not a keyword, nonce, hash, bare scheme or "*".
     */
    public static function isHost(string $source): bool
    {
        return 1 === preg_match('~^(?:https?|wss?)://(?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::\d{1,5})?(?:/[A-Za-z0-9._\~!$&()*+=:@%/-]*)?$~', $source);
    }

    /**
     * Whether a host source ({@see isHost()}) allows an absolute URL, as browsers match them: the same
     * scheme (or its secure form: "http:" allows "https:"), the host or, with "*.", any subdomain of it,
     * the port (or the scheme's default), and the path, if the source has one, as a prefix ("/a/") or
     * exactly ("/a.js"). Other sources (keywords, schemes) never match here.
     */
    public static function allows(string $source, string $url): bool
    {
        if (!self::isHost($source)) {
            return false;
        }

        $allowed = parse_url($source);
        $wanted = parse_url($url);

        if (!\is_array($allowed) || !\is_array($wanted) || !isset($allowed['scheme'], $allowed['host'], $wanted['scheme'], $wanted['host'])) {
            return false;
        }

        $scheme = strtolower($wanted['scheme']);
        $schemes = match (strtolower($allowed['scheme'])) {
            'http' => ['http', 'https'],
            'ws' => ['ws', 'wss'],
            default => [$allowed['scheme']],
        };

        if (!\in_array($scheme, $schemes, true)) {
            return false;
        }

        $host = self::asciiHost($wanted['host']);

        if (null === $host) {
            return false;
        }

        $pattern = strtolower($allowed['host']);

        if (str_starts_with($pattern, '*.') ? !str_ends_with($host, substr($pattern, 1)) : $host !== $pattern) {
            return false;
        }

        $defaults = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443];

        if (isset($allowed['port']) && ($wanted['port'] ?? $defaults[$scheme] ?? null) !== $allowed['port']) {
            return false;
        }

        if (!isset($allowed['port']) && isset($wanted['port']) && $wanted['port'] !== ($defaults[$scheme] ?? null)) {
            return false;
        }

        $path = $allowed['path'] ?? '';

        if ('' === $path || '/' === $path) {
            return true;
        }

        $wantedPath = rawurldecode($wanted['path'] ?? '/');

        return str_ends_with($path, '/') ? str_starts_with($wantedPath, rawurldecode($path)) : $wantedPath === rawurldecode($path);
    }

    /**
     * A URL's host as browsers compare it: lower case, and international names in their ASCII form
     * ("bücher.example" is "xn--bcher-kva.example"); null for one that isn't a host.
     */
    public static function asciiHost(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host, '[]')), '.');

        if ('' === $host) {
            return null;
        }

        if (1 === preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = \function_exists('idn_to_ascii') ? idn_to_ascii($host, \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46) : false;

            return \is_string($ascii) && '' !== $ascii ? strtolower($ascii) : null;
        }

        return 1 === preg_match('/^[a-z0-9.:_-]+$/', $host) ? $host : null;
    }

    /**
     * @throws \InvalidArgumentException for a directive this class doesn't know
     */
    public static function assertDirective(string $directive): void
    {
        if (!\in_array($directive, self::DIRECTIVES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a Content-Security-Policy directive Quire sends. Directives: %s.', $directive, implode(', ', self::DIRECTIVES)));
        }
    }

    /**
     * @throws \InvalidArgumentException for a source that isn't one, e.g. with a space or ";"
     */
    public static function assertSource(string $directive, string $source): void
    {
        if ('sandbox' === $directive) {
            if (1 !== preg_match('/^allow-[a-z-]+$/', $source)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a sandbox flag, such as "allow-scripts".', $source));
            }

            return;
        }

        if ('report-uri' === $directive || 'report-to' === $directive) {
            if (1 !== preg_match('~^[A-Za-z0-9._\~:/?#\[\]@!$&()*+=%-]+$~', $source) || str_contains($source, ',')) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a reporting address or endpoint name.', $source));
            }

            return;
        }

        if ('upgrade-insecure-requests' === $directive) {
            throw new \InvalidArgumentException('"upgrade-insecure-requests" takes no sources.');
        }

        // Unquoted, a keyword would be a host of that name: never what was meant.
        if (\in_array("'".$source."'", self::KEYWORDS, true)) {
            throw new \InvalidArgumentException(\sprintf('"%1$s" needs its quotes: "\'%1$s\'".', $source));
        }

        if (!\in_array($source, self::KEYWORDS, true) && 1 !== preg_match(self::SOURCE, $source)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a Content-Security-Policy source: a keyword such as "\'self\'", a scheme such as "https:", or a host such as "https://cdn.example.com".', $source));
        }
    }
}
