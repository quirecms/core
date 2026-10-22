<?php

declare(strict_types=1);

namespace Quire\Content\Permalink;

/**
 * A URL pattern for the items of a content type, such as "/blog/{slug}" or "/{year}/{month}/{slug}".
 *
 * Placeholders: {slug}, {path} (the slug below the parent's path, for hierarchical types), {id}, and
 * {year}, {month}, {day} of the publication date.
 *
 * @api
 */
final readonly class PermalinkPattern
{
    public const array PLACEHOLDERS = ['slug', 'path', 'id', 'year', 'month', 'day'];

    /** At least one of these makes each item's URL unique. */
    private const array IDENTIFYING_PLACEHOLDERS = ['slug', 'path', 'id'];

    /** Segments of static text and placeholders. */
    private const string SYNTAX = '#^(?:/(?:[a-z0-9._~-]|\{[a-z]+\})+)+$#';

    private const array PLACEHOLDER_REGEX = [
        'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        'path' => '[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*',
        'id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        'year' => '[0-9]{4}',
        'month' => '[0-9]{2}',
        'day' => '[0-9]{2}',
    ];

    /**
     * @param list<string> $segments     The parts between slashes
     * @param list<string> $placeholders In order
     */
    private function __construct(
        public string $pattern,
        private array $segments,
        public array $placeholders,
    ) {
    }

    /**
     * @param bool $hierarchical Whether the type's items have parents, which {path} needs
     *
     * @throws \InvalidArgumentException when the pattern isn't valid, with a message that says why
     */
    public static function parse(string $pattern, bool $hierarchical): self
    {
        if (1 !== preg_match(self::SYNTAX, $pattern)) {
            throw new \InvalidArgumentException(\sprintf('The permalink "%s" must start with "/" and contain only lowercase letters, digits, "-", "_", ".", "~" and placeholders such as {slug}.', $pattern));
        }

        preg_match_all('/\{([a-z]+)\}/', $pattern, $matches);
        $placeholders = $matches[1];

        foreach ($placeholders as $placeholder) {
            if (!\in_array($placeholder, self::PLACEHOLDERS, true)) {
                throw new \InvalidArgumentException(\sprintf('The permalink "%s" uses the unknown placeholder {%s}. Use: {%s}.', $pattern, $placeholder, implode('}, {', self::PLACEHOLDERS)));
            }
        }

        if (\count($placeholders) !== \count(array_unique($placeholders))) {
            throw new \InvalidArgumentException(\sprintf('The permalink "%s" uses a placeholder more than once.', $pattern));
        }

        if ([] === array_intersect($placeholders, self::IDENTIFYING_PLACEHOLDERS)) {
            throw new \InvalidArgumentException(\sprintf('The permalink "%s" needs {slug}, {path} or {id}, so each item gets its own URL.', $pattern));
        }

        if (\in_array('path', $placeholders, true) && !$hierarchical) {
            throw new \InvalidArgumentException(\sprintf('The permalink "%s" uses {path}, which only hierarchical types have. Use {slug}.', $pattern));
        }

        $segments = explode('/', substr($pattern, 1));

        if (array_any($segments, static fn (string $segment): bool => str_contains($segment, '{path}') && '{path}' !== $segment)) {
            throw new \InvalidArgumentException(\sprintf('The permalink "%s" must have {path} on its own between slashes, because a path can span several of them.', $pattern));
        }

        return new self($pattern, $segments, $placeholders);
    }

    public function has(string $placeholder): bool
    {
        return \in_array($placeholder, $this->placeholders, true);
    }

    /**
     * The shape of the URLs the pattern produces, where placeholders could be anything: patterns with the
     * same signature compete for the same URLs. "/blog/{slug}" and "/blog/{id}" both are "/blog/*".
     */
    public function signature(): string
    {
        return '/'.implode('/', array_map(
            static fn (string $segment): string => '{path}' === $segment ? '**' : (string) preg_replace('/\{[a-z]+\}/', '*', $segment),
            $this->segments,
        ));
    }

    /**
     * For trying patterns in order when several could match a URL: those with more static segments
     * first ("/blog/{slug}" before "/{slug}"), then those without {path}, then those with more static text.
     *
     * @return array{int, int, int} Compare descending
     */
    public function specificity(): array
    {
        $static = array_filter($this->segments, static fn (string $segment): bool => !str_contains($segment, '{'));

        return [\count($static), $this->has('path') ? 0 : 1, \strlen((string) preg_replace('/\{[a-z]+\}/', '', $this->pattern))];
    }

    /**
     * The static part before the first placeholder, e.g. "/blog" for "/blog/{slug}"; "" when there is none.
     */
    public function staticPrefix(): string
    {
        $prefix = '';

        foreach ($this->segments as $segment) {
            if (str_contains($segment, '{')) {
                break;
            }

            $prefix .= '/'.$segment;
        }

        return $prefix;
    }

    /**
     * @param string $path A URL path such as "/blog/hello", in lowercase
     *
     * @return array<string, string>|null The placeholder values, or null when the path doesn't fit
     */
    public function match(string $path): ?array
    {
        $regex = (string) preg_replace_callback(
            '/\\\\\{([a-z]+)\\\\\}/',
            static fn (array $match): string => \sprintf('(?P<%s>%s)', $match[1], self::PLACEHOLDER_REGEX[$match[1]]),
            preg_quote($this->pattern, '#'),
        );

        if (1 !== preg_match('#^'.$regex.'$#', $path, $matches)) {
            return null;
        }

        return array_intersect_key($matches, array_flip($this->placeholders));
    }

    /**
     * @param array<string, string> $values A value for each placeholder
     */
    public function generate(array $values): string
    {
        $pattern = $this->pattern;

        return (string) preg_replace_callback(
            '/\{([a-z]+)\}/',
            static fn (array $match): string => $values[$match[1]] ?? throw new \LogicException(\sprintf('No value for {%s} in "%s".', $match[1], $pattern)),
            $pattern,
        );
    }
}
