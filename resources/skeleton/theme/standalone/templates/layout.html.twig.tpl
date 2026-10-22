{#
    The page around every page.

    Variables: site (title, description, url, locale, direction), theme, canonical_url when the page has
    one, and the variables of the page's own template.
    Blocks: title, content
    Slots: head, body_start, header.nav, sidebar, footer, body_end
#}
<!doctype html>
<html lang="{{ app.request.locale|default(site.locale) }}" dir="{{ site.direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}{{ site.title }}{% endblock %}</title>
    {{ quire_seo() }}
    {{ quire_slot('head') }}
</head>
<body>
    {{ quire_slot('body_start') }}
    <header>
        <a href="{{ quire_home_url() }}">{{ site.title }}</a>
        {% if quire_slot_filled('header.nav') %}<nav aria-label="Main">{{ quire_slot('header.nav') }}</nav>{% endif %}
    </header>
    <main id="content">
        {% block content %}{% endblock %}
    </main>
    {% if quire_slot_filled('sidebar') %}<aside>{{ quire_slot('sidebar') }}</aside>{% endif %}
    <footer>{{ quire_slot('footer') }}</footer>
    {{ quire_slot('body_end') }}
</body>
</html>
