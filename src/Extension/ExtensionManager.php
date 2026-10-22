<?php

declare(strict_types=1);

namespace Quire\Extension;

use Psr\EventDispatcher\EventDispatcherInterface;
use Quire\Extension\Event\ExtensionDisabled;
use Quire\Extension\Event\ExtensionEnabled;
use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\State\ExtensionStateStoreInterface;

/**
 * Enables and disables extensions, and reports which ones run.
 *
 * @api
 */
final readonly class ExtensionManager
{
    public function __construct(
        private InstalledExtensions $installed,
        private ExtensionStateStoreInterface $state,
        private EventDispatcherInterface $dispatcher,
        private DependencyResolver $resolver = new DependencyResolver(),
    ) {
    }

    public function resolve(): ResolvedExtensions
    {
        return $this->resolver->resolve($this->installed->all(), $this->state->enabledIds());
    }

    public function isEnabled(string $id): bool
    {
        return \in_array($id, $this->state->enabledIds(), true);
    }

    /**
     * Why extensions can't be enabled or disabled at runtime, or null when they can.
     */
    public function readOnlyReason(): ?string
    {
        return $this->state->readOnlyReason();
    }

    /**
     * What {@see enable()} would enable: the given extensions plus missing requirements, in boot order.
     *
     * @param list<string> $ids
     *
     * @return list<ExtensionManifest>
     *
     * @throws UnknownExtensionException
     * @throws DependencyException
     * @throws ExtensionStateException
     */
    public function planEnable(array $ids): array
    {
        $this->assertNoThemes($ids);

        return $this->resolver->planEnable($this->installed->all(), $this->state->enabledIds(), $ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ExtensionManifest> The extensions that were enabled, in boot order
     *
     * @throws UnknownExtensionException
     * @throws DependencyException
     * @throws ExtensionStateException
     */
    public function enable(array $ids): array
    {
        $this->assertWritable();
        $plan = $this->planEnable($ids);

        if ([] === $plan) {
            return [];
        }

        $this->state->enable(array_map(static fn (ExtensionManifest $m): string => $m->id, $plan));

        foreach ($plan as $manifest) {
            $this->dispatcher->dispatch(new ExtensionEnabled($manifest));
        }

        return $plan;
    }

    /**
     * What {@see disable()} would disable.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     *
     * @throws UnknownExtensionException
     * @throws DependencyException
     * @throws ExtensionStateException
     */
    public function planDisable(array $ids): array
    {
        $this->assertNoThemes($ids);

        return $this->resolver->planDisable($this->installed->all(), $this->state->enabledIds(), $ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> IDs of the extensions that were disabled
     *
     * @throws UnknownExtensionException
     * @throws DependencyException
     * @throws ExtensionStateException
     */
    public function disable(array $ids): array
    {
        $this->assertWritable();
        $plan = $this->planDisable($ids);

        if ([] === $plan) {
            return [];
        }

        // Dependents go first, so listeners never see an extension outlive what it requires.
        $order = array_reverse(array_values(array_filter(
            $this->resolve()->bootOrderIds(),
            static fn (string $id): bool => \in_array($id, $plan, true),
        )));
        $order = [...$order, ...array_diff($plan, $order)];

        $this->state->disable($plan);

        foreach ($order as $id) {
            $this->dispatcher->dispatch(new ExtensionDisabled($id, $this->installed->has($id) ? $this->installed->get($id) : null));
        }

        return $plan;
    }

    /**
     * @param list<string> $ids
     *
     * @throws UnknownExtensionException
     */
    private function assertNoThemes(array $ids): void
    {
        foreach ($ids as $id) {
            if ($this->installed->hasTheme($id)) {
                throw new UnknownExtensionException(\sprintf('"%s" is a theme, not an extension. Make it the site\'s theme with "bin/console quire:theme:use %1$s".', $id));
            }
        }
    }

    private function assertWritable(): void
    {
        $reason = $this->state->readOnlyReason();

        if (null !== $reason) {
            throw new ExtensionStateException($reason);
        }
    }
}
