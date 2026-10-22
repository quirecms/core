<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Content\Content;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Internal\QueryRunner;
use Quire\Content\Query\RelationLoaderInterface;
use Quire\Content\Type\ContentTypeRegistry;

/**
 * Extends content queries: named scopes, visibility rules and relations.
 *
 *     new Extend\Query()
 *         ->scope('featured', FeaturedScope::class, types: ['post'])
 *         ->visibility(HideUnapprovedReviews::class, types: ['review'])
 *         ->relation('comments', CommentsLoader::class, types: ['post']);
 *
 * Scopes and visibility rules are invokable classes that take the query (and, for scopes, the arguments
 * given to `->scope()`) and return it refined:
 *
 *     final readonly class TaggedScope
 *     {
 *         public function __invoke(ContentQuery $query, string $tag): ContentQuery
 *         {
 *             return $query->where('fields.tags', 'contains', $tag);
 *         }
 *     }
 *
 * The classes become autowired services.
 *
 * @api
 *
 * @phpstan-import-type Config from QueryRunner
 */
final class Query implements ExtenderInterface
{
    private const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var list<\Closure(ExtenderContext): void> */
    private array $calls = [];

    /** @var Config The configuration being built, during {@see extend()} */
    private array $config = ['scopes' => [], 'visibility' => [], 'relations' => []];

    /**
     * Adds a named scope, used as `->scope('featured')` in PHP and `.scope('featured')` in Twig. Unlike code in
     * templates, scopes may use raw SQL ({@see ContentQuery::whereSql()}, joins and selects).
     *
     * @param class-string      $class An invokable class: `__invoke(ContentQuery $query, ...$arguments): ContentQuery`
     * @param list<string>|null $types The content types it applies to; null for all
     */
    public function scope(string $name, string $class, ?array $types = null): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addScope($context, $name, $class, $types);

        return $this;
    }

    /**
     * Adds a visibility rule: a condition every query applies automatically, unless it calls
     * {@see ContentQuery::withoutVisibilityScope()}. Use it for items visitors must not see, e.g. unapproved reviews.
     *
     * A rule only adds conditions (`where()`, `whereSql()`). It applies to items of its types and leaves other
     * types in the same query alone.
     *
     * @param class-string      $class An invokable class: `__invoke(ContentQuery $query): ContentQuery`
     * @param list<string>|null $types The content types it applies to; null for all
     */
    public function visibility(string $class, ?array $types = null): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addVisibility($context, $class, $types);

        return $this;
    }

    /**
     * Adds a relation that queries load for all their items at once with `->with('name')`. Read it as a
     * property: `$post->comments`, `{{ post.comments }}`.
     *
     * @param class-string<RelationLoaderInterface> $class
     * @param list<string>|null                     $types The content types that have it; null for all
     */
    public function relation(string $name, string $class, ?array $types = null): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addRelation($context, $name, $class, $types);

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();
        $config = $container->getParameterBag()->all()[QueryRunner::PARAMETER] ?? null;

        /** @var Config $config */
        $config = \is_array($config) ? $config : ['scopes' => [], 'visibility' => [], 'relations' => []];
        $this->config = $config;

        foreach ($this->calls as $call) {
            $call($context);
        }

        $container->setParameter(QueryRunner::PARAMETER, $this->config);
    }

    /**
     * @param list<string>|null $types
     */
    private function addScope(ExtenderContext $context, string $name, string $class, ?array $types): void
    {
        $where = \sprintf('Extend\Query::scope("%s")', $name);
        $this->assertName($context, $where, $name);

        if (isset($this->config['scopes'][$name])) {
            throw $context->error($where, \sprintf('The scope is already added by "%s".', $this->config['scopes'][$name]['owner']));
        }

        $this->config['scopes'][$name] = ['class' => $this->invokable($context, $where, $class), 'types' => $this->types($context, $where, $types), 'owner' => $context->id];
    }

    /**
     * @param list<string>|null $types
     */
    private function addVisibility(ExtenderContext $context, string $class, ?array $types): void
    {
        $where = \sprintf('Extend\Query::visibility(%s)', $class);
        $this->config['visibility'][] = ['class' => $this->invokable($context, $where, $class), 'types' => $this->types($context, $where, $types), 'owner' => $context->id];
    }

    /**
     * @param list<string>|null $types
     */
    private function addRelation(ExtenderContext $context, string $name, string $class, ?array $types): void
    {
        $where = \sprintf('Extend\Query::relation("%s")', $name);
        $this->assertName($context, $where, $name);

        $error = match (true) {
            isset($this->config['relations'][$name]) => \sprintf('The relation is already added by "%s".', $this->config['relations'][$name]['owner']),
            property_exists(Content::class, $name) => \sprintf('"%s" is a property of content items; choose another name.', $name),
            !class_exists($class) => \sprintf('The class "%s" doesn\'t exist. Check the class name and that your package\'s Composer autoload covers it.', $class),
            !is_a($class, RelationLoaderInterface::class, true) => \sprintf('The class "%s" must implement %s.', $class, RelationLoaderInterface::class),
            default => null,
        };

        if (null !== $error) {
            throw $context->error($where, $error);
        }

        $context->service($class);
        $this->config['relations'][$name] = ['class' => $class, 'types' => $this->types($context, $where, $types), 'owner' => $context->id];
    }

    /**
     * @return class-string
     */
    private function invokable(ExtenderContext $context, string $where, string $class): string
    {
        if (!class_exists($class)) {
            throw $context->error($where, \sprintf('The class "%s" doesn\'t exist. Check the class name and that your package\'s Composer autoload covers it.', $class));
        }

        $invoke = method_exists($class, '__invoke') ? new \ReflectionMethod($class, '__invoke') : null;
        $first = $invoke?->getParameters()[0] ?? null;
        $firstType = $first?->getType();

        if (!$firstType instanceof \ReflectionNamedType || ContentQuery::class !== $firstType->getName()) {
            throw $context->error($where, \sprintf('The class "%s" must have a method __invoke(ContentQuery $query, ...): ContentQuery.', $class));
        }

        $context->service($class);

        return $class;
    }

    /**
     * @param list<string>|null $types
     *
     * @return list<string>|null
     */
    private function types(ExtenderContext $context, string $where, ?array $types): ?array
    {
        if (null === $types) {
            return null;
        }

        $registered = $context->container()->getParameterBag()->all()[ContentTypeRegistry::PARAMETER] ?? [];
        $registered = \is_array($registered) ? array_keys($registered) : [];
        $unknown = array_diff($types, $registered);

        if ([] === $types || [] !== $unknown) {
            throw $context->error($where, [] === $types ? 'Give at least one content type, or null for all types.' : \sprintf('Unknown content type "%s". If another extension provides it, require that extension in composer.json, so it boots first.', implode('", "', $unknown)));
        }

        return $types;
    }

    private function assertName(ExtenderContext $context, string $where, string $name): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw $context->error($where, 'Names start with a lowercase letter, followed by up to 63 lowercase letters, digits or underscores.');
        }
    }
}
