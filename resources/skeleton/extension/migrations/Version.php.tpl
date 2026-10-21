<?php

declare(strict_types=1);

namespace @@NAMESPACE@@\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class @@VERSION@@ extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the @@TABLE@@ table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE @@TABLE@@ (id SERIAL PRIMARY KEY, message TEXT NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE @@TABLE@@');
    }
}
