<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Content\Check\ContentCheckInterface;
use Quire\Content\Check\Level;

/**
 * Adds checks of content before it's published: e.g. that images have a description, or that a product
 * has a price.
 *
 *     new Extend\ContentChecks()
 *         ->check('acme-shop.price', PriceCheck::class, 'Products have a price', level: Level::Error, types: ['product']),
 *
 * Errors block publishing; warnings are shown while editing. The site can change a check's level, or turn
 * it off, with the setting "quire.content.check_levels", e.g. {"acme-shop.price": "warning"}.
 *
 * @api
 */
final class ContentChecks implements ExtenderInterface
{
    /** @internal Parameter: list<{id, class, label, level, types, owner}> */
    public const string PARAMETER = 'quire.content.checks';

    /** @internal Tag of the checks' services */
    public const string TAG = 'quire.content_check';

    private const string ID_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

    /** @var list<array{id: string, class: string, label: string, level: Level, types: ?list<string>}> */
    private array $checks = [];

    /**
     * @param string                              $id    Starts with your extension's ID and a dot, e.g. "acme-shop.price"
     * @param class-string<ContentCheckInterface> $class A service
     * @param string                              $label What it checks, e.g. "Products have a price"
     * @param list<string>|null                   $types The content types it checks; null for all
     */
    public function check(string $id, string $class, string $label, Level $level = Level::Warning, ?array $types = null): self
    {
        $this->checks[] = ['id' => $id, 'class' => $class, 'label' => $label, 'level' => $level, 'types' => $types];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        /** @var list<array{id: string, owner: string}> $registered */
        $registered = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];
        $taken = array_column($registered, 'owner', 'id');
        $prefix = ExtenderContext::SITE === $context->id ? 'custom.' : $context->id.'.';

        foreach ($this->checks as $check) {
            $where = \sprintf('Extend\ContentChecks::check("%s")', $check['id']);

            $error = match (true) {
                1 !== preg_match(self::ID_PATTERN, $check['id']) || !str_starts_with($check['id'], $prefix) => \sprintf('Check IDs start with "%s", followed by lowercase letters, digits, hyphens and underscores, e.g. "%salt-text".', $prefix, $prefix),
                isset($taken[$check['id']]) => \sprintf('The check is already added by "%s".', $taken[$check['id']]),
                !class_exists($check['class']) || !is_a($check['class'], ContentCheckInterface::class, true) => \sprintf('Content checks are classes implementing %s.', ContentCheckInterface::class),
                [] === $check['types'] => 'Give the content types as a list of IDs, e.g. ["post"], or null for all types.',
                default => null,
            };

            if (null !== $error) {
                throw $context->error($where, $error);
            }

            $taken[$check['id']] = $context->id;
            $context->service($check['class'])->addTag(self::TAG);
            $context->appendToParameter(self::PARAMETER, [...$check, 'level' => $check['level']->value, 'owner' => $context->id]);
        }
    }
}
