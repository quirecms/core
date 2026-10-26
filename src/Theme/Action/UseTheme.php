<?php

declare(strict_types=1);

namespace Quire\Theme\Action;

use Quire\Action\ActionDefinition;
use Quire\Action\ActionInterface;
use Quire\Action\Arguments;
use Quire\Action\CommandLine;
use Quire\Action\Internal\Access;
use Quire\Action\Parameter;
use Quire\Action\ParameterType;
use Quire\Theme\ThemeSwitcher;

/**
 * "theme.use": make an installed theme the site's theme, or use Quire's built-in templates.
 *
 * @internal
 */
final readonly class UseTheme implements ActionInterface
{
    public function __construct(
        private ThemeSwitcher $themes,
        private Access $access,
    ) {
    }

    public function definition(): ActionDefinition
    {
        return new ActionDefinition('Change the theme', [
            new Parameter('theme', ParameterType::Theme, 'Theme; none for Quire\'s built-in templates', required: false),
        ], command: 'quire:theme:use');
    }

    public function denied(Arguments $arguments): ?string
    {
        return $this->access->denied(Access::THEMES);
    }

    public function run(Arguments $arguments): null
    {
        $this->themes->use($arguments->optionalString('theme'), static function (string $line): void {});

        return null;
    }

    public function commandLine(Arguments $arguments): string
    {
        return $arguments->has('theme') ? CommandLine::of('quire:theme:use', [$arguments->string('theme')]) : CommandLine::of('quire:theme:use', options: ['none' => true]);
    }
}
