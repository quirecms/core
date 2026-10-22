# @@TITLE@@

A theme for [Quire](https://quirecms.com), built on `@@PARENT@@`: it has that theme's templates, stylesheet and settings, and changes what's in this package.

- **Styles:** `assets/styles.css`, loaded after the parent's stylesheet.
- **Templates:** to change one, copy it from the parent theme to the same path in `templates/`, e.g. `templates/content/single.html.twig`, and edit it there. To build on it instead, extend the parent's version: `{% extends '@@@PARENT@@/content/single.html.twig' %}`.
- **Files:** put images and fonts in `assets/` and link them with `{{ quire_asset('@@ID@@/logo.svg') }}`.

Check the theme with `bin/console quire:theme:lint @@ID@@`, and run its tests with `vendor/bin/phpunit -c themes/@@NAME@@` from the site.
