<?php

declare(strict_types=1);

namespace Quire\Extend;

/**
 * Declares the storage disks an extension keeps files on, e.g. uploads:
 *
 *     new Extend\Storage()
 *         ->disk('acme-gallery', public: true, label: 'Gallery photos')
 *
 * A declared disk works without configuration, as a directory under the site's storage root. The site
 * can put it anywhere else, e.g. on S3, in config/packages/quire.yaml ("quire.storage.disks"), without
 * the extension changing. Files on public disks are served to everyone; those on private disks only
 * through signed URLs. Store files with {@see \Quire\Storage\ObjectStore}.
 *
 * @api
 */
final class Storage implements ExtenderInterface
{
    /** @internal Parameter: list<{name, public, label, owner}> */
    public const string PARAMETER = 'quire.storage.declared_disks';

    /** @internal Disk names: lowercase letters, digits, "-" and "_", starting with a letter */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    /** @var list<array{name: string, public: bool, label: ?string}> */
    private array $disks = [];

    /**
     * @param string      $name   Unique on the site; start it with your extension's ID, e.g. "acme-gallery"
     * @param bool        $public Whether its files are served to everyone (e.g. images in pages), or only
     *                            through signed URLs (e.g. downloads for members)
     * @param string|null $label  What the files are, for people managing the site
     */
    public function disk(string $name, bool $public, ?string $label = null): self
    {
        $this->disks[] = ['name' => $name, 'public' => $public, 'label' => $label];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        /** @var list<array{name: string, owner: string}> $declared */
        $declared = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];
        $taken = array_column($declared, 'owner', 'name');

        foreach ($this->disks as $disk) {
            $extender = \sprintf('Extend\Storage::disk("%s")', $disk['name']);

            if (1 !== preg_match(self::NAME_PATTERN, $disk['name'])) {
                throw $context->error($extender, 'Disk names have lowercase letters, digits, "-" and "_", and start with a letter.');
            }

            if (isset($taken[$disk['name']])) {
                throw $context->error($extender, \sprintf('The disk is already declared by "%s".', $taken[$disk['name']]));
            }

            $taken[$disk['name']] = $context->id;
            $context->appendToParameter(self::PARAMETER, [...$disk, 'owner' => $context->id]);
        }
    }
}
