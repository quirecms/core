<?php

declare(strict_types=1);

namespace Quire\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Quire\Extension\DependencyResolver;
use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\ExtensionType;
use Quire\Extension\ResolvedExtensions;

final class DependencyResolverTest extends TestCase
{
    private DependencyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DependencyResolver();
    }

    public function testNothingEnabled(): void
    {
        $result = $this->resolver->resolve($this->installed(['a' => []]), []);

        self::assertSame([], $result->bootOrder);
        self::assertSame([], $result->broken);
        self::assertSame([], $result->missing);
    }

    public function testIndependentExtensionsBootAlphabetically(): void
    {
        $result = $this->resolver->resolve($this->installed(['c' => [], 'a' => [], 'b' => []]), ['c', 'a', 'b']);

        self::assertSame(['a', 'b', 'c'], $result->bootOrderIds());
    }

    public function testRequiredExtensionsBootFirst(): void
    {
        // reviews -> comments -> users-plus; "a-theme" has no dependencies.
        $installed = $this->installed([
            'reviews' => ['requires' => ['comments']],
            'comments' => ['requires' => ['users-plus']],
            'users-plus' => [],
            'a-theme' => [],
        ]);

        $result = $this->resolver->resolve($installed, ['reviews', 'comments', 'users-plus', 'a-theme']);

        self::assertSame(['a-theme', 'users-plus', 'comments', 'reviews'], $result->bootOrderIds());
        self::assertSame(3, $result->position('comments'));
        self::assertNull($result->position('nope'));
    }

    public function testOptionalDependenciesBootFirstOnlyWhenEnabled(): void
    {
        $installed = $this->installed(['a' => ['optional' => ['z']], 'z' => []]);

        self::assertSame(['z', 'a'], $this->resolver->resolve($installed, ['a', 'z'])->bootOrderIds());
        self::assertSame(['a'], $this->resolver->resolve($installed, ['a'])->bootOrderIds());
    }

    public function testOptionalDependenciesOnUninstalledExtensionsAreIgnored(): void
    {
        $installed = $this->installed(['a' => ['optional' => ['not-installed']]]);

        self::assertSame(['a'], $this->resolver->resolve($installed, ['a'])->bootOrderIds());
    }

    public function testCycleOfOptionalDependenciesStillBoots(): void
    {
        $installed = $this->installed(['a' => ['optional' => ['b']], 'b' => ['optional' => ['a']]]);

        $result = $this->resolver->resolve($installed, ['a', 'b']);

        self::assertSame(['a', 'b'], $result->bootOrderIds());
        self::assertSame([], $result->broken);
    }

    public function testOptionalDependencyNeverOverridesARequiredOne(): void
    {
        // b requires a, and a would like b first: a must still boot first.
        $installed = $this->installed(['a' => ['optional' => ['b']], 'b' => ['requires' => ['a']]]);

        self::assertSame(['a', 'b'], $this->resolver->resolve($installed, ['a', 'b'])->bootOrderIds());
    }

    public function testEnabledButUninstalledExtensionsAreReportedAsMissing(): void
    {
        $result = $this->resolver->resolve($this->installed(['a' => []]), ['a', 'gone', 'also-gone']);

        self::assertSame(['a'], $result->bootOrderIds());
        self::assertSame(['also-gone', 'gone'], $result->missing);
    }

    public function testUnmetRequirementsBreakTheDependentChainButNotTheRest(): void
    {
        $installed = $this->installed([
            'reviews' => ['requires' => ['comments']],
            'comments' => ['requires' => ['spam-filter']],
            'spam-filter' => [],
            'blog' => ['requires' => ['not-installed']],
            'seo' => [],
        ]);

        // spam-filter is installed but not enabled.
        $result = $this->resolver->resolve($installed, ['reviews', 'comments', 'blog', 'seo']);

        self::assertSame(['seo'], $result->bootOrderIds());
        self::assertSame([
            'blog' => 'it requires "not-installed", which isn\'t installed',
            'comments' => 'it requires "spam-filter", which isn\'t enabled',
            'reviews' => 'it requires "comments", which can\'t run',
        ], $result->broken);
        self::assertFalse($result->isRunning('comments'));
        self::assertTrue($result->isRunning('seo'));
    }

    public function testRequiredCycleBreaksOnlyItsMembersAndTheirDependents(): void
    {
        $installed = $this->installed([
            'a' => ['requires' => ['b']],
            'b' => ['requires' => ['c']],
            'c' => ['requires' => ['a']],
            'depends-on-cycle' => ['requires' => ['a']],
            'unrelated' => [],
        ]);

        $result = $this->resolver->resolve($installed, ['a', 'b', 'c', 'depends-on-cycle', 'unrelated']);

        self::assertSame(['unrelated'], $result->bootOrderIds());
        self::assertSame([
            'a' => 'it\'s part of a dependency cycle between "a", "b", "c"',
            'b' => 'it\'s part of a dependency cycle between "a", "b", "c"',
            'c' => 'it\'s part of a dependency cycle between "a", "b", "c"',
            'depends-on-cycle' => 'it requires "a", which can\'t run',
        ], $result->broken);
    }

    public function testPlanEnableIncludesMissingRequirementsInBootOrder(): void
    {
        $installed = $this->installed([
            'reviews' => ['requires' => ['comments']],
            'comments' => ['requires' => ['spam-filter']],
            'spam-filter' => [],
        ]);

        $plan = $this->resolver->planEnable($installed, ['spam-filter'], ['reviews']);

        self::assertSame(['comments', 'reviews'], $this->ids($plan));
    }

    public function testPlanEnableOfAnAlreadyEnabledExtensionIsEmpty(): void
    {
        self::assertSame([], $this->resolver->planEnable($this->installed(['a' => []]), ['a'], ['a']));
    }

    public function testPlanEnableRejectsUnknownExtensions(): void
    {
        $this->expectException(UnknownExtensionException::class);
        $this->expectExceptionMessageIsOrContains('No extension with ID "comentss" is installed. Did you mean "comments"?');

        $this->resolver->planEnable($this->installed(['comments' => []]), [], ['comentss']);
    }

    public function testPlanEnableRejectsUninstalledRequirements(): void
    {
        $this->expectException(DependencyException::class);
        $this->expectExceptionMessageIsOrContains('"comments" requires "spam-filter", which isn\'t installed. Install it with Composer first.');

        $this->resolver->planEnable($this->installed(['reviews' => ['requires' => ['comments']], 'comments' => ['requires' => ['spam-filter']]]), [], ['reviews']);
    }

    public function testPlanEnableRejectsCycles(): void
    {
        $this->expectException(DependencyException::class);
        $this->expectExceptionMessageIsOrContains('can\'t be enabled because it\'s part of a dependency cycle between "a", "b"');

        $this->resolver->planEnable($this->installed(['a' => ['requires' => ['b']], 'b' => ['requires' => ['a']]]), [], ['a']);
    }

    public function testPlanDisable(): void
    {
        $installed = $this->installed(['a' => [], 'b' => []]);

        self::assertSame(['a'], $this->resolver->planDisable($installed, ['a', 'b'], ['a']));
        self::assertSame([], $this->resolver->planDisable($installed, ['b'], ['a']), 'Already disabled.');
    }

    public function testPlanDisableIsBlockedByEnabledDependents(): void
    {
        $installed = $this->installed(['reviews' => ['requires' => ['comments']], 'comments' => []]);

        $this->expectException(DependencyException::class);
        $this->expectExceptionMessageIsOrContains('"comments" can\'t be disabled because "reviews" requires it. Disable "reviews" first, or disable both at once.');

        $this->resolver->planDisable($installed, ['reviews', 'comments'], ['comments']);
    }

    public function testPlanDisableAllowsDisablingDependentsTogether(): void
    {
        $installed = $this->installed(['reviews' => ['requires' => ['comments']], 'comments' => []]);

        self::assertSame(['comments', 'reviews'], $this->resolver->planDisable($installed, ['reviews', 'comments'], ['comments', 'reviews']));
    }

    public function testPlanDisableAllowsCleaningUpUninstalledExtensions(): void
    {
        self::assertSame(['gone'], $this->resolver->planDisable($this->installed([]), ['gone'], ['gone']));
    }

    public function testPlanDisableRejectsUnknownExtensions(): void
    {
        $this->expectException(UnknownExtensionException::class);

        $this->resolver->planDisable($this->installed([]), [], ['nope']);
    }

    /**
     * @param array<string, array{requires?: list<string>, optional?: list<string>}> $definitions
     *
     * @return array<string, ExtensionManifest>
     */
    private function installed(array $definitions): array
    {
        $installed = [];

        foreach ($definitions as $id => $definition) {
            $installed[$id] = new ExtensionManifest(
                id: $id,
                packageName: 'acme/'.$id,
                type: ExtensionType::Extension,
                version: '1.0.0',
                path: '/vendor/acme/'.$id,
                title: ucfirst($id),
                requires: $definition['requires'] ?? [],
                optional: $definition['optional'] ?? [],
            );
        }

        return $installed;
    }

    /**
     * @param list<ExtensionManifest> $manifests
     *
     * @return list<string>
     */
    private function ids(array $manifests): array
    {
        return new ResolvedExtensions($manifests)->bootOrderIds();
    }
}
