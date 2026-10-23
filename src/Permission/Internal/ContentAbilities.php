<?php

declare(strict_types=1);

namespace Quire\Permission\Internal;

use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Extend\Permissions;
use Quire\User\Role;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Declares the abilities of every content type, once all types are registered: content.<type>.view,
 * view_drafts, create, edit_own, edit_any, publish, review and delete.
 *
 * @internal
 */
final class ContentAbilities
{
    /** @var array<string, array{string, list<string>}> Ability => label, default roles */
    private const array ABILITIES = [
        'view' => ['View %s', [Role::GUEST]],
        'view_drafts' => ['Preview unpublished %s', [Role::EDITOR]],
        'create' => ['Create %s', [Role::EDITOR]],
        'edit_own' => ['Edit their own %s', [Role::EDITOR]],
        'edit_any' => ['Edit any %s', [Role::EDITOR]],
        'publish' => ['Publish %s', [Role::EDITOR]],
        'review' => ['Review changes to %s', [Role::EDITOR]],
        'delete' => ['Delete %s', [Role::EDITOR]],
    ];

    public static function declare(ContainerBuilder $container): void
    {
        $parameters = $container->getParameterBag()->all();
        /** @var array{roles: array<string, mixed>, abilities: array<string, mixed>, rules: list<mixed>} $config */
        $config = \is_array($parameters[Permissions::PARAMETER] ?? null) ? $parameters[Permissions::PARAMETER] : ['roles' => [], 'abilities' => [], 'rules' => []];
        /** @var array<string, array<string, mixed>> $types */
        $types = \is_array($parameters[ContentTypeRegistry::PARAMETER] ?? null) ? $parameters[ContentTypeRegistry::PARAMETER] : [];

        foreach ($types as $data) {
            $type = ContentTypeDefinition::fromArray($data);

            foreach (self::ABILITIES as $action => [$label, $roles]) {
                $config['abilities'][self::name($type->id, $action)] = [
                    'label' => \sprintf($label, strtolower($type->pluralLabel)),
                    'roles' => $roles,
                    'group' => 'content',
                    'strict' => false,
                    'owner' => $type->owner,
                ];
            }
        }

        $container->setParameter(Permissions::PARAMETER, $config);
    }

    public static function name(string $type, string $action): string
    {
        return \sprintf('content.%s.%s', $type, $action);
    }
}
