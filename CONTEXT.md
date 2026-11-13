# Smart Tech HRMS

HR and payroll for Smart Technology's office staff, site engineers, permanent site workers and day laborers in Alexandria. It replaces the company's Excel workbooks.

## People

**Employee** (موظف):
Anyone the company pays, of any employee type. Most employees have no system account; a site manager can be both an employee and an account holder, linked one to one (at most one account per employee and one employee per account), and the link grants nothing on their pay.
_Avoid_: staff member, worker (as the general term), user

**Employee type**:
One of office (موظفين مثبتين شركة), engineer (مهندسين), permanent worker (عمال مثبتين) or day laborer (عمال باليومية). Each type has its own payroll cycle. An employee's type can change over time.
_Avoid_: category, contract type

**Monthly staff**:
Office staff, engineers and permanent workers together: everyone paid a monthly salary.
_Avoid_: permanent employees, مثبتين (alone; it is ambiguous)

**Site manager** (مدير الموقع):
An account holder who covers one or more sites and records attendance and absence for the people they reach on a date: the workers whose home site is one of their sites, and the engineers assigned to them. Office staff are in no site manager's reach. A site manager never sees or sets wages; HR owns them. A site manager never reaches their own employee record: HR records their attendance, leave and requests like anyone else's (owner, 2026-11-12).
_Avoid_: team lead, مسؤول التيم (chosen 2026-11-03, dropped 2026-11-04), supervisor; manager or مدير alone only where the site is obvious

**Fingerprint name**:
The English name an office employee has on the fingerprint device, used to match its export to the employee.
_Avoid_: English name, device ID

**Employee ID** (الرقم الوظيفي):
The number the company gives each employee; stored as the employee's `code`, a whole number from 0. It stays visible. A number is never reissued: a new employee gets the highest ID ever issued + 1, even after renumbering, and a deleted employee's ID stays theirs. HR (permission `employee.edit_code`) may change an ID to any free number from 0 up to the highest issued, or swap it with another employee's. Changing an employee's ID or Arabic name after an attendance template download refuses that employee's rows in the old template; download a fresh one.
_Avoid_: الكود; code (in the UI)
0 is the CEO's ID and no one else's: whoever holds 0 is the CEO. It is given only while no one holds it, and never swapped. Employees are selected by name; the ID tells two people with the same name apart and keys imports and pasted batches.

**Archive** (الأرشيف):
The employees whose termination date has passed. The termination day itself is still worked, so an employee leaves the active list the day after. Reactivating an archived employee clears the termination date and reason, which counts the days since as employed again.
Archived employees are hidden everywhere by default: in no list, search, picker, count or alert (owner, 2026-11-11). Only all-scope viewers reach them, through the Archive view of the employees list, on a read-only page whose only actions are Reactivate and correcting the termination date or reason; a site manager never reaches them. HR shows them on the period grid («إظهار من تركوا العمل») to finish their last days. The lock and payroll still include them.
Archiving deactivates the employee's linked account: at once when the termination date saved has already passed, else at 00:05 Cairo on the day after it (owner, 2026-11-12). Reactivating the employee leaves the account inactive; it is reactivated by hand, after the employee.
_Avoid_: inactive list, deleted (a deleted employee is gone from both lists)

**View as** (عرض كـ):
The developer uses the app as another account, without its password, for at most 30 minutes; the audit log names the developer as the actor, on behalf of that account. In code: the permission `user.impersonate`, `user_sessions.impersonator_id` and `audit_log.on_behalf_of`.
_Avoid_: log in as, switch user; impersonate outside those code names

## Work and attendance

**Site** (موقع):
A place where a day of work is done, usually a client location.
_Avoid_: location, project, place (المكان)

**Home site** (الموقع الأساسي):
The site a worker belongs to from a date until HR moves them. A worker helping at another site, even for one day, is moved there first; a day laborer's one-day move is a crew change instead (see Crew). A one-day visit HR records through the site workbook needs neither: the day alone is at the visited site. A site manager's own writes still need the home site (owner, 2026-11-12). An office employee may hold one too, set by HR, but it only lists them at that site in HR's attendance filter: it gives no site manager reach and changes no pay, fare or site costing (owner, 2026-11-08). Engineers have none: where an engineer is comes from their recorded days.
_Avoid_: current site, assigned site

**Crew** (طاقم):
The day laborers on a site's list for one date: those whose home site it is, changed for that date only by the site manager's crew changes (add someone to today's crew, move them between their own sites, or take them off). A crew change never touches the home site, yesterday's records or tomorrow's list (owner, 2026-11-04).
_Avoid_: team, gang, roster (that is the whole list, workers and engineers)

**Proposed laborer** (عامل مقترح):
A new day laborer a site manager adds to today's crew, with their name, gender, optional mobile and a proposed wage. HR's site workbook can also create one, with a national ID, an optional mobile, residence and proposed wage, and gender male until HR changes it (the owner's default, 2026-11-12); with no proposed wage HR sets the wage on the employee's page. They have no home site and no pay row until HR approves the wage; if HR refuses it, they end on that day and leave every crew, and the days already recorded wait for HR.
_Avoid_: temporary worker, new hire

**Proposed wage** (أجر مقترح):
The daily wage a site manager types for a proposed laborer. A request, never a pay rate: HR sets the wage when approving it. The site manager never sees a stored wage.
_Avoid_: wage, rate

**Leave day-type**:
A non-work value recorded instead of a site for a day: Friday, Saturday, official holiday, sick, rest, absent (غ). It gets its own row in site costing. Stored as a `sites` row with `kind=leave` and a `leave_code`; the UI calls them أنواع الإجازات / Leave types and never "leave site".
_Avoid_: leave site

**Leave request** (طلب إجازة):
A request for annual, casual, sick or unpaid leave over a date range, filed by a site manager or HR, approved, refused or escalated to the CEO by HR, and cancellable; approval writes its days into attendance and cancelling an approved one clears them.

**Leave balance** (رصيد الإجازات):
A monthly employee's annual entitlement for the year minus the annual and casual days stored in attendance and the days HR records as used before the system; it may go negative.

**Annual leave** (اعتيادية):
A paid leave day-type that counts against the leave balance.

**Casual leave** (عارضة):
A paid leave day-type that counts against both the leave balance and its own yearly casual cap (a setting, never above the entitlement).

**Unpaid leave** (بدون أجر):
A leave day-type that deducts a day's pay and leaves the balance alone; approval writes it for days beyond the balance.

**Residence** (محل الإقامة):
The town an employee travels from. Together with the site it sets the fare.
_Avoid_: السكن; address, home

**Shift**:
An employee's scheduled start and end time. Lateness and overtime are measured against it.
_Avoid_: schedule, working hours

**Late permission** (إذن تأخير):
An approved request that cancels one day's lateness.

**Early leave** (انصراف مبكر):
Either a one-day permission to leave before shift end, or a permanent change to the employee's shift.

**Attendance day** (يوم حضور):
One employee's record for one date: one site, one leave day-type, or (office) present. A day with none is a missing day, never an absence.
_Avoid_: timesheet row, punch

**Open day** (يوم مفتوح):
A field day (engineer, permanent worker, day laborer) with an arrival and no departure, in a period that is not locked. The site manager records no later day for that person until it is completed; past the yesterday window they may still add its departure, and HR hears of it at once. A period with an open day cannot be locked. Office days are never open days.
_Avoid_: incomplete day, missing checkout

**Attendance lock** (قفل الحضور):
HR's freeze of one group's period; nobody edits until HR reopens with a reason.
_Avoid_: close (that is the Release 2 payroll period close)

**Official holiday** (إجازة رسمية):
A named day or period of consecutive days off for everyone (such as Eid), kept in Settings; holidays never overlap.
_Avoid_: public holiday, vacation

**Derived day** (يوم تلقائي):
A weekly off day or official holiday the system fills in until HR records the day; stored at lock.
_Avoid_: default day, auto row

**Day sheet** (كشف اليوم):
One group's attendance for one date, where HR marks many employees at once. The group All lists every type for reading only; each row's Details opens the day editor.
_Avoid_: roll call, check-in screen

**Site workbook** (ملف الموقع):
An Excel file HR downloads empty for one site and up to 31 days, one sheet per day. The site manager fills it by name: each person's status, times, advance and note, and a new day laborer's details. HR uploads it, previews and confirms. Every day it records is HR's and is at that site for that date only. It sits beside the per-type attendance template, which takes one site or leave day-type per cell (owner, 2026-11-12).
_Avoid_: site sheet, site template, Excel file (alone)

**Last site** (آخر موقع):
The work site of an employee's latest recorded work day in the 7 days before a date.
_Avoid_: yesterday's site

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
HR sets every fare; the team lead reads their own team's. The residence × site amount is the **default**; a per-worker **override** wins over it. Engineers get a fare only by override; a day laborer with no fare gets 0.
_Avoid_: الأجرة; transport allowance (for office tower trips, see Tower transport)

**Tower transport** (بدل انتقالات البرج):
An office employee's allowance of a quarter day's pay per tower day.

**Day cost**:
What one recorded day costs the company: day rate plus that day's extras. Summed per site for site costing.

**Advance** (سلفة):
Money given ahead of pay and recovered in one payment or in installments.
_Avoid_: loan

**Grace period** (دقائق السماح):
The first 15 late minutes of a day, which cost nothing. Applied once per day: arriving 08:15 for an 08:00 start costs nothing, 08:20 costs 5 minutes.

**Chargeable late minutes**:
A day's late minutes minus the grace period, never below zero. Only these are deducted.
_Avoid_: net late minutes (that is the period's sum after exemptions)

**Lateness multiplier** (معامل التأخير):
How many minutes of pay one chargeable late minute costs. It belongs to the employee type, not to the person or the job title: 1 for office staff, 1.5 for engineers, 2 for permanent workers and day laborers.
_Avoid_: penalty rate, lateness factor

**Lateness deduction** (خصم التأخير):
Chargeable late minutes × the employee's minute rate × their type's lateness multiplier. A different thing from the absence deduction, early leave and overtime, which have their own rules.

**Absence deduction** (خصم الغياب):
The pay lost for a full day absent (غ). Separate from lateness: a lateness multiplier of 1 exempts no one from it.

**Override**:
A value HR types in place of one the system computed, with a reason.
_Avoid_: manual edit, adjustment

**Payroll sheet** (المرتبات):
One group's payroll for one period: a line per employee who holds the group's type on some day of the period (leavers included), with each component's computed value, payroll override and final value, the net, the flags and a totals row; each line opens to its days. It is computed on read from attendance and the payroll entries, and stays editable after the attendance lock until payroll close exists.
_Avoid_: payroll run, payslip (that is one employee's PDF)

**Payroll entry** (مدخلات المرتبات):
HR's inputs for one employee in one period, the values payroll cannot compute: a bonus (office, day laborers); a deduction with its reason (field types); office's negligence deduction, lateness exemption, travel and tower days, overtime minutes and advances; the insurance shares and income tax (monthly staff); a note. A type takes only its own fields; blank counts as 0. The English UI calls it Payroll inputs or HR's inputs; in code: the table `payroll_entries`.

**Payroll override** (قيمة بديلة):
An override of one component of one employee's line on the payroll sheet, the net included. It keeps the computed value it replaced, so the line shows "inputs changed" when that value moves later; HR reverts it with a reason. A component has one live payroll override; a net override replaces the net, any other moves the net by its change.

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
