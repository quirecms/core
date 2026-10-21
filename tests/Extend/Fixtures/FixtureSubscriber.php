<?php

declare(strict_types=1);

namespace Quire\Tests\Extend\Fixtures;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class FixtureSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [];
    }
}
