<?php

declare(strict_types=1);

namespace Quire\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Restarts FrankenPHP workers gracefully through the Caddy admin API, which listens on localhost.
 *
 * Workers finish their current request before restarting. Elsewhere (php-fpm, the CLI without
 * FrankenPHP) the API isn't reachable and there are no workers to restart: restart() returns false.
 */
final readonly class FrankenPhpWorkerRestarter implements WorkerRestarterInterface
{
    public function __construct(
        #[Autowire(env: 'default:quire.runtime.default_admin_url:QUIRE_FRANKENPHP_ADMIN_URL')]
        private string $adminUrl,
    ) {
    }

    public function restart(): bool
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents(rtrim($this->adminUrl, '/').'/frankenphp/workers/restart', context: $context);

        if (false === $response) {
            return false;
        }

        $statusLine = http_get_last_response_headers()[0] ?? '';

        return 1 === preg_match('#^HTTP/\S+ 2\d\d#', $statusLine);
    }
}
