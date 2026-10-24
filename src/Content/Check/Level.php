<?php

declare(strict_types=1);

namespace Quire\Content\Check;

/**
 * How much a content check's findings matter: errors block publishing, warnings don't.
 *
 * @api
 */
enum Level: string
{
    case Error = 'error';
    case Warning = 'warning';
}
