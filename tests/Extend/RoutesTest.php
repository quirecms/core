<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\Routes;
use Quire\Extend\Storage;
use Quire\QuireBundle;
use Quire\Routing\ExtensionRouteLoader;
use Quire\Tests\Extend\Fixtures\InvokableController;
use Quire\Tests\Extend\Fixtures\NotInvokable;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class RoutesTest extends ExtenderTestCase
{
    public function testRegistersRoutesAndControllerServices(): void
    {
        $this->apply(new Routes()
            ->get('/reviews', 'acme_reviews_index', InvokableController::class)
            ->post('/reviews', 'acme_reviews_store', [InvokableController::class, 'store'])
            ->add('/reviews/{id}', 'acme_reviews_show', InvokableController::class, ['get'], ['id' => '\d+'], ['format' => 'html']));

        self::assertSame([
            [
                'type' => 'route', 'extension' => 'acme-reviews', 'group' => 'site', 'name' => 'acme_reviews_index', 'path' => '/reviews',
                'controller' => InvokableController::class, 'methods' => ['GET', 'HEAD'], 'requirements' => [], 'defaults' => [],
            ],
            [
                'type' => 'route', 'extension' => 'acme-reviews', 'group' => 'site', 'name' => 'acme_reviews_store', 'path' => '/reviews',
                'controller' => InvokableController::class.'::store', 'methods' => ['POST'], 'requirements' => [], 'defaults' => [],
            ],
            [
                'type' => 'route', 'extension' => 'acme-reviews', 'group' => 'site', 'name' => 'acme_reviews_show', 'path' => '/reviews/{id}',
                'controller' => InvokableController::class, 'methods' => ['GET'], 'requirements' => ['id' => '\d+'], 'defaults' => ['format' => 'html'],
            ],
        ], $this->parameter(Routes::PARAMETER));

        $controller = $this->container->getDefinition(InvokableController::class);
        self::assertTrue($controller->isPublic());
        self::assertTrue($controller->isAutowired());
        self::assertTrue($controller->hasTag('controller.service_arguments'));
    }

    public function testControllersDirectoryIsRegisteredAndImported(): void
    {
        $this->apply(new Routes()->controllers('Quire\\Tests\\Extend\\Fixtures\\', __DIR__.'/Fixtures', prefix: '/acme'));

        self::assertSame([
            ['type' => 'import', 'extension' => 'acme-reviews', 'group' => 'site', 'resource' => __DIR__.'/Fixtures', 'prefix' => '/acme'],
        ], $this->parameter(Routes::PARAMETER));
        self::assertTrue($this->container->getDefinition(InvokableController::class)->hasTag('controller.service_arguments'));
    }

    public function testRoutesOfTheAdminGroup(): void
    {
        $this->apply(new Routes(group: Routes::ADMIN)->get('/reviews', 'acme_reviews_admin', InvokableController::class));

        /** @var list<array<string, mixed>> $routes */
        $routes = $this->parameter(Routes::PARAMETER);
        self::assertSame(['admin', '/reviews'], [$routes[0]['group'], $routes[0]['path']], 'The admin\'s path is added when routes load, so it can be configured.');

        $this->assertExtenderFails(new Routes(group: 'api')->get('/reviews', 'acme_reviews_api', InvokableController::class), 'Unknown group "api". Use "site" or "admin".');
    }

    public function testTheAdminsPathIsConfigured(): void
    {
        self::assertSame('/admin', $this->adminPrefix([]));
        self::assertSame('/cms-admin', $this->adminPrefix(['admin_prefix' => '/cms-admin']));

        foreach (['', 'admin', '/admin/', '/Admin'] as $invalid) {
            try {
                $this->adminPrefix(['admin_prefix' => $invalid]);
                self::fail(\sprintf('"%s" was accepted.', $invalid));
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('"quire.routing.admin_prefix" must be a path such as "/admin"', $e->getMessage());
            }
        }
    }

    public function testInvalidRouteName(): void
    {
        $this->assertExtenderFails(new Routes()->get('/reviews', 'acme reviews', InvokableController::class), 'Extend\Routes::add("acme reviews"): Route names may only contain');
    }

    public function testPathMustStartWithSlash(): void
    {
        $this->assertExtenderFails(new Routes()->get('reviews', 'acme_reviews', InvokableController::class), 'The path "reviews" must start with "/".');
    }

    public function testUnknownControllerClass(): void
    {
        $this->assertExtenderFails(
            new Routes()->get('/reviews', 'acme_reviews', 'Acme\\Reviews\\MissingController'), // @phpstan-ignore argument.type (deliberately not a class)
            'The controller class "Acme\\Reviews\\MissingController" doesn\'t exist.',
        );
    }

    public function testControllerWithoutInvoke(): void
    {
        $this->assertExtenderFails(new Routes()->get('/reviews', 'acme_reviews', NotInvokable::class), 'has no __invoke() method');
    }

    public function testUnknownControllerMethod(): void
    {
        $this->assertExtenderFails(new Routes()->get('/reviews', 'acme_reviews', [InvokableController::class, 'nope']), 'has no method "nope"');
    }

    public function testControllersNamespaceMustEndWithBackslash(): void
    {
        $this->assertExtenderFails(new Routes()->controllers('Acme\\Reviews', __DIR__), 'must end with a backslash');
    }

    public function testControllersDirectoryMustExist(): void
    {
        $this->assertExtenderFails(new Routes()->controllers('Acme\\Reviews\\', __DIR__.'/missing'), 'doesn\'t exist');
    }

    /**
     * @param array<string, string> $routing
     */
    private function adminPrefix(array $routing): string
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', '/srv/site');
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.debug', false);
        $builder->setParameter(Storage::PARAMETER, []);

        $extension = new QuireBundle()->getContainerExtension();
        \assert($extension instanceof ExtensionInterface);
        $extension->load([['routing' => $routing]], $builder);
        $prefix = $builder->getParameterBag()->all()[ExtensionRouteLoader::ADMIN_PREFIX_PARAMETER] ?? null;

        return \is_string($prefix) ? $prefix : '';
    }
}
