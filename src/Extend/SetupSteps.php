<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Setup\SetupStepInterface;

/**
 * Adds steps of setting a new site up, which the admin's home page and a theme's empty home page show
 * from the same list ({@see \Quire\Setup\SetupSteps}).
 *
 *     new Extend\SetupSteps()
 *         ->step('acme-shop.connect', ConnectShopStep::class, priority: 50),
 *
 * Higher priorities come first; core's admin uses 400 to 100.
 *
 * @api
 */
final class SetupSteps implements ExtenderInterface
{
    /** @internal Parameter: list<{id, class, priority, owner}>, sorted */
    public const string PARAMETER = 'quire.setup.steps';

    /** @internal Tag of the steps' services */
    public const string TAG = 'quire.setup_step';

    private const string ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

    /** @var list<array{id: string, class: string, priority: int}> */
    private array $steps = [];

    /**
     * @param string                           $id    Starts with your extension's ID and a dot, e.g. "acme-shop.connect"
     * @param class-string<SetupStepInterface> $class A service
     */
    public function step(string $id, string $class, int $priority = 0): self
    {
        $this->steps[] = ['id' => $id, 'class' => $class, 'priority' => $priority];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        /** @var list<array{id: string, class: string, priority: int, owner: string}> $registered */
        $registered = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];
        $taken = array_column($registered, 'owner', 'id');
        $prefix = ExtenderContext::SITE === $context->id ? 'custom.' : $context->id.'.';

        foreach ($this->steps as $step) {
            $where = \sprintf('Extend\SetupSteps::step("%s")', $step['id']);

            $error = match (true) {
                1 !== preg_match(self::ID_PATTERN, $step['id']) || !str_starts_with($step['id'], $prefix) => \sprintf('Step IDs start with "%s", followed by lowercase letters, digits, hyphens and underscores, e.g. "%sconnect".', $prefix, $prefix),
                isset($taken[$step['id']]) => \sprintf('The step is already added by "%s".', $taken[$step['id']]),
                !class_exists($step['class']) || !is_a($step['class'], SetupStepInterface::class, true) => \sprintf('Setup steps are classes implementing %s.', SetupStepInterface::class),
                default => null,
            };

            if (null !== $error) {
                throw $context->error($where, $error);
            }

            $taken[$step['id']] = $context->id;
            // Tagged once every extender has run (SetupStepsPass): an Extend\Services() after this one
            // would replace a definition made here
            $registered[] = [...$step, 'owner' => $context->id];
        }

        // Highest priority first; the same priority in the order they were added
        $order = array_flip(array_keys($registered));
        uksort($registered, static fn (int $a, int $b): int => [$registered[$b]['priority'], $order[$a]] <=> [$registered[$a]['priority'], $order[$b]]);
        $context->container()->setParameter(self::PARAMETER, array_values($registered));
    }
}
