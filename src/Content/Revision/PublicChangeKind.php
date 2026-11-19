<?php

declare(strict_types=1);

namespace Quire\Content\Revision;

/**
 * What a published change is for readers: an update (something added or brought up to date), or a
 * correction (something was wrong: the note says what).
 *
 * @api
 */
enum PublicChangeKind: string
{
    case Update = 'update';
    case Correction = 'correction';
}
