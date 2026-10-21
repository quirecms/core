<?php

declare(strict_types=1);

namespace Quire\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Starts `quire:app:refresh` in the background, so no visitor's request waits for the rebuild.
 *
 * A worker triggers it at most once per cooldown period; the command itself makes sure only one
 * refresh runs at a time on this machine.
 */
final class BackgroundRefresher
{
    private ?int $lastTriggeredAt = null;

    /** @var \Closure(string): void */
    private readonly \Closure $spawn;

    /**
     * @param (\Closure(string): void)|null $spawn Runs a shell command without waiting for it; replaceable in tests
     */
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
        private readonly int $cooldownSeconds = 60,
        ?\Closure $spawn = null,
        private readonly ?\Closure $clock = null,
    ) {
        $this->spawn = $spawn ?? static function (string $command): void {
            Process::fromShellCommandline($command)->run();
        };
    }

    /**
     * @return bool False when this worker already triggered a refresh recently
     */
    public function trigger(): bool
    {
        $now = $this->clock instanceof \Closure ? ($this->clock)() : time();
        \assert(\is_int($now));

        if (null !== $this->lastTriggeredAt && $now - $this->lastTriggeredAt < $this->cooldownSeconds) {
            return false;
        }

        $this->lastTriggeredAt = $now;

        ($this->spawn)(\sprintf(
            // Detached with "&", so the shell returns at once and the refresh outlives this request.
            '%s %s quire:app:refresh --env=%s >> %s 2>&1 &',
            escapeshellarg(PhpBinary::path()),
            escapeshellarg($this->projectDir.'/bin/console'),
            escapeshellarg($this->environment),
            escapeshellarg($this->projectDir.'/var/log/quire-refresh.log'),
        ));

        return true;
    }
}
