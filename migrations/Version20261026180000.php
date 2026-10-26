<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the audit log';
    }

    public function up(Schema $schema): void
    {
        // actor_name and subject_label keep names as they were, also after the user or item is deleted.
        // last_at and repeats: saving the same item again soon after updates its entry (AuditLog::record()).
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.audit_entry (
                id UUID DEFAULT uuidv7() NOT NULL,
                occurred_at TIMESTAMP(0) WITH TIME ZONE DEFAULT statement_timestamp() NOT NULL,
                last_at TIMESTAMP(0) WITH TIME ZONE DEFAULT statement_timestamp() NOT NULL,
                repeats INT DEFAULT 1 NOT NULL,
                action VARCHAR(200) NOT NULL,
                actor_id UUID DEFAULT NULL,
                actor_name TEXT DEFAULT NULL,
                origin VARCHAR(20) NOT NULL,
                via VARCHAR(255) DEFAULT NULL,
                subject_type VARCHAR(100) DEFAULT NULL,
                subject_id VARCHAR(255) DEFAULT NULL,
                subject_label TEXT DEFAULT NULL,
                details JSONB DEFAULT '{}' NOT NULL,
                command TEXT DEFAULT NULL,
                correlation_id UUID NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT audit_entry_actor_fk FOREIGN KEY (actor_id) REFERENCES quire.app_user (id) ON DELETE SET NULL
            )
            SQL);
        $this->addSql('CREATE INDEX audit_entry_last_at_idx ON quire.audit_entry (last_at DESC, id DESC)');
        $this->addSql('CREATE INDEX audit_entry_actor_idx ON quire.audit_entry (actor_id, last_at DESC) WHERE actor_id IS NOT NULL');
        $this->addSql('CREATE INDEX audit_entry_subject_idx ON quire.audit_entry (subject_type, subject_id, last_at DESC)');
        $this->addSql('CREATE INDEX audit_entry_action_idx ON quire.audit_entry (action text_pattern_ops)');
        $this->addSql('CREATE INDEX audit_entry_correlation_idx ON quire.audit_entry (correlation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.audit_entry');
    }
}
