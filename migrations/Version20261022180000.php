<?php

declare(strict_types=1);

namespace Quire\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content paths with their history, earlier permalink patterns and redirects';
    }

    public function up(Schema $schema): void
    {
        // An item's path is its slug, below its parent's path for hierarchical types ("about/team"). It's
        // kept by the database, like the search vector, so it's right whoever writes the row, and a URL
        // resolves with one index lookup instead of walking up the tree.
        $this->addSql('ALTER TABLE quire.content ADD path TEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            WITH RECURSIVE tree AS (
                SELECT id, slug::text AS path FROM quire.content WHERE parent_id IS NULL
                UNION ALL
                SELECT c.id, tree.path || '/' || c.slug FROM quire.content c JOIN tree ON c.parent_id = tree.id
            )
            UPDATE quire.content c SET path = tree.path FROM tree WHERE tree.id = c.id
            SQL);
        $this->addSql('ALTER TABLE quire.content ALTER path SET NOT NULL');
        $this->addSql('CREATE INDEX content_path_idx ON quire.content (type, path) WHERE deleted_at IS NULL');

        // Earlier paths, so old URLs redirect to the item wherever it is now, without redirect chains.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.content_path_history (
                content_id UUID NOT NULL,
                path TEXT NOT NULL,
                changed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (content_id, path),
                CONSTRAINT content_path_history_content_fk FOREIGN KEY (content_id) REFERENCES quire.content (id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql('CREATE INDEX content_path_history_path_idx ON quire.content_path_history (path)');

        $this->addSql(<<<'SQL'
            CREATE FUNCTION quire.content_set_path() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.parent_id IS NULL THEN
                    NEW.path := NEW.slug;
                ELSE
                    SELECT p.path || '/' || NEW.slug INTO NEW.path FROM quire.content p WHERE p.id = NEW.parent_id;
                END IF;

                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER content_set_path BEFORE INSERT OR UPDATE OF slug, parent_id ON quire.content FOR EACH ROW EXECUTE FUNCTION quire.content_set_path()');

        // When a path changes, remember the old one and move the children along (their triggers then
        // move their own children).
        $this->addSql(<<<'SQL'
            CREATE FUNCTION quire.content_path_changed() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO quire.content_path_history (content_id, path) VALUES (OLD.id, OLD.path)
                    ON CONFLICT (content_id, path) DO UPDATE SET changed_at = now();
                DELETE FROM quire.content_path_history WHERE content_id = NEW.id AND path = NEW.path;
                UPDATE quire.content SET path = NEW.path || '/' || slug WHERE parent_id = NEW.id;

                RETURN NULL;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER content_path_changed AFTER UPDATE OF slug, parent_id, path ON quire.content FOR EACH ROW WHEN (OLD.path IS DISTINCT FROM NEW.path) EXECUTE FUNCTION quire.content_path_changed()');

        // Patterns a type's permalinks used before, so URLs in the old format keep working.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.permalink_history (
                type VARCHAR(64) NOT NULL,
                pattern TEXT NOT NULL,
                replaced_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (type, pattern)
            )
            SQL);

        // Redirects added by hand, e.g. for URLs of a previous site. The target is a content item (so the
        // redirect follows it when its URL changes) or a fixed URL.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.redirect (
                source TEXT NOT NULL,
                target_content_id UUID DEFAULT NULL,
                target_url TEXT DEFAULT NULL,
                status SMALLINT DEFAULT 301 NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT now() NOT NULL,
                PRIMARY KEY (source),
                CONSTRAINT redirect_content_fk FOREIGN KEY (target_content_id) REFERENCES quire.content (id) ON DELETE CASCADE,
                CONSTRAINT redirect_target_check CHECK ((target_content_id IS NULL) <> (target_url IS NULL)),
                CONSTRAINT redirect_status_check CHECK (status IN (301, 302, 307, 308))
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.redirect');
        $this->addSql('DROP TABLE quire.permalink_history');
        $this->addSql('DROP TRIGGER content_path_changed ON quire.content');
        $this->addSql('DROP FUNCTION quire.content_path_changed()');
        $this->addSql('DROP TRIGGER content_set_path ON quire.content');
        $this->addSql('DROP FUNCTION quire.content_set_path()');
        $this->addSql('DROP TABLE quire.content_path_history');
        $this->addSql('ALTER TABLE quire.content DROP path');
    }
}
