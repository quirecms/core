<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * A shortcode as written in the text: `[[gallery id=3 caption="Our trip" wide]]` has the name "gallery"
 * and the attributes id: "3", caption: "Our trip" and wide: true.
 *
 * Attribute values come from the author: escape them like any user input.
 *
 * @api
 */
final readonly class Shortcode
{
    /**
     * @param array<string, string|true> $attributes
     * @param string                     $source     The shortcode as written, e.g. to show it when it fails
     */
    public function __construct(
        public string $name,
        public array $attributes,
        public string $source,
    ) {
    }

    public function attribute(string $name, ?string $default = null): ?string
    {
        $value = $this->attributes[$name] ?? $default;

        return true === $value ? '' : $value;
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->attributes);
    }
}
