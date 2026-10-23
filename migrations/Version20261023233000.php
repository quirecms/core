<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep pending revisions, changes to content that aren\'t published yet, and their reviews';
    }

    public function up(Schema $schema): void
    {
        // A working copy, saved in place as the editor types. "base" is the item's text and fields at
        // base_version, which publishing needs to merge with what was published since.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_pending_revision (
                id UUID DEFAULT uuidv7() NOT NULL,
                content_id UUID NOT NULL,
                base_version INT NOT NULL,
                base JSONB NOT NULL,
                title TEXT NOT NULL,
                excerpt TEXT DEFAULT NULL,
                body TEXT NOT NULL,
                body_format VARCHAR(32) NOT NULL,
                fields JSONB NOT NULL,
                message TEXT DEFAULT NULL,
                state VARCHAR(32) DEFAULT 'editing' NOT NULL,
                author_id UUID DEFAULT NULL,
                version INT DEFAULT 1 NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                -- To the microsecond: autosaves come seconds apart, and lists show the latest first.
                updated_at TIMESTAMP(6) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT content_pending_revision_content_fk FOREIGN KEY (content_id) REFERENCES quire.content (id) ON DELETE CASCADE,
                CONSTRAINT content_pending_revision_author_fk FOREIGN KEY (author_id) REFERENCES quire.app_user (id) ON DELETE SET NULL,
                CONSTRAINT content_pending_revision_state_check CHECK (state IN ('editing', 'in_review', 'changes_requested', 'approved')),
                CONSTRAINT content_pending_revision_fields_check CHECK (jsonb_typeof(fields) = 'object')
            )
            SQL);
        $this->addSql('CREATE INDEX content_pending_revision_content_idx ON quire.content_pending_revision (content_id)');
        $this->addSql('CREATE INDEX content_pending_revision_author_idx ON quire.content_pending_revision (author_id) WHERE author_id IS NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_revision_review (
                id UUID DEFAULT uuidv7() NOT NULL,
                pending_revision_id UUID NOT NULL,
                revision_version INT NOT NULL,
                verdict VARCHAR(32) NOT NULL,
                reviewer_id UUID DEFAULT NULL,
                message TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT content_revision_review_pending_fk FOREIGN KEY (pending_revision_id) REFERENCES quire.content_pending_revision (id) ON DELETE CASCADE,
                CONSTRAINT content_revision_review_reviewer_fk FOREIGN KEY (reviewer_id) REFERENCES quire.app_user (id) ON DELETE SET NULL,
                CONSTRAINT content_revision_review_verdict_check CHECK (verdict IN ('approve', 'request_changes', 'comment'))
            )
            SQL);
        $this->addSql('CREATE INDEX content_revision_review_pending_idx ON quire.content_revision_review (pending_revision_id)');
        $this->addSql('CREATE INDEX content_revision_review_reviewer_idx ON quire.content_revision_review (reviewer_id) WHERE reviewer_id IS NOT NULL');

        // Published changes become a revision; who approved them is part of its history.
        $this->addSql('ALTER TABLE quire.content_revision ADD approved_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE quire.content_revision ADD CONSTRAINT content_revision_approved_by_fk FOREIGN KEY (approved_by) REFERENCES quire.app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX content_revision_approved_by_idx ON quire.content_revision (approved_by) WHERE approved_by IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quire.content_revision DROP approved_by');
        $this->addSql('DROP TABLE quire.content_revision_review');
        $this->addSql('DROP TABLE quire.content_pending_revision');
    }
}
