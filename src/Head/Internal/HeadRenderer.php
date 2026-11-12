<?php

declare(strict_types=1);

namespace Quire\Head\Internal;

use Psr\Container\ContainerInterface;
use Quire\Extend\Head as HeadExtender;
use Quire\Head\Head;
use Quire\Head\HeadContributorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Builds a page's head tags from the contributors, lowest priority first, and prints them.
 *
 * @internal
 */
final readonly class HeadRenderer
{
    /**
     * @param list<array{class: string, priority: int, owner: string}> $contributors The service IDs (classes) of Extend\Head
     */
    public function __construct(
        #[AutowireLocator(HeadExtender::TAG)]
        private ContainerInterface $services,
        #[Autowire(param: HeadExtender::PARAMETER)]
        private array $contributors,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function build(array $context): Head
    {
        $head = new Head();
        $ordered = $this->contributors;
        // Stable: contributors of the same priority run in the order they were registered.
        usort($ordered, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($ordered as $contributor) {
            $service = $this->services->get($contributor['class']);
            \assert($service instanceof HeadContributorInterface);
            $service->contribute($head, $context);
        }

        return $head;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(array $context): string
    {
        return $this->build($context)->toHtml();
    }
}
