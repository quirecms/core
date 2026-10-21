<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use Quire\Extension\State\ExtensionStateStoreInterface;

final class InMemoryExtensionStateStore implements ExtensionStateStoreInterface
{
    /** @var array<string, true> */
    private array $enabled = [];

    /**
     * @param list<string> $enabledIds
     */
    public function __construct(array $enabledIds = [])
    {
        $this->enable($enabledIds);
    }

    public function enabledIds(): array
    {
        $ids = array_keys($this->enabled);
        sort($ids);

        return $ids;
    }

    public function readOnlyReason(): ?string
    {
        return null;
    }

    public function enable(array $ids): void
    {
        foreach ($ids as $id) {
            $this->enabled[$id] = true;
        }
    }

    public function disable(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->enabled[$id]);
        }
    }
}
