<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the results of content checks';
    }

    public function up(Schema $schema): void
    {
        // Findings without their level: the site's levels apply when they're read, so changing a level
        // needs no new check.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_check_result (
                id BIGINT GENERATED ALWAYS AS IDENTITY NOT NULL,
                content_id UUID NOT NULL,
                check_id VARCHAR(200) NOT NULL,
                message TEXT NOT NULL,
                property VARCHAR(200) DEFAULT NULL,
                line INT DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT content_check_result_content_fk FOREIGN KEY (content_id) REFERENCES quire.content (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX content_check_result_content_idx ON quire.content_check_result (content_id, check_id)');
        $this->addSql('CREATE INDEX content_check_result_check_idx ON quire.content_check_result (check_id, content_id)');
        // Which version of each item was checked, and when: items that changed since are checked again.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_check_run (
                content_id UUID NOT NULL,
                content_version INT NOT NULL,
                checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT statement_timestamp() NOT NULL,
                PRIMARY KEY (content_id),
                CONSTRAINT content_check_run_content_fk FOREIGN KEY (content_id) REFERENCES quire.content (id) ON DELETE CASCADE
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.content_check_run');
        $this->addSql('DROP TABLE quire.content_check_result');
    }
}
