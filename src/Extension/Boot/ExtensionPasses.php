<?php

declare(strict_types=1);

namespace Quire\Extension\Boot;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Runs the compiler passes that extensions add (Extend\Services::compilerPass()). Extensions boot
 * while the container is already compiling (QuireBundle::prependExtension()), and Symfony takes the
 * list of passes once, when compiling starts: a pass added then would never run. So they're kept
 * here, per container, and one of these per type, added by QuireBundle::build(), runs them in order.
 *
 * @internal
 */
final class ExtensionPasses implements CompilerPassInterface
{
    /** The types of pass an extension may add: none after Quire's checks, before services are removed */
    public const array TYPES = [PassConfig::TYPE_BEFORE_OPTIMIZATION, PassConfig::TYPE_OPTIMIZE, PassConfig::TYPE_BEFORE_REMOVING];

    /** @var \WeakMap<ContainerBuilder, list<array{pass: CompilerPassInterface, type: string, priority: int, order: int}>>|null */
    private static ?\WeakMap $passes = null;

    public function __construct(private readonly string $type)
    {
    }

    /**
     * Keeps an extension's pass for the container being built.
     */
    public static function add(ContainerBuilder $container, CompilerPassInterface $pass, string $type, int $priority): void
    {
        self::$passes ??= new \WeakMap();
        $list = self::$passes[$container] ?? [];
        $list[] = ['pass' => $pass, 'type' => $type, 'priority' => $priority, 'order' => \count($list)];
        self::$passes[$container] = $list;
    }

    /**
     * @return list<CompilerPassInterface> The container's extension passes of a type, in the order they run
     */
    public static function of(ContainerBuilder $container, string $type): array
    {
        $passes = array_values(array_filter(self::$passes[$container] ?? [], static fn (array $pass): bool => $type === $pass['type']));
        // Higher priority first, as Symfony orders them; then in the order extensions added them.
        usort($passes, static fn (array $a, array $b): int => [$b['priority'], $a['order']] <=> [$a['priority'], $b['order']]);

        return array_column($passes, 'pass');
    }

    /**
     * One runner for each type of pass, for QuireBundle::build().
     *
     * @return array<string, self>
     */
    public static function runners(): array
    {
        $runners = [];

        foreach (self::TYPES as $type) {
            $runners[$type] = new self($type);
        }

        return $runners;
    }

    public function process(ContainerBuilder $container): void
    {
        foreach (self::of($container, $this->type) as $pass) {
            $pass->process($container);
        }
    }
}
