<?php

declare(strict_types=1);

namespace Quire\Audit;

/**
 * What made a change: a web request (e.g. in the admin), a console command, or a background job.
 *
 * @api
 */
enum Origin: string
{
    case Web = 'web';
    case Console = 'console';
    case Job = 'job';
}
