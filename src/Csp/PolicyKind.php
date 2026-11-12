<?php

declare(strict_types=1);

namespace Quire\Csp;

/**
 * Which of Quire's policies a response gets. Quire's routes say it with the route default
 * {@see Csp::ATTRIBUTE}; without one, HTML pages of the site get {@see Page} and the admin's
 * {@see Admin}. An extension's route can ask for {@see Private} the same way, for a page with a form
 * for passwords or accounts:
 *
 *     new Extend\Routes()
 *         ->add('/members/join', 'acme_members_join', JoinController::class, ['GET', 'POST'], defaults: [Csp::ATTRIBUTE => 'private'])
 *
 * @api
 */
enum PolicyKind: string
{
    /** The site's pages, rendered with its theme: scripts, styles and images from the site itself */
    case Page = 'page';

    /** Pages with forms for passwords and accounts: a page's policy, and never shown in a frame */
    case Private = 'private';

    /** Uploaded files: someone's content, never able to run scripts as the site */
    case StoredFile = 'stored-file';

    /** The admin's pages: no frames from elsewhere, no plugins, no other base address */
    case Admin = 'admin';
}
