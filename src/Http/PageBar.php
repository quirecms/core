<?php

declare(strict_types=1);

namespace Quire\Http;

/**
 * The bar at the top of a page that only its viewer sees, with a button to stop (seeing the site as
 * someone else, previewing a theme). Its styles come with it in a `<style>` element with the
 * response's nonce, since a theme's styles are unknown and the policy allows no style attributes.
 *
 * @internal
 */
final class PageBar
{
    /**
     * @param string $class  The bar's class, e.g. "quire-view-as"
     * @param string $action Where the button posts
     */
    public static function html(string $class, string $label, string $text, string $action, string $token, string $back, string $button, string $nonce): string
    {
        return \sprintf(
            '<style nonce="%1$s">.%2$s{position:relative;z-index:2147483000;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:center;padding:.6rem 1rem;background:#1d1a24;color:#fff;font:600 15px/1.4 system-ui,sans-serif}'
            .'.%2$s form{margin:0}.%2$s button{min-height:2.25rem;padding:.3rem 1rem;border:0;border-radius:999px;background:#f0b04a;color:#1d1a24;font:inherit;cursor:pointer}</style>'
            .'<div class="%2$s" role="region" aria-label="%3$s"><span>%4$s</span>'
            .'<form method="post" action="%5$s"><input type="hidden" name="_token" value="%6$s"><input type="hidden" name="back" value="%7$s">'
            .'<button type="submit">%8$s</button></form></div>',
            htmlspecialchars($nonce),
            $class,
            htmlspecialchars($label),
            htmlspecialchars($text),
            htmlspecialchars($action),
            htmlspecialchars($token),
            htmlspecialchars($back),
            htmlspecialchars($button),
        );
    }

    private function __construct()
    {
    }
}
