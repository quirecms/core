<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make content authors and revision authors reference users';
    }

    public function up(Schema $schema): void
    {
        // Authors set before users existed point nowhere.
        $this->addSql('UPDATE quire.content SET author_id = NULL WHERE author_id IS NOT NULL AND author_id NOT IN (SELECT id FROM quire.app_user)');
        $this->addSql('UPDATE quire.content_revision SET author_id = NULL WHERE author_id IS NOT NULL AND author_id NOT IN (SELECT id FROM quire.app_user)');

        // Deleting a user who wrote content means choosing: reassign it or keep it without an author
        // (UserWriter::delete()). Who made a revision is history: it's forgotten with the user.
        $this->addSql('ALTER TABLE quire.content ADD CONSTRAINT content_author_fk FOREIGN KEY (author_id) REFERENCES quire.app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE quire.content_revision ADD CONSTRAINT content_revision_author_fk FOREIGN KEY (author_id) REFERENCES quire.app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX content_revision_author_idx ON quire.content_revision (author_id) WHERE author_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX quire.content_revision_author_idx');
        $this->addSql('ALTER TABLE quire.content_revision DROP CONSTRAINT content_revision_author_fk');
        $this->addSql('ALTER TABLE quire.content DROP CONSTRAINT content_author_fk');
    }
}
