<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the user table and the identities that link users to other login providers';
    }

    public function up(Schema $schema): void
    {
        // Users who sign in only through another provider (SSO, a host application) have no email or
        // password here. Emails are unique regardless of case; usernames are lowercase slugs.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.app_user (
                id UUID DEFAULT uuidv7() NOT NULL,
                email TEXT DEFAULT NULL,
                username VARCHAR(64) NOT NULL,
                display_name TEXT NOT NULL,
                password_hash TEXT DEFAULT NULL,
                roles TEXT[] DEFAULT '{}' NOT NULL,
                status VARCHAR(16) DEFAULT 'active' NOT NULL,
                attributes JSONB DEFAULT '{}' NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT app_user_status_check CHECK (status IN ('active', 'suspended')),
                CONSTRAINT app_user_username_check CHECK (username ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX app_user_email_idx ON quire.app_user (lower(email)) WHERE email IS NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX app_user_username_idx ON quire.app_user (username)');
        $this->addSql('CREATE INDEX app_user_roles_idx ON quire.app_user USING GIN (roles)');

        // An identity at another provider: an SSO account, or the user of a host application (D-13).
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.user_identity (
                id UUID DEFAULT uuidv7() NOT NULL,
                user_id UUID NOT NULL,
                provider VARCHAR(64) NOT NULL,
                subject TEXT NOT NULL,
                claims JSONB DEFAULT '{}' NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                last_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT user_identity_user_fk FOREIGN KEY (user_id) REFERENCES quire.app_user (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX user_identity_subject_idx ON quire.user_identity (provider, subject)');
        $this->addSql('CREATE INDEX user_identity_user_idx ON quire.user_identity (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.user_identity');
        $this->addSql('DROP TABLE quire.app_user');
    }
}
