<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember what a pinned site runs, so it keeps running when the site stops pinning';
    }

    public function up(Schema $schema): void
    {
        // One row at most: the enabled extensions and the theme fixed in the configuration, as of the last
        // time the site's migrations ran while it was pinned.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.pinned_state (
                id SMALLINT DEFAULT 1 NOT NULL CHECK (id = 1),
                enabled JSONB NOT NULL,
                theme VARCHAR(100),
                recorded_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.pinned_state');
    }
}
