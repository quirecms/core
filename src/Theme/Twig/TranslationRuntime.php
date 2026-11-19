<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;

/**
 * A translated message with HTML in its parameters, never in its text (ARC-0063): the translation is
 * escaped like any text, and only the parameters the code made as HTML (a Markup, such as the
 * `<time>` of `quire_admin_when`) go in as they are; any other parameter is escaped too. A translation
 * is someone else's text: with `|trans|raw`, one that's careless or compromised would be markup, and
 * script, in every page that shows it.
 *
 *     {{ 'saved {when}'|quire_trans_html({when: item.updatedAt|quire_admin_when}, 'quire-admin') }}
 *
 * @internal
 */
final readonly class TranslationRuntime implements RuntimeExtensionInterface
{
    /** Marks where a parameter goes: private-use characters, which escaping leaves as they are */
    private const string MARK = "\u{E000}";

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function transHtml(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): Markup
    {
        $marked = [];
        $html = [];
        $index = 0;

        foreach ($parameters as $name => $value) {
            if ($value instanceof Markup) {
                // The translation gets a mark in its place, and the markup comes back after escaping
                $mark = self::MARK.$index++.self::MARK;
                $marked[$name] = $mark;
                $html[$mark] = (string) $value;
            } else {
                $marked[$name] = $value;
            }
        }

        $text = htmlspecialchars($this->translator->trans($message, $marked, $domain, $locale), \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');

        return new Markup([] === $html ? $text : strtr($text, $html), 'UTF-8');
    }
}
