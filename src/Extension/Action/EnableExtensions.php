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
 * "extension.enable": enable installed extensions, and the extensions they require.
 *
 * @internal
 */
final readonly class EnableExtensions implements ActionInterface
{
    public function __construct(
        private ExtensionSwitcher $switcher,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Enable extensions', [
            new Parameter('extensions', ParameterType::Extension, 'Extensions', multiple: true),
        ], command: 'quire:extension:enable');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::EXTENSIONS);
    }

    public function run(Arguments $arguments): null
    {
        $this->switcher->enable($arguments->strings('extensions'), static function (string $line): void {});

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return CommandLine::of('quire:extension:enable', $arguments->strings('extensions'));
    }
}
