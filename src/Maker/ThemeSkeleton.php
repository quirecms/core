<?php

declare(strict_types=1);

namespace Quire\Maker;

use Quire\Extension\ManifestParser;
use Quire\Maker\Internal\SkeletonFiles;

/**
 * Renders the files of a new theme from the templates in resources/skeleton/theme: a child of another
 * theme with a stylesheet of its own, or, without a parent, a theme with templates of its own.
 *
 * @internal
 */
final readonly class ThemeSkeleton
{
    private const string PACKAGE_PATTERN = '#^[a-z][a-z0-9]*(?:[-_.][a-z0-9]+)*/[a-z][a-z0-9]*(?:[-_.][a-z0-9]+)*$#';

    public string $id;

    /** The package name without the vendor, e.g. "dark": the default directory name */
    public string $name;

    public string $title;

    /**
     * @param string|null $parent ID of the theme to inherit from; null for a theme with templates of its own
     *
     * @throws \InvalidArgumentException when the package name isn't valid
     */
    public function __construct(
        public string $package,
        public ?string $parent,
        ?string $title = null,
    ) {
        if (1 !== preg_match(self::PACKAGE_PATTERN, $package)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a valid package name. Use "vendor/name" in lowercase, e.g. "acme/dark".', $package));
        }

        $this->name = explode('/', $package)[1];
        $this->id = ManifestParser::idFromPackageName($package);
        $this->title = $title ?? ManifestParser::titleFromPackageName($package);

        if ($parent === $this->id) {
            throw new \InvalidArgumentException('A theme can\'t inherit from itself.');
        }
    }

    /**
     * @return array<string, string> Relative path => contents
     */
    public function files(): array
    {
        [$vendor] = explode('/', $this->package);
        $studly = static fn (string $name): string => str_replace(' ', '', ucwords((string) preg_replace('/[^a-z0-9]+/', ' ', $name)));
        $replacements = [
            '@@PACKAGE@@' => $this->package,
            '@@NAME@@' => $this->name,
            '@@ID@@' => $this->id,
            '@@TITLE@@' => $this->title,
            '@@PARENT@@' => (string) $this->parent,
            '@@NAMESPACE@@' => $studly($vendor).'\\'.$studly($this->name),
            '@@NAMESPACE_ESCAPED@@' => $studly($vendor).'\\\\'.$studly($this->name),
            '@@THEME_SECTION@@' => null === $this->parent ? '' : ",\n            \"theme\": {\n                \"parent\": \"".$this->parent."\"\n            }",
        ];

        $files = [
            ...SkeletonFiles::render('theme/common', $replacements),
            ...SkeletonFiles::render(null === $this->parent ? 'theme/standalone' : 'theme/child', $replacements),
        ];
        ksort($files);

        return $files;
    }
}
