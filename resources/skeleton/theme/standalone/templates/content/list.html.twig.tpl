{#
    A list of items, one page at a time.

    Variables: type (the content type), page (iterate it for the items; page.page, page.pageCount),
    is_home, previous_url, next_url, canonical_url
#}
{% extends 'layout.html.twig' %}

{% block content %}
    <h1>{{ is_home ? site.title : type.pluralLabel }}</h1>

    {% for item in page %}
        <article>
            <h2><a href="{{ quire_url(item) }}">{{ item.title }}</a></h2>
            <p>{{ quire_excerpt(item) }}</p>
        </article>
    {% endfor %}

    {% if previous_url or next_url %}
        <nav aria-label="Pages">
            {% if previous_url %}<a href="{{ previous_url }}" rel="prev">Newer</a>{% endif %}
            {% if next_url %}<a href="{{ next_url }}" rel="next">Older</a>{% endif %}
        </nav>
    {% endif %}
{% endblock %}
