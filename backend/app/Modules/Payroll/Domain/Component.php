<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/** A column of a payroll line (design, "Components"); each type's line carries only the ones it uses. */
enum Component: string
{
    case Salary = 'salary';
    case Wages = 'wages';
    case Allowance = 'allowance';
    case Transport = 'transport';
    case Travel = 'travel';
    case Tower = 'tower';
    case Overtime = 'overtime';
    case Outside = 'outside';
    case Bonus = 'bonus';
    case Lateness = 'lateness';
    case Absence = 'absence';
    case UnpaidLeave = 'unpaid_leave';
    case SickCut = 'sick_cut';
    case NotEmployed = 'not_employed';
    case Deduction = 'deduction';
    case Negligence = 'negligence';
    case InsuranceEmployee = 'insurance_employee';
    case IncomeTax = 'income_tax';
    case Advances = 'advances';
    case InsuranceCompany = 'insurance_company';
    case InsuredBasic = 'insured_basic';
    case Incentives = 'incentives';
    case Net = 'net';
}
