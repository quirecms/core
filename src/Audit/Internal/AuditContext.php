<?php

declare(strict_types=1);

namespace Quire\Audit\Internal;

use Quire\Audit\Origin;
use Quire\User\CurrentUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Who is acting, and through what: the signed-in user of a web request, a console command, or a job a
 * worker handles. Each request, command and job gets a correlation ID, so its entries can be grouped.
 *
 * @internal
 */
final class AuditContext implements ResetInterface
{
    private const string REQUEST_ATTRIBUTE = '_quire_audit_correlation';

    private ?string $command = null;

    private ?string $commandCorrelation = null;

    private ?string $job = null;

    private ?string $jobCorrelation = null;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly UserRepository $users,
    ) {
    }

    #[AsEventListener]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $this->command = $event->getCommand()?->getName();
        $this->commandCorrelation = $this->uuid();
    }

    #[AsEventListener(event: ConsoleTerminateEvent::class)]
    public function onTerminate(): void
    {
        $this->command = $this->commandCorrelation = null;
    }

    #[AsEventListener]
    public function onJob(WorkerMessageReceivedEvent $event): void
    {
        $this->job = $event->getEnvelope()->getMessage()::class;
        $this->jobCorrelation = $this->uuid();
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onJobDone(): void
    {
        $this->job = $this->jobCorrelation = null;
    }

    public function origin(): Origin
    {
        return match (true) {
            null !== $this->job => Origin::Job,
            $this->requests->getMainRequest() instanceof Request => Origin::Web,
            default => Origin::Console,
        };
    }

    /**
     * The route, command or job class.
     */
    public function via(): ?string
    {
        $route = $this->requests->getMainRequest()?->attributes->get('_route');

        return match ($this->origin()) {
            Origin::Job => $this->job,
            Origin::Web => \is_string($route) ? $route : null,
            Origin::Console => $this->command,
        };
    }

    public function correlationId(): string
    {
        if (Origin::Job === $this->origin()) {
            return $this->jobCorrelation ??= $this->uuid();
        }

        $request = $this->requests->getMainRequest();

        if (!$request instanceof Request) {
            return $this->commandCorrelation ??= $this->uuid();
        }

        $id = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (!\is_string($id)) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $id = $this->uuid());
        }

        return $id;
    }

    /**
     * The signed-in user; none in console commands and jobs. Only looked up: users of a host application
     * get their Quire user on their first request (see {@see CurrentUser}), which is recorded too.
     */
    public function user(): ?User
    {
        $user = Origin::Web === $this->origin() ? $this->security->getUser() : null;

        return match (true) {
            $user instanceof User, !$user instanceof UserInterface => $user,
            default => $this->users->findByIdentity(CurrentUser::HOST_PROVIDER, $user->getUserIdentifier()),
        };
    }

    public function reset(): void
    {
        $this->job = $this->jobCorrelation = null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
