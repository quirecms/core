<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@@TITLE@@</title>
</head>
<body>
    <main>
        <h1>{{ greeting }}</h1>

        {% if messages is empty %}
            <p>No messages yet.</p>
        {% else %}
            <ul>
                {% for message in messages %}
                    <li>{{ message }}</li>
                {% endfor %}
            </ul>
        {% endif %}
    </main>
</body>
</html>
