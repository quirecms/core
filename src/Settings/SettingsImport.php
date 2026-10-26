<?php

declare(strict_types=1);

namespace Quire\Settings;

/**
 * What importing settings would change, made by {@see SettingsTransfer::plan()}: to look at before
 * applying it.
 *
 * @api
 */
final readonly class SettingsImport
{
    /**
     * @param array<string, array{from: mixed, to: mixed, sensitive: bool}> $changes By key: what each setting is, and would be
     * @param list<string>                                                  $errors  Why it can't be imported: keys that aren't settings, values that don't fit, values the environment sets
     * @param list<string>                                                  $skipped Keys left out, as no enabled extension declares them
     */
    public function __construct(
        public array $changes,
        public array $errors,
        public array $skipped,
    ) {
    }

    public function canApply(): bool
    {
        return [] === $this->errors;
    }
}
