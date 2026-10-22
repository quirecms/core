<?php

declare(strict_types=1);

namespace Quire\Backup\Internal;

use Doctrine\DBAL\Connection;
use Quire\Backup\Exception\BackupException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs PostgreSQL's client tools (pg_dump, pg_restore) against the database Quire is connected to.
 *
 * The connection details go into the tools' environment (PGHOST, PGPASSWORD, …) rather than their
 * arguments, so the password never shows up in the process list.
 *
 * @internal
 */
final readonly class PostgresTools
{
    /** Connection parameters of the pdo_pgsql driver, and the libpq variables they map to */
    private const array ENVIRONMENT = [
        'host' => 'PGHOST',
        'port' => 'PGPORT',
        'user' => 'PGUSER',
        'password' => 'PGPASSWORD',
        'dbname' => 'PGDATABASE',
        'sslmode' => 'PGSSLMODE',
        'sslrootcert' => 'PGSSLROOTCERT',
        'sslcert' => 'PGSSLCERT',
        'sslkey' => 'PGSSLKEY',
        'sslcrl' => 'PGSSLCRL',
        'gssencmode' => 'PGGSSENCMODE',
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string>                  $arguments
     * @param (\Closure(string): void)|null $output    Receives the standard output as it comes; without it, it's returned
     *
     * @throws BackupException when the tool isn't installed or fails
     */
    public function run(string $tool, array $arguments, ?\Closure $output = null): string
    {
        $binary = new ExecutableFinder()->find($tool) ?? throw new BackupException(\sprintf('"%s" isn\'t installed. Install the PostgreSQL client tools of the same version as the database server or newer, e.g. the Debian package "postgresql-client-%s".', $tool, explode('.', $this->connection->getServerVersion())[0]));

        $process = new Process([$binary, ...$arguments], env: $this->environment(), timeout: null);
        $stdout = '';
        $process->run(static function (string $type, string $buffer) use ($output, &$stdout): void {
            if (Process::OUT !== $type) {
                return;
            }

            if (!$output instanceof \Closure) {
                $stdout .= $buffer;
            } else {
                $output($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $hint = str_contains($error, 'version mismatch')
                ? \sprintf(' Install the PostgreSQL client tools of version %s or newer.', explode('.', $this->connection->getServerVersion())[0])
                : '';

            throw new BackupException(\sprintf('%s failed: %s%s', $tool, '' === $error ? 'exit code '.$process->getExitCode() : $error, $hint));
        }

        return $stdout;
    }

    /**
     * The database to pass as pg_restore's --dbname, which it needs to restore into a database instead of
     * writing SQL. The connection details themselves come from the environment.
     */
    public function connectionString(): string
    {
        $database = $this->parameters()['dbname'] ?? null;

        return null === $database ? '' : 'dbname='.$this->quote($database);
    }

    /**
     * Parameters that aren't set are left to the inherited environment, as they are for the PDO
     * connection, which also uses libpq.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = ['PGAPPNAME' => 'quire', 'PGCONNECT_TIMEOUT' => '10'];

        foreach ($this->parameters() as $parameter => $value) {
            $environment[self::ENVIRONMENT[$parameter]] = $value;
        }

        return $environment;
    }

    /**
     * @return array<string, string>
     */
    private function parameters(): array
    {
        $parameters = $this->connection->getParams();
        // A primary/replica connection: back up from and restore into the primary.
        $parameters = \is_array($parameters['primary'] ?? null) ? $parameters['primary'] : $parameters;
        $strings = [];

        foreach (array_keys(self::ENVIRONMENT) as $name) {
            $value = $parameters[$name] ?? null;

            if (\is_string($value) || \is_int($value)) {
                $strings[$name] = (string) $value;
            }
        }

        return $strings;
    }

    private function quote(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
