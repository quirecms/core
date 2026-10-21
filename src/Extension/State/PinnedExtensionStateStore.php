<?php

declare(strict_types=1);

namespace Quire\Extension\State;

use Quire\Extension\Exception\ExtensionStateException;

/**
 * Enabled extensions fixed in configuration (`quire.extensions.pinned: true`).
 *
 * Used for immutable production images: the enabled set ships with the code and can't change at runtime.
 */
final readonly class PinnedExtensionStateStore implements ExtensionStateStoreInterface
{
    private const string READ_ONLY_REASON = 'Extensions are pinned in configuration (quire.extensions.pinned: true). Change the "quire.extensions.enabled" list in config/packages/quire.yaml instead, then redeploy.';

    /** @var list<string> */
    private array $enabledIds;

    /**
     * @param list<string> $enabledIds
     */
    public function __construct(array $enabledIds)
    {
        $ids = array_values(array_unique($enabledIds));
        sort($ids);
        $this->enabledIds = $ids;
    }

    public function enabledIds(): array
    {
        return $this->enabledIds;
    }

    public function readOnlyReason(): string
    {
        return self::READ_ONLY_REASON;
    }

    public function enable(array $ids): void
    {
        throw new ExtensionStateException(self::READ_ONLY_REASON);
    }

    public function disable(array $ids): void
    {
        throw new ExtensionStateException(self::READ_ONLY_REASON);
    }
}
