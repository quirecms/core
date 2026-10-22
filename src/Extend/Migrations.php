<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Database\MigrationOrder;

/**
 * Registers an extension's database migrations (Doctrine Migrations).
 *
 *     new Extend\Migrations('Acme\\Reviews\\Migrations', __DIR__.'/migrations');
 *
 * They run when the extension is enabled (or with "bin/console quire:migrations:migrate"), and are
 * rolled back when it's disabled with --purge. Create the tables in Quire's schema, named after the
 * extension: "CREATE TABLE quire.acme_reviews_review (…)" (see {@see \Quire\Database\DatabaseSchema}).
 * Generate a migration class with "bin/console doctrine:migrations:generate", then move it into your
 * extension's migrations directory and namespace.
 *
 * @api
 */
final readonly class Migrations implements ExtenderInterface
{
    private const string NAMESPACE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * @param string $namespace Namespace of the migration classes, e.g. "Acme\Reviews\Migrations"
     * @param string $directory Directory holding them; don't put it under your Composer autoload paths
     */
    public function __construct(
        private string $namespace,
        private string $directory,
    ) {
    }

    public function extend(ExtenderContext $context): void
    {
        $where = \sprintf('Extend\Migrations("%s")', $this->namespace);
        $namespace = trim($this->namespace, '\\');

        if (1 !== preg_match(self::NAMESPACE_PATTERN, $namespace)) {
            throw $context->error($where, 'The namespace is not a valid PHP namespace, e.g. "Acme\Reviews\Migrations".');
        }

        if (!is_dir($this->directory)) {
            throw $context->error($where, \sprintf('The directory "%s" doesn\'t exist.', $this->directory));
        }

        $all = $context->container()->getParameterBag()->all()[MigrationOrder::PARAMETER] ?? [];

        foreach (\is_array($all) ? $all : [] as $registered) {
            if (\is_array($registered) && ($registered['namespace'] ?? null) === $namespace) {
                throw $context->error($where, \sprintf('The namespace is already used by "%s". Each extension needs its own migrations namespace.', \is_string($registered['owner'] ?? null) ? $registered['owner'] : 'another extension'));
            }
        }

        $context->appendToParameter(MigrationOrder::PARAMETER, ['namespace' => $namespace, 'directory' => $this->directory, 'owner' => $context->id]);
    }
}
