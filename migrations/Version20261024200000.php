<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let pending revisions be published at a set time, and remember why that failed';
    }

    public function up(Schema $schema): void
    {
        // publish_at: when quire.publish-due publishes the changes. publish_problem: why it couldn't (a
        // conflict, a missing approval), then publish_at is cleared, so it isn't tried every minute.
        $this->addSql('ALTER TABLE quire.content_pending_revision ADD publish_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE quire.content_pending_revision ADD publish_problem TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE quire.content_pending_revision ADD scheduled_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE quire.content_pending_revision ADD CONSTRAINT content_pending_revision_scheduled_by_fk FOREIGN KEY (scheduled_by) REFERENCES quire.app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX content_pending_revision_publish_at_idx ON quire.content_pending_revision (publish_at) WHERE publish_at IS NOT NULL');
        $this->addSql('CREATE INDEX content_pending_revision_scheduled_by_idx ON quire.content_pending_revision (scheduled_by) WHERE scheduled_by IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quire.content_pending_revision DROP scheduled_by');
        $this->addSql('ALTER TABLE quire.content_pending_revision DROP publish_problem');
        $this->addSql('ALTER TABLE quire.content_pending_revision DROP publish_at');
    }
}
