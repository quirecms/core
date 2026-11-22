<?php

declare(strict_types=1);

namespace Quire\Translation\Internal;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Puts the loaded language artifacts' files into the translator, file by file (never their directories, so
 * nothing else of theirs is read), where they rank: over the packages' own texts, under the themes' and the
 * site's. The translator loads each language's files in order, and a later one wins.
 *
 * @internal
 */
final class TranslationArtifactsPass implements CompilerPassInterface
{
    /** list<array{name, package, locale, status, reason, files}>, set by the extension booter */
    public const string ARTIFACTS = 'quire.translation.artifacts';

    /** The themes' translations/ directories, set by the extension booter */
    public const string OVERRIDE_DIRS = 'quire.translation.override_dirs';

    public function process(ContainerBuilder $container): void
    {
        $bag = $container->getParameterBag();
        $parameters = $bag->all();
        $artifacts = $parameters[self::ARTIFACTS] ?? null;

        if (!$container->hasDefinition('translator.default') || !\is_array($artifacts)) {
            return;
        }

        /** @var list<array{locale: ?string, status: string, files: list<string>}> $artifacts */
        $added = [];

        foreach ($artifacts as $artifact) {
            if ('loaded' === $artifact['status'] && null !== $artifact['locale']) {
                foreach ($artifact['files'] as $file) {
                    $added[$artifact['locale']][] = $file;
                }
            }
        }

        if ([] === $added) {
            return;
        }

        /** @var list<string> $overrides */
        $overrides = \is_array($parameters[self::OVERRIDE_DIRS] ?? null) ? $parameters[self::OVERRIDE_DIRS] : [];
        $sitePath = $bag->unescapeValue($bag->resolveValue($parameters['translator.default_path'] ?? null));

        if (\is_string($sitePath)) {
            $overrides[] = $sitePath;
        }

        $overrides = array_values(array_filter($overrides, \is_string(...)));
        $unescape = static fn (string $file): string => \is_string($path = $bag->unescapeValue($file)) ? $path : $file;
        $escape = static fn (string $file): string => \is_string($escaped = $bag->escapeValue($file)) ? $escaped : $file;

        $translator = $container->getDefinition('translator.default');
        /** @var array{resource_files?: array<string, list<string>>, cache_vary?: array<string, mixed>} $options */
        $options = $translator->getArgument(4);
        $files = $options['resource_files'] ?? [];

        foreach ($added as $locale => $artifactFiles) {
            $files[$locale] = self::insert($files[$locale] ?? [], array_map($escape, $artifactFiles), $overrides, $unescape);
        }

        $options['resource_files'] = $files;
        // Another set of artifacts is another catalogue
        $options['cache_vary']['quire_artifacts'] = $bag->escapeValue(array_merge(...array_values($added)));
        $translator->replaceArgument(4, $options);

        $container->register('quire.translation.artifact_loader', ArtifactLoader::class)
            ->setDecoratedService('translation.loader.yml')
            ->setArguments([new Reference('.inner'), $bag->escapeValue(array_merge(...array_values($added))), new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]);
    }

    /**
     * The artifact's files go before the first file of a theme or the site.
     *
     * @param list<string>            $files
     * @param list<string>            $artifactFiles
     * @param list<string>            $overrides
     * @param callable(string): mixed $unescape
     *
     * @return list<string>
     */
    public static function insert(array $files, array $artifactFiles, array $overrides, callable $unescape): array
    {
        $at = \count($files);

        foreach ($files as $i => $file) {
            $path = $unescape($file);

            if (!\is_string($path)) {
                continue;
            }

            foreach ($overrides as $dir) {
                if (str_starts_with($path, rtrim($dir, '/\\').\DIRECTORY_SEPARATOR)) {
                    $at = $i;

                    break 2;
                }
            }
        }

        array_splice($files, $at, 0, $artifactFiles);

        return $files;
    }
}
