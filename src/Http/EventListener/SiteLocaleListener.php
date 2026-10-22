<?php

declare(strict_types=1);

namespace Quire\Http\EventListener;

use Quire\Routing\ExtensionRouteLoader;
use Quire\Settings\Settings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the site's language (the "site.locale" setting) the language of Quire's pages, so translations
 * and `<html lang>` follow it. An application that Quire was added to keeps its own language handling
 * for its own pages, and routes with a {_locale} keep theirs.
 *
 * It runs just before Symfony's LocaleListener (16), which only overrides it from the route, and before
 * the translator and other services are switched to the request's language (15).
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
final readonly class SiteLocaleListener
{
    public function __construct(
        private Settings $settings,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->attributes->getBoolean(ExtensionRouteLoader::QUIRE_ROUTE) && !$request->attributes->has('_locale')) {
            $request->setLocale($this->settings->getString('site.locale'));
        }
    }
}
