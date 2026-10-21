<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the extension table, which stores which extensions are enabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE extension (id VARCHAR(100) NOT NULL, enabled BOOLEAN DEFAULT false NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE extension');
    }
}
