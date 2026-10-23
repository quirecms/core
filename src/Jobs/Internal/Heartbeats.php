<?php

declare(strict_types=1);

namespace Quire\Jobs\Internal;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records that workers are alive: a row each in quire.job_worker, for the status of the whole site,
 * and a file on this machine, for container health checks.
 *
 * @internal
 */
final readonly class Heartbeats
{
    /** Relative to the project directory; its modification time is the last heartbeat on this machine */
    public const string FILE = 'var/quire-worker.heartbeat';

    public function __construct(
        private Connection $connection,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * @param string       $id         Unique per worker process
     * @param list<string> $transports What it works on; "schedule" for runs of the scheduler from cron
     */
    public function beat(string $id, array $transports): void
    {
        $this->connection->executeStatement(
            'INSERT INTO quire.job_worker (id, host, pid, transports) VALUES (:id, :host, :pid, :transports)
             ON CONFLICT (id) DO UPDATE SET seen_at = now(), transports = EXCLUDED.transports',
            ['id' => $id, 'host' => self::host(), 'pid' => self::pid(), 'transports' => implode(',', $transports)],
        );
        // Workers that stopped without saying so, e.g. killed.
        $this->connection->executeStatement("DELETE FROM quire.job_worker WHERE seen_at < now() - INTERVAL '1 day'");
        @touch($this->projectDir.'/'.self::FILE);
    }

    /**
     * This machine's name, e.g. the container's ID.
     */
    public static function host(): string
    {
        $host = gethostname();

        return false === $host || '' === $host ? 'unknown' : $host;
    }

    public static function pid(): int
    {
        $pid = getmypid();

        return false === $pid ? 0 : $pid;
    }

    public function stop(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM quire.job_worker WHERE id = ?', [$id]);
    }
}
