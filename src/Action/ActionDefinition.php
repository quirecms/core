<?php

declare(strict_types=1);

namespace Quire\Action;

/**
 * What an action is called and what it needs.
 *
 * @api
 */
final readonly class ActionDefinition
{
    /**
     * @param string          $label       What it does, as a short instruction, e.g. "Publish an item"
     * @param list<Parameter> $parameters  What it needs
     * @param string|null     $command     The console command that does the same, e.g. "quire:content:transition"
     * @param bool            $destructive Whether it deletes what can't be had back, so it's confirmed first
     */
    public function __construct(
        public string $label,
        public array $parameters = [],
        public ?string $command = null,
        public bool $destructive = false,
    ) {
    }

    public function parameter(string $name): ?Parameter
    {
        return array_find($this->parameters, static fn (Parameter $parameter): bool => $parameter->name === $name);
    }
}
