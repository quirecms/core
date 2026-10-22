<?php

declare(strict_types=1);

namespace Quire\Theme\Exception;

/**
 * No asset has the logical path a template or an extender asked for.
 *
 * @api
 */
final class AssetNotFoundException extends \RuntimeException
{
}
