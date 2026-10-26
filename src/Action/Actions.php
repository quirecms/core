<?php

declare(strict_types=1);

namespace Quire\Action;

use Quire\Action\Exception\ActionDeniedException;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Action\Exception\UnknownActionException;
use Quire\Extend\Actions as ActionsExtender;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The catalogue of actions: what people can do with the site, from core and from extensions (see
 * {@see ActionsExtender}). Admin interfaces, APIs and the audit log use it, so an action is checked,
 * done and described the same way wherever it comes from.
 *
 *     $actions->denied('content.transition', ['content' => $id, 'transition' => 'publish']); // null, or why not
 *     $actions->run('content.transition', ['content' => $id, 'transition' => 'publish']);
 *     $actions->commandLine('content.transition', [...]); // bin/console quire:content:transition … publish
 *
 * Arguments are what JSON can hold: references to content, users, roles and so on are IDs or names.
 *
 * @api
 */
final readonly class Actions
{
    /** @var array<string, array{id: string, class: string, owner: string}> */
    private array $actions;

    /**
     * @param list<array{id: string, class: string, owner: string}> $actions
     * @param ServiceLocator<ActionInterface>                       $services By class
     */
    public function __construct(
        #[Autowire(param: ActionsExtender::PARAMETER)]
        array $actions,
        #[AutowireLocator(ActionsExtender::TAG)]
        private ServiceLocator $services,
    ) {
        $this->actions = array_column($actions, null, 'id');
    }

    /**
     * @return list<string> The actions' IDs, in the order they were registered
     */
    public function ids(): array
    {
        return array_keys($this->actions);
    }

    public function has(string $id): bool
    {
        return isset($this->actions[$id]);
    }

    /**
     * @throws UnknownActionException
     */
    public function definition(string $id): ActionDefinition
    {
        return $this->action($id)->definition();
    }

    /**
     * The extension that registered the action, or "core".
     *
     * @throws UnknownActionException
     */
    public function owner(string $id): string
    {
        return ($this->actions[$id] ?? throw new UnknownActionException($id))['owner'];
    }

    /**
     * Why the signed-in user may not do it with these arguments; null when they may.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws UnknownActionException
     * @throws InvalidArgumentsException
     */
    public function denied(string $id, array $arguments): ?string
    {
        $action = $this->action($id);

        return $action->denied(Arguments::check($id, $action->definition(), $arguments));
    }

    /**
     * Does it, as the signed-in user, when they may.
     *
     * @param array<string, mixed> $arguments
     *
     * @return object|null What it created or changed, e.g. the content item
     *
     * @throws UnknownActionException
     * @throws InvalidArgumentsException
     * @throws ActionDeniedException
     */
    public function run(string $id, array $arguments): ?object
    {
        $action = $this->action($id);
        $checked = Arguments::check($id, $action->definition(), $arguments);
        $denied = $action->denied($checked);

        if (null !== $denied) {
            throw new ActionDeniedException($id, $denied);
        }

        return $action->run($checked);
    }

    /**
     * The console command that does the same; null when there's none for these arguments.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws UnknownActionException
     * @throws InvalidArgumentsException
     */
    public function commandLine(string $id, array $arguments): ?string
    {
        $action = $this->action($id);

        return $action->commandLine(Arguments::check($id, $action->definition(), $arguments));
    }

    private function action(string $id): ActionInterface
    {
        $class = ($this->actions[$id] ?? throw new UnknownActionException($id))['class'];

        return $this->services->get($class);
    }
}
