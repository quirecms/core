<?php

declare(strict_types=1);

namespace Quire\Http\Session;

use Doctrine\DBAL\Connection;
use Quire\Database\DatabaseSchema;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Keeps sessions in PostgreSQL (the "quire.session" table), so they survive restarts and deployments and
 * are shared by every server: signing in on one is signing in on all. Use it with
 * `framework.session.handler_id: quire.session_handler`.
 *
 * The handler gets a database connection of its own: it holds a transaction open while a request runs, to
 * lock the session, which mustn't mix with the application's transactions.
 *
 * @internal
 */
final readonly class SessionHandlerFactory
{
    public const string SERVICE = 'quire.session_handler';

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function create(): PdoSessionHandler
    {
        $parameters = $this->connection->getParams();
        // A primary/replica connection: sessions are written, so they use the primary.
        $parameters = \is_array($parameters['primary'] ?? null) ? $parameters['primary'] : $parameters;
        $dsn = [];

        foreach (['host' => 'host', 'port' => 'port', 'dbname' => 'dbname', 'sslmode' => 'sslmode', 'sslrootcert' => 'sslrootcert', 'sslcert' => 'sslcert', 'sslkey' => 'sslkey'] as $parameter => $key) {
            $value = $parameters[$parameter] ?? null;

            if (\is_string($value) || \is_int($value)) {
                $dsn[] = $key.'='.$value;
            }
        }

        return new PdoSessionHandler('pgsql:'.implode(';', $dsn), [
            'db_table' => DatabaseSchema::NAME.'.session',
            'db_username' => \is_string($parameters['user'] ?? null) ? $parameters['user'] : '',
            'db_password' => \is_string($parameters['password'] ?? null) ? $parameters['password'] : '',
            'db_connection_options' => [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        ]);
    }
}
