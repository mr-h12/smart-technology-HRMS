# Smart Tech HRMS — Every Calculation

Every number the system computes, in plain formulas. Source: the client's Excel files (`docs/excel-system-analysis.md`) plus the decisions in `docs/hrms-module-plan.md`.
Values in **bold** are settings (editable, with an effective date). ⚠️ marks a point still open with the client (also listed in `docs/client-questions.md`).

**Applies everywhere**
- No intermediate rounding, as in the Excel. Values are stored as `NUMERIC(18,6)`; screens and PDFs show 2 decimals.
- HR can override any computed value below. The override needs a reason, is highlighted, is audit-logged, and survives recomputation; if its inputs change later, the screen warns "inputs changed after your edit". HR can revert to the computed value.

## 0. Base rates (used everywhere)
For a monthly salary `S` (basic + raise):

| Rate | Formula | Example, S = 9,000 |
|---|---|---|
| Day rate | S ÷ **30** | 300 |
| Hour rate | Day rate ÷ **8** | 37.50 |
| Minute rate | Hour rate ÷ 60 | 0.625 |

For a day laborer, the day rate is the worker's own daily wage (section 4), not S ÷ 30.

## 1. Office staff (موظفين مثبتين شركة): calendar month

**Salary parts**
- Salary = basic + raise. The raise is paid (this fixes Excel error #2).
- Incentives (حوافز) = salary − **insured basic**. The payslip shows insured basic + incentives.

**Lateness, from the fingerprint file**
- Shift **09:00–17:00** by default, with a per-employee override. Weekly off **Friday + Saturday**.
- Daily late minutes = max(0, check-in − shift start − **15 grace**)
- If an approved late permission covers that day, its late minutes = 0
- Net late minutes = Σ daily late − exemption (entered by HR)
- Lateness deduction = net late minutes × minute rate × **1** (office multiplier)
- The import flags missing punches and early leave (check-out before shift end); HR confirms or edits each flag.
- Office lateness from in time: max(0, in − shift start − **15** grace), where shift start is **09:00** (`office_shift_start`) or the pay-row override. It is shown as a minutes hint; the fingerprint import fills the same fields in Release 2.

**Additions**
- Overtime = OT minutes × minute rate × **1.5**. OT minutes are suggested from check-out − shift end; HR confirms.
- Travel allowance = travel days × day rate
- Tower transport = tower days × day rate ÷ 4
- Bonus = entered by HR

**Deductions**
- Absence = absence days × day rate
- Social insurance (employee share) = entered by HR; blank = 0 (section 5)
- Income tax = entered by HR; blank = 0
- Advance installment = section 6
- Negligence deduction = entered by HR

**Net**
```
Net = salary + travel + tower + overtime + bonus
      − insurance − tax − advance − negligence − lateness − absence
```
Example (S = 9,000): 60 late minutes after grace → 60 × 0.625 = **37.50** deducted; 2 absence days → **600** deducted; 120 OT minutes → 120 × 0.625 × 1.5 = **112.50** added.

## 2. Engineers (مهندسين): 26th → 25th
Each day records: site, OT minutes, late minutes, minutes worked outside Alexandria, advance taken. Weekly off **Friday**.

- Day extra = OT min × minute rate × **1.5** − late min × minute rate × **1.5** + outside-Alex min × minute rate
- Day cost = day rate + day extra (used for site costing, section 8)
- Absence = days marked غ by the manager × day rate
- Deduction (الخصم) = penalty entered by HR with a reason
- Net = salary + Σ day extra − absence − deduction − insurance − tax − advance installments

Outside-Alex minutes are paid at ×1, with no multiplier, as in the Excel.

## 2a. Derived off days
- Weekly off per type: **Friday + Saturday** for office staff, **Friday** for the field types.
- Each weekly off weekday is derived as the leave day-type with the same code: Friday → «اجازة الجمعة», Saturday → «اجازة السبت».
- Official holidays come from the holiday list. When a weekly off day is also a holiday, the holiday wins.
- A derived day is a leave day: no scheduled times, and a permanent worker's transport is none.
- Derived on read while the period is open; stored at lock. HR can record the day instead (a site, if the person worked).
- Reopening keeps the stored days. ⚠️ A later holiday or weekly-off change does not refresh them when the period is locked again; is that right?
- A derived day is never an absence.

## 3. Permanent workers (عمال مثبتين): 26th → 25th
Each day records: scheduled in/out (default **08:00–16:00**), actual in/out, site, outside-Alex money, transport (full / half / none), advance. Weekly off **Friday**.

- Scheduled in/out are stored on each day; they default to **08:00–16:00** (`worker_shift_start`/`worker_shift_end`) or the pay-row override. Late and OT minutes are computed from actual vs scheduled and shown as hints.
- ⚠️ Late minutes do not wrap around midnight: scheduled 00:00 with arrival 23:50 the day before gives 1,430 late minutes; scheduled 23:50 with arrival 00:10 gives 0. Must a scheduled start that crosses midnight wrap?

- Hours worked = actual out − actual in. If out is earlier than in, the shift crossed midnight: add 24 h.
- Late hours = actual in − scheduled in (if positive)
- Lateness money = late hours × hour rate × **2**
- OT hours = max(0, hours worked − **8**)
- OT money = OT hours × hour rate × **1.5** + outside-Alex money
- Transport = fare (section 4 fare rule) for full, fare ÷ 2 for half. The manager picks per day; default full, including on a missing or future work day. A leave day stores none.
- Day cost = day rate + OT money (site costing)
- Net = salary + Σ OT money + Σ transport − Σ lateness − absence − deduction − insurance − tax − advance installments

The Excel worker template **adds** lateness to the net (Excel error #4) and leaves transport out. The system subtracts lateness and pays transport.

## 4. Day laborers (عمال باليومية): 1–15 and 16–end
Each day records a site. The worker has a **daily wage** (effective-dated) and a **residence**.

| Item | Formula |
|---|---|
| Daily wage | the worker's daily wage. 0 on non-work days (Friday, absent غ, rest) |
| Allowance (بدل) | the site's allowance rule: a share of the wage (e.g. Alex Apparel = wage × 0.25, Nestlé Sharm = wage × 2) or a fixed amount. Most sites 0 |
| Transport | fare: the worker's override for that site if set, else the residence × site fare. ⚠️ the October Excel pays 0 transport to every day laborer |
| OT money | OT min × (wage ÷ 8 ÷ 60) × **1.5** |
| Lateness | late min × (wage ÷ 8 ÷ 60) × **2** |
| Day total | wage + allowance + transport + OT − lateness |

```
Net = Σ wage + Σ allowance + Σ transport + Σ OT + bonuses
      − Σ lateness − advance installments − deductions
```
Day laborers have no social insurance and no leave balance.
⚠️ Does a worker's wage itself ever change by site, or only the allowance?

Example: daily wage 330 at a site with no allowance, fare 35, 60 OT minutes → 330 + 0 + 35 + (60 × 330/480 × 1.5 = 61.875) = **426.875** for that day (shown 426.88).

## 5. Social insurance and income tax
- Employee insurance share, company insurance share and income tax are typed by HR per employee per period. Blank = 0.
- The employee share and tax are deducted from net. The company share is a company cost (section 9).
- Day laborers have no insurance fields.

## 6. Advances (السلف)
- For every advance, including one logged on a timesheet day, HR chooses: deduct in full this period, or split into installments.
- Installment = advance amount ÷ number of installments
- Deduction this period = the installments due in this period
- Balance = Σ advances − Σ repayments

## 7. Leave balance (الإجازات)
- Annual entitlement = **10** days if service < 1 year on Jan 1, otherwise (1 year or more) **21**. Of those, up to **6** may be casual (عارضة).
- Balance = entitlement − leave days used this year
- Casual balance = 6 − casual days used
- The year starts Jan 1. Nothing carries over.
- Someone who passes 1 year of service mid-year: HR gets an alert and decides whether to change the entitlement.
- ⚠️ Is a first-year hire's 10 days pro-rated?
- ⚠️ Leave pay: are annual, casual and sick leave fully paid (sick 75%?), and does leave beyond the balance become absence? Decide when building leave.

## 8. Site labor cost (ملخص المواقع)
For each site and period:
```
Site cost = Σ day cost of every day recorded at that site
```
Day cost comes from the engineer (section 2), permanent worker (section 3) or day laborer day total (section 4). Leave day-types (Friday, official holiday, sick, غ) get their own rows, so the cost of paid leave is visible.

## 9. Period totals and payroll approval
- Gross per employee = all additions. Deductions = all deductions. Net = gross − deductions.
- Period totals = Σ over employees, per column
- Company cost = Σ gross + Σ company insurance share
- When HR closes the period and the CEO approves it, every input, setting and result is frozen. Later changes to settings don't change it.

## 10. New hires, leavers, changes and end of service
- Days not employed in a period (before hire, after leaving) → absence deduction over **30** days (section 1). HR can edit.
- A salary change splits the period at its effective date; each part uses its own day rate.
- A type change (monthly ↔ daily) starts at a period boundary.
- End-of-service bonus suggestion = **0.5** month × years of service × last total wage (basic + incentives). HR edits it. ⚠️ Are fractions of a year counted? Which wage is the base? (The client's form drops the extra months.)
- Clearance is allowed only when the employee has no open custody items.

## 11. Reminders
- Reminder date = event date − **lead days** for that date type. Defaults: contract end 30, probation end 14, ID/licence/permit expiry 30.
- Probation end = hire date + **90** days

## 12. Permissions (requests)
- Late permission: max duration and max count per month are **settings** (blank = no limit). ⚠️ values from the client.
- Early leave: a one-day permission, or a permanent shift change that updates the employee's shift on approval.

## Not calculated by the system
- Bonuses, negligence deductions, penalties, lateness exemptions, insurance shares and income tax: entered by HR.
- Bank transfers: HR only marks "paid" and writes how the money was received.
