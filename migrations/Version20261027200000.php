<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comments on pending revisions, anchored to lines, with replies';
    }

    public function up(Schema $schema): void
    {
        // A thread is a comment without parent_id and its replies. The anchor (property, line, quote, as
        // written at anchor_version) is found again in later versions by its quote, when it's read.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_revision_comment (
                id UUID DEFAULT uuidv7() NOT NULL,
                pending_revision_id UUID NOT NULL,
                parent_id UUID DEFAULT NULL,
                author_id UUID DEFAULT NULL,
                body TEXT NOT NULL,
                anchor_property VARCHAR(200) DEFAULT NULL,
                anchor_line INT DEFAULT NULL,
                anchor_quote TEXT DEFAULT NULL,
                anchor_version INT DEFAULT NULL,
                resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                resolved_by UUID DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT statement_timestamp() NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT content_revision_comment_pending_fk FOREIGN KEY (pending_revision_id) REFERENCES quire.content_pending_revision (id) ON DELETE CASCADE,
                CONSTRAINT content_revision_comment_parent_fk FOREIGN KEY (parent_id) REFERENCES quire.content_revision_comment (id) ON DELETE CASCADE,
                CONSTRAINT content_revision_comment_author_fk FOREIGN KEY (author_id) REFERENCES quire.app_user (id) ON DELETE SET NULL,
                CONSTRAINT content_revision_comment_resolved_by_fk FOREIGN KEY (resolved_by) REFERENCES quire.app_user (id) ON DELETE SET NULL,
                CONSTRAINT content_revision_comment_anchor_check CHECK ((anchor_property IS NULL) = (anchor_quote IS NULL))
            )
            SQL);
        $this->addSql('CREATE INDEX content_revision_comment_pending_idx ON quire.content_revision_comment (pending_revision_id, created_at)');
        $this->addSql('CREATE INDEX content_revision_comment_parent_idx ON quire.content_revision_comment (parent_id) WHERE parent_id IS NOT NULL');
        $this->addSql('CREATE INDEX content_revision_comment_author_idx ON quire.content_revision_comment (author_id) WHERE author_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.content_revision_comment');
    }
}
