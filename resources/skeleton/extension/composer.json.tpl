{
    "name": "@@PACKAGE@@",
    "type": "quire-extension",
    "description": "@@TITLE@@ extension for Quire.",
    "license": "proprietary",
    "require": {
        "php": ">=8.5",
        "quire/core": "^0.1@dev"
    },
    "require-dev": {
        "quire/testing": "^0.1@dev"
    },
    "autoload": {
        "psr-4": {
            "@@NAMESPACE_ESCAPED@@\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "@@NAMESPACE_ESCAPED@@\\Tests\\": "tests/"
        }
    },
    "extra": {
        "quire": {
            "id": "@@ID@@",
            "title": "@@TITLE@@"
        }
    }
}
