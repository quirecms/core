<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Head\HeadContributorInterface;

/**
 * Adds tags to the <head> of the site's pages, the ones `{{ quire_head() }}` prints: meta tags, links
 * and structured data.
 *
 *     (new Extend\Head())
 *         ->contributor(VerificationTags::class);
 *
 * Contributors run in order of priority, lowest first, so one with a higher priority can change or
 * remove what an earlier one set. Quire's own tags (description, canonical URL, Open Graph, structured
 * data) run at priority -100, so the default 0 comes after them.
 *
 * @api
 */
final class Head implements ExtenderInterface
{
    /** @internal Parameter: list<{class, priority, owner}> */
    public const string PARAMETER = 'quire.head.contributors';

    /** @internal Tag of the contributors' services */
    public const string TAG = 'quire.head_contributor';

    /** @var list<array{class: string, priority: int}> */
    private array $contributors = [];

    /**
     * @param class-string<HeadContributorInterface> $class A service
     */
    public function contributor(string $class, int $priority = 0): self
    {
        $this->contributors[] = ['class' => $class, 'priority' => $priority];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        /** @var list<array{class: string, owner: string}> $registered */
        $registered = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];
        $taken = array_column($registered, 'owner', 'class');

        foreach ($this->contributors as $contributor) {
            $where = \sprintf('Extend\Head::contributor("%s")', $contributor['class']);

            $error = match (true) {
                !class_exists($contributor['class']) || !is_a($contributor['class'], HeadContributorInterface::class, true) => \sprintf('Head contributors are classes implementing %s.', HeadContributorInterface::class),
                isset($taken[$contributor['class']]) => \sprintf('The contributor is already added by "%s".', $taken[$contributor['class']]),
                default => null,
            };

            if (null !== $error) {
                throw $context->error($where, $error);
            }

            $taken[$contributor['class']] = $context->id;
            $context->service($contributor['class'])->addTag(self::TAG);
            $context->appendToParameter(self::PARAMETER, [...$contributor, 'owner' => $context->id]);
        }
    }
}
