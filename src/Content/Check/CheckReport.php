<?php

declare(strict_types=1);

namespace Quire\Content\Check;

/**
 * What the content checks found in an item: errors, which block publishing it, and warnings.
 *
 * @api
 */
final readonly class CheckReport
{
    /**
     * @param list<CheckResult> $results Errors first
     */
    public function __construct(
        public array $results = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors();
    }

    /**
     * @return list<CheckResult>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->results, static fn (CheckResult $result): bool => Level::Error === $result->level));
    }

    /**
     * @return list<CheckResult>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->results, static fn (CheckResult $result): bool => Level::Warning === $result->level));
    }

    /**
     * The errors as violations, by property ("body" when the check doesn't say), as
     * {@see \Quire\Content\Exception\InvalidContentException} has them.
     *
     * @return array<string, string>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->errors() as $error) {
            $property = $error->finding->property ?? 'body';
            $violations[$property] = isset($violations[$property]) ? $violations[$property].' '.$error->finding->message : $error->finding->message;
        }

        return $violations;
    }
}
