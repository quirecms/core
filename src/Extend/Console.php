<?php

declare(strict_types=1);

namespace Quire\Extend;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * Adds console commands (`bin/console ...`).
 *
 *     (new Extend\Console())->command(RecalculateRatings::class);
 *
 * Commands need the `#[AsCommand]` attribute. Commands registered through {@see Services::autoload()}
 * are picked up automatically, without this extender.
 *
 * @api
 */
final class Console implements ExtenderInterface
{
    /** @var list<string> */
    private array $commands = [];

    /**
     * @param class-string $command A class with #[AsCommand]: an invokable class or a Command subclass
     */
    public function command(string $command): self
    {
        $this->commands[] = $command;

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->commands as $command) {
            $where = \sprintf('Extend\Console::command("%s")', $command);

            if (!class_exists($command)) {
                throw $context->error($where, 'The class doesn\'t exist.');
            }

            $reflection = new \ReflectionClass($command);

            if ([] === $reflection->getAttributes(AsCommand::class)) {
                throw $context->error($where, 'The class needs a #[AsCommand(name: \'...\')] attribute.');
            }

            if (!$reflection->isSubclassOf(Command::class) && !$reflection->hasMethod('__invoke')) {
                throw $context->error($where, \sprintf('The class must extend %s or have an __invoke() method.', Command::class));
            }

            // Autoconfiguration tags it as a console command, based on #[AsCommand].
            $context->service($command);
        }
    }
}
