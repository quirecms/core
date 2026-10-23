<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

use Quire\Content\Revision\Internal\LineMerge;
use Quire\Content\Revision\Internal\Values;

/**
 * Combines a pending revision with what was published since it was started, as a three-way merge of
 * the original, the live item and the proposal. Each field is merged separately, and text by line: an
 * editor fixing a typo in the first paragraph and another rewriting the last don't conflict.
 *
 * @api
 */
final readonly class Merge
{
    /**
     * @param Snapshot              $result    The merged text and fields; where they conflict, the proposal's
     * @param array<string, string> $conflicts Why, keyed by property ("title", "body", "fields.<name>", …)
     */
    public function __construct(
        public Snapshot $result,
        public array $conflicts,
    ) {
    }

    public static function of(Snapshot $base, Snapshot $live, Snapshot $proposed): self
    {
        $conflicts = [];
        $merge = static function (string $property, mixed $base, mixed $live, mixed $proposed) use (&$conflicts): mixed {
            if (Values::same($proposed, $base) || Values::same($proposed, $live)) {
                return $live;
            }

            if (Values::same($live, $base)) {
                return $proposed;
            }

            $merged = \is_string($base) && \is_string($live) && \is_string($proposed) ? LineMerge::merge($base, $live, $proposed) : null;

            if (null === $merged) {
                $conflicts[$property] = 'body' === $property
                    ? 'The same lines were changed since these changes were started. Compare them with the live text and resolve the conflict.'
                    : 'It was changed since these changes were started. Choose which value to keep.';

                return $proposed;
            }

            return $merged;
        };

        $result = [];

        foreach (['title', 'excerpt', 'body', 'body_format'] as $property) {
            $result[$property] = $merge($property, $base->toArray()[$property], $live->toArray()[$property], $proposed->toArray()[$property]);
        }

        // Absent fields are values too: removing a field is a change.
        $absent = new \stdClass();
        $fields = [];

        foreach (array_keys($live->fields + $base->fields + $proposed->fields) as $name) {
            $value = $merge('fields.'.$name, $base->fields[$name] ?? $absent, $live->fields[$name] ?? $absent, $proposed->fields[$name] ?? $absent);

            if ($value !== $absent) {
                $fields[$name] = $value;
            }
        }

        $result['fields'] = $fields;

        return new self(Snapshot::fromArray($result), $conflicts);
    }

    public function isClean(): bool
    {
        return [] === $this->conflicts;
    }
}
