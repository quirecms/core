<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sites that already exist keep the list as their homepage, now that new sites start with an edition';
    }

    public function up(Schema $schema): void
    {
        // A site with users is one that was installed before; it never stored "site.homepage" because the
        // list was the default. A new site has no users yet when its migrations run, so it gets the new default.
        $this->addSql(<<<'SQL'
            INSERT INTO quire.setting (name, value, updated_at)
            SELECT 'site.homepage', '"listing"'::jsonb, now()
            WHERE EXISTS (SELECT 1 FROM quire.app_user)
            ON CONFLICT (name) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Kept: the stored value is what the site showed before, and is the old default too
    }
}
