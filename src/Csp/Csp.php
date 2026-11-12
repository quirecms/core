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

        foreach ($this->firstParty as $provider) {
            $policy = $this->add($policy, $provider->sources(), hostsOnly: true);
        }

        if (!$private) {
            $policy = $this->add($policy, $this->thirdParty->sources(), hostsOnly: true);
        }

        // The site's own configuration may also change the document rules, e.g. let a partner's site
        // show its pages in a frame; but a private page is never in a frame.
        $policy = $this->add($policy, $private ? array_diff_key($this->siteSources, ['frame-ancestors' => true]) : $this->siteSources, hostsOnly: false);

        if (null !== $this->reportUri && '' !== $this->reportUri) {
            $policy = $policy->with('report-uri', $this->reportUri);
        }

        return $policy;
    }

    /**
     * @param array<string, list<string>> $sources
     * @param bool                        $hostsOnly Only other sites' hosts, in fetch directives: what
     *                                               core's parts and quire/privacy may add. Never a
     *                                               keyword ("'unsafe-inline'"), a nonce or a bare scheme
     *                                               ("https:", which allows every site).
     */
    private function add(Policy $policy, array $sources, bool $hostsOnly): Policy
    {
        foreach ($sources as $directive => $list) {
            if ($hostsOnly) {
                if (!Policy::isFetchDirective($directive)) {
                    throw new \InvalidArgumentException(\sprintf('Only fetch directives such as "script-src" or "img-src" can get another site\'s hosts, not "%s".', $directive));
                }

                foreach ($list as $source) {
                    if (!Policy::isHost($source)) {
                        throw new \InvalidArgumentException(\sprintf('"%s" for "%s" isn\'t a host such as "https://cdn.example.com": only the site\'s configuration can add keywords or schemes.', $source, $directive));
                    }
                }
            }

            if ([] !== $list) {
                $policy = $policy->with($directive, ...$list);
            }
        }

        return $policy;
    }
}
