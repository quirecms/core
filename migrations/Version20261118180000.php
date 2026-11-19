<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A published item\'s public history: what changed for readers, as its authors wrote it';
    }

    public function up(Schema $schema): void
    {
        // Apart from the revisions, which are pruned ("quire.content.revisions_keep") and whose message
        // is the editors' own: this is what readers are told, and stays for the item's life.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_public_change (
                id UUID DEFAULT uuidv7() NOT NULL,
                content_id UUID NOT NULL,
                content_version INT NOT NULL,
                kind VARCHAR(16) NOT NULL CHECK (kind IN ('update', 'correction')),
                note TEXT NOT NULL CHECK (length(note) BETWEEN 1 AND 500),
                author_id UUID DEFAULT NULL,
                published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT content_public_change_content_fk FOREIGN KEY (content_id) REFERENCES quire.content (id) ON DELETE CASCADE,
                -- Who wrote a note is forgotten with them; what readers were told stays
                CONSTRAINT content_public_change_author_fk FOREIGN KEY (author_id) REFERENCES quire.app_user (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE INDEX content_public_change_idx ON quire.content_public_change (content_id, published_at DESC)');
        $this->addSql('CREATE INDEX content_public_change_author_idx ON quire.content_public_change (author_id) WHERE author_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.content_public_change');
    }
}
