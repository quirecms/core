<?php

declare(strict_types=1);

namespace Quire\Csp;

use Quire\Csp\Internal\FirstPartySourcesInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

/**
 * The site's Content-Security-Policy. Quire is its only owner (doc 20, D-49 and condition C-A): every
 * response of Quire, its extensions and the site gets one policy, from here, and nothing else sets the
 * header. Browsers apply every policy a response has, so a second one would quietly block what this
 * one allows.
 *
 * What a page may load:
 * - from the site itself: scripts, styles, images, fonts, and requests (`'self'`); images also as
 *   `data:` URLs. No inline scripts or style attributes: a theme puts them in its files;
 * - from the site's public disks that are elsewhere (S3, a CDN): images and media;
 * - from other sites, only what `quire/privacy` declares for its services ({@see ThirdPartySourcesInterface});
 * - what the site's configuration adds ("quire.csp.sources").
 *
 * The policy is the same for every visitor, so pages can be cached. An inline script that is the same
 * for everyone (the importmap) is allowed by its hash ({@see allowScript()}). A response with inline
 * code only its viewer sees, e.g. the "View as" bar, asks for a {@see nonce()}: it's then never cached.
 *
 * @api
 */
final readonly class Csp
{
    /** Route default (and request attribute) naming the response's {@see PolicyKind}, e.g. "private" */
    public const string ATTRIBUTE = '_quire_csp';

    /** @internal Request attribute holding the response's nonce, once asked for */
    public const string NONCE_ATTRIBUTE = '_quire_csp_nonce';

    /** @internal Request attribute holding the hashes of the response's inline scripts */
    public const string HASHES_ATTRIBUTE = '_quire_csp_hashes';

    /** @internal */
    public const string REPORT_ONLY_PARAMETER = 'quire.csp.report_only';

    /** @internal */
    public const string REPORT_URI_PARAMETER = 'quire.csp.report_uri';

    /** @internal */
    public const string SOURCES_PARAMETER = 'quire.csp.sources';

    /**
     * Uploaded files. Caddy serves local public disks' files itself, with the same header
     * (frankenphp/Caddyfile; a test keeps the two equal).
     */
    public const string STORED_FILE = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; media-src 'self'; sandbox";

    /**
     * @param iterable<FirstPartySourcesInterface> $firstParty
     * @param array<string, list<string>>          $siteSources
     */
    public function __construct(
        private ThirdPartySourcesInterface $thirdParty,
        #[AutowireIterator(FirstPartySourcesInterface::TAG)]
        private iterable $firstParty = [],
        #[Autowire(param: self::REPORT_ONLY_PARAMETER)]
        private bool $reportOnly = false,
        #[Autowire(param: self::REPORT_URI_PARAMETER)]
        private ?string $reportUri = null,
        #[Autowire(param: self::SOURCES_PARAMETER)]
        private array $siteSources = [],
    ) {
    }

    /**
     * The policy of one kind of response, without a nonce.
     */
    public function policy(PolicyKind $kind): Policy
    {
        return match ($kind) {
            PolicyKind::Page => $this->page(private: false),
            PolicyKind::Private => $this->page(private: true),
            PolicyKind::StoredFile => new Policy([
                'default-src' => ["'none'"],
                'img-src' => ["'self'", 'data:'],
                'style-src' => ["'unsafe-inline'"],
                'media-src' => ["'self'"],
                'sandbox' => [],
            ]),
            PolicyKind::Admin => new Policy([
                'base-uri' => ["'self'"],
                'object-src' => ["'none'"],
                'frame-ancestors' => ["'self'"],
            ]),
        };
    }

    /**
     * Whether the site's pages get their policy as "Content-Security-Policy-Report-Only": the browser
     * reports what it would block, and blocks nothing ("quire.csp.mode: report-only"), to try a policy
     * out on a live site.
     */
    public function reportOnly(): bool
    {
        return $this->reportOnly;
    }

    /**
     * Where browsers report what the policy blocked ("quire.csp.report_uri"), or null.
     */
    public function reportUri(): ?string
    {
        return null === $this->reportUri || '' === $this->reportUri ? null : $this->reportUri;
    }

    /**
     * What the site's pages may load besides their own, and why: Quire's own parts ("quire", e.g. a
     * storage disk on another host), quire/privacy's services ("privacy"), and the site's
     * configuration ("site", "quire.csp.sources"), which loads for every visitor without asking. The
     * same list the policy is built from, so what an admin shows is what browsers get.
     *
     * @internal For the admin; made @api once a third party needs it
     *
     * @param bool $private The policy of pages with forms for passwords or accounts: no other sites'
     *                      services, and never in a frame
     *
     * @return list<array{directive: string, source: string, origin: 'quire'|'privacy'|'site'}>
     */
    public function sources(bool $private = false): array
    {
        return array_map(static fn (array $added): array => ['directive' => $added['directive'], 'source' => $added['source'], 'origin' => $added['origin']], $this->added($private));
    }

    /**
     * A nonce for inline `<script>` or `<style>` elements of this response, e.g.
     * `<style nonce="…">`: the same for the whole response. The response is then never cached, since a
     * nonce must not be seen twice.
     */
    public function nonce(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);

        if (!\is_string($nonce)) {
            $nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    /**
     * Allows one inline `<script>` of this response by its content's hash, e.g. the importmap that
     * Symfony's AssetMapper prints: the same for every visitor, so the page stays cacheable. Pass the
     * exact text between `<script…>` and `</script>`.
     */
    public function allowScript(Request $request, string $content): void
    {
        $hashes = $request->attributes->get(self::HASHES_ATTRIBUTE);
        $hashes = \is_array($hashes) ? array_filter($hashes, \is_string(...)) : [];
        $hashes[] = \sprintf("'sha256-%s'", base64_encode(hash('sha256', $content, true)));
        $request->attributes->set(self::HASHES_ATTRIBUTE, array_values(array_unique($hashes)));
    }

    /**
     * @param bool $private A page with a form for passwords or accounts: never in a frame, and nothing
     *                      from other sites' services, whatever the visitor consented to, since a
     *                      script there could read what's typed
     */
    private function page(bool $private): Policy
    {
        $policy = new Policy([
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'"],
            'img-src' => ["'self'", 'data:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => [$private ? "'none'" : "'self'"],
        ]);

        foreach ($this->added($private) as $added) {
            $policy = $policy->with($added['directive'], $added['source']);
        }

        if (null !== $this->reportUri && '' !== $this->reportUri) {
            $policy = $policy->with('report-uri', $this->reportUri);
        }

        return $policy;
    }

    /**
     * What's added to a page's policy, with where it comes from, in order: core's parts, quire/privacy's
     * services (not on private pages), and the site's configuration (which may also change the
     * document rules, e.g. let a partner's site show its pages in a frame; but a private page is never
     * in a frame). Core's parts and quire/privacy may add only other sites' hosts, in fetch directives:
     * never a keyword ("'unsafe-inline'"), a nonce or a bare scheme ("https:", which allows every site).
     *
     * @return list<array{directive: string, source: string, origin: 'quire'|'privacy'|'site'}>
     */
    private function added(bool $private): array
    {
        $groups = [];

        foreach ($this->firstParty as $provider) {
            $groups[] = ['quire', $provider->sources()];
        }

        if (!$private) {
            $groups[] = ['privacy', $this->thirdParty->sources()];
        }

        $groups[] = ['site', $private ? array_diff_key($this->siteSources, ['frame-ancestors' => true]) : $this->siteSources];
        $added = [];

        foreach ($groups as [$origin, $sources]) {
            foreach ($sources as $directive => $list) {
                if ('site' !== $origin) {
                    if (!Policy::isFetchDirective($directive)) {
                        throw new \InvalidArgumentException(\sprintf('Only fetch directives such as "script-src" or "img-src" can get another site\'s hosts, not "%s".', $directive));
                    }

                    foreach ($list as $source) {
                        if (!Policy::isHost($source)) {
                            throw new \InvalidArgumentException(\sprintf('"%s" for "%s" isn\'t a host such as "https://cdn.example.com": only the site\'s configuration can add keywords or schemes.', $source, $directive));
                        }
                    }
                }

                foreach ($list as $source) {
                    $added[] = ['directive' => $directive, 'source' => $source, 'origin' => $origin];
                }
            }
        }

        return $added;
    }
}
