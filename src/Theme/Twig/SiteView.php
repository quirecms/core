<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Quire\Content\Permalink\Permalinks;
use Quire\Settings\Settings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The `site` variable of every template: `{{ site.title }}`, `{{ site.url }}`.
 *
 * Values are read when a template uses them, so they're current in long-running PHP workers too.
 *
 * @api
 */
final readonly class SiteView
{
    public function __construct(
        private Settings $settings,
        private Permalinks $permalinks,
    ) {
    }

    public function title(): string
    {
        return $this->settings->getString('site.title');
    }

    public function description(): string
    {
        return $this->settings->getString('site.description');
    }

    /**
     * The language tag, e.g. "en" or "pt-BR", for `<html lang="…">`.
     */
    public function locale(): string
    {
        return $this->settings->getString('site.locale');
    }

    /**
     * The homepage's absolute URL, e.g. "https://example.com/".
     */
    public function url(): string
    {
        return $this->permalinks->homeUrl(UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
