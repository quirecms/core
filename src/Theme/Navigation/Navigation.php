<?php

declare(strict_types=1);

namespace Quire\Theme\Navigation;

use Quire\Content\Content;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The site's navigation, for themes: `{% for item in quire_navigation() %}`. Read once per request,
 * from its provider (core's: the visible top-level pages; a menus extension's instead), with
 * `current` set for the page being shown and the section it's in.
 *
 * @api
 */
final class Navigation implements ResetInterface, RuntimeExtensionInterface
{
    /** The request attribute that holds the item a page shows */
    public const string CONTENT_ATTRIBUTE = '_quire_content';

    /** @var list<NavigationItem>|null */
    private ?array $items = null;

    public function __construct(
        private readonly NavigationProviderInterface $provider,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        if (null !== $this->items) {
            return $this->items;
        }

        $request = $this->requests->getMainRequest();
        $path = $request?->getPathInfo();
        $shown = $request?->attributes->get(self::CONTENT_ATTRIBUTE);

        return $this->items = array_map(static function (NavigationItem $item) use ($path, $shown): NavigationItem {
            $current = false;
            $itemPath = parse_url($item->url, \PHP_URL_PATH);

            if ($shown instanceof Content && $item->content instanceof Content) {
                $current = $shown->id === $item->content->id ? 'page' : (str_starts_with($shown->path.'/', $item->content->path.'/') && $shown->type === $item->content->type ? 'section' : false);
            } elseif (\is_string($itemPath) && null !== $path) {
                $current = rtrim($itemPath, '/') === rtrim($path, '/') ? 'page' : false;
            }

            return new NavigationItem($item->title, $item->url, $item->content, $current);
        }, $this->provider->items());
    }

    public function reset(): void
    {
        $this->items = null;
    }
}
