<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Small fake-data generator for factories and seeders: fake()->name(), fake()->unique()->safeEmail() ...
 */
final class Fake
{
    private const FIRST = ['Ama', 'Kofi', 'Akosua', 'Kwame', 'Abena', 'Yaw', 'Efua', 'Kojo', 'Adwoa', 'Kwesi', 'Esi', 'Fiifi',
        'Grace', 'Daniel', 'Sarah', 'Michael', 'Linda', 'James', 'Mary', 'David', 'Emmanuel', 'Isaac', 'Priscilla', 'Samuel'];

    private const LAST = ['Mensah', 'Owusu', 'Boateng', 'Asante', 'Appiah', 'Osei', 'Agyeman', 'Darko', 'Addo', 'Ofori',
        'Quaye', 'Amoah', 'Tetteh', 'Annan', 'Sarpong', 'Nkrumah', 'Badu', 'Ansah', 'Frimpong', 'Acheampong'];

    private const WORDS = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do',
        'eiusmod', 'tempor', 'incididunt', 'labore', 'dolore', 'magna', 'aliqua', 'enim', 'minim', 'veniam', 'quis',
        'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'commodo', 'consequat'];

    private const CITIES = ['Accra', 'Kumasi', 'Tamale', 'Takoradi', 'Cape Coast', 'Ho', 'Koforidua', 'Sunyani', 'Tema', 'Bolgatanga'];

    private const DOMAINS = ['example.com', 'example.org', 'example.net'];

    private bool $unique = false;

    /** @var array<string, array<string, true>> */
    private static array $used = [];

    /** Next value is guaranteed not to repeat (per method). */
    public function unique(): self
    {
        $clone = clone $this;
        $clone->unique = true;

        return $clone;
    }

    public function firstName(): string
    {
        return $this->make(__FUNCTION__, fn () => $this->pick(self::FIRST));
    }
    public function lastName(): string
    {
        return $this->make(__FUNCTION__, fn () => $this->pick(self::LAST));
    }
    public function name(): string
    {
        return $this->make(__FUNCTION__, fn () => $this->pick(self::FIRST) . ' ' . $this->pick(self::LAST));
    }
    public function word(): string
    {
        return $this->make(__FUNCTION__, fn () => $this->pick(self::WORDS));
    }
    public function city(): string
    {
        return $this->make(__FUNCTION__, fn () => $this->pick(self::CITIES));
    }
    public function company(): string
    {
        return $this->make(__FUNCTION__, fn () => $this->pick(self::LAST) . ' ' . $this->pick(['Ltd', 'Group', 'Ventures', 'Enterprise', 'Solutions']));
    }
    public function uuid(): string
    {
        return Str::uuid();
    }
    public function boolean(int $chanceOfTrue = 50): bool
    {
        return random_int(1, 100) <= $chanceOfTrue;
    }
    public function numberBetween(int $min = 0, int $max = 1000): int
    {
        return $this->make(__FUNCTION__, fn () => random_int($min, $max));
    }
    public function randomElement(array $items): mixed
    {
        return $this->pick($items);
    }

    public function safeEmail(): string
    {
        return $this->make(__FUNCTION__, fn () => strtolower($this->pick(self::FIRST) . '.' . $this->pick(self::LAST))
            . random_int(1, 9999) . '@' . $this->pick(self::DOMAINS));
    }

    public function email(): string
    {
        return $this->safeEmail();
    }

    public function phoneNumber(): string
    {
        return $this->make(__FUNCTION__, fn () => '+233 ' . $this->pick(['20', '24', '26', '27', '54', '55', '59']) . ' ' . random_int(100, 999) . ' ' . random_int(1000, 9999));
    }

    public function sentence(int $words = 8): string
    {
        $list = [];
        for ($i = 0; $i < $words; $i++) {
            $list[] = $this->pick(self::WORDS);
        }

        return ucfirst(implode(' ', $list)) . '.';
    }

    public function paragraph(int $sentences = 4): string
    {
        $list = [];
        for ($i = 0; $i < $sentences; $i++) {
            $list[] = $this->sentence(random_int(6, 12));
        }

        return implode(' ', $list);
    }

    public function dateTimeBetween(string $start = '-1 year', string $end = 'now'): \DateTimeImmutable
    {
        $from = (int) strtotime($start);
        $to = (int) strtotime($end);

        return (new \DateTimeImmutable())->setTimestamp(random_int(min($from, $to), max($from, $to)));
    }

    /** @param array<mixed> $items */
    private function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    private function make(string $method, callable $generator): mixed
    {
        if (!$this->unique) {
            return $generator();
        }

        for ($i = 0; $i < 10000; $i++) {
            $value = $generator();
            $key = (string) $value;
            if (!isset(self::$used[$method][$key])) {
                self::$used[$method][$key] = true;

                return $value;
            }
        }

        throw new \OverflowException("Ran out of unique values for fake()->$method().");
    }
}
