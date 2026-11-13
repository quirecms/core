<?php

declare(strict_types=1);

namespace Quire\Theme;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A check of a theme, run with Quire's own by "quire:theme:lint" and for the admin's themes page.
 *
 * Checks read templates without rendering them, so they see what's written in them literally: a URL
 * made in Twig ({{ … }}, asset(), a variable) isn't seen. A clean run isn't proof.
 *
 * @api
 */
#[AutoconfigureTag(self::TAG)]
interface ThemeCheckInterface
{
    public const string TAG = 'quire.theme_check';

    /**
     * Whether the check runs the theme's code, e.g. renders one of its templates. The admin runs such
     * checks only for the theme in use, so nothing of an installed theme that isn't used runs there;
     * "quire:theme:lint" runs them for any theme it's asked about.
     */
    public function rendersTheme(): bool;

    /**
     * @param string                $theme     The theme's ID
     * @param array<string, string> $templates Every template the site would use with the theme, by its
     *                                         name (e.g. "extensions/quire_privacy/consent.html.twig"):
     *                                         the theme's, its parents', and Quire's built-in ones
     *
     * @return list<ThemeProblem>
     */
    public function check(string $theme, array $templates): array;
}
