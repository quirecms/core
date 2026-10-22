<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the session table, so sessions survive restarts and are shared between servers';
    }

    public function up(Schema $schema): void
    {
        // The layout Symfony's PdoSessionHandler expects for PostgreSQL.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.session (
                sess_id VARCHAR(128) NOT NULL,
                sess_data BYTEA NOT NULL,
                sess_lifetime INTEGER NOT NULL,
                sess_time INTEGER NOT NULL,
                PRIMARY KEY (sess_id)
            )
            SQL);
        $this->addSql('CREATE INDEX session_lifetime_idx ON quire.session (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.session');
    }
}
