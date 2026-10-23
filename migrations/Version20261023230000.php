<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the links of relation fields between content items';
    }

    public function up(Schema $schema): void
    {
        // A relation field's value is in the item's fields; this table holds the same links, so the database
        // refuses links to items that don't exist, removes links to items deleted for good, and finds the
        // items linking to one ("posts with this tag") by index.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_relation (
                source_id UUID NOT NULL,
                field VARCHAR(64) NOT NULL,
                target_id UUID NOT NULL,
                position INT NOT NULL,
                PRIMARY KEY (source_id, field, target_id),
                CONSTRAINT content_relation_source_fk FOREIGN KEY (source_id) REFERENCES quire.content (id) ON DELETE CASCADE,
                CONSTRAINT content_relation_target_fk FOREIGN KEY (target_id) REFERENCES quire.content (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX content_relation_target_idx ON quire.content_relation (target_id, field)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.content_relation');
    }
}
