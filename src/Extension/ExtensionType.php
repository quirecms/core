<?php

declare(strict_types=1);

namespace Quire\Extension;

/**
 * The Composer package types Quire recognises.
 *
 * @api
 */
enum ExtensionType: string
{
    case Extension = 'quire-extension';
    case Theme = 'quire-theme';
}
