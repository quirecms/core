<?php

declare(strict_types=1);

namespace Quire\Extension\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Internal\Access;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Extension\ExtensionSwitcher;

/**
 * "extension.purge": disable extensions and delete their data for good: their migrations are rolled back and their settings deleted.
 *
 * @internal
 */
final readonly class PurgeExtensions implements ActionInterface
{
    public function __construct(
        private ExtensionSwitcher $switcher,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Delete extensions\' data', [
            new Parameter('extensions', ParameterType::Extension, 'Extensions', multiple: true),
        ], command: 'quire:extension:disable', destructive: true);
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::EXTENSIONS);
    }

    public function run(Arguments $arguments): null
    {
        $this->switcher->disable($arguments->strings('extensions'), true, static function (string $line): void {});

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:extension:disable', $arguments->strings('extensions'), ['purge' => true, 'force' => true]);
    }
}
