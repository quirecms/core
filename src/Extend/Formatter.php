<?php

declare(strict_types=1);

namespace Quire\Extend;

use League\CommonMark\Extension\ExtensionInterface as CommonMarkExtensionInterface;
use Quire\Formatter\FormatInterface;
use Quire\Formatter\Formatter as Service;
use Quire\Formatter\FormatterProfile;
use Quire\Formatter\HtmlProcessorInterface;
use Quire\Formatter\ShortcodeInterface;
use Quire\Formatter\SourceProcessorInterface;

/**
 * Extends how text is turned into HTML: formats, shortcodes, CommonMark extensions, processors and profiles.
 *
 *     new Extend\Formatter()
 *         ->shortcode('gallery', GalleryShortcode::class)
 *         ->commonMark(FootnoteExtension::class)
 *         ->postProcessor(LazyImages::class)
 *         ->profile('review', formats: ['markdown'], sanitizer: 'basic', linkRel: ['nofollow', 'ugc']);
 *
 * The classes become autowired services. Use them through {@see Service}.
 *
 * @api
 *
 * @phpstan-type Processor array{class: string, profiles: ?list<string>, owner: string}
 * @phpstan-type Config array{formats: array<string, array{class: string, owner: string}>, profiles: array<string, array<string, mixed>>, shortcodes: array<string, array{class: string, trusted: bool, owner: string}>, commonmark: list<Processor>, pre: list<Processor>, post: list<Processor>}
 */
final class Formatter implements ExtenderInterface
{
    private const string NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** @var list<\Closure(ExtenderContext): void> */
    private array $calls = [];

    /** @var Config The configuration being built, during {@see extend()} */
    private array $config = ['formats' => [], 'profiles' => [], 'shortcodes' => [], 'commonmark' => [], 'pre' => [], 'post' => []];

    /**
     * Adds a format that text can be written in.
     *
     * @param class-string<FormatInterface> $class
     * @param list<string>                  $profiles Existing profiles that allow the format
     */
    public function format(string $name, string $class, array $profiles = []): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addFormat($context, $name, $class, $profiles);

        return $this;
    }

    /**
     * Adds a shortcode, written as `[[name attribute=value]]`.
     *
     * @param class-string<ShortcodeInterface> $class
     * @param bool                             $trusted Skip sanitising its output, e.g. for embeds that need iframes.
     *                                                  Only for output that never contains user input unescaped.
     */
    public function shortcode(string $name, string $class, bool $trusted = false): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addShortcode($context, $name, $class, $trusted);

        return $this;
    }

    /**
     * Adds a league/commonmark extension to Markdown parsing: custom syntax, nodes, renderers or AST changes.
     *
     * @param class-string<CommonMarkExtensionInterface> $class
     * @param list<string>|null                          $profiles The profiles to use it in; null for all
     */
    public function commonMark(string $class, ?array $profiles = null): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addProcessor($context, 'commonmark', $class, $profiles);

        return $this;
    }

    /**
     * @param class-string<SourceProcessorInterface> $class
     * @param list<string>|null                      $profiles The profiles to use it in; null for all
     */
    public function preProcessor(string $class, ?array $profiles = null): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addProcessor($context, 'pre', $class, $profiles);

        return $this;
    }

    /**
     * @param class-string<HtmlProcessorInterface> $class
     * @param list<string>|null                    $profiles The profiles to use it in; null for all
     */
    public function postProcessor(string $class, ?array $profiles = null): self
    {
        $this->calls[] = fn (ExtenderContext $context) => $this->addProcessor($context, 'post', $class, $profiles);

        return $this;
    }

    /**
     * Adds a profile: rules for one kind of text, e.g. reviews written by visitors.
     *
     * @param list<string> $formats    Formats the text may be written in
     * @param list<string> $shortcodes Allowed shortcodes; ["*"] allows all
     * @param string       $sanitizer  "rich" (everything safe, including headings, tables and images) or "basic"
     *                                 (paragraphs, emphasis, links, lists, quotes and code)
     * @param bool         $rawHtml    Let Markdown contain HTML, which is still sanitised. Off by default.
     * @param list<string> $linkRel    Added to the rel attribute of every link, e.g. ["nofollow", "ugc"]
     */
    public function profile(
        string $name,
        array $formats,
        array $shortcodes = [],
        string $sanitizer = FormatterProfile::RICH,
        bool $headingAnchors = false,
        bool $images = true,
        bool $rawHtml = false,
        array $linkRel = [],
    ): self {
        $this->calls[] = fn (ExtenderContext $context) => $this->addProfile($context, new FormatterProfile($name, $context->id, $formats, $shortcodes, $sanitizer, $headingAnchors, $images, $rawHtml, $linkRel));

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        $container = $context->container();
        $config = $container->getParameterBag()->all()[Service::PARAMETER] ?? null;

        /** @var Config $config */
        $config = \is_array($config) ? $config : ['formats' => [], 'profiles' => [], 'shortcodes' => [], 'commonmark' => [], 'pre' => [], 'post' => []];
        $this->config = $config;

        foreach ($this->calls as $call) {
            $call($context);
        }

        $container->setParameter(Service::PARAMETER, $this->config);
    }

    /**
     * @param list<string> $profiles
     */
    private function addFormat(ExtenderContext $context, string $name, string $class, array $profiles): void
    {
        $where = \sprintf('Extend\Formatter::format("%s")', $name);
        $this->assertName($context, $where, $name);
        $this->registerService($context, $where, $class, FormatInterface::class);

        if (isset($this->config['formats'][$name])) {
            throw $context->error($where, \sprintf('The format is already added by "%s".', $this->config['formats'][$name]['owner']));
        }

        $this->config['formats'][$name] = ['class' => $class, 'owner' => $context->id];

        foreach ($profiles as $profile) {
            $this->assertProfile($context, $where, $profile);
            $formats = $this->config['profiles'][$profile]['formats'] ?? [];
            $this->config['profiles'][$profile]['formats'] = [...\is_array($formats) ? $formats : [], $name];
        }
    }

    private function addShortcode(ExtenderContext $context, string $name, string $class, bool $trusted): void
    {
        $where = \sprintf('Extend\Formatter::shortcode("%s")', $name);
        $this->assertName($context, $where, $name);
        $this->registerService($context, $where, $class, ShortcodeInterface::class);

        if (isset($this->config['shortcodes'][$name])) {
            throw $context->error($where, \sprintf('The shortcode is already added by "%s".', $this->config['shortcodes'][$name]['owner']));
        }

        $this->config['shortcodes'][$name] = ['class' => $class, 'trusted' => $trusted, 'owner' => $context->id];
    }

    private function addProfile(ExtenderContext $context, FormatterProfile $profile): void
    {
        $where = \sprintf('Extend\Formatter::profile("%s")', $profile->name);
        $this->assertName($context, $where, $profile->name);

        $formats = array_keys($this->config['formats']);
        $unknown = array_diff($profile->formats, $formats);
        $existing = $this->config['profiles'][$profile->name] ?? null;

        $error = match (true) {
            null !== $existing => \sprintf('The profile is already added by "%s".', \is_string($existing['owner'] ?? null) ? $existing['owner'] : 'another extension'),
            [] === $profile->formats => 'A profile needs at least one format.',
            [] !== $unknown => \sprintf('Unknown format "%s". Known: %s.', implode('", "', $unknown), implode(', ', $formats)),
            !\in_array($profile->sanitizer, [FormatterProfile::RICH, FormatterProfile::BASIC], true) => 'The sanitizer is "rich" or "basic".',
            [] !== array_filter($profile->linkRel, static fn (string $rel): bool => 1 !== preg_match('/^[a-z]+$/', $rel)) => 'Link rel values are single lowercase words, e.g. "nofollow".',
            default => null,
        };

        if (null !== $error) {
            throw $context->error($where, $error);
        }

        $this->config['profiles'][$profile->name] = $profile->toArray();
    }

    /**
     * @param 'commonmark'|'pre'|'post' $kind
     * @param list<string>|null         $profiles
     */
    private function addProcessor(ExtenderContext $context, string $kind, string $class, ?array $profiles): void
    {
        [$method, $interface] = match ($kind) {
            'commonmark' => ['commonMark', CommonMarkExtensionInterface::class],
            'pre' => ['preProcessor', SourceProcessorInterface::class],
            'post' => ['postProcessor', HtmlProcessorInterface::class],
        };
        $where = \sprintf('Extend\Formatter::%s(%s)', $method, $class);
        $this->registerService($context, $where, $class, $interface);

        foreach ($profiles ?? [] as $profile) {
            $this->assertProfile($context, $where, $profile);
        }

        $this->config[$kind][] = ['class' => $class, 'profiles' => $profiles, 'owner' => $context->id];
    }

    private function assertName(ExtenderContext $context, string $where, string $name): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw $context->error($where, 'Names start with a lowercase letter, followed by up to 31 lowercase letters, digits, "-" or "_".');
        }
    }

    private function assertProfile(ExtenderContext $context, string $where, string $profile): void
    {
        if (!isset($this->config['profiles'][$profile])) {
            throw $context->error($where, \sprintf('Unknown profile "%s". Known: %s.', $profile, implode(', ', array_keys($this->config['profiles']))));
        }
    }

    /**
     * @param class-string $interface
     */
    private function registerService(ExtenderContext $context, string $where, string $class, string $interface): void
    {
        if (!class_exists($class)) {
            throw $context->error($where, \sprintf('The class "%s" doesn\'t exist. Check the class name and that your package\'s Composer autoload covers it.', $class));
        }

        if (!is_a($class, $interface, true)) {
            throw $context->error($where, \sprintf('The class "%s" must implement %s.', $class, $interface));
        }

        $context->service($class)->addTag(Service::SERVICE_TAG);
    }
}
