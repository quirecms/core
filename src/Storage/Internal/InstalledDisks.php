<?php

declare(strict_types=1);

namespace Quire\Storage\Internal;

use Quire\Extend\ExtenderException;
use Quire\Extend\Storage;
use Quire\Extension\Boot\OfflineBoot;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Storage\StorageDisks;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Every disk the site has files on, whether the extension that declares it is enabled or not: the site's
 * disks, plus those every installed extension declares, where the site placed them or where they are
 * without configuration. A disabled extension keeps its data, files too, so backups copy them and
 * restores put them back. The garbage collector leaves them alone: it collects disks in use only.
 *
 * A disk that no installed extension declares, e.g. of an extension removed with Composer, isn't here:
 * where its files are can't be known.
 *
 * @internal
 */
final readonly class InstalledDisks
{
    public function __construct(
        private StorageDisks $disks,
        private InstalledExtensions $installed,
        private ExtensionStateStoreInterface $state,
        private OfflineBoot $boot,
    ) {
    }

    public function all(): StorageDisks
    {
        $installed = array_map(strval(...), array_keys($this->installed->all()));
        $enabled = array_values(array_filter($this->state->enabledIds(), $this->installed->has(...)));

        if ([] === array_diff($installed, $enabled)) {
            return $this->disks;
        }

        try {
            return $this->disks->withDeclarations($this->declarations($this->boot->boot($installed)));
        } catch (ExtenderException) {
            // Extensions that can't run together, e.g. two declaring the same disk: each with the
            // enabled ones, which run together. One whose extenders refuse keeps its disks unknown.
            $declarations = [];

            foreach (array_diff($installed, $enabled) as $id) {
                try {
                    $declarations = [...$declarations, ...$this->declarations($this->boot->boot([...$enabled, $id]))];
                } catch (ExtenderException) {
                }
            }

            return $this->disks->withDeclarations($declarations);
        }
    }

    /**
     * @return list<string> Disks "quire.storage.disks" places without "public" that no installed extension
     *                      declares, e.g. a typo or a renamed disk: never used. Cheap when there are
     *                      no dormant disks, which is when the extenders aren't run.
     */
    public function placedButUndeclared(): array
    {
        return [] === $this->disks->dormant() ? [] : $this->all()->dormant();
    }

    /**
     * @return list<array{name: string, public: bool, label: ?string, owner: string}>
     */
    private function declarations(ContainerBuilder $booted): array
    {
        /** @var list<array{name: string, public: bool, label: ?string, owner: string}> $declared */
        $declared = $booted->getParameterBag()->all()[Storage::PARAMETER] ?? [];

        return $declared;
    }
}
