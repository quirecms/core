<?php

declare(strict_types=1);

namespace Quire\Http;

use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * What a visitor can quote when a page fails: a short reference ("7f3a-91c2") and its time, the same as the
 * line the failure was logged with, so the site's owner finds it in the log. Made at the first server error
 * of a request, never before: other pages have none. Random, so it says nothing about the request.
 *
 * Reset between requests (PHP workers serve many).
 *
 * @api
 */
final class ErrorReference implements ResetInterface, RuntimeExtensionInterface
{
    private ?string $code = null;
    private ?\DateTimeImmutable $at = null;

    /**
     * The reference of this request's failure, made now if it has none yet.
     */
    public function make(): string
    {
        if (null === $this->code) {
            $hex = bin2hex(random_bytes(4));
            $this->code = substr($hex, 0, 4).'-'.substr($hex, 4);
            $this->at = new \DateTimeImmutable();
        }

        return $this->code;
    }

    /**
     * @return array{code: string, at: \DateTimeImmutable}|null The reference and its time, or null when this request failed with no server error
     */
    public function current(): ?array
    {
        return null === $this->code || !$this->at instanceof \DateTimeImmutable ? null : ['code' => $this->code, 'at' => $this->at];
    }

    public function reset(): void
    {
        $this->code = null;
        $this->at = null;
    }
}
