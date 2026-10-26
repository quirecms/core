<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Find every word of a title, stop words too';
    }

    public function up(Schema $schema): void
    {
        // In its language, a title loses its stop words: a page called "About" couldn't be found by its
        // title. The title goes in unstemmed as well, which search already asks with "simple".
        $this->addSql(<<<'SQL'
            ALTER TABLE quire.content ALTER COLUMN search SET EXPRESSION AS (
                setweight(to_tsvector(search_config, title), 'A')
                || setweight(to_tsvector('simple'::regconfig, title), 'A')
                || setweight(to_tsvector(search_config, coalesce(excerpt, '')), 'B')
                || setweight(to_tsvector(search_config, body), 'C')
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE quire.content ALTER COLUMN search SET EXPRESSION AS (
                setweight(to_tsvector(search_config, title), 'A')
                || setweight(to_tsvector(search_config, coalesce(excerpt, '')), 'B')
                || setweight(to_tsvector(search_config, body), 'C')
            )
            SQL);
    }
}
