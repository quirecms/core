<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the content and content_revision tables, which store every content item and its history';
    }

    public function up(Schema $schema): void
    {
        // IDs are UUIDv7 (time-ordered, like ULIDs), so the database can generate them for raw inserts too.
        // The search vector is a generated column: it can never go stale, whoever writes the row.
        $this->addSql(<<<'SQL'
            CREATE TABLE content (
                id UUID DEFAULT uuidv7() NOT NULL,
                type VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                locale VARCHAR(35) NOT NULL,
                translation_group_id UUID DEFAULT NULL,
                title TEXT NOT NULL,
                slug VARCHAR(200) NOT NULL,
                parent_id UUID DEFAULT NULL,
                position INT DEFAULT 0 NOT NULL,
                excerpt TEXT DEFAULT NULL,
                body TEXT DEFAULT '' NOT NULL,
                body_format VARCHAR(32) NOT NULL,
                fields JSONB DEFAULT '{}' NOT NULL,
                author_id UUID DEFAULT NULL,
                version INT DEFAULT 1 NOT NULL,
                search_config REGCONFIG DEFAULT 'simple' NOT NULL,
                search TSVECTOR GENERATED ALWAYS AS (
                    setweight(to_tsvector(search_config, title), 'A')
                    || setweight(to_tsvector(search_config, coalesce(excerpt, '')), 'B')
                    || setweight(to_tsvector(search_config, body), 'C')
                ) STORED,
                published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT content_parent_fk FOREIGN KEY (parent_id) REFERENCES content (id),
                CONSTRAINT content_slug_check CHECK (slug <> '' AND strpos(slug, '/') = 0),
                CONSTRAINT content_version_check CHECK (version > 0),
                CONSTRAINT content_fields_check CHECK (jsonb_typeof(fields) = 'object'),
                CONSTRAINT content_published_at_check CHECK (status NOT IN ('published', 'scheduled') OR published_at IS NOT NULL)
            )
            SQL);

        // Slugs are unique among siblings of the same type and language. Items in the trash don't hold on to theirs.
        $this->addSql('CREATE UNIQUE INDEX content_slug_unique ON content (type, parent_id, locale, slug) NULLS NOT DISTINCT WHERE deleted_at IS NULL');
        $this->addSql('CREATE UNIQUE INDEX content_translation_unique ON content (translation_group_id, locale)');
        $this->addSql('CREATE INDEX content_listing_idx ON content (type, status, published_at DESC) WHERE deleted_at IS NULL');
        $this->addSql('CREATE INDEX content_children_idx ON content (parent_id, position) WHERE parent_id IS NOT NULL');
        $this->addSql('CREATE INDEX content_author_idx ON content (author_id) WHERE author_id IS NOT NULL');
        $this->addSql('CREATE INDEX content_search_idx ON content USING GIN (search)');

        $this->addSql(<<<'SQL'
            CREATE TABLE content_revision (
                id UUID DEFAULT uuidv7() NOT NULL,
                content_id UUID NOT NULL,
                content_version INT NOT NULL,
                title TEXT NOT NULL,
                excerpt TEXT DEFAULT NULL,
                body TEXT NOT NULL,
                body_format VARCHAR(32) NOT NULL,
                fields JSONB NOT NULL,
                author_id UUID DEFAULT NULL,
                message TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT content_revision_content_fk FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT content_revision_version_unique UNIQUE (content_id, content_version)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_revision');
        $this->addSql('DROP TABLE content');
    }
}
