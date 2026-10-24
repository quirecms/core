<?php

declare(strict_types=1);

namespace Quire\Content\Check;

use Quire\Content\Content;
use Quire\Extend\ContentChecks;
use Quire\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Runs the content checks of {@see ContentChecks} on an item, at the levels the site gives them.
 *
 * @api
 */
final readonly class ContentChecker
{
    /** Setting: the site's levels of checks, e.g. {"core.alt-text": "error", "core.excerpt": "off"} */
    public const string LEVELS_SETTING = 'quire.content.check_levels';

    /**
     * @param list<array{id: string, class: string, label: string, level: string, types: ?list<string>, owner: string}> $checks
     * @param ServiceLocator<ContentCheckInterface>                                                                     $services
     */
    public function __construct(
        #[Autowire(param: ContentChecks::PARAMETER)]
        private array $checks,
        #[AutowireLocator(ContentChecks::TAG)]
        private ServiceLocator $services,
        private Settings $settings,
    ) {
    }

    public function check(Content $item): CheckReport
    {
        $results = [];

        foreach ($this->checks($item->type) as $check) {
            if (null === $check['level']) {
                continue;
            }

            foreach ($this->services->get($check['class'])->check($item) as $finding) {
                $results[] = new CheckResult($check['id'], $check['label'], $check['level'], $finding);
            }
        }

        usort($results, static fn (CheckResult $a, CheckResult $b): int => (Level::Error === $a->level ? 0 : 1) <=> (Level::Error === $b->level ? 0 : 1));

        return new CheckReport($results);
    }

    /**
     * The checks, with the level the site gives each (null when it's off).
     *
     * @param string|null $type Only those of this content type
     *
     * @return list<array{id: string, class: string, label: string, level: ?Level, default: Level, types: ?list<string>, owner: string}>
     */
    public function checks(?string $type = null): array
    {
        $levels = $this->settings->get(self::LEVELS_SETTING);
        $levels = \is_array($levels) ? $levels : [];
        $checks = [];

        foreach ($this->checks as $check) {
            if (null !== $type && null !== $check['types'] && !\in_array($type, $check['types'], true)) {
                continue;
            }

            $default = Level::from($check['level']);
            $configured = $levels[$check['id']] ?? null;
            $level = 'off' === $configured ? null : (\is_string($configured) ? Level::tryFrom($configured) ?? $default : $default);
            $checks[] = [...$check, 'level' => $level, 'default' => $default];
        }

        return $checks;
    }
}
