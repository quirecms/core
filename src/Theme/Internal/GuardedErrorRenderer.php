<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * A theme's error page that fails (its "latest posts" query, a template error) never turns a 404
 * into a 500: the page is a plain one with the status it was meant to have, and the failure is
 * logged. Error pages are what crawlers and probes hit most, so they must not break on the theme's
 * extras (default theme v2 A5 #6).
 *
 * @internal
 */
#[AsDecorator('twig.error_renderer.html')]
final readonly class GuardedErrorRenderer implements ErrorRendererInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ErrorRendererInterface $inner,
        private LoggerInterface $logger,
    ) {
    }

    public function render(\Throwable $exception): FlattenException
    {
        try {
            return $this->inner->render($exception);
        } catch (\Throwable $failure) {
            $this->logger->error('The theme\'s error page failed: {failure}. A plain page was sent instead.', ['failure' => $failure->getMessage(), 'exception' => $failure]);
            $flat = FlattenException::createFromThrowable($exception);
            $title = 404 === $flat->getStatusCode() ? 'Page not found' : 'Something went wrong';

            return $flat->setAsString(\sprintf('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%1$s</title></head><body><main><h1>%1$s</h1><p><a href="/">Go to the home page</a></p></main></body></html>', $title));
        }
    }
}
