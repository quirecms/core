<?php

declare(strict_types=1);

namespace Quire\Theme;

use Quire\Theme\Internal\ThemeLinter;

/**
 * What "quire:theme:lint" finds in a theme, for admin interfaces: its templates compile and render
 * the standard slots, the files they link exist, and the checks of {@see ThemeCheckInterface} (what
 * they load from other sites, a theme's own cookie banner). Only what's written in the templates is
 * seen, not what Twig makes.
 *
 * @api
 */
final readonly class ThemeChecks
{
    public function __construct(private ThemeLinter $linter)
    {
    }

    /**
     * @param bool $inUse Whether it's the theme in use: only then do checks that run the theme's code
     *                    run, so nothing of an installed theme that isn't used runs
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function run(string $id, bool $inUse): array
    {
        $result = $this->linter->lint($id, render: $inUse);

        return ['errors' => $result['errors'], 'warnings' => $result['warnings']];
    }
}
