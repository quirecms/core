<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-person preferences';
    }

    public function up(Schema $schema): void
    {
        // Only what someone changed from the default is stored, one row per preference, so changing one
        // never touches the user's row (or races with an admin editing it).
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.user_preference (
                user_id UUID NOT NULL,
                name VARCHAR(200) NOT NULL,
                value JSONB NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT statement_timestamp() NOT NULL,
                PRIMARY KEY (user_id, name),
                CONSTRAINT user_preference_user_fk FOREIGN KEY (user_id) REFERENCES quire.app_user (id) ON DELETE CASCADE
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.user_preference');
    }
}
