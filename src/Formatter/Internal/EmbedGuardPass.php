<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

use Quire\Csp\Internal\PrivacySlotPass;
use Quire\Csp\ThirdPartySourcesInterface;
use Quire\Formatter\EmbedGuardInterface;
use Quire\Formatter\Formatter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Who may guard the embeds of trusted shortcodes (doc 20, condition C-B): core's default, which leaves
 * them as they are, or quire/privacy's, which holds them until the visitor consents; and, whenever
 * quire/privacy's services' hosts are in the policy, only quire/privacy's. The site's policy
 * allows a service's hosts for every visitor, so another guard (one that does nothing) would let every
 * embed load before consent: it stops the container from being built, with the reason. Checked as
 * {@see \Quire\Csp\Internal\CspSourcesPass} checks the policy's sources: the slot, and what the
 * formatter is actually given, by reference or inline, with its factory and configurator.
 *
 * @internal
 */
final readonly class EmbedGuardPass extends PrivacySlotPass
{
    private const string MISSING = 'quire/privacy adds its services\' hosts to the Content-Security-Policy, but the formatter isn\'t given its guard of embeds, so embeds of trusted shortcodes would load before the visitor consents. Something removed or replaced quire/privacy\'s guard (Quire\\Formatter\\EmbedGuardInterface). See docs/security.md.';

    public function process(ContainerBuilder $container): void
    {
        // With quire/privacy's services' hosts in the policy, its guard must hold their embeds: core's
        // default, or no guard at all, would let them load before consent.
        $privacy = ($container->hasAlias(ThirdPartySourcesInterface::class) || $container->hasDefinition(ThirdPartySourcesInterface::class))
            && $this->isPrivacy($container, $this->class($container, ThirdPartySourcesInterface::class));
        $slot = $container->hasAlias(EmbedGuardInterface::class) || $container->hasDefinition(EmbedGuardInterface::class);

        if ($slot) {
            $this->assertGuard($container, $this->class($container, EmbedGuardInterface::class), $privacy);
        } elseif ($privacy) {
            throw new InvalidArgumentException(self::MISSING);
        }

        if (!$container->hasDefinition(Formatter::class)) {
            return;
        }

        $formatter = $container->getDefinition(Formatter::class);
        $this->assertBuiltBy($container, $formatter, Formatter::class, $this->assertGuard(...), 'The formatter service');
        $arguments = $formatter->getArguments();
        $position = 0;

        foreach (new \ReflectionMethod(Formatter::class, '__construct')->getParameters() as $parameter) {
            if ('embeds' === $parameter->getName()) {
                $position = $parameter->getPosition();
            }
        }

        $given = iterator_to_array($this->definitions($container, [$arguments['$embeds'] ?? $arguments[$position] ?? null]), false);

        if ($privacy && [] === $given) {
            // The formatter's default, core's guard that holds nothing.
            throw new InvalidArgumentException(self::MISSING);
        }

        $assert = fn (ContainerBuilder $container, string $class) => $this->assertGuard($container, $class, $privacy);

        foreach ($given as $definition) {
            $this->assertGuard($container, $this->classOf($container, $definition), $privacy);
            $this->assertBuiltBy($container, $definition, null, $assert, '');
        }
    }

    /**
     * @param bool $privacy Whether quire/privacy is in use: then only its guard will do
     */
    private function assertGuard(ContainerBuilder $container, string $class, bool $privacy = false): void
    {
        if ($this->isPrivacy($container, $class)) {
            return;
        }

        if (NoEmbedGuard::class !== $class) {
            throw new InvalidArgumentException(\sprintf('"%s" guards the embeds of trusted shortcodes. Only quire/privacy may (it holds them until the visitor consents): an embed that needs consent goes through it. See docs/security.md.', $class));
        }

        if ($privacy) {
            throw new InvalidArgumentException(self::MISSING);
        }
    }
}
