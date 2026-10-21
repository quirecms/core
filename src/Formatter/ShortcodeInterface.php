<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * Renders a shortcode such as `[[gallery id=3]]`. Register it with {@see \Quire\Extend\Formatter::shortcode()}.
 *
 * Shortcodes run each time the text is shown, not when it's cached, so their output can change, e.g.
 * when the gallery gets new images. Cache expensive work yourself.
 *
 * @api
 */
interface ShortcodeInterface
{
    /**
     * @return string HTML. Unless the shortcode is registered as trusted, it's sanitised with the profile's policy.
     */
    public function render(Shortcode $shortcode, FormatContext $context): string;
}
