<?php

declare(strict_types=1);

namespace Quire\Tests\Extend;

use Quire\Extend\Routes;
use Quire\Tests\Extend\Fixtures\InvokableController;
use Quire\Tests\Extend\Fixtures\NotInvokable;

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
                'type' => 'route', 'extension' => 'acme-reviews', 'name' => 'acme_reviews_index', 'path' => '/reviews',
                'controller' => InvokableController::class, 'methods' => ['GET', 'HEAD'], 'requirements' => [], 'defaults' => [],
            ],
            [
                'type' => 'route', 'extension' => 'acme-reviews', 'name' => 'acme_reviews_store', 'path' => '/reviews',
                'controller' => InvokableController::class.'::store', 'methods' => ['POST'], 'requirements' => [], 'defaults' => [],
            ],
            [
                'type' => 'route', 'extension' => 'acme-reviews', 'name' => 'acme_reviews_show', 'path' => '/reviews/{id}',
                'controller' => InvokableController::class, 'methods' => ['GET'], 'requirements' => ['id' => '\d+'], 'defaults' => ['format' => 'html'],
            ],
        ], $this->container->getParameter(Routes::PARAMETER));

        $controller = $this->container->getDefinition(InvokableController::class);
        self::assertTrue($controller->isPublic());
        self::assertTrue($controller->isAutowired());
        self::assertTrue($controller->hasTag('controller.service_arguments'));
    }

    public function testControllersDirectoryIsRegisteredAndImported(): void
    {
        $this->apply(new Routes()->controllers('Quire\\Tests\\Extend\\Fixtures\\', __DIR__.'/Fixtures', prefix: '/acme'));

        self::assertSame([
            ['type' => 'import', 'extension' => 'acme-reviews', 'resource' => __DIR__.'/Fixtures', 'prefix' => '/acme'],
        ], $this->container->getParameter(Routes::PARAMETER));
        self::assertTrue($this->container->getDefinition(InvokableController::class)->hasTag('controller.service_arguments'));
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
}
