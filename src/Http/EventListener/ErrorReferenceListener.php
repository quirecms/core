<?php

declare(strict_types=1);

namespace Quire\Http\EventListener;

use Psr\Log\LoggerInterface;
use Quire\Http\ErrorReference;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives a server error (500 and up) its reference, and logs it with the failure, before Symfony's error
 * listener renders the error page (-128), which shows the reference (quire_error_reference()). It runs
 * after the firewall's listener (1), which turns a denied access into a 403 or the sign-in page: those,
 * like a 404, get none, as there's nothing for the owner to look up.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -1)]
final readonly class ErrorReferenceListener
{
    public function __construct(
        private ErrorReference $reference,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($event->hasResponse()) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        if ($status < 500) {
            return;
        }

        $this->logger->error('Error reference {reference}: {class}: {message}', [
            'reference' => $this->reference->make(),
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
