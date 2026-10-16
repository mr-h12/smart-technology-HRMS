# Smart Tech HRMS

HR and payroll for Smart Technology's office staff, site engineers, permanent site workers and day laborers in Alexandria. It replaces the company's Excel workbooks.

## People

**Employee** (موظف):
Anyone the company pays, of any employee type. Employees have no system account.
_Avoid_: staff member, worker (as the general term), user

**Employee type**:
One of office (موظفين مثبتين شركة), engineer (مهندسين), permanent worker (عمال مثبتين) or day laborer (عمال باليومية). Each type has its own payroll cycle. An employee's type can change over time.
_Avoid_: category, contract type

**Monthly staff**:
Office staff, engineers and permanent workers together: everyone paid a monthly salary.
_Avoid_: permanent employees, مثبتين (alone; it is ambiguous)

**Manager** (مدير):
An account holder who acts on behalf of the employees assigned to them. Each employee has exactly one manager at a time.
_Avoid_: supervisor, team leader

**Fingerprint name**:
The English name an office employee has on the fingerprint device, used to match its export to the employee.
_Avoid_: English name, device ID

## Work and attendance

**Site** (موقع):
A place where a day of work is done, usually a client location.
_Avoid_: location, project, place (المكان)

**Leave day-type**:
A non-work value recorded instead of a site for a day: Friday, official holiday, sick, rest, absent (غ). It gets its own row in site costing.
_Avoid_: leave site

**Residence** (السكن):
The town an employee travels from. Together with the site it sets the fare.
_Avoid_: address, home

**Shift**:
An employee's scheduled start and end time. Lateness and overtime are measured against it.
_Avoid_: schedule, working hours

**Late permission** (إذن تأخير):
An approved request that cancels one day's lateness.

**Early leave** (انصراف مبكر):
Either a one-day permission to leave before shift end, or a permanent change to the employee's shift.

## Pay

**Salary** (المرتب):
A monthly employee's basic plus raise.
_Avoid_: basic (on its own), wage

**Insured basic** (الأساسي التأميني):
The part of salary shown as "basic" on the payslip; the rest is incentives.
_Avoid_: basic salary

**Incentives** (حوافز):
Salary minus insured basic.
_Avoid_: allowances

**Daily wage** (اليومية):
What a day laborer earns for one worked day.
_Avoid_: wage tier, rate, grade

**Site allowance** (بدل):
An extra amount a site adds to a day laborer's day, as a share of the daily wage or a fixed amount.
_Avoid_: bonus, extra

**Fare** (المواصلات):
The transport amount paid for travelling from a residence to a site, full or half.
_Avoid_: transport allowance (for office tower trips, see Tower transport)

**Tower transport** (بدل انتقالات البرج):
An office employee's allowance of a quarter day's pay per tower day.

**Day cost**:
What one recorded day costs the company: day rate plus that day's extras. Summed per site for site costing.

**Advance** (سلفة):
Money given ahead of pay and recovered in one payment or in installments.
_Avoid_: loan

**Override**:
A value HR types in place of one the system computed, with a reason.
_Avoid_: manual edit, adjustment

**Period** (فترة):
One payroll cycle for one employee type: calendar month, 26th → 25th, or half month. A closed period is read-only.
_Avoid_: month (for non-office types), payroll run

## Leaving

**Custody** (عهدة):
An item the company has handed to an employee, such as a mobile line or a device.
_Avoid_: asset (for a held item)

**Clearance** (إخلاء طرف):
Certification that a leaving employee has returned all custody. It cannot be issued while custody is open.

**End-of-service bonus** (مكافأة نهاية الخدمة):
A one-time amount paid to a leaver, suggested by the system and set by HR.
_Avoid_: gratuity
