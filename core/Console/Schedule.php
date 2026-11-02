<?php

declare(strict_types=1);

namespace Core\Console;

use Closure;
use Core\Queue\Job;

/**
 * Task scheduling (routes/console.php). Run "php console schedule:run" every minute from cron
 * or Windows Task Scheduler, and it runs whatever is due:
 *
 *   $schedule->command('cache:clear')->daily();
 *   $schedule->call(fn () => DB::table('sessions')->where(...)->delete())->hourly();
 *   $schedule->job(new SendReports())->weeklyOn(1, '08:00');
 *   $schedule->call(...)->cron('0 9-17 * * 1-5');
 */
final class Schedule
{
    /** @var ScheduledEvent[] */
    private array $events = [];

    public function call(Closure $callback): ScheduledEvent
    {
        return $this->events[] = new ScheduledEvent('closure', $callback);
    }

    /** @param string[] $arguments */
    public function command(string $command, array $arguments = []): ScheduledEvent
    {
        return $this->events[] = new ScheduledEvent('command', [$command, $arguments]);
    }

    public function job(Job $job): ScheduledEvent
    {
        return $this->events[] = new ScheduledEvent('job', $job);
    }

    /** @return ScheduledEvent[] */
    public function dueEvents(\DateTimeInterface $now): array
    {
        return array_values(array_filter($this->events, static fn (ScheduledEvent $e) => $e->isDue($now)));
    }

    /** @return ScheduledEvent[] */
    public function events(): array
    {
        return $this->events;
    }
}
