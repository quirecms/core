<?php

declare(strict_types=1);

namespace Quire\Extend;

/**
 * Applies extenders only when a condition holds, e.g. to integrate with an optional extension.
 *
 *     (new Extend\Conditional())
 *         ->whenExtensionEnabled('acme-seo', fn () => [
 *             (new Extend\Events())->listen(SeoMetadataCollected::class, AddReviewRating::class),
 *         ]);
 *
 * Declare such extensions under "optional" in your manifest, so they load before yours.
 *
 * @api
 */
final class Conditional implements ExtenderInterface
{
    /** @var list<array{condition: \Closure(ExtenderContext): bool, extenders: \Closure(): iterable<mixed>}> */
    private array $branches = [];

    /**
     * @param callable(): iterable<ExtenderInterface> $extenders Called only when the extension is enabled
     */
    public function whenExtensionEnabled(string $id, callable $extenders): self
    {
        return $this->add(static fn (ExtenderContext $context): bool => $context->isEnabled($id), $extenders);
    }

    /**
     * @param bool|callable(ExtenderContext): bool    $condition
     * @param callable(): iterable<ExtenderInterface> $extenders Called only when the condition holds
     */
    public function when(bool|callable $condition, callable $extenders): self
    {
        return $this->add(\is_bool($condition) ? static fn (): bool => $condition : $condition(...), $extenders);
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->branches as $branch) {
            if (!($branch['condition'])($context)) {
                continue;
            }

            $position = 0;

            foreach (($branch['extenders'])() as $extender) {
                ++$position;

                if (!$extender instanceof ExtenderInterface) {
                    throw $context->error('Extend\Conditional', \sprintf('Item #%d returned by the callback is %s; expected an extender such as new Extend\Routes().', $position, get_debug_type($extender)));
                }

                $extender->extend($context);
            }
        }
    }

    /**
     * @param callable(ExtenderContext): bool         $condition
     * @param callable(): iterable<ExtenderInterface> $extenders
     */
    private function add(callable $condition, callable $extenders): self
    {
        $this->branches[] = ['condition' => $condition(...), 'extenders' => $extenders(...)];

        return $this;
    }
}
