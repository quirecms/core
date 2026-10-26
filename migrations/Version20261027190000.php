<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the backups Quire makes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.backup_run (
                id BIGINT GENERATED ALWAYS AS IDENTITY NOT NULL,
                finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT statement_timestamp() NOT NULL,
                ok BOOLEAN NOT NULL,
                bytes BIGINT DEFAULT NULL,
                target TEXT DEFAULT NULL,
                problem TEXT DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX backup_run_finished_idx ON quire.backup_run (finished_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.backup_run');
    }
}
