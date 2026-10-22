<?php

declare(strict_types=1);

namespace Quire\Content\Event;

use Quire\Content\Content;
use Symfony\Component\HttpFoundation\Request;

/**
 * Dispatched before a content item's page is rendered. Listeners can add template variables, or deny
 * the view, which responds with "404 Not Found".
 *
 *     #[AsEventListener]
 *     public function __invoke(ContentViewing $event): void
 *     {
 *         $event->set('related', $this->related->for($event->content));
 *     }
 *
 * @api
 */
final class ContentViewing
{
    /** @var array<string, mixed> */
    private array $variables = [];

    private bool $denied = false;

    public function __construct(
        public readonly Content $content,
        public readonly Request $request,
    ) {
    }

    /**
     * Adds a variable for the template.
     */
    public function set(string $name, mixed $value): void
    {
        $this->variables[$name] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * Responds with "404 Not Found" instead of showing the item.
     */
    public function deny(): void
    {
        $this->denied = true;
    }

    public function isDenied(): bool
    {
        return $this->denied;
    }
}
