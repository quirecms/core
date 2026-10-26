<?php

declare(strict_types=1);

namespace Quire\Action;

/**
 * Something an action needs, e.g. the content item to publish.
 *
 * @api
 */
final readonly class Parameter
{
    /**
     * @param string            $name     The key of its argument, e.g. "content"
     * @param string            $label    What it is, e.g. "Item"
     * @param bool              $required Whether the action needs it
     * @param bool              $multiple Whether it's a list of values
     * @param list<string>|null $choices  The values it may have, if they're fixed
     * @param bool              $secret   Whether its value must never be shown or recorded, e.g. a password
     */
    public function __construct(
        public string $name,
        public ParameterType $type,
        public string $label,
        public bool $required = true,
        public bool $multiple = false,
        public ?array $choices = null,
        public bool $secret = false,
    ) {
    }
}
