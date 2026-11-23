<?php

declare(strict_types=1);

namespace Quire\Translation;

use Quire\Content\Type\ContentTypeDefinition;
use Quire\Extend\ExtenderContext;
use Quire\Permission\Permissions;
use Quire\Settings\SettingDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The names people read for content types, roles and settings, in the language of the page: the only way
 * to print them. Each is translated in its owner's domain (core's in "quire", the site's in "site", an
 * extension's in its ID). A type's sentences ("New post", "Search 54 posts…") are its own phrases, worded
 * whole in each language; a type without them reads "New: Recipe".
 *
 * Short names are ambiguous ("Post" a noun or a verb, "Editor" a role or a program), so their keys carry
 * their context after a "|": "Post|content type", "Editor|role". The English text is the key without it.
 *
 * @api
 */
final readonly class Labels implements RuntimeExtensionInterface
{
    public const string TYPE_CONTEXT = 'content type';

    public const string ROLE_CONTEXT = 'role';

    /** What a type that doesn't word a phrase reads: neutral forms with its name, in core's texts */
    private const array NEUTRAL = [
        'new' => 'New: {type}',
        'all' => 'All: {types}',
        'count' => '{types}: {count}',
        'search' => 'Search: {types}…',
        'empty' => 'Nothing here yet.',
    ];

    public function __construct(
        #[Autowire(service: 'translator')]
        private TranslatorInterface&TranslatorBagInterface $translator,
        private Permissions $permissions,
    ) {
    }

    /**
     * The translation domain of what an owner declares: "core" → "quire", "site" → "site", an extension's
     * or theme's ID → itself.
     */
    public static function domainOf(string $owner): string
    {
        return match ($owner) {
            ExtenderContext::CORE => 'quire',
            ExtenderContext::SITE => TranslationsDeclaration::SITE_DOMAIN,
            default => $owner,
        };
    }

    /**
     * A key with its context: "Post|content type". Its English text is the part before the "|".
     */
    public static function inContext(string $text, string $context): string
    {
        return $text.'|'.$context;
    }

    public function type(ContentTypeDefinition $type, bool $plural = false): string
    {
        return $this->name($plural ? $type->pluralLabel : $type->label, self::TYPE_CONTEXT, self::domainOf($type->owner));
    }

    /**
     * One of the type's sentences ({@see ContentTypeDefinition::PHRASES}), with its parameters (e.g. count).
     *
     * @param array<string, mixed> $parameters
     */
    public function phrase(ContentTypeDefinition $type, string $phrase, array $parameters = []): string
    {
        if (!\in_array($phrase, ContentTypeDefinition::PHRASES, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a phrase of a type. Phrases: %s.', $phrase, implode(', ', ContentTypeDefinition::PHRASES)));
        }

        if (isset($type->phrases[$phrase])) {
            $message = $type->phrases[$phrase];
            $domain = self::domainOf($type->owner);

            [$text, $locale] = [$message, 'en'];

            // Phrases are ICU messages by contract, so they're formatted here, whatever kind of domain holds
            // their translation: Symfony's plain formatter would swap "count" inside "{count, plural, …}"
            for ($catalogue = $this->translator->getCatalogue(); $catalogue instanceof \Symfony\Component\Translation\MessageCatalogueInterface; $catalogue = $catalogue->getFallbackCatalogue()) {
                if ($catalogue->defines($message, $domain)) {
                    [$text, $locale] = [$catalogue->get($message, $domain), $catalogue->getLocale()];

                    break;
                }
            }

            $formatted = \MessageFormatter::formatMessage($locale, $text, $parameters);

            if (false === $formatted) {
                throw new \LogicException(\sprintf('The phrase "%s" of the type "%s" can\'t be formatted in "%s": %s.', $phrase, $type->id, $locale, intl_get_error_message()));
            }

            return $formatted;
        }

        return $this->translator->trans(self::NEUTRAL[$phrase], ['type' => $this->type($type), 'types' => $this->type($type, plural: true), ...$parameters], 'quire');
    }

    /**
     * A role's name. The site's own roles read as they were named; the others are their owner's to translate.
     */
    public function role(string $role): string
    {
        $label = $this->permissions->roles()[$role] ?? $role;
        $owner = $this->permissions->roleOwner($role);

        return null === $owner ? $label : $this->name($label, self::ROLE_CONTEXT, self::domainOf($owner));
    }

    /**
     * Every role, by name, as people read it: "Everyone" first, then declared roles, then the site's own.
     *
     * @return array<string, string>
     */
    public function roles(): array
    {
        $roles = [];

        foreach (array_keys($this->permissions->roles()) as $role) {
            $roles[$role] = $this->role($role);
        }

        return $roles;
    }

    public function setting(SettingDefinition $setting): string
    {
        return $this->translator->trans($setting->label ?? $setting->key, [], self::domainOf($setting->owner));
    }

    public function settingHelp(SettingDefinition $setting): ?string
    {
        return null === $setting->help ? null : $this->translator->trans($setting->help, [], self::domainOf($setting->owner));
    }

    private function name(string $text, string $context, string $domain): string
    {
        $key = self::inContext($text, $context);
        $name = $this->translator->trans($key, [], $domain);

        return $name === $key ? $text : $name;
    }
}
