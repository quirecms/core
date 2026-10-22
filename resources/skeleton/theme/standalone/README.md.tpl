# @@TITLE@@

A theme for [Quire](https://quirecms.com) with templates of its own.

- **Templates:** `templates/layout.html.twig` is the page around every page; `templates/content/single.html.twig` shows an item and `templates/content/list.html.twig` a list. Every theme renders the standard slots, where extensions add their parts: keep the `quire_slot()` calls.
- **Styles:** `assets/styles.css`.
- **Files:** put images and fonts in `assets/` and link them with `{{ quire_asset('@@ID@@/logo.svg') }}`.

Check the theme with `bin/console quire:theme:lint @@ID@@`, and run its tests with `vendor/bin/phpunit -c themes/@@NAME@@` from the site.
