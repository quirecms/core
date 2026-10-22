<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Internal;

use Doctrine\DBAL\Connection;
use Quire\Settings\Event\SettingChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Remembers a type's previous URL pattern when it changes, so URLs in the old format redirect to the new ones.
 *
 * @internal
 */
#[AsEventListener]
final readonly class PermalinkHistoryListener
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(SettingChanged $event): void
    {
        if (!str_starts_with($event->key, PermalinkSettings::PERMALINK_PREFIX) || !\is_string($event->oldValue)) {
            return;
        }

        $type = substr($event->key, \strlen(PermalinkSettings::PERMALINK_PREFIX));
        $this->connection->executeStatement(
            'INSERT INTO quire.permalink_history (type, pattern) VALUES (?, ?) ON CONFLICT (type, pattern) DO UPDATE SET replaced_at = now()',
            [$type, $event->oldValue],
        );
        // Back to an earlier pattern: it's current again.
        $this->connection->executeStatement('DELETE FROM quire.permalink_history WHERE type = ? AND pattern = ?', [$type, $event->newValue]);
    }
}
