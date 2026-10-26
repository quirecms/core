<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Theme\SlotRendererInterface;
use Quire\Theme\Slots;

/**
 * Registers Twig templates and globals.
 *
 *     (new Extend\Views())
 *         ->namespace('reviews', __DIR__.'/templates')   // render '@reviews/list.html.twig'
 *         ->slot('content.after_body', '@reviews/summary.html.twig')
 *         ->global('reviews_per_page', 20);
 *
 * Sites and themes override a namespace's templates by placing files in templates/extensions/<namespace>/,
 * e.g. templates/extensions/reviews/list.html.twig. An override can extend the original as
 * '@!reviews/list.html.twig'.
 *
 * @api
 */
final class Views implements ExtenderInterface
{
    /** @internal Parameter listing the namespaces as {namespace, directory, owner} */
    public const string PARAMETER = 'quire.views.namespaces';

    private const string NAMESPACE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

    private const string SLOT_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/';

    /** @var list<array{slot: string, fragment: string, priority: int}> */
    private array $slots = [];

    /** @var array<string, string> Namespace => directory */
    private array $namespaces = [];

    /** @var array<string, mixed> */
    private array $globals = [];

    /**
     * @internal What it declares, e.g. for previewing a theme
     *
     * @return array{namespaces: array<string, string>, slots: int, globals: int}
     */
    public function declared(): array
    {
        return ['namespaces' => $this->namespaces, 'slots' => \count($this->slots), 'globals' => \count($this->globals)];
    }

    /**
     * Makes the templates in $directory available as `@namespace/...`.
     */
    public function namespace(string $namespace, string $directory): self
    {
        $this->namespaces[$namespace] = $directory;

        return $this;
    }

    /**
     * Fills a slot of the theme, e.g. "content.after_body" (see {@see Slots::STANDARD}), with a template
     * (a name ending in ".twig", which gets the slot's context as variables) or a {@see SlotRendererInterface}
     * class. Fragments with a higher priority come first.
     */
    public function slot(string $slot, string $fragment, int $priority = 0): self
    {
        $this->slots[] = ['slot' => $slot, 'fragment' => $fragment, 'priority' => $priority];

        return $this;
    }

    /**
     * Adds a variable available in every template. Values must be scalars or arrays of scalars.
     */
    public function global(string $name, mixed $value): self
    {
        $this->globals[$name] = $value;

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $registered = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];

        foreach ($this->namespaces as $namespace => $directory) {
            $where = \sprintf('Extend\Views::namespace("%s")', $namespace);

            if (1 !== preg_match(self::NAMESPACE_PATTERN, $namespace)) {
                throw $context->error($where, 'Namespaces must start with a letter and contain only letters, digits and underscores, e.g. "reviews".');
            }

            if ('Quire' === $namespace) {
                throw $context->error($where, 'The "Quire" namespace is reserved for core templates. Use your extension\'s own name.');
            }

            if (!is_dir($directory)) {
                throw $context->error($where, \sprintf('The directory "%s" doesn\'t exist.', $directory));
            }

            foreach (\is_array($registered) ? $registered : [] as $other) {
                if (\is_array($other) && ($other['namespace'] ?? null) === $namespace) {
                    throw $context->error($where, \sprintf('The namespace is already used by "%s". Each extension needs its own namespace.', \is_string($other['owner'] ?? null) ? $other['owner'] : 'another extension'));
                }
            }

            $context->appendToParameter(self::PARAMETER, ['namespace' => $namespace, 'directory' => $directory, 'owner' => $context->id]);
        }

        foreach ($this->slots as $slot) {
            $where = \sprintf('Extend\Views::slot("%s", "%s")', $slot['slot'], $slot['fragment']);

            if (1 !== preg_match(self::SLOT_PATTERN, $slot['slot'])) {
                throw $context->error($where, 'Slot names are lowercase words separated by dots, e.g. "content.after_body".');
            }

            $isTemplate = str_ends_with($slot['fragment'], '.twig');

            if (!$isTemplate && !is_a($slot['fragment'], SlotRendererInterface::class, true)) {
                throw $context->error($where, \sprintf('The fragment must be a template name ending in ".twig", or a class implementing %s.', SlotRendererInterface::class));
            }

            $context->appendToParameter(Slots::PARAMETER, [
                'slot' => $slot['slot'],
                'template' => $isTemplate ? $slot['fragment'] : null,
                'class' => $isTemplate ? null : $slot['fragment'],
                'priority' => $slot['priority'],
                'owner' => $context->id,
            ]);
        }

        foreach ($this->globals as $name => $value) {
            if (!self::isScalarOrArrayOfScalars($value)) {
                throw $context->error(\sprintf('Extend\Views::global("%s")', $name), \sprintf('Globals must be scalars or arrays of scalars, got %s. For objects, register a Twig extension instead.', get_debug_type($value)));
            }
        }

        if ([] !== $this->globals) {
            $context->prependConfig('twig', ['globals' => $this->globals]);
        }
    }

    private static function isScalarOrArrayOfScalars(mixed $value): bool
    {
        if (\is_array($value)) {
            return array_all($value, self::isScalarOrArrayOfScalars(...));
        }

        return null === $value || \is_scalar($value);
    }
}
