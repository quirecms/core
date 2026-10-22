<?php

declare(strict_types=1);

namespace Quire\Permission\Security;

use Psr\Container\ContainerInterface;
use Quire\Content\Content;
use Quire\Extend\Permissions as PermissionsExtender;
use Quire\Permission\AbilityRuleInterface;
use Quire\Permission\Permissions;
use Quire\User\Role;
use Quire\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Answers is_granted() for Quire's abilities, e.g. is_granted('content.post.publish').
 *
 * It applies the rules of extensions first: any rule that refuses wins, whatever roles say. Otherwise the
 * ability is granted when a rule grants it or one of the user's roles has it. Visitors, signed in or not,
 * have the guest role's abilities. "content.<type>.edit_own" is granted only for the user's own items.
 *
 * Abilities are decided here, as one vote, so a refusal holds whatever access decision strategy the
 * application uses.
 *
 * @extends Voter<string, mixed>
 *
 * @internal
 */
final class AbilityVoter extends Voter
{
    /**
     * @param array{rules: list<array{class: string, owner: string}>} $config
     * @param ContainerInterface                                      $rules  The rule services, by class, set by {@see PermissionsPass}
     */
    public function __construct(
        private readonly Permissions $permissions,
        #[Autowire(param: PermissionsExtender::PARAMETER)]
        private readonly array $config,
        private readonly ContainerInterface $rules,
    ) {
    }

    /**
     * The roles a user has for abilities: guest for everyone, and a Quire user's own roles.
     *
     * @return list<string>
     */
    public static function rolesOf(?UserInterface $user): array
    {
        return $user instanceof User ? [Role::GUEST, ...$user->roles] : [Role::GUEST];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->permissions->has($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $granted = false;

        foreach ($this->config['rules'] as $rule) {
            $service = $this->rules->get($rule['class']);
            \assert($service instanceof AbilityRuleInterface);
            $decision = $service->decide($attribute, $user, $subject);

            if (false === $decision) {
                $vote?->addReason(\sprintf('%s refused it.', $rule['class']));

                return false;
            }

            $granted = $granted || true === $decision;
        }

        $granted = $granted || $this->permissions->allows(self::rolesOf($user), $attribute);

        // Their own items only: the subject's author must be the user.
        if ($granted && str_ends_with($attribute, '.edit_own') && $subject instanceof Content) {
            return $user instanceof User && $subject->authorId === $user->id;
        }

        return $granted;
    }
}
