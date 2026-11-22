<?php

declare(strict_types=1);

namespace Quire\Translation;

use Quire\Extension\ExtensionManifest;
use Quire\Extension\ManifestParser;

/**
 * Finds the language artifacts among the installed packages and decides each one: loaded, or ignored with
 * why. An artifact is data: one carrying code, or any file but its own plain texts, is ignored whole. It's
 * loaded only for a package that says its texts come from the hub, on the version line it's made for, in
 * that package's domains, and only when no other artifact translates the same package into the same
 * language (until artifacts are signed, a second one could be anybody's).
 *
 * @internal
 */
final readonly class TranslationArtifacts
{
    public function __construct(
        private ManifestParser $parser = new ManifestParser(),
    ) {
    }

    /**
     * @param list<array<mixed>> $packages          The entries of Composer's installed.json
     * @param string             $vendorComposerDir Where installed.json is; install paths are relative to it
     *
     * @return list<ArtifactStatus>
     */
    public function read(array $packages, string $vendorComposerDir): array
    {
        $byName = [];

        foreach ($packages as $package) {
            if (\is_string($package['name'] ?? null)) {
                $byName[$package['name']] = $package;
            }
        }

        $statuses = [];

        foreach ($byName as $name => $package) {
            if (TranslationArtifact::TYPE === ($package['type'] ?? null)) {
                $statuses[] = $this->decide($name, $package, $byName, $vendorComposerDir);
            }
        }

        return $this->oneEach($statuses);
    }

    /**
     * @param array<mixed>                $package
     * @param array<string, array<mixed>> $installed
     */
    private function decide(string $name, array $package, array $installed, string $vendorComposerDir): ArtifactStatus
    {
        $extra = \is_array($package['extra'] ?? null) ? $package['extra'] : [];
        $quire = \is_array($extra['quire'] ?? null) ? $extra['quire'] : [];

        try {
            $artifact = TranslationArtifact::fromManifest($name, $quire['translation'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return new ArtifactStatus($name, null, null, false, 'Its manifest isn\'t one: '.$e->getMessage());
        }

        $ignored = static fn (string $reason): ArtifactStatus => new ArtifactStatus($name, $artifact->package, $artifact->locale, false, $reason);

        foreach (['autoload', 'autoload-dev', 'bin', 'scripts'] as $key) {
            if ([] !== ($package[$key] ?? []) && null !== ($package[$key] ?? null)) {
                return $ignored(\sprintf('It carries code ("%s"); an artifact is texts only.', $key));
            }
        }

        $target = $installed[$artifact->package] ?? null;

        if (null === $target) {
            return $ignored(\sprintf('%s isn\'t installed.', $artifact->package));
        }

        try {
            $manifest = $this->parser->parse($target, $vendorComposerDir);
        } catch (\Throwable) {
            $manifest = null;
        }

        if (!$manifest instanceof ExtensionManifest) {
            return $ignored(\sprintf('%s isn\'t a Quire extension or theme whose texts come from the hub.', $artifact->package));
        }

        $declared = $manifest->translations();

        if (TranslationDelivery::Hub !== $declared->delivery) {
            return $ignored(\sprintf('%s ships its own translations ("delivery": "bundled").', $artifact->package));
        }

        $line = VersionLine::ofPackage($target);

        if ($line !== $artifact->line) {
            return $ignored(null === $line
                ? \sprintf('%s is installed at %s, which has no version line.', $artifact->package, $manifest->version)
                : \sprintf('It\'s for %s %s; %s is installed.', $artifact->package, $artifact->line, $manifest->version));
        }

        $foreign = array_values(array_diff($artifact->domains, $declared->domains));

        if ([] !== $foreign) {
            return $ignored(\sprintf('%s has no domain "%s".', $artifact->package, $foreign[0]));
        }

        try {
            $files = $this->files($package, $artifact, $vendorComposerDir);
        } catch (\InvalidArgumentException $e) {
            return $ignored($e->getMessage());
        }

        return new ArtifactStatus($name, $artifact->package, $artifact->locale, true, null, $files);
    }

    /**
     * Its texts: translations/<domain>+intl-icu.<locale>.yaml for its domains and language, and nothing else.
     *
     * @param array<mixed> $package
     *
     * @return list<string>
     */
    private function files(array $package, TranslationArtifact $artifact, string $vendorComposerDir): array
    {
        $path = \is_string($package['install-path'] ?? null) ? realpath($vendorComposerDir.\DIRECTORY_SEPARATOR.$package['install-path']) : false;
        $directory = false === $path ? null : $path.\DIRECTORY_SEPARATOR.'translations';

        if (null === $directory || is_link($directory) || !is_dir($directory)) {
            throw new \InvalidArgumentException('It has no translations/ directory.');
        }

        $pattern = '/^(?:'.implode('|', array_map(static fn (string $domain): string => preg_quote($domain, '/'), $artifact->domains)).')\+intl-icu\.'.preg_quote($artifact->locale, '/').'\.yaml$/';
        $files = [];

        $entries = scandir($directory);

        foreach (false === $entries ? [] : $entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $file = $directory.\DIRECTORY_SEPARATOR.$entry;

            if (1 !== preg_match($pattern, $entry)) {
                throw new \InvalidArgumentException(\sprintf('It holds "translations/%s", which isn\'t a text of its own: only %s.', $entry, implode(', ', array_map(static fn (string $domain): string => $domain.'+intl-icu.'.$artifact->locale.'.yaml', $artifact->domains))));
            }

            PlainTexts::read($file);
            $files[] = $file;
        }

        if ([] === $files) {
            throw new \InvalidArgumentException('Its translations/ directory is empty.');
        }

        return $files;
    }

    /**
     * One artifact per package and language: two that both fit are both ignored, each naming the other.
     *
     * @param list<ArtifactStatus> $statuses
     *
     * @return list<ArtifactStatus>
     */
    private function oneEach(array $statuses): array
    {
        $claims = [];

        foreach ($statuses as $status) {
            if ($status->loaded) {
                $claims[$status->package.'|'.$status->locale][] = $status->name;
            }
        }

        return array_map(static function (ArtifactStatus $status) use ($claims): ArtifactStatus {
            $rivals = $status->loaded ? array_values(array_diff($claims[$status->package.'|'.$status->locale], [$status->name])) : [];

            return [] === $rivals ? $status : new ArtifactStatus($status->name, $status->package, $status->locale, false, \sprintf('%s translates %s into "%s" too; with two, neither is loaded.', implode(', ', $rivals), $status->package, $status->locale));
        }, $statuses);
    }
}
