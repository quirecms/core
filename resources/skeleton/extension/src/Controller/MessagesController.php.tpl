<?php

declare(strict_types=1);

namespace @@NAMESPACE@@\Controller;

use Doctrine\DBAL\Connection;
use Quire\Settings\Settings;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Shows the greeting setting and the messages stored in the @@TABLE@@ table.
 */
final readonly class MessagesController
{
    public function __construct(
        private Settings $settings,
        private Connection $connection,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('@@@TWIG_NAMESPACE@@/messages.html.twig', [
            'greeting' => $this->settings->getString('@@ID@@.greeting'),
            'messages' => $this->connection->fetchFirstColumn('SELECT message FROM @@TABLE@@ ORDER BY id'),
        ]));
    }
}
