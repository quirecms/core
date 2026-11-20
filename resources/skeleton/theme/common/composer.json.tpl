{
    "name": "@@PACKAGE@@",
    "type": "quire-theme",
    "description": "@@TITLE@@ theme for Quire.",
    "license": "proprietary",
    "require": {
        "php": ">=8.5",
        "quire/core": "^0.4"
    },
    "require-dev": {
        "quire/testing": "^0.4"
    },
    "autoload-dev": {
        "psr-4": {
            "@@NAMESPACE_ESCAPED@@\\Tests\\": "tests/"
        }
    },
    "extra": {
        "quire": {
            "id": "@@ID@@",
            "title": "@@TITLE@@"@@THEME_SECTION@@
        }
    }
}
