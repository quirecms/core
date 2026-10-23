<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the files kept on storage disks';
    }

    public function up(Schema $schema): void
    {
        // The bytes are on the disk; this row is what the database knows about them. Tables that use a
        // file reference this row (ON DELETE RESTRICT), which is how the garbage collector knows what's
        // still in use, and why a database backup always knows which files it needs.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.storage_object (
                id UUID DEFAULT uuidv7() NOT NULL,
                disk VARCHAR(64) NOT NULL,
                key TEXT NOT NULL,
                size BIGINT NOT NULL,
                sha256 CHAR(64) NOT NULL,
                mime_type VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                -- Last time this content was stored: storing it again restarts the grace period before
                -- an unreferenced object is collected.
                stored_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT storage_object_disk_key_unique UNIQUE (disk, key)
            )
            SQL);
        $this->addSql('CREATE INDEX storage_object_stored_idx ON quire.storage_object (stored_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.storage_object');
    }
}
