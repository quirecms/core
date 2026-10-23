<?php

declare(strict_types=1);

namespace Quire\User;

/**
 * A user to create with {@see UserWriter::create()}.
 *
 * @api
 */
final readonly class CreateUser
{
    /**
     * @param string|null          $username   Derived from the display name (or email) when null
     * @param string|null          $password   In plain text; it's hashed before it's stored. Null for no password
     * @param list<string>         $roles      Quire's role names, e.g. ["editor"]
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $displayName,
        public ?string $email = null,
        public ?string $username = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public array $roles = [],
        public array $attributes = [],
        public bool $emailVerified = true,
    ) {
    }
}
