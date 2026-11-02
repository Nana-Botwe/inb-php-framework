<?php

declare(strict_types=1);

namespace Core\Console;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * One scheduled task with a cron expression (minute hour day-of-month month day-of-week).
 */
final class ScheduledEvent
{
    private string $expression = '* * * * *';

    private ?string $description = null;

    public function __construct(public readonly string $type, public readonly mixed $target)
    {
    }

    public function cron(string $expression): self
    {
        if (count(preg_split('/\s+/', trim($expression)) ?: []) !== 5) {
            throw new InvalidArgumentException("Invalid cron expression [$expression].");
        }
        $this->expression = trim($expression);

        return $this;
    }

    public function everyMinute(): self { return $this->cron('* * * * *'); }
    public function everyFiveMinutes(): self { return $this->cron('*/5 * * * *'); }
    public function everyTenMinutes(): self { return $this->cron('*/10 * * * *'); }
    public function everyFifteenMinutes(): self { return $this->cron('*/15 * * * *'); }
    public function everyThirtyMinutes(): self { return $this->cron('0,30 * * * *'); }
    public function hourly(): self { return $this->cron('0 * * * *'); }
    public function hourlyAt(int $minute): self { return $this->cron("$minute * * * *"); }
    public function daily(): self { return $this->cron('0 0 * * *'); }
    public function weekly(): self { return $this->cron('0 0 * * 0'); }
    public function monthly(): self { return $this->cron('0 0 1 * *'); }

    public function dailyAt(string $time): self
    {
        [$hour, $minute] = $this->time($time);

        return $this->cron("$minute $hour * * *");
    }

    public function twiceDaily(int $first = 1, int $second = 13): self
    {
        return $this->cron("0 $first,$second * * *");
    }

    /** @param int $day 0 = Sunday ... 6 = Saturday */
    public function weeklyOn(int $day, string $time = '0:00'): self
    {
        [$hour, $minute] = $this->time($time);

        return $this->cron("$minute $hour * * $day");
    }

    public function monthlyOn(int $day = 1, string $time = '0:00'): self
    {
        [$hour, $minute] = $this->time($time);

        return $this->cron("$minute $hour $day * *");
    }

    public function weekdays(): self
    {
        $parts = explode(' ', $this->expression);
        $parts[4] = '1-5';

        return $this->cron(implode(' ', $parts));
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function getDescription(): string
    {
        return $this->description ?? match ($this->type) {
            'command' => 'php console ' . $this->target[0],
            'job' => $this->target::class,
            default => 'Closure',
        };
    }

    public function isDue(DateTimeInterface $now): bool
    {
        [$minute, $hour, $day, $month, $weekday] = preg_split('/\s+/', $this->expression) ?: [];

        return $this->matches($minute, (int) $now->format('i'), 0, 59)
            && $this->matches($hour, (int) $now->format('G'), 0, 23)
            && $this->matches($day, (int) $now->format('j'), 1, 31)
            && $this->matches($month, (int) $now->format('n'), 1, 12)
            && $this->matches($weekday, (int) $now->format('w'), 0, 7, true);
    }

    /** Supports *, numbers, lists (1,2), ranges (1-5) and steps (*\/5, 0-30/10). */
    private function matches(string $field, int $value, int $min, int $max, bool $weekday = false): bool
    {
        foreach (explode(',', $field) as $part) {
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                $step = max(1, (int) $stepText);
            }

            if ($part === '*') {
                [$start, $end] = [$min, $max];
            } elseif (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
            } else {
                $start = $end = (int) $part;
            }

            for ($i = $start; $i <= $end; $i += $step) {
                if ($i === $value || ($weekday && $i === 7 && $value === 0)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0: int, 1: int} */
    private function time(string $time): array
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw new InvalidArgumentException("Invalid time [$time]. Use HH:MM.");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
