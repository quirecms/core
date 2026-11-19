<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Csp\Csp;
use Quire\Csp\Policy;
use Quire\Csp\PolicyKind;

/**
 * Whether a page may show an image from its address: by the same policy the page's header sends
 * (`img-src`, or `default-src` without it), so the renderer and the browser never disagree. An image
 * the policy blocks would be a broken picture; one it allows from another site sends that site every
 * visitor's address. The site's own (relative or same-origin) addresses are always allowed.
 *
 * @internal
 */
final readonly class ExternalImages
{
    public function __construct(
        private Csp $csp,
    ) {
    }

    /** The other site's host when the page's policy blocks the image, or null when it shows */
    public function blockedHost(string $url): ?string
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $policy = $this->csp->policy(PolicyKind::Page);
        $directive = $policy->has('img-src') ? 'img-src' : 'default-src';
        $scheme = strtolower($parts['scheme'] ?? 'https');

        foreach ($policy->sources($directive) as $source) {
            if ('*' === $source || strtolower($source) === $scheme.':' || ('http:' === strtolower($source) && 'https' === $scheme) || Policy::allows($source, $url)) {
                return null;
            }
        }

        return strtolower($parts['host']);
    }
}
