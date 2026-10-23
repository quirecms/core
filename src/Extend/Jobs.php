<?php

declare(strict_types=1);

namespace Quire\Extend;

use Quire\Jobs\Job;
use Quire\Jobs\ScheduledTask;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Registers the handlers of an extension's background jobs, and jobs that run on a schedule:
 *
 *     new Extend\Jobs()
 *         ->handler(GenerateVariantsHandler::class)
 *         ->schedule('acme-feeds.fetch', 'every 15 minutes', FetchFeeds::class)
 *         ->schedule('acme-reports.weekly', '0 6 * * 1', SendWeeklyReport::class, timezone: 'Europe/Berlin')
 *
 * Jobs are messages implementing {@see Job}; dispatch them on the message bus. Handlers are Messenger
 * handlers: an invokable class taking the message, optionally marked #[AsMessageHandler]. Handlers
 * registered through {@see Services} need no call here.
 *
 * A scheduled job runs once per occurrence, however many workers and servers there are. When no
 * worker ran at the time (e.g. during a deployment), the most recent missed occurrence runs once
 * when one starts again; older ones are skipped.
 *
 * @api
 */
final class Jobs implements ExtenderInterface
{
    /** @internal Parameter: list of {@see ScheduledTask::toArray()} */
    public const string PARAMETER = 'quire.jobs.schedule';

    /** @var list<class-string> */
    private array $handlers = [];

    /** @var list<array{name: string, frequency: string, message: string, timezone: ?string}> */
    private array $tasks = [];

    /**
     * @param class-string $class A message handler, registered as an autowired service
     */
    public function handler(string $class): self
    {
        $this->handlers[] = $class;

        return $this;
    }

    /**
     * @param string            $name      Unique; start it with your extension's ID and a dot, e.g. "acme-feeds.fetch"
     * @param string            $frequency A cron expression ("30 3 * * *", "@hourly") or an interval ("every 15 minutes")
     * @param class-string<Job> $message   The job to dispatch, created without arguments
     * @param string|null       $timezone  For cron expressions: the time zone of their times, e.g. "Europe/Berlin" (default: PHP's, usually UTC)
     */
    public function schedule(string $name, string $frequency, string $message, ?string $timezone = null): self
    {
        $this->tasks[] = ['name' => $name, 'frequency' => $frequency, 'message' => $message, 'timezone' => $timezone];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        foreach ($this->handlers as $class) {
            $where = \sprintf('Extend\Jobs::handler("%s")', $class);

            if (!class_exists($class)) {
                throw $context->error($where, \sprintf('The class "%s" doesn\'t exist. Check the class name and that your package\'s Composer autoload covers it.', $class));
            }

            $definition = $context->service($class);

            // Classes marked #[AsMessageHandler] get their tag from autoconfiguration; a second one would run them twice.
            if ([] === new \ReflectionClass($class)->getAttributes(AsMessageHandler::class) && !$definition->hasTag('messenger.message_handler')) {
                $definition->addTag('messenger.message_handler');
            }
        }

        /** @var list<array{name: string, owner: string}> $declared */
        $declared = $context->container()->getParameterBag()->all()[self::PARAMETER] ?? [];
        $taken = array_column($declared, 'owner', 'name');
        $prefix = match ($context->id) {
            ExtenderContext::CORE => 'quire.',
            ExtenderContext::SITE => 'site.',
            default => ($context->theme ? 'theme.' : '').$context->id.'.',
        };

        foreach ($this->tasks as $task) {
            $where = \sprintf('Extend\Jobs::schedule("%s")', $task['name']);

            if (1 !== preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*(?:\.[a-z0-9]+(?:[-_][a-z0-9]+)*)+$/', $task['name']) || !str_starts_with($task['name'], $prefix)) {
                throw $context->error($where, \sprintf('Task names are lowercase words separated by dots, starting with "%s", e.g. "%sclean-up".', $prefix, $prefix));
            }

            if (isset($taken[$task['name']])) {
                throw $context->error($where, \sprintf('The task is already scheduled by "%s".', $taken[$task['name']]));
            }

            $error = ScheduledTask::frequencyError($task['frequency']);

            if (null !== $error) {
                throw $context->error($where, \sprintf('"%s" isn\'t a frequency. %s', $task['frequency'], $error));
            }

            if (!class_exists($task['message']) || !is_a($task['message'], Job::class, true)) {
                throw $context->error($where, \sprintf('The message "%s" must be a class implementing %s, so it runs in the background.', $task['message'], Job::class));
            }

            if (null !== $task['timezone'] && !\in_array($task['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                throw $context->error($where, \sprintf('"%s" isn\'t a time zone. Use a name such as "Europe/Berlin".', $task['timezone']));
            }

            $taken[$task['name']] = $context->id;
            $context->appendToParameter(self::PARAMETER, new ScheduledTask($task['name'], $task['frequency'], $task['message'], $context->id, $task['timezone'])->toArray());
        }
    }
}
