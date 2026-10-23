<?php

declare(strict_types=1);

namespace Quire\Jobs;

use Cron\CronExpression;

/**
 * A job dispatched on a schedule, declared with {@see \Quire\Extend\Jobs::schedule()}.
 *
 * The frequency is a cron expression ("30 3 * * *", "@daily") or an interval ("every 15 minutes",
 * "every 2 hours", "every 1 day"). Intervals count from midnight UTC, so "every 6 hours" runs at 00:00, 06:00,
 * 12:00 and 18:00 UTC, on every server alike. For weekly tasks, use cron ("0 6 * * 1").
 *
 * @api
 */
final readonly class ScheduledTask
{
    private const string INTERVAL = '/^every (\d+) (minute|hour|day)s?$/';

    private const array SECONDS = ['minute' => 60, 'hour' => 3600, 'day' => 86400];

    /**
     * @param class-string<Job> $message  The job to dispatch; created without arguments
     * @param string            $owner    ID of the extension that declared it, or "core"
     * @param string|null       $timezone For cron expressions: the time zone their times are in (default: PHP's)
     */
    public function __construct(
        public string $name,
        public string $frequency,
        public string $message,
        public string $owner,
        public ?string $timezone = null,
    ) {
    }

    /**
     * Why the frequency can't be used, or null when it can.
     */
    public static function frequencyError(string $frequency): ?string
    {
        if (1 === preg_match(self::INTERVAL, $frequency, $match)) {
            return (int) $match[1] > 0 ? null : 'Intervals are at least 1 minute.';
        }

        return CronExpression::isValidExpression($frequency) ? null : 'Use a cron expression, e.g. "30 3 * * *" or "@daily", or an interval, e.g. "every 15 minutes".';
    }

    /**
     * The last time the task was due, at or before $now.
     */
    public function lastOccurrence(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $seconds = $this->intervalSeconds();

        if (null !== $seconds) {
            return new \DateTimeImmutable('@'.(intdiv($now->getTimestamp(), $seconds) * $seconds));
        }

        return \DateTimeImmutable::createFromMutable($this->cron()->getPreviousRunDate($now, 0, true, $this->timezone));
    }

    /**
     * The next time the task is due, after $after.
     */
    public function nextOccurrence(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $seconds = $this->intervalSeconds();

        if (null !== $seconds) {
            return new \DateTimeImmutable('@'.((intdiv($after->getTimestamp(), $seconds) + 1) * $seconds));
        }

        return \DateTimeImmutable::createFromMutable($this->cron()->getNextRunDate($after, 0, false, $this->timezone));
    }

    /**
     * @internal Compiled into the container as a parameter
     *
     * @return array{name: string, frequency: string, message: class-string<Job>, owner: string, timezone: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'frequency' => $this->frequency, 'message' => $this->message, 'owner' => $this->owner, 'timezone' => $this->timezone];
    }

    /**
     * @internal
     *
     * @param array{name: string, frequency: string, message: class-string<Job>, owner: string, timezone: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['frequency'], $data['message'], $data['owner'], $data['timezone']);
    }

    private function intervalSeconds(): ?int
    {
        return 1 === preg_match(self::INTERVAL, $this->frequency, $match) ? (int) $match[1] * self::SECONDS[$match[2]] : null;
    }

    private function cron(): CronExpression
    {
        return new CronExpression($this->frequency);
    }
}
