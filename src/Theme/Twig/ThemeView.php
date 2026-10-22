<?php

declare(strict_types=1);

namespace Quire\Theme\Twig;

use Quire\Settings\Settings;
use Quire\Theme\Themes;

/**
 * The `theme` variable of every template: `{{ theme.id }}`, `{{ theme.settings.accent }}`.
 *
 * @api
 */
final readonly class ThemeView
{
    public function __construct(
        private Themes $themes,
        private Settings $settings,
    ) {
    }

    /**
     * The active theme's ID; null when the site uses Quire's built-in templates.
     */
    public function id(): ?string
    {
        return $this->themes->active()?->id;
    }

    public function title(): ?string
    {
        return $this->themes->active()?->title;
    }

    /**
     * The theme's settings without their "theme.<id>." prefix, including those of the themes it inherits
     * from: a child theme's setting with the same name wins.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $settings = [];

        foreach (array_reverse($this->themes->chain()) as $id) {
            $prefix = 'theme.'.$id.'.';

            foreach ($this->settings->all($prefix) as $key => $value) {
                $settings[substr($key, \strlen($prefix))] = $value;
            }
        }

        return $settings;
    }
}
