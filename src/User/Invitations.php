<?php

declare(strict_types=1);

namespace Quire\User;

use Quire\Settings\Settings;
use Quire\User\Event\UserInvited;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Mail\UserMailer;
use Quire\User\Security\SignInListener;
use Quire\User\Token\UserTokens;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Invites users by email to choose their password, e.g. after an admin added them, so nobody but them ever
 * knows it. The link works once, for seven days; inviting again replaces it.
 *
 * @api
 */
final readonly class Invitations
{
    public function __construct(
        private UserTokens $tokens,
        private UserMailer $mailer,
        private Settings $settings,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Why users can't be invited, or null when they can.
     */
    public function unavailableReason(): ?string
    {
        return $this->settings->getBool(SignInListener::PASSWORD_LOGIN_SETTING)
            ? null
            : 'Signing in with a password is turned off, so there\'s no password to choose.';
    }

    /**
     * Invitations not used yet: the users invited who haven't chosen their password. Expired ones are
     * there too, so their user can be invited again.
     *
     * @param list<string>|null $userIds Only these users' invitations, e.g. those of a page of a list
     *
     * @return array<string, Invitation> By user ID
     */
    public function pending(?array $userIds = null): array
    {
        return array_map(
            static fn (array $token): Invitation => new Invitation($token['issuedAt'], $token['expiresAt']),
            $this->tokens->issued(UserTokens::INVITATION, $userIds),
        );
    }

    /**
     * @param User|null $by Who invites them, named in the email
     *
     * @throws InvalidUserException        when the user has no email, or users can't be invited
     * @throws TransportExceptionInterface when the email couldn't be sent
     */
    public function invite(User $user, ?User $by = null): void
    {
        $reason = $this->unavailableReason() ?? (null === $user->email ? 'The user has no email to send the invitation to.' : null);

        if (null !== $reason) {
            throw InvalidUserException::single('email', $reason);
        }

        $this->mailer->sendInvitation($user, $this->tokens->issue($user, UserTokens::INVITATION, new \DateInterval(UserMailer::INVITATION_LIFETIME)), $by);
        $this->dispatcher->dispatch(new UserInvited($user, $by));
    }
}
