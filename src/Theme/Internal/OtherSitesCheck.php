<?php

declare(strict_types=1);

namespace Quire\Theme\Internal;

use Quire\Csp\Csp;
use Quire\Csp\Policy;
use Quire\Csp\PolicyKind;
use Quire\Theme\TemplateUrls;
use Quire\Theme\ThemeCheckInterface;
use Quire\Theme\ThemeProblem;

/**
 * What a theme's templates load from other sites that the site's Content-Security-Policy doesn't
 * allow, so browsers block it: a host belongs in a service of quire/privacy (which asks visitors
 * first) or in the site's "quire.csp.sources".
 *
 * @internal
 */
final readonly class OtherSitesCheck implements ThemeCheckInterface
{
    public function __construct(private Csp $csp)
    {
    }

    public function rendersTheme(): bool
    {
        return false;
    }

    public function check(string $theme, array $templates): array
    {
        $policy = $this->csp->policy(PolicyKind::Page);
        $problems = [];

        foreach ($templates as $name => $file) {
            foreach (TemplateUrls::find((string) file_get_contents($file)) as $found) {
                $directive = $policy->has($found['directive']) ? $found['directive'] : 'default-src';
                $allowed = array_any($policy->sources($directive), static fn (string $source): bool => Policy::allows($source, $found['url']));

                if (!$allowed) {
                    $problems[] = ThemeProblem::warning(\sprintf('%s line %d: loads %s, which the site\'s Content-Security-Policy blocks (%s). If visitors must be asked first (analytics, fonts, videos), declare the service through quire/privacy; otherwise add the host to "quire.csp.sources", or serve the file from the theme. See "Content-Security-Policy" in docs/themes.md.', $name, $found['line'], $found['url'], $directive));
                }
            }
        }

        return $problems;
    }
}
