# @@TITLE@@

A [Quire](https://quirecms.com) extension.

| | |
|---|---|
| Package | `@@PACKAGE@@` |
| Extension ID | `@@ID@@` |
| Page | `@@PATH@@` |

## What's inside

| Path | Contents |
|------|----------|
| `extend.php` | What the extension adds to Quire: its services, route, templates, setting and migrations |
| `src/` | PHP code, autoloaded as `@@NAMESPACE@@\` |
| `templates/` | Twig templates, available as `@@@TWIG_NAMESPACE@@/...` |
| `migrations/` | Database migrations: run when the extension is enabled |
| `tests/` | PHPUnit tests, using [quire/testing](https://github.com/quirecms/framework/blob/main/docs/extensions.md#testing-your-extension) |

## Common tasks

```bash
# Enable or disable the extension
bin/console quire:extension:enable @@ID@@
bin/console quire:extension:disable @@ID@@

# Change its setting
bin/console quire:settings:set @@ID@@.greeting "Welcome!"

# Run its tests (from the site's root directory)
vendor/bin/phpunit -c extensions/@@NAME@@
```
