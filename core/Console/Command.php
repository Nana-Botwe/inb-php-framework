<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * Base class for your own commands in app/Console/Commands (php console make:command SendReminders).
 *
 *   protected string $signature = 'reminders:send {days=3} {--dry-run}';
 *   protected string $description = 'Email customers with unpaid invoices';
 *   public function handle(): int { $days = (int) $this->argument('days'); ... return self::SUCCESS; }
 */
abstract class Command
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    protected string $signature = '';

    protected string $description = '';

    /** @var array<string, string|null> */
    private array $arguments = [];

    /** @var array<string, string|bool> */
    private array $options = [];

    abstract public function handle(): int;

    public function name(): string
    {
        return (string) strtok(trim($this->signature), ' ');
    }

    public function description(): string
    {
        return $this->description;
    }

    public function signature(): string
    {
        return $this->signature;
    }

    /** @param string[] $argv */
    public function setInput(array $argv): void
    {
        [$positional, $this->options] = Input::parse($argv);

        preg_match_all('/\{\s*([\w-]+)(\?)?(?:=([^}]*))?\s*\}/', $this->signature, $matches, PREG_SET_ORDER);
        $index = 0;
        foreach ($matches as $match) {
            if (str_starts_with($match[1], '-')) {
                continue;
            }
            $this->arguments[$match[1]] = $positional[$index] ?? (isset($match[3]) && $match[3] !== '' ? $match[3] : null);
            $index++;
        }

        preg_match_all('/\{\s*--([\w-]+)(?:=([^}]*))?\s*\}/', $this->signature, $optionMatches, PREG_SET_ORDER);
        foreach ($optionMatches as $match) {
            if (!array_key_exists($match[1], $this->options)) {
                $this->options[$match[1]] = isset($match[2]) && $match[2] !== '' ? $match[2] : false;
            }
        }
    }

    protected function argument(string $name): ?string
    {
        return $this->arguments[$name] ?? null;
    }

    protected function option(string $name): string|bool|null
    {
        return $this->options[$name] ?? null;
    }

    protected function info(string $message): void
    {
        Output::success($message);
    }

    protected function line(string $message = ''): void
    {
        Output::line($message);
    }

    protected function warn(string $message): void
    {
        Output::warn($message);
    }

    protected function error(string $message): void
    {
        Output::error($message);
    }

    /** @param string[] $headers @param array<int, array<int, string>> $rows */
    protected function table(array $headers, array $rows): void
    {
        Output::table($headers, $rows);
    }
}
