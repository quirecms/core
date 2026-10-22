<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Console;

use Quire\Content\Content;
use Quire\Content\Permalink\PermalinkResolver;
use Quire\Content\Permalink\Redirects;
use Quire\Content\Permalink\Resolution;

/**
 * Checks and adds redirects for the redirect commands.
 *
 * @internal
 */
final readonly class RedirectTargets
{
    public function __construct(
        private Redirects $redirects,
        private PermalinkResolver $resolver,
    ) {
    }

    /**
     * Adds a redirect. A target path that leads to a content item is stored as that item, so the
     * redirect follows it when its URL changes.
     *
     * @throws \InvalidArgumentException when the redirect isn't valid or would never apply
     */
    public function add(string $source, string $target, int $status): void
    {
        $existing = $this->resolver->resolve(Redirects::normalize($source));

        if ($existing instanceof Resolution && Resolution::REDIRECT !== $existing->kind) {
            throw new \InvalidArgumentException(\sprintf('"%s" already shows published content, which takes precedence over redirects. Change that item\'s URL first.', Redirects::normalize($source)));
        }

        $resolved = str_starts_with($target, '/') ? $this->resolver->resolve(Redirects::normalize($target)) : null;

        $this->redirects->add($source, $resolved?->content instanceof Content ? $resolved->content : $target, $status);
    }
}
