<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Quire\Content\Permalink\Permalinks;
use Quire\Settings\Settings;
use Symfony\Component\HttpFoundation\RequestStack;
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
    /** Languages written from right to left */
    private const array RTL_LANGUAGES = ['ar', 'arc', 'ckb', 'dv', 'fa', 'ha', 'he', 'khw', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi'];

    public function __construct(
        private Settings $settings,
        private Permalinks $permalinks,
        private RequestStack $requests,
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
     * "rtl" when the page's language is written from right to left, e.g. Arabic or Hebrew; otherwise "ltr".
     * For `<html dir="…">`.
     */
    public function direction(): string
    {
        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? $this->locale();
        $language = strtolower((string) preg_replace('/[-_].*$/', '', $locale));

        return \in_array($language, self::RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }

    /**
     * The homepage's absolute URL, e.g. "https://example.com/".
     */
    public function url(): string
    {
        return $this->permalinks->homeUrl(UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
