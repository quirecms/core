<?php

declare(strict_types=1);

namespace Quire\Extension\State;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema of the `extension` table.
 *
 * {@see DatabaseExtensionStateStore} reads and writes the table with plain SQL, because it's also needed
 * while the container is being built. The mapping exists so Doctrine's schema tools know the table.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'extension')]
class ExtensionRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 100)]
        public string $id,
        #[ORM\Column(options: ['default' => false])]
        public bool $enabled,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
