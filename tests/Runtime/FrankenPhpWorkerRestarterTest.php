<?php

declare(strict_types=1);

namespace Quire\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Quire\Runtime\FrankenPhpWorkerRestarter;

final class FrankenPhpWorkerRestarterTest extends TestCase
{
    public function testReturnsFalseWhenNoAdminApiIsListening(): void
    {
        // Port 9 (discard) is closed in the test container, as on most machines.
        self::assertFalse(new FrankenPhpWorkerRestarter('http://127.0.0.1:9')->restart());
    }
}
