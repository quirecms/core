<?php

declare(strict_types=1);

namespace Quire\User;

/**
 * Changes to a user's profile for {@see UserWriter::update()}; only what's set changes.
 *
 *     $writer->update(new UpdateUser($user->id)->displayName('Ada L.')->email('ada@example.com'));
 *
 * @api
 */
final readonly class UpdateUser
{
    /**
     * @param array<string, mixed> $changes
     */
    public function __construct(
        public string $id,
        public array $changes = [],
    ) {
    }

    public function displayName(string $displayName): self
    {
        return $this->with('display_name', $displayName);
    }

    /**
     * @param string|null $email Null removes it, for users who sign in through another provider
     */
    public function email(?string $email): self
    {
        return $this->with('email', $email);
    }

    public function username(string $username): self
    {
        return $this->with('username', $username);
    }

    /**
     * @param array<string, mixed> $attributes Replaces all attributes
     */
    public function attributes(array $attributes): self
    {
        return $this->with('attributes', $attributes);
    }

    private function with(string $property, mixed $value): self
    {
        return new self($this->id, [...$this->changes, $property => $value]);
    }
}
