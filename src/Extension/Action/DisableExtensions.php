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
 * "extension.disable": disable extensions, and those that require them; their data is kept.
 *
 * @internal
 */
final readonly class DisableExtensions implements ActionInterface
{
    public function __construct(
        private ExtensionSwitcher $switcher,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Disable extensions', [
            new Parameter('extensions', ParameterType::Extension, 'Extensions', multiple: true),
        ], command: 'quire:extension:disable');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::EXTENSIONS);
    }

    public function run(Arguments $arguments): null
    {
        $this->switcher->disable($arguments->strings('extensions'), false, static function (string $line): void {});

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:extension:disable', $arguments->strings('extensions'));
    }
}
