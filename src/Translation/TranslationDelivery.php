<?php

declare(strict_types=1);

namespace Quire\Translation;

/**
 * How a package's texts in other languages reach a site ("extra.quire.translations.delivery").
 *
 * @api
 */
enum TranslationDelivery: string
{
    /** In the package's own translations/ directory, released with it; language artifacts for it are ignored */
    case Bundled = 'bundled';

    /** As language artifacts built by the translation hub, one per language and version line */
    case Hub = 'hub';
}
