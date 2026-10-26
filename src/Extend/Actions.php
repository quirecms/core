<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Action\ActionInterface;

/**
 * Adds actions: things people do with the site, which admin interfaces offer, APIs run and the audit
 * log describes, each with who may do it and the console command that does the same.
 *
 *     new Extend\Actions()
 *         ->action('acme-reviews.approve', ApproveReview::class),
 *
 * The class implements {@see ActionInterface}. Run actions through {@see \Quire\Action\Actions}.
 *
 * @api
 */
final class Actions implements ExtenderInterface
{
    /** @internal Parameter: list<{id, class, owner}> */
    public const string PARAMETER = 'quire.actions';

    /** @internal Tag of the actions' services */
    public const string TAG = 'quire.action';

    private const string ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:_[a-z0-9]+)*$/';

    /** @var list<array{id: string, class: string}> */
    private array $actions = [];

    /**
     * @param string                        $id    Starts with your extension's ID and a dot, then a verb, e.g. "acme-reviews.approve"
     * @param class-string<ActionInterface> $class A service
     */
    public function action(string $id, string $class): self
    {
        $this->actions[] = ['id' => $id, 'class' => $class];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        /** @var list<array{id: string, owner: string}> $registered */
        $registered = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];
        $taken = array_column($registered, 'owner', 'id');
        // Core's actions are named after what they act on: "content.transition", "user.suspend".
        $prefix = match ($context->id) {
            ExtenderContext::CORE => null,
            ExtenderContext::SITE => 'custom.',
            default => $context->id.'.',
        };

        foreach ($this->actions as $action) {
            $where = \sprintf('Extend\Actions::action("%s")', $action['id']);

            $error = match (true) {
                1 !== preg_match(self::ID_PATTERN, $action['id']) || (null !== $prefix && !str_starts_with($action['id'], $prefix)) => \sprintf('Action IDs start with "%s", followed by a verb in lowercase letters, digits and underscores, e.g. "%sapprove".', $prefix, $prefix),
                isset($taken[$action['id']]) => \sprintf('The action is already added by "%s".', $taken[$action['id']]),
                !class_exists($action['class']) || !is_a($action['class'], ActionInterface::class, true) => \sprintf('Actions are classes implementing %s.', ActionInterface::class),
                default => null,
            };

            if (null !== $error) {
                throw $context->error($where, $error);
            }

            $taken[$action['id']] = $context->id;
            $context->service($action['class'])->addTag(self::TAG);
            $context->appendToParameter(self::PARAMETER, [...$action, 'owner' => $context->id]);
        }
    }
}
