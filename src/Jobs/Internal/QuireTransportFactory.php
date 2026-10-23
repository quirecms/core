<?php

declare(strict_types=1);

namespace Quire\Jobs\Internal;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Exception\InvalidArgumentException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Transports for DSNs such as "quire://" and "quire://default?queue=failed": Messenger's Doctrine
 * transport on Quire's table quire.job, through the Doctrine connection named in the DSN ("default"
 * if none).
 *
 * Workers poll the table (an indexed query a second while idle) rather than LISTEN for notifications:
 * LISTEN doesn't work behind connection poolers such as PgBouncer, where waiting jobs would then go
 * unnoticed for up to a minute. Add "notify=1" to use it on direct connections.
 *
 * @internal
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final readonly class QuireTransportFactory implements TransportFactoryInterface
{
    public const string TABLE = 'quire.job';

    public function __construct(
        #[Autowire(service: 'messenger.transport.doctrine.factory')]
        private DoctrineTransportFactory $doctrine,
    ) {
    }

    /**
     * @param array<mixed> $options
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        // parse_url() refuses "quire://" without a connection name.
        [$connection, $queryString] = explode('?', substr($dsn, \strlen('quire://')), 2) + [1 => ''];
        parse_str($queryString, $query);
        $queue = $query['queue'] ?? 'default';
        $notify = filter_var($query['notify'] ?? false, \FILTER_VALIDATE_BOOL);
        unset($query['queue'], $query['notify']);

        if ([] !== $query) {
            throw new InvalidArgumentException(\sprintf('Unknown option(s) in "%s": %s. Quire\'s transport takes "queue" and "notify".', $dsn, implode(', ', array_keys($query))));
        }

        if (1 !== preg_match('/^[A-Za-z0-9_]*$/', $connection)) {
            throw new InvalidArgumentException(\sprintf('The DSN "%s" is invalid. Use e.g. "quire://", "quire://default?queue=failed" or "quire://other_connection".', $dsn));
        }

        $doctrineDsn = \sprintf('doctrine://%s?%s', '' === $connection ? 'default' : $connection, http_build_query([
            'table_name' => self::TABLE,
            'queue_name' => \is_string($queue) ? $queue : 'default',
            // The table is created by Quire's migrations.
            'auto_setup' => 'false',
        ]));

        return $this->doctrine->createTransport($doctrineDsn, ['use_notify' => $notify] + $options, $serializer);
    }

    /**
     * @param array<mixed> $options
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'quire://');
    }
}
