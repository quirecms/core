<?php

declare(strict_types=1);

namespace Quire\Extension\Boot;

use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderException;
use Quire\Extend\ExtenderInterface;
use Quire\Extend\Routes;
use Quire\Extension\DependencyResolver;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\ResolvedExtensions;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Runs the extenders of the enabled extensions, then of the site, while the container is being built.
 *
 * @internal
 */
final readonly class ExtensionBooter
{
    public const string BOOT_ORDER_PARAMETER = 'quire.extensions.boot_order';

    public const string FILES_PARAMETER = 'quire.extensions.extend_files';

    public function __construct(
        private InstalledExtensions $installed,
        private DependencyResolver $resolver = new DependencyResolver(),
    ) {
    }

    /**
     * @param list<string> $enabledIds
     * @param string       $siteExtendFile The project's own extend.php, which runs last
     */
    public function boot(ContainerBuilder $container, array $enabledIds, string $siteExtendFile): ResolvedExtensions
    {
        $resolved = $this->resolver->resolve($this->installed->all(), $enabledIds);
        $bootOrder = $resolved->bootOrderIds();
        $files = [];

        $container->setParameter(Routes::PARAMETER, []);

        foreach ($resolved->bootOrder as $manifest) {
            $file = $manifest->path.\DIRECTORY_SEPARATOR.'extend.php';
            $files[] = $file;

            if (is_file($file)) {
                $container->addResource(new FileResource($file));
                $this->run(new ExtenderContext($manifest->id, $manifest->path, $container, $bootOrder), $file);
            } else {
                $container->addResource(new FileExistenceResource($file));
            }
        }

        $files[] = $siteExtendFile;
        $container->addResource(new FileExistenceResource($siteExtendFile));

        if (is_file($siteExtendFile)) {
            $container->addResource(new FileResource($siteExtendFile));
            $this->run(new ExtenderContext(ExtenderContext::SITE, \dirname($siteExtendFile), $container, $bootOrder), $siteExtendFile);
        }

        $container->setParameter(self::BOOT_ORDER_PARAMETER, $bootOrder);
        $container->setParameter(self::FILES_PARAMETER, $files);

        return $resolved;
    }

    private function run(ExtenderContext $context, string $file): void
    {
        try {
            $extenders = (static fn (string $file): mixed => require $file)($file);
        } catch (ExtenderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExtenderException(\sprintf('[%s] Loading "%s" failed: %s', $context->id, $file, $e->getMessage()), $e->getCode(), previous: $e);
        }

        if (!is_iterable($extenders)) {
            throw new ExtenderException(\sprintf('[%s] "%s" must return a list of extenders, e.g. "return [new Extend\Routes()];", but it returned %s.', $context->id, $file, get_debug_type($extenders)));
        }

        $position = 0;

        foreach ($extenders as $extender) {
            ++$position;

            if (!$extender instanceof ExtenderInterface) {
                throw new ExtenderException(\sprintf('[%s] Item #%d returned by "%s" is %s; expected an extender such as new Quire\Extend\Routes().', $context->id, $position, $file, get_debug_type($extender)));
            }

            try {
                $extender->extend($context);
            } catch (ExtenderException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new ExtenderException(\sprintf('[%s] %s (item #%d in "%s") failed: %s', $context->id, $extender::class, $position, $file, $e->getMessage()), $e->getCode(), previous: $e);
            }
        }
    }
}
