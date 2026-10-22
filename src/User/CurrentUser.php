<?php

declare(strict_types=1);

namespace Quire\User;

use Doctrine\DBAL\Connection;
use Quire\User\Exception\IdentityLinkRefusedException;
use Quire\User\Exception\InvalidUserException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The Quire user of whoever is signed in.
 *
 * Usually that's the user Symfony's security signed in. When Quire is installed in an application that
 * signs in its own users ("quire.users.host"), each of them gets a linked Quire user on their first visit,
 * so content can name them as its author. Their roles are mapped from the application's on every request,
 * so a change there applies at once, and add to the roles given in Quire.
 *
 * @api
 */
final class CurrentUser implements ResetInterface
{
    /** @internal */
    public const string HOST_PARAMETER = 'quire.users.host';

    /** @internal */
    public const string LOGIN_PARAMETER = 'quire.users.login';

    /** The provider of the identities that link users of the host application */
    public const string HOST_PROVIDER = 'host';

    /** @var array<string, User|null> Users of the host application, by class and identifier, for this request */
    private array $hostUsers = [];

    /**
     * @param array{roles: array<string, list<string>>, display_name: ?string, email: ?string}|null $host
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly UserRepository $users,
        private readonly UserWriter $writer,
        private readonly RoleHierarchyInterface $roleHierarchy,
        #[Autowire(param: self::HOST_PARAMETER)]
        private readonly ?array $host,
    ) {
    }

    /**
     * Null for visitors who aren't signed in, and for users of the host application suspended in Quire.
     */
    public function get(): ?User
    {
        return $this->of($this->security->getUser());
    }

    /**
     * The Quire user of a user Symfony's security signed in.
     */
    public function of(?UserInterface $user): ?User
    {
        if (!$user instanceof UserInterface || $user instanceof User) {
            return $user;
        }

        if (null === $this->host) {
            return null;
        }

        $key = $user::class.'#'.$user->getUserIdentifier();

        return \array_key_exists($key, $this->hostUsers) ? $this->hostUsers[$key] : $this->hostUsers[$key] = $this->hostUser($user, $this->host);
    }

    /**
     * The Quire roles the host application's roles give.
     *
     * @return list<string>
     */
    public function mappedRoles(UserInterface $user): array
    {
        $roles = [];

        foreach ($this->roleHierarchy->getReachableRoleNames($user->getRoles()) as $hostRole) {
            $roles = [...$roles, ...$this->host['roles'][$hostRole] ?? []];
        }

        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }

    public function reset(): void
    {
        $this->hostUsers = [];
    }

    /**
     * @param array{roles: array<string, list<string>>, display_name: ?string, email: ?string} $host
     */
    private function hostUser(UserInterface $hostUser, array $host): ?User
    {
        $subject = $hostUser->getUserIdentifier();
        $claims = [
            'name' => $this->read($hostUser, $host['display_name']) ?? $subject,
            'email' => $this->read($hostUser, $host['email']),
            'roles' => $this->mappedRoles($hostUser),
        ];

        $user = $this->users->findByIdentity(self::HOST_PROVIDER, $subject) ?? $this->link($subject, $claims);

        if (!$user instanceof User) {
            return null;
        }

        // What the application says about them is kept for display, e.g. their mapped roles.
        if (!$this->same($claims, $this->users->identityClaims(self::HOST_PROVIDER, $subject) ?? [])) {
            $this->writer->updateIdentity(self::HOST_PROVIDER, $subject, $claims);

            if ($user->displayName !== $claims['name']) {
                try {
                    $user = $this->writer->update(new UpdateUser($user->id)->displayName($claims['name']));
                } catch (InvalidUserException) {
                    // A name Quire doesn't accept, e.g. too long: keep the one it has.
                }
            }
        }

        if (!$user->isActive()) {
            return null;
        }

        return $user->withRoles([...$user->roles, ...$claims['roles']]);
    }

    /**
     * A new Quire user for a user of the host application.
     *
     * @param array{name: string, email: ?string, roles: list<string>} $claims
     */
    private function link(string $subject, array $claims): ?User
    {
        // An email another Quire user has stays with them: linking accounts by email would let whoever
        // controls the application's account take over the Quire one.
        $email = null !== $claims['email'] && !$this->users->findByEmail($claims['email']) instanceof User ? $claims['email'] : null;

        try {
            return $this->create($subject, $claims, $email);
        } catch (IdentityLinkRefusedException) {
            return null;
        } catch (InvalidUserException $e) {
            // Linked by another request meanwhile, or an email Quire doesn't accept.
            $linked = $this->users->findByIdentity(self::HOST_PROVIDER, $subject);

            if ($linked instanceof User || null === $email || !isset($e->violations['email'])) {
                return $linked ?? throw $e;
            }

            try {
                return $this->create($subject, $claims, null);
            } catch (IdentityLinkRefusedException) {
                return null;
            }
        }
    }

    /**
     * @param array{name: string, email: ?string, roles: list<string>} $claims
     */
    private function create(string $subject, array $claims, ?string $email): User
    {
        return $this->connection->transactional(function () use ($subject, $claims, $email): User {
            $user = $this->writer->create(new CreateUser(mb_substr($claims['name'], 0, 100), $email));
            $this->writer->linkIdentity($user->id, self::HOST_PROVIDER, $subject, $claims);

            return $user;
        });
    }

    /**
     * Whether claims are the same, in whichever order PostgreSQL stored their keys.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function same(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    private function read(UserInterface $user, ?string $path): ?string
    {
        if (null === $path) {
            return null;
        }

        $value = PropertyAccess::createPropertyAccessor()->getValue($user, $path);

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
