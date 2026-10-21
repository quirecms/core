<?php

declare(strict_types=1);

namespace Quire\Settings;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Schema of the `setting` table, read and written by {@see Settings} with plain SQL.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'setting')]
class SettingRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 190)]
        public string $name,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        public mixed $value,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
