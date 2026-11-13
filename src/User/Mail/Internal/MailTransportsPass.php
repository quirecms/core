<?php

declare(strict_types=1);

namespace Quire\User\Mail\Internal;

use Quire\User\Mail\MailSetup;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The DSNs of the mail transports the site actually uses (framework.mailer), for {@see MailSetup}: as
 * configured, with environment variables resolved when read, never shown.
 *
 * @internal
 */
final readonly class MailTransportsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $transports = $container->hasDefinition('mailer.transports') ? $container->getDefinition('mailer.transports')->getArgument(0) : [];
        $container->setParameter(MailSetup::TRANSPORTS_PARAMETER, \is_array($transports) ? array_values(array_filter($transports, \is_string(...))) : []);
    }
}
