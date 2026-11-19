<?php

declare(strict_types=1);

namespace Quire\Formatter\Internal;

/**
 * Marks the ids the renderer itself makes (headings', footnotes') until DocumentStructure takes them:
 * a random token, made once per process and kept in memory, so no author can write one, not as text
 * nor as an HTML character reference (which a fixed character could be: "&#xE002;" decodes to it). It
 * never reaches the output, which DocumentStructure rewrites to plain "h-", "fn-" and "fnref-" before
 * anything is cached.
 *
 * @internal
 */
final readonly class StructureMark
{
    public string $value;

    public function __construct()
    {
        // 128 bits; letters and digits only, so it's valid wherever an id or a fragment is
        $this->value = 'q'.bin2hex(random_bytes(16)).'q';
    }
}
