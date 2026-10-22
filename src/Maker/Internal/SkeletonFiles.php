<?php

declare(strict_types=1);

namespace Quire\Maker\Internal;

/**
 * Renders the files of a skeleton directory in resources/skeleton/.
 *
 * @internal
 */
final class SkeletonFiles
{
    /**
     * @param string                $directory    Under resources/skeleton/, e.g. "extension"
     * @param array<string, string> $replacements Placeholder => value, e.g. "@@ID@@" => "acme-reviews"
     * @param array<string, string> $renames      Part of a file name => replacement
     *
     * @return array<string, string> Relative path => contents
     */
    public static function render(string $directory, array $replacements, array $renames = []): array
    {
        $root = \dirname(__DIR__, 3).'/resources/skeleton/'.$directory;
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            // Templates end in ".tpl", so tools don't analyse them as code.
            $relative = strtr(substr($file->getPathname(), \strlen($root) + 1, -\strlen('.tpl')), $renames);
            $files[$relative] = strtr((string) file_get_contents($file->getPathname()), $replacements);
        }

        ksort($files);

        return $files;
    }
}
