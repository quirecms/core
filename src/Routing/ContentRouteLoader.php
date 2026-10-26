<?php

declare(strict_types=1);

namespace Quire\Routing;

use Quire\Content\Permalink\Permalinks;
use Quire\Http\Controller\ContentController;
use Quire\Http\Controller\LoginController;
use Quire\Http\Controller\PasswordResetController;
use Quire\Http\Controller\RegistrationController;
use Quire\Http\Controller\StorageController;
use Quire\Http\Controller\ThemePreviewController;
use Quire\Http\Controller\ViewAsController;
use Quire\Permission\ViewAs;
use Quire\User\CurrentUser;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads the routes of the homepage and of content URLs, under the "quire.routing.prefix" option.
 *
 * Content URLs come from settings and change at runtime, so one route catches every path and
 * {@see ContentController} resolves it. Both routes have the lowest priority: any other route of the
 * application or an extension matches first, wherever it's imported.
 *
 * @internal
 */
final class ContentRouteLoader extends Loader
{
    public const string TYPE = 'quire_content';

    public const int PRIORITY = -1000;

    private bool $loaded = false;

    public function __construct(
        #[Autowire(param: 'quire.routing.prefix')]
        private readonly string $prefix,
        #[Autowire(param: CurrentUser::LOGIN_PARAMETER)]
        private readonly bool $login,
        #[Autowire(param: 'kernel.environment')]
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \LogicException(\sprintf('Routes of type "%s" are imported twice. Import them only once.', self::TYPE));
        }

        $this->loaded = true;
        $collection = new RouteCollection();

        $collection->add(Permalinks::HOME_ROUTE, new Route(
            path: '' === $this->prefix ? '/' : $this->prefix,
            defaults: ['_controller' => ContentController::class.'::home', ExtensionRouteLoader::QUIRE_ROUTE => true],
            methods: ['GET', 'HEAD'],
        ), self::PRIORITY);

        // Signing in and out: above content URLs, below every route of the application. Not there when
        // the application signs its users in itself.
        if ($this->login) {
            $this->addLoginRoutes($collection);
        }

        // Stored files (see docs/storage.md). The disk names "_signed" can't clash with: disk names start
        // with a letter.
        $collection->add(StorageController::SIGNED_ROUTE, new Route(
            path: $this->prefix.'/storage/_signed/{disk}/{key}',
            defaults: ['_controller' => StorageController::class.'::signed', ExtensionRouteLoader::QUIRE_ROUTE => true],
            requirements: ['disk' => '[a-z][a-z0-9_-]*', 'key' => StorageController::KEY_PATTERN],
            methods: ['GET', 'HEAD'],
        ), self::PRIORITY + 1);
        $collection->add(StorageController::FILE_ROUTE, new Route(
            path: $this->prefix.'/storage/{disk}/{key}',
            defaults: ['_controller' => StorageController::class.'::file', ExtensionRouteLoader::QUIRE_ROUTE => true],
            requirements: ['disk' => '[a-z][a-z0-9_-]*', 'key' => StorageController::KEY_PATTERN],
            methods: ['GET', 'HEAD'],
        ), self::PRIORITY + 1);

        // Seeing the site as another user: stopping is let through while it's on.
        $collection->add(ViewAsController::START_ROUTE, new Route(
            path: $this->prefix.'/_quire/view-as',
            defaults: ['_controller' => ViewAsController::class.'::start', ExtensionRouteLoader::QUIRE_ROUTE => true],
            methods: ['POST'],
        ), self::PRIORITY + 1);
        $collection->add(ViewAsController::STOP_ROUTE, new Route(
            path: $this->prefix.'/_quire/view-as/stop',
            defaults: ['_controller' => ViewAsController::class.'::stop', ExtensionRouteLoader::QUIRE_ROUTE => true, ViewAs::ALLOWED_ROUTE => true],
            methods: ['POST'],
        ), self::PRIORITY + 1);

        // Previewing a theme, only for who looks.
        $collection->add(ThemePreviewController::START_ROUTE, new Route(
            path: $this->prefix.'/_quire/theme-preview',
            defaults: ['_controller' => ThemePreviewController::class.'::start', ExtensionRouteLoader::QUIRE_ROUTE => true],
            methods: ['POST'],
        ), self::PRIORITY + 1);
        $collection->add(ThemePreviewController::STOP_ROUTE, new Route(
            path: $this->prefix.'/_quire/theme-preview/stop',
            defaults: ['_controller' => ThemePreviewController::class.'::stop', ExtensionRouteLoader::QUIRE_ROUTE => true],
            methods: ['POST'],
        ), self::PRIORITY + 1);

        $collection->add(Permalinks::CONTENT_ROUTE, new Route(
            path: $this->prefix.'/{path}',
            defaults: ['_controller' => ContentController::class.'::show', ExtensionRouteLoader::QUIRE_ROUTE => true],
            requirements: ['path' => '.+'],
            methods: ['GET', 'HEAD'],
        ), self::PRIORITY);

        // Rebuild the route cache in development when the prefix changes.
        $collection->addResource(new ContainerParametersResource(['quire.routing.prefix' => $this->prefix, CurrentUser::LOGIN_PARAMETER => $this->login]));

        return $collection;
    }

    private function addLoginRoutes(RouteCollection $collection): void
    {
        $collection->add(LoginController::LOGIN_ROUTE, new Route(
            path: $this->prefix.'/login',
            defaults: ['_controller' => LoginController::class.'::login', ExtensionRouteLoader::QUIRE_ROUTE => true],
            methods: ['GET', 'HEAD', 'POST'],
        ), self::PRIORITY + 1);

        $collection->add(LoginController::LOGOUT_ROUTE, new Route(
            path: $this->prefix.'/logout',
            defaults: ['_controller' => LoginController::class.'::logout', ExtensionRouteLoader::QUIRE_ROUTE => true],
            methods: ['GET', 'POST'],
        ), self::PRIORITY + 1);

        // Accounts: each page answers 404 while its setting is off.
        $accounts = [
            RegistrationController::REGISTER_ROUTE => ['/register', RegistrationController::class.'::register', ['GET', 'HEAD', 'POST'], []],
            RegistrationController::VERIFY_ROUTE => ['/verify-email/{token}', RegistrationController::class.'::verify', ['GET', 'HEAD'], ['token' => '[0-9a-f]{32}\.[A-Za-z0-9_-]+']],
            RegistrationController::RESEND_ROUTE => ['/verify-email', RegistrationController::class.'::resend', ['GET', 'HEAD', 'POST'], []],
            PasswordResetController::FORGOT_ROUTE => ['/forgot-password', PasswordResetController::class.'::forgot', ['GET', 'HEAD', 'POST'], []],
            PasswordResetController::RESET_ROUTE => ['/reset-password/{token}', PasswordResetController::class.'::reset', ['GET', 'HEAD', 'POST'], ['token' => '[0-9a-f]{32}\.[A-Za-z0-9_-]+']],
            PasswordResetController::WELCOME_ROUTE => ['/accept-invitation/{token}', PasswordResetController::class.'::welcome', ['GET', 'HEAD', 'POST'], ['token' => '[0-9a-f]{32}\.[A-Za-z0-9_-]+']],
        ];

        foreach ($accounts as $name => [$path, $controller, $methods, $requirements]) {
            $collection->add($name, new Route(
                path: $this->prefix.$path,
                defaults: ['_controller' => $controller, ExtensionRouteLoader::QUIRE_ROUTE => true, ...(\in_array($name, [PasswordResetController::RESET_ROUTE, PasswordResetController::WELCOME_ROUTE], true) ? ['token' => null] : [])],
                requirements: $requirements,
                methods: $methods,
            ), self::PRIORITY + 1);
        }
    }
}
