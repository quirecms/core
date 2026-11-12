<?php

declare(strict_types=1);

namespace Quire\Csp\Internal;

/**
 * Sources the site's own parts need, from core only: e.g. the address of a public disk on S3 or a CDN,
 * so the images stored there show. Not for extensions (see {@see \Quire\Csp\ThirdPartySourcesInterface}):
 * the container refuses an implementation outside core.
 *
 * @internal
 */
interface FirstPartySourcesInterface
{
    public const string TAG = 'quire.csp.first_party_sources';

    /**
     * Directive => sources.
     *
     * @return array<string, list<string>>
     */
    public function sources(): array;
}
