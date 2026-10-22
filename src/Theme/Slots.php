<?php

declare(strict_types=1);

namespace Quire\Theme;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * Named places in a theme's templates that extensions fill: `{{ quire_slot('content.after_body', { content }) }}`.
 *
 * Extensions add fragments to slots with Extend\Views::slot(), as templates or {@see SlotRendererInterface}
 * services. A slot renders its fragments by priority, highest first. Slots are the contract between themes
 * and extensions: every theme renders the {@see STANDARD} slots, so extensions work with any theme.
 *
 * @api
 */
final readonly class Slots
{
    /** @internal Parameter listing the fragments, sorted by slot and priority */
    public const string PARAMETER = 'quire.slots';

    /**
     * The slots every theme renders, with what they get as context.
     *
     * @var array<string, string>
     */
    public const array STANDARD = [
        'head' => 'Inside <head>, e.g. meta tags and stylesheets. No context.',
        'body_start' => 'Right after <body>, e.g. a skip link or a banner. No context.',
        'body_end' => 'Right before </body>, e.g. scripts. No context.',
        'header.nav' => 'The navigation in the site header. No context.',
        'content.before_title' => 'Before a content item\'s title. Context: content.',
        'content.after_title' => 'After a content item\'s title, e.g. its date and author. Context: content.',
        'content.before_body' => 'Before a content item\'s body. Context: content.',
        'content.after_body' => 'After a content item\'s body, e.g. reviews or share buttons. Context: content.',
        'content.footer' => 'At the end of a content item, e.g. comments. Context: content.',
        'sidebar' => 'The sidebar, when the theme has one. No context.',
        'footer' => 'The site footer. No context.',
    ];

    /** @var array<string, list<array{template: ?string, class: ?string, owner: string}>> */
    private array $fragments;

    /**
     * @param list<array{slot: string, template: ?string, class: ?string, priority: int, owner: string}> $fragments Sorted
     * @param ContainerInterface                                                                         $renderers The {@see SlotRendererInterface} services, by class
     */
    public function __construct(
        private Environment $twig,
        #[Autowire(param: self::PARAMETER)]
        array $fragments,
        private ContainerInterface $renderers,
        private LoggerInterface $logger,
        #[Autowire(param: 'kernel.debug')]
        private bool $debug,
    ) {
        $bySlot = [];

        foreach ($fragments as $fragment) {
            $bySlot[$fragment['slot']][] = ['template' => $fragment['template'], 'class' => $fragment['class'], 'owner' => $fragment['owner']];
        }

        $this->fragments = $bySlot;
    }

    /**
     * The slot's fragments, one after the other.
     *
     * A fragment that fails is left out, and the error logged, so one extension can't break every page;
     * in debug mode, the error is shown instead.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $name, array $context = []): string
    {
        $html = '';

        foreach ($this->fragments[$name] ?? [] as $fragment) {
            try {
                $output = null !== $fragment['template']
                    ? $this->twig->render($fragment['template'], $context)
                    : $this->renderer((string) $fragment['class'])->render($context);
            } catch (\Throwable $e) {
                if ($this->debug) {
                    throw $e;
                }

                $this->logger->error('The fragment of "{owner}" in the slot "{slot}" failed, so it was left out: {message}', ['owner' => $fragment['owner'], 'slot' => $name, 'message' => $e->getMessage(), 'exception' => $e]);

                continue;
            }

            $html .= $this->debug ? \sprintf("<!-- slot %s: %s -->\n%s", $name, $fragment['owner'], $output) : $output;
        }

        return $html;
    }

    /**
     * Whether anything fills the slot, e.g. to leave out a sidebar's markup when it would be empty.
     */
    public function has(string $name): bool
    {
        return isset($this->fragments[$name]);
    }

    /**
     * Who fills the slot, in order, e.g. [["owner" => "acme-reviews", "fragment" => "@reviews/summary.html.twig"]].
     *
     * @return list<array{owner: string, fragment: string}>
     */
    public function fragments(string $name): array
    {
        return array_map(static fn (array $f): array => ['owner' => $f['owner'], 'fragment' => $f['template'] ?? (string) $f['class']], $this->fragments[$name] ?? []);
    }

    private function renderer(string $class): SlotRendererInterface
    {
        $renderer = $this->renderers->get($class);
        \assert($renderer instanceof SlotRendererInterface);

        return $renderer;
    }
}
