<?php

declare(strict_types=1);

namespace Quire\Content\Internal;

use Quire\Content\Field\FieldType;
use Quire\Content\Field\Type\RelationField;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Checks relation fields once every extension has declared its types: they link to types that exist, and
 * their inverse names are free on those types. Then compiles the inverses: for each type, the relations
 * that list the items linking to it.
 *
 * @internal
 */
final class RelationFieldsPass implements CompilerPassInterface
{
    /** Parameter: target type → inverse name → list<{type, field}> */
    public const string INVERSES_PARAMETER = 'quire.content.relation_inverses';

    /** Relations every type has, which inverses can't be named after */
    private const array CORE_RELATIONS = ['parent', 'children', 'translations'];

    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, array{owner: string, fields?: array<string, array{name: string, type: string, options: array<string, mixed>}>}> $types */
        $types = $container->getParameterBag()->all()[ContentTypeRegistry::PARAMETER] ?? [];
        $inverses = [];

        foreach ($types as $id => $type) {
            foreach ($type['fields'] ?? [] as $name => $field) {
                if (FieldType::Relation->value !== $field['type']) {
                    continue;
                }

                $where = \sprintf('Extend\ContentType("%s")::field("%s")', $id, $name);
                /** @var list<string> $targets */
                $targets = (array) ($field['options'][RelationField::TYPES] ?? []);
                $inverse = $field['options'][RelationField::INVERSE] ?? null;

                foreach ($targets as $target) {
                    if (!isset($types[$target])) {
                        throw new InvalidArgumentException(\sprintf('%s links to the type "%s", which isn\'t registered. Types: %s.', $where, $target, implode(', ', array_keys($types))));
                    }

                    if (!\is_string($inverse)) {
                        continue;
                    }

                    $taken = match (true) {
                        isset($types[$target]['fields'][$inverse]) => 'a field of',
                        \in_array($inverse, self::CORE_RELATIONS, true) => 'a relation of every type, including',
                        isset($inverses[$target][$inverse]) && !\in_array(['type' => $id, 'field' => $name], $inverses[$target][$inverse], true) => 'already the inverse of another relation of',
                        default => null,
                    };

                    if (null !== $taken) {
                        throw new InvalidArgumentException(\sprintf('%s: the inverse "%s" is %s the type "%s". Choose another name.', $where, $inverse, $taken, $target));
                    }

                    $inverses[$target][$inverse][] = ['type' => $id, 'field' => $name];
                }
            }
        }

        $container->setParameter(self::INVERSES_PARAMETER, $inverses);
    }
}
