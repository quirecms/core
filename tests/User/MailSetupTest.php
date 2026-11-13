<?php

declare(strict_types=1);

namespace Quire\Tests\User;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\User\Mail\MailSetup;

/**
 * Whether the site's mail transport sends, from its parsed scheme: never by showing or matching the DSN.
 */
final class MailSetupTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function transports(): iterable
    {
        yield 'none' => ['', false];
        yield 'null' => ['null://null', false];
        yield 'SMTP with credentials' => ['smtp://user:p%40ss@smtp.example.com:587', true];
        yield 'a provider' => ['postmark+api://KEY@default', true];
        yield 'failover with one that sends' => ['failover(null://null smtp://smtp.example.com:587)', true];
        yield 'round robin of nulls' => ['roundrobin(null://null null://null)', false];
        yield 'not a DSN' => ['smtp.example.com', false];
    }

    #[DataProvider('transports')]
    public function testWhetherATransportSends(string $dsn, bool $sends): void
    {
        self::assertSame($sends, MailSetup::sends($dsn));
    }
}
