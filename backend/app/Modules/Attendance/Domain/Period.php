<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

use App\Modules\Employees\Domain\EmployeeType;
use DateTimeImmutable;
use InvalidArgumentException;

/** One group's attendance period, as inclusive Y-m-d calendar dates: office a calendar month, engineers and permanent workers the 26th to the 25th, day laborers the 1st–15th or the 16th to month end. */
final readonly class Period
{
    public function __construct(public EmployeeType $group, public string $start, public string $end) {}

    public static function containing(EmployeeType $group, string $date): self
    {
        $d = self::parse($date);
        $day = (int) $d->format('j');
        $month = $d->modify('first day of this month');

        $start = match ($group) {
            EmployeeType::Office => $month,
            EmployeeType::Engineer, EmployeeType::PermanentWorker => ($day >= 26 ? $month : $month->modify('-1 month'))->modify('+25 days'),
            EmployeeType::DayLaborer => $day <= 15 ? $month : $month->modify('+15 days'),
        };
        $end = match ($group) {
            EmployeeType::Engineer, EmployeeType::PermanentWorker => $start->modify('+1 month -1 day'),
            EmployeeType::DayLaborer => $day <= 15 ? $month->modify('+14 days') : $month->modify('last day of this month'),
            EmployeeType::Office => $month->modify('last day of this month'),
        };

        return new self($group, $start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public static function startingAt(EmployeeType $group, string $start): self
    {
        $period = self::containing($group, $start);
        if ($period->start !== $start) {
            throw new InvalidArgumentException("{$start} is not the start of a {$group->value} period.");
        }

        return $period;
    }

    public function previous(): self
    {
        return self::containing($this->group, self::parse($this->start)->modify('-1 day')->format('Y-m-d'));
    }

    public function next(): self
    {
        return self::containing($this->group, self::parse($this->end)->modify('+1 day')->format('Y-m-d'));
    }

    /** @return list<string> */
    public function days(): array
    {
        $days = [];
        for ($d = self::parse($this->start); $d->format('Y-m-d') <= $this->end; $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    public function contains(string $date): bool
    {
        return $this->start <= $date && $date <= $this->end;
    }

    private static function parse(string $date): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("{$date} is not a Y-m-d date.");
        }

        return $d;
    }
}
