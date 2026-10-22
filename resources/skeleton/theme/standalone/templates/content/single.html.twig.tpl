{#
    A content item.

    Variables: content (the item), type (its content type), body (the body as HTML), canonical_url
    Slots: content.before_title, content.after_title, content.before_body, content.after_body,
    content.footer, each with { content }
#}
{% extends 'layout.html.twig' %}

{% block title %}{{ content.title }} – {{ site.title }}{% endblock %}

{% block content %}
    <article>
        {{ quire_slot('content.before_title', { content }) }}
        <h1>{{ content.title }}</h1>
        {% if content.publishedAt and type.has('scheduling') %}
            <p><time datetime="{{ content.publishedAt|date('c') }}">{{ content.publishedAt|quire_date }}</time></p>
        {% endif %}
        {{ quire_slot('content.after_title', { content }) }}
        {{ quire_slot('content.before_body', { content }) }}
        {{ body|raw }}
        {{ quire_slot('content.after_body', { content }) }}
        {{ quire_slot('content.footer', { content }) }}
    </article>
{% endblock %}
