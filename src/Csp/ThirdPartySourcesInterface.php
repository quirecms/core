<?php

declare(strict_types=1);

namespace Quire\Csp;

/**
 * The one place other sites' hosts enter the site's policy: those of the services the visitor may be
 * asked to consent to (analytics, fonts, video), as `quire/privacy` declares them.
 *
 * Only `quire/privacy` implements it (doc 20, condition C-C): there's no general "add a CSP source" for
 * extensions, so a host can't load unless a declared service, which the privacy page lists and the
 * banner asks about, needs it. The container refuses an implementation from anywhere else (another
 * namespace, or a class outside the installed `quire/privacy` package), also as a decorator. There's
 * one implementation, so it returns the hosts of every declared service together. Without
 * `quire/privacy`, the site's pages load nothing from other sites but what the site's own
 * configuration allows ("quire.csp.sources").
 *
 * The policy is an allowlist, not consent: a host in it is one a page *may* load, for every visitor,
 * so pages stay cacheable. What a visitor allowed is up to `quire/privacy`'s loader, which adds a
 * provider's script only after consent. Pages with forms for passwords or accounts never get these
 * hosts, consent or not: a script there could read what's typed.
 *
 * @api
 */
interface ThirdPartySourcesInterface
{
    /** The only namespace whose classes may implement it */
    public const string NAMESPACE = 'Quire\\Privacy\\';

    /**
     * Directive => sources, e.g. ['script-src' => ['https://www.googletagmanager.com']].
     *
     * @return array<string, list<string>>
     */
    public function sources(): array;
}
