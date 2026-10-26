<?php

declare(strict_types=1);

namespace Quire\Theme;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Seeing the site in another installed theme before using it, only for the person who looks: until they
 * stop, the site's pages render with that theme's templates, stylesheets and settings (at their
 * defaults), and everyone else sees the site as it is. It takes the ability to change the theme.
 *
 * Only themes that add nothing but templates, stylesheets and settings can be previewed; for others,
 * {@see reason()} says why.
 *
 * @api
 */
final class ThemePreview implements ResetInterface
{
    public const string ABILITY = 'quire.themes.manage';

    /** @internal Container parameter: what each installed theme needs for a preview */
    public const string PARAMETER = 'quire.theme.previews';

    private const string SESSION_KEY = '_quire_theme_preview';

    /** Whether this request renders with the previewed theme (see {@see render()}) */
    private bool $rendering = false;

    /**
     * @param array<string, array{chain: list<array{id: string, path: string}>, reason: ?string, stylesheets: list<string>, settings: array<string, mixed>, namespaces: array<string, string>}> $previews
     * @param list<array{id: string, path: string}>                                                                                                                                             $active
     */
    public function __construct(
        #[Autowire(param: self::PARAMETER)]
        private readonly array $previews,
        #[Autowire(param: Themes::CHAIN_PARAMETER)]
        private readonly array $active,
        private readonly RequestStack $requests,
        private readonly Security $security,
    ) {
    }

    /**
     * Why the theme can't be previewed, or null when it can.
     */
    public function reason(string $id): ?string
    {
        return match (true) {
            !isset($this->previews[$id]) => \sprintf('No theme "%s" is installed.', $id),
            ($this->active[0]['id'] ?? null) === $id => 'It\'s the theme the site uses.',
            default => $this->previews[$id]['reason'],
        };
    }

    /**
     * @throws \LogicException when it can't be previewed, or the person may not
     */
    public function start(string $id): void
    {
        $reason = $this->security->isGranted(self::ABILITY) ? $this->reason($id) : 'Previewing themes takes the ability "'.self::ABILITY.'".';

        if (null !== $reason) {
            throw new \LogicException($reason);
        }

        $this->requests->getSession()->set(self::SESSION_KEY, $id);
    }

    public function stop(): void
    {
        $this->requests->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * The theme the person is previewing, if any.
     */
    public function current(): ?string
    {
        $request = $this->requests->getMainRequest();

        if (!$request instanceof \Symfony\Component\HttpFoundation\Request || !$request->hasPreviousSession()) {
            return null;
        }

        $id = $request->getSession()->get(self::SESSION_KEY);

        return \is_string($id) && null === $this->reason($id) && $this->security->isGranted(self::ABILITY) ? $id : null;
    }

    /**
     * @internal For the listener: this request renders with the previewed theme
     */
    public function render(bool $rendering): void
    {
        $this->rendering = $rendering;
    }

    /**
     * The previewed theme and its parents, the theme first, while this request renders with it.
     *
     * @return list<array{id: string, path: string}>|null
     */
    public function chain(): ?array
    {
        $id = $this->rendering ? $this->current() : null;

        return null === $id ? null : $this->previews[$id]['chain'];
    }

    /**
     * @internal The previewed theme's stylesheets
     *
     * @return list<string>
     */
    public function stylesheets(): array
    {
        $id = $this->rendering ? $this->current() : null;

        return null === $id ? [] : $this->previews[$id]['stylesheets'];
    }

    /**
     * @internal The previewed theme's settings, at their defaults
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $id = $this->rendering ? $this->current() : null;

        return null === $id ? [] : $this->previews[$id]['settings'];
    }

    /**
     * @internal The previewed theme's template namespaces, by name
     *
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        $id = $this->rendering ? $this->current() : null;

        return null === $id ? [] : $this->previews[$id]['namespaces'];
    }

    public function reset(): void
    {
        $this->rendering = false;
    }
}
