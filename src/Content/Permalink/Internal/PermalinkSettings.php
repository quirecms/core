<?php

declare(strict_types=1);

namespace Quire\Content\Permalink\Internal;

use Quire\Content\Permalink\PermalinkPattern;
use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\SettingDefinition;
use Quire\Settings\SettingsSchema;
use Quire\Settings\SettingType;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The settings for each content type's URLs, declared once all content types are known.
 *
 * - "quire.permalinks.<type>": the URL pattern of the type's items, e.g. "/blog/{slug}";
 * - "quire.listings.<type>": the URL of the type's listing, e.g. "/blog", for listable types.
 *
 * @internal
 */
final class PermalinkSettings
{
    public const string PERMALINK_PREFIX = 'quire.permalinks.';

    public const string LISTING_PREFIX = 'quire.listings.';

    public static function declare(ContainerBuilder $container): void
    {
        $parameters = $container->getParameterBag()->all();
        /** @var array<string, array<string, mixed>> $settings */
        $settings = \is_array($parameters[SettingsSchema::PARAMETER] ?? null) ? $parameters[SettingsSchema::PARAMETER] : [];
        /** @var array<string, array<string, mixed>> $types */
        $types = \is_array($parameters[ContentTypeRegistry::PARAMETER] ?? null) ? $parameters[ContentTypeRegistry::PARAMETER] : [];

        foreach ($types as $data) {
            $type = ContentTypeDefinition::fromArray($data);
            $plural = strtolower($type->pluralLabel);

            $settings[self::PERMALINK_PREFIX.$type->id] = new SettingDefinition(
                key: self::PERMALINK_PREFIX.$type->id,
                type: SettingType::String,
                default: $type->permalink,
                owner: $type->owner,
                label: \sprintf('URLs of %s', $plural),
                help: 'For example /blog/{slug} or /{year}/{month}/{slug}. Placeholders: {'.implode('}, {', PermalinkPattern::PLACEHOLDERS).'}. Old URLs keep working: they redirect to the new ones.',
                group: 'permalinks',
            )->toArray();

            if ($type->has(Capability::LISTABLE)) {
                $prefix = PermalinkPattern::parse($type->permalink, $type->has(Capability::HIERARCHICAL))->staticPrefix();

                $settings[self::LISTING_PREFIX.$type->id] = new SettingDefinition(
                    key: self::LISTING_PREFIX.$type->id,
                    type: SettingType::String,
                    default: '' === $prefix ? null : $prefix,
                    owner: $type->owner,
                    label: \sprintf('URL of the list of %s', $plural),
                    help: 'For example /blog. Empty for no list.',
                    group: 'permalinks',
                    nullable: true,
                )->toArray();
            }
        }

        $container->setParameter(SettingsSchema::PARAMETER, $settings);
    }
}
