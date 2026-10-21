<?php

declare(strict_types=1);

namespace Quire\Extend;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Adds HTTP routes.
 *
 *     (new Extend\Routes())
 *         ->get('/reviews', 'acme_reviews_index', ReviewsController::class)
 *         ->post('/reviews', 'acme_reviews_store', [ReviewsController::class, 'store'])
 *         ->controllers('Acme\\Reviews\\Controller\\', __DIR__.'/src/Controller');
 *
 * A route with the same name as an existing one replaces it, so extensions can override core routes.
 * Controllers are registered as services automatically.
 *
 * @api
 */
final class Routes implements ExtenderInterface
{
    /** @internal Parameter collecting the routes of every extension */
    public const string PARAMETER = 'quire.extension_routes';

    private const string NAME_PATTERN = '/^[a-zA-Z0-9_.]+$/';

    /**
     * @var list<array{kind: 'route', path: string, name: string, controller: string|array<mixed>, methods: list<string>, requirements: array<string, string>, defaults: array<string, mixed>}|array{kind: 'controllers', namespace: string, directory: string, prefix: string}>
     */
    private array $entries = [];

    /**
     * @param class-string|array{class-string, string} $controller   An invokable controller class, or [class, method]
     * @param array<string, string>                    $requirements
     * @param array<string, mixed>                     $defaults
     */
    public function get(string $path, string $name, string|array $controller, array $requirements = [], array $defaults = []): self
    {
        return $this->add($path, $name, $controller, ['GET', 'HEAD'], $requirements, $defaults);
    }

    /**
     * @param class-string|array{class-string, string} $controller
     * @param array<string, string>                    $requirements
     * @param array<string, mixed>                     $defaults
     */
    public function post(string $path, string $name, string|array $controller, array $requirements = [], array $defaults = []): self
    {
        return $this->add($path, $name, $controller, ['POST'], $requirements, $defaults);
    }

    /**
     * @param class-string|array{class-string, string} $controller
     * @param list<string>                             $methods      Empty for any method
     * @param array<string, string>                    $requirements
     * @param array<string, mixed>                     $defaults
     */
    public function add(string $path, string $name, string|array $controller, array $methods = [], array $requirements = [], array $defaults = []): self
    {
        $this->entries[] = [
            'kind' => 'route',
            'path' => $path,
            'name' => $name,
            'controller' => $controller,
            'methods' => $methods,
            'requirements' => $requirements,
            'defaults' => $defaults,
        ];

        return $this;
    }

    /**
     * Registers every class in a directory as a controller service, and loads the routes declared on
     * them with `#[Route]` attributes.
     *
     * @param string $namespace Namespace of the classes in $directory, ending with a backslash
     */
    public function controllers(string $namespace, string $directory, string $prefix = ''): self
    {
        $this->entries[] = ['kind' => 'controllers', 'namespace' => $namespace, 'directory' => $directory, 'prefix' => $prefix];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->entries as $entry) {
            if ('route' === $entry['kind']) {
                $this->extendRoute($context, $entry);
            } else {
                $this->extendControllers($context, $entry);
            }
        }
    }

    /**
     * @param array{kind: 'route', path: string, name: string, controller: string|array<mixed>, methods: list<string>, requirements: array<string, string>, defaults: array<string, mixed>} $route
     */
    private function extendRoute(ExtenderContext $context, array $route): void
    {
        $where = \sprintf('Extend\Routes::add("%s")', $route['name']);

        if (1 !== preg_match(self::NAME_PATTERN, $route['name'])) {
            throw $context->error($where, 'Route names may only contain letters, digits, underscores and dots, e.g. "acme_reviews_index".');
        }

        if (!str_starts_with($route['path'], '/')) {
            throw $context->error($where, \sprintf('The path "%s" must start with "/".', $route['path']));
        }

        $controller = $route['controller'];
        $class = \is_array($controller) ? ($controller[0] ?? null) : $controller;
        $method = \is_array($controller) ? ($controller[1] ?? null) : '__invoke';

        if (!\is_string($class) || !class_exists($class)) {
            throw $context->error($where, \sprintf('The controller class "%s" doesn\'t exist. Check the class name and that your package\'s Composer autoload covers it.', \is_string($class) ? $class : get_debug_type($class)));
        }

        if (!\is_string($method) || !method_exists($class, $method)) {
            throw $context->error($where, '__invoke' === $method ? \sprintf('The controller "%s" has no __invoke() method. Add one, or pass [%1$s::class, \'methodName\'].', $class) : \sprintf('The controller "%s" has no method "%s".', $class, \is_string($method) ? $method : get_debug_type($method)));
        }

        $definition = $context->service($class)->setPublic(true);

        if (!$definition->hasTag('controller.service_arguments')) {
            $definition->addTag('controller.service_arguments');
        }

        $context->appendToParameter(self::PARAMETER, [
            'type' => 'route',
            'extension' => $context->id,
            'name' => $route['name'],
            'path' => $route['path'],
            'controller' => '__invoke' === $method ? $class : $class.'::'.$method,
            'methods' => array_map(strtoupper(...), $route['methods']),
            'requirements' => $route['requirements'],
            'defaults' => $route['defaults'],
        ]);
    }

    /**
     * @param array{kind: 'controllers', namespace: string, directory: string, prefix: string} $controllers
     */
    private function extendControllers(ExtenderContext $context, array $controllers): void
    {
        $where = 'Extend\Routes::controllers()';

        if (!str_ends_with($controllers['namespace'], '\\')) {
            throw $context->error($where, \sprintf('The namespace "%s" must end with a backslash.', $controllers['namespace']));
        }

        if (!is_dir($controllers['directory'])) {
            throw $context->error($where, \sprintf('The directory "%s" doesn\'t exist.', $controllers['directory']));
        }

        $prototype = new Definition()
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true)
            ->addTag('controller.service_arguments');

        $loader = new PhpFileLoader($context->container(), new FileLocator($controllers['directory']));
        $loader->registerClasses($prototype, $controllers['namespace'], rtrim($controllers['directory'], '/').'/');

        $context->appendToParameter(self::PARAMETER, [
            'type' => 'import',
            'extension' => $context->id,
            'resource' => $controllers['directory'],
            'prefix' => $controllers['prefix'],
        ]);
    }
}
