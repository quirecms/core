<?php

declare(strict_types=1);

namespace Quire\Extension\Exception;

/**
 * Enabling or disabling extensions would leave unmet dependencies.
 *
 * @api
 */
final class DependencyException extends \RuntimeException
{
}
