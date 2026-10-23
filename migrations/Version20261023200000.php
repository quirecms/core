<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when users confirmed their email, and keep the one-time tokens of emailed links';
    }

    public function up(Schema $schema): void
    {
        // Users so far were created by admins, who vouch for their emails.
        $this->addSql('ALTER TABLE quire.app_user ADD email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE quire.app_user SET email_verified_at = created_at WHERE email IS NOT NULL');

        // A token is a public selector, to find it, and a secret verifier, kept only as a hash: a leaked
        // table doesn't give away working links, and comparing hashes takes the same time either way.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.user_token (
                selector VARCHAR(32) NOT NULL,
                verifier_hash VARCHAR(64) NOT NULL,
                user_id UUID NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                email TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (selector),
                CONSTRAINT user_token_user_fk FOREIGN KEY (user_id) REFERENCES quire.app_user (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX user_token_user_idx ON quire.user_token (user_id, purpose)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.user_token');
        $this->addSql('ALTER TABLE quire.app_user DROP email_verified_at');
    }
}
