<?php

declare(strict_types=1);

namespace Quire\Setup;

use Psr\Container\ContainerInterface;
use Quire\Extend\SetupSteps as Extender;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The steps of setting this site up, one list for every place that shows them: the admin's home page
 * and a theme's empty home page (`quire_setup_steps()`). Each step says whether it's done from the
 * site as it is, and only the steps the person looking may take are listed: a visitor gets none.
 *
 * A page that shows them is for its viewer only: its response is made `private, no-store`.
 *
 * @api
 */
final readonly class SetupSteps
{
    /** @internal The request attribute that makes its response private */
    public const string PRIVATE_ATTRIBUTE = '_quire_setup_steps';

    /**
     * @param list<array{id: string, class: string, priority: int, owner: string}> $registered
     */
    public function __construct(
        #[Autowire(param: Extender::PARAMETER)]
        private array $registered,
        #[AutowireLocator(Extender::TAG)]
        private ContainerInterface $steps,
        private RequestStack $requests,
    ) {
    }

    /**
     * Every step the person looking may take, done or not, in order.
     *
     * @return list<SetupStep>
     *
     * @phpstan-impure It depends on who's looking
     */
    public function all(): array
    {
        $steps = [];

        foreach ($this->registered as $registration) {
            $service = $this->steps->get($registration['class']);
            \assert($service instanceof SetupStepInterface);
            $step = $service->step();

            if ($step instanceof SetupStep) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * The steps while some aren't done, with how many are; null once they all are, or when the person
     * may take none (a visitor).
     *
     * @return array{steps: list<SetupStep>, done: int}|null
     *
     * @phpstan-impure It depends on who's looking, and marks the response private
     */
    public function pending(): ?array
    {
        $steps = $this->all();
        $done = \count(array_filter($steps, static fn (SetupStep $step): bool => $step->done));

        if ([] === $steps || $done === \count($steps)) {
            return null;
        }

        // Shown to this person only: never kept by a shared cache
        $this->requests->getMainRequest()?->attributes->set(self::PRIVATE_ATTRIBUTE, true);

        return ['steps' => $steps, 'done' => $done];
    }
}
