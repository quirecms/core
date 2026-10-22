<?php

declare(strict_types=1);

namespace Quire\Database;

/**
 * Quire keeps all its tables, and those of its extensions, in their own PostgreSQL schema.
 *
 * That way Quire can share a database with another application (e.g. when it's added to an existing
 * Symfony app) without its tables clashing with the application's, the application's schema tools
 * leave them alone, and Quire's data can be backed up on its own. Write table names qualified with the
 * schema: "quire.content", "quire.acme_reviews_review".
 *
 * @api
 */
final class DatabaseSchema
{
    public const string NAME = 'quire';

    private function __construct()
    {
    }
}
