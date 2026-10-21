<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Formatter\FormatterProfile;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Builds the HTML sanitiser for a profile.
 *
 * @internal
 */
final class Sanitizers
{
    private const array LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const array BASIC_ELEMENTS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'del' => [], 's' => [],
        'code' => [], 'pre' => [], 'blockquote' => [], 'ul' => [], 'ol' => ['start'], 'li' => [],
        'a' => ['href', 'title'],
    ];

    /** Dropped with their content: what's inside them isn't text meant for the reader. */
    private const array DROPPED = ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'textarea', 'select', 'button'];

    public static function for(FormatterProfile $profile): HtmlSanitizer
    {
        $config = new HtmlSanitizerConfig()
            // The default (20 000 characters) would silently cut long articles.
            ->withMaxInputLength(-1)
            ->allowLinkSchemes(self::LINK_SCHEMES)
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias();

        if (FormatterProfile::BASIC === $profile->sanitizer) {
            $config = $config->defaultAction(HtmlSanitizerAction::Block);

            foreach (self::BASIC_ELEMENTS as $element => $attributes) {
                $config = $config->allowElement($element, $attributes);
            }

            if ($profile->images) {
                $config = $config->allowElement('img', ['src', 'alt', 'title', 'width', 'height']);
            }
        } else {
            // Code blocks keep their language class ("language-php") for syntax highlighters, and task
            // lists their checkboxes (forms are dropped, so inputs can't submit anything).
            $config = $config->allowSafeElements()
                ->allowAttribute('class', ['code'])
                ->allowElement('input', ['type', 'checked', 'disabled']);

            if (!$profile->images) {
                $config = $config->dropElement('img')->dropElement('picture')->dropElement('source');
            }
        }

        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        if ([] !== $profile->linkRel) {
            $config = $config->forceAttribute('a', 'rel', implode(' ', $profile->linkRel));
        }

        return new HtmlSanitizer($config);
    }
}
