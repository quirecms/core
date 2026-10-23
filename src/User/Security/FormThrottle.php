<?php

declare(strict_types=1);

namespace Quire\User\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Limits how often one address can send the forms that create accounts or send emails: 5 times in
 * 15 minutes per form, so they can't be used to flood inboxes or create accounts in bulk.
 *
 * @internal
 */
final readonly class FormThrottle
{
    public const string CACHE_POOL = 'quire.rate_limiter';

    private RateLimiterFactory $limits;

    public function __construct(
        #[Autowire(service: self::CACHE_POOL)]
        CacheItemPoolInterface $cache,
    ) {
        $this->limits = new RateLimiterFactory(['id' => 'quire_account_forms', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes'], new CacheStorage($cache));
    }

    /**
     * Counts a submission of the form, and tells whether it's within the limit.
     */
    public function allows(string $form, Request $request): bool
    {
        return $this->limits->create($form.'|'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted();
    }
}
