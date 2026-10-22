<?php

declare(strict_types=1);

namespace Quire\Content\Query\Relation;

use Quire\Content\Content;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\RelationLoaderInterface;

/**
 * `->with('translations')`: the other translations of each item, by locale, e.g. for language switchers and
 * hreflang links.
 *
 * @internal Registered by core's extend.php
 */
final readonly class TranslationsLoader implements RelationLoaderInterface
{
    public function load(array $items, ContentQuery $query): array
    {
        $groups = array_values(array_unique(array_filter(array_map(static fn (Content $item): ?string => $item->translationGroupId, $items), static fn (?string $id): bool => null !== $id)));
        $byGroup = [];

        if ([] !== $groups) {
            foreach ($query->related()->where('translation_group_id', 'in', $groups)->orderBy('created_at')->get() as $translation) {
                $byGroup[$translation->translationGroupId ?? ''][$translation->locale] = $translation;
            }
        }

        $values = [];

        foreach ($items as $item) {
            $values[$item->id] = array_filter($byGroup[$item->translationGroupId ?? ''] ?? [], static fn (Content $translation): bool => $translation->id !== $item->id);
        }

        return $values;
    }
}
