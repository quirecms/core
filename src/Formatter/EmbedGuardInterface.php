<?php

declare(strict_types=1);

namespace Quire\Formatter;

/**
 * What a trusted shortcode prints passes through here before it's shown, e.g. an embedded video, so it
 * can wait for the visitor's consent: `quire/privacy` turns what loads from another company's service
 * into a placeholder that loads it once the visitor allows it. Without it, output is shown as it is.
 *
 * Only trusted shortcodes' output: everything else is sanitised, and keeps no embeds.
 *
 * @api
 */
interface EmbedGuardInterface
{
    /**
     * @param string $html What a trusted shortcode printed
     *
     * @return string The same, with embeds that need consent in placeholders
     */
    public function guard(string $html): string;
}
