<?php

namespace Tests\Unit;

use App\Modules\Attendance\Domain\Period;
use App\Modules\Employees\Domain\EmployeeType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase
{
    public function test_periods_per_group(): void
    {
        $p = Period::containing(EmployeeType::Engineer, '2026-10-20');
        $this->assertSame(['2026-09-26', '2026-10-25'], [$p->start, $p->end]);
        $this->assertCount(30, $p->days());
        $this->assertSame(['2026-09-26', '2026-10-25'], [$p->days()[0], $p->days()[29]]);
        $this->assertSame(['2026-10-26', '2026-11-25'], [Period::containing(EmployeeType::PermanentWorker, '2026-10-26')->start, Period::containing(EmployeeType::PermanentWorker, '2026-10-26')->end]);
        $this->assertSame('2026-12-26', Period::containing(EmployeeType::Engineer, '2027-01-10')->start);
        $this->assertSame(['2026-02-01', '2026-02-28'], [Period::containing(EmployeeType::Office, '2026-02-14')->start, Period::containing(EmployeeType::Office, '2026-02-14')->end]);
        $this->assertSame('2026-10-15', Period::containing(EmployeeType::DayLaborer, '2026-10-15')->end);
        $this->assertSame(['2026-10-16', '2026-10-31'], [Period::containing(EmployeeType::DayLaborer, '2026-10-16')->start, Period::containing(EmployeeType::DayLaborer, '2026-10-16')->end]);
        $this->assertSame('2028-02-29', Period::containing(EmployeeType::DayLaborer, '2028-02-20')->end);
        $this->assertSame('2026-08-26', $p->previous()->start);
        $this->assertSame('2026-10-26', $p->next()->start);
        $this->assertSame('2026-09-16', Period::containing(EmployeeType::DayLaborer, '2026-10-01')->previous()->start);
        $this->assertTrue($p->contains('2026-10-25'));
        $this->assertFalse($p->contains('2026-10-26'));
    }

    public function test_a_period_must_start_on_its_groups_boundary(): void
    {
        $this->assertSame('2026-10-25', Period::startingAt(EmployeeType::Engineer, '2026-09-26')->end);
        $this->expectException(InvalidArgumentException::class);
        Period::startingAt(EmployeeType::Engineer, '2026-10-01');
    }
}
