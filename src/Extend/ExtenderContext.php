<?php

declare(strict_types=1);

namespace Quire\Extend;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * What an extender can see and change while the container is being built.
 *
 * @api
 */
final readonly class ExtenderContext
{
    /** ID used for the site's own extend.php in the project root. */
    public const string SITE = 'site';

    /**
     * @internal Created by Quire for each extension
     *
     * @param string       $id        ID of the extension being loaded, or {@see SITE}
     * @param string       $path      Root directory of the extension (or of the project, for the site)
     * @param list<string> $bootOrder IDs of the extensions that run, in boot order
     */
    public function __construct(
        public string $id,
        public string $path,
        private ContainerBuilder $container,
        private array $bootOrder,
    ) {
    }

    /**
     * Whether an extension is enabled and able to run. Use it for integrations with optional extensions.
     */
    public function isEnabled(string $id): bool
    {
        return \in_array($id, $this->bootOrder, true);
    }

    /**
     * The container being built. An escape hatch for what extenders don't cover; prefer extenders.
     */
    public function container(): ContainerBuilder
    {
        return $this->container;
    }

    /**
     * Returns the service definition for a class, registering it (autowired and autoconfigured) when
     * it doesn't exist yet.
     *
     * @param class-string $class
     */
    public function service(string $class): Definition
    {
        if ($this->container->hasDefinition($class)) {
            return $this->container->getDefinition($class);
        }

        return $this->container->register($class, $class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
    }

    /**
     * Adds configuration for another bundle, e.g. `prependConfig('twig', ['globals' => [...]])`.
     *
     * @param array<string, mixed> $config
     */
    public function prependConfig(string $bundleAlias, array $config): void
    {
        $this->container->prependExtensionConfig($bundleAlias, $config);
    }

    /**
     * Appends an entry to a list parameter shared by all extensions, e.g. the routes they register.
     *
     * @internal Used by Quire's own extenders
     */
    public function appendToParameter(string $name, mixed $value): void
    {
        $current = $this->container->hasParameter($name) ? $this->container->getParameter($name) : [];
        $list = \is_array($current) ? array_values($current) : [];
        $list[] = $value;

        $this->container->setParameter($name, $list);
    }

    /**
     * Creates an exception whose message names this extension and the extender method.
     */
    public function error(string $extender, string $message): ExtenderException
    {
        return new ExtenderException(\sprintf('[%s] %s: %s', $this->id, $extender, $message));
    }
}
