<?php

declare(strict_types=1);

namespace Quire\Maker;

use Quire\Extension\ManifestParser;

/**
 * Renders the files of a new extension from the templates in resources/skeleton/extension.
 *
 * @internal
 */
final readonly class ExtensionSkeleton
{
    private const string PACKAGE_PATTERN = '#^[a-z][a-z0-9]*(?:[-_.][a-z0-9]+)*/[a-z][a-z0-9]*(?:[-_.][a-z0-9]+)*$#';

    public string $id;

    /** The package name without the vendor, e.g. "reviews": the default directory name */
    public string $name;

    public string $namespace;

    public string $title;

    /** URL path of the generated page, e.g. "/hello-world" */
    public string $path;

    /** Database table of the generated migration */
    public string $table;

    private string $snakeId;

    /**
     * @throws \InvalidArgumentException when the package name isn't valid
     */
    public function __construct(
        public string $package,
        ?string $title = null,
        private ?\DateTimeImmutable $now = null,
    ) {
        if (1 !== preg_match(self::PACKAGE_PATTERN, $package)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid package name. Use "vendor/name" in lowercase, e.g. "acme/reviews".', $package));
        }

        [$vendor, $name] = explode('/', $package);

        $this->name = $name;
        $this->id = ManifestParser::idFromPackageName($package);
        $this->snakeId = str_replace('-', '_', $this->id);
        $this->namespace = $this->studly($vendor).'\\'.$this->studly($name);
        $this->title = $title ?? ManifestParser::titleFromPackageName($package);
        $this->path = '/'.trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');
        $this->table = $this->snakeId.'_message';
    }

    /**
     * @return array<string, string> Relative path => contents
     */
    public function files(): array
    {
        $version = 'Version'.($this->now ?? new \DateTimeImmutable())->format('YmdHis');
        $replacements = [
            '@@PACKAGE@@' => $this->package,
            '@@NAME@@' => $this->name,
            '@@ID@@' => $this->id,
            '@@TITLE@@' => $this->title,
            '@@NAMESPACE@@' => $this->namespace,
            '@@NAMESPACE_ESCAPED@@' => str_replace('\\', '\\\\', $this->namespace),
            '@@PATH@@' => $this->path,
            '@@ROUTE@@' => $this->snakeId,
            '@@TWIG_NAMESPACE@@' => $this->snakeId,
            '@@TABLE@@' => $this->table,
            '@@VERSION@@' => $version,
        ];

        $root = \dirname(__DIR__, 2).'/resources/skeleton/extension';
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            // Templates end in ".tpl", so tools don't analyse them as code.
            $relative = substr($file->getPathname(), \strlen($root) + 1, -\strlen('.tpl'));
            $relative = str_replace('Version.php', $version.'.php', $relative);
            $files[$relative] = strtr((string) file_get_contents($file->getPathname()), $replacements);
        }

        ksort($files);

        return $files;
    }

    private function studly(string $name): string
    {
        return str_replace(' ', '', ucwords((string) preg_replace('/[^a-z0-9]+/', ' ', $name)));
    }
}
