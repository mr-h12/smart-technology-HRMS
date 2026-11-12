# Smart Tech HRMS — Every Calculation

Every number the system computes, in plain formulas. Source: the client's Excel files (`docs/excel-system-analysis.md`) plus the decisions in `docs/hrms-module-plan.md`.
Values in **bold** are settings (editable, with an effective date). ⚠️ marks a point still open with the client (also listed in `docs/client-questions.md`).

**Applies everywhere**
- No intermediate rounding, as in the Excel. Values are stored as `NUMERIC(18,6)`; screens and PDFs show 2 decimals. An amount typed per day (such as outside money) is stored at 6 decimals too, so where the Excel holds a formula with endless decimals the sum of days can differ in the sixth decimal (`stored_amount_six_decimals` in the October regression).
- HR can override any computed value below. The override needs a reason, is highlighted, is audit-logged, and survives recomputation; if its inputs change later, the screen warns "inputs changed after your edit". HR can revert to the computed value.
- On the payroll sheet a component has one live override: a second is refused (409), so changing one is a revert and a new override, both audited. The final net is HR's net override if there is one; else the computed net moved by each override's change, added for a paid component and subtracted for a deducted one.
- A net below 0 is shown as computed and flagged `negative_net`, never clipped, for every employee type; the flag is checked on the final net, after the overrides.

## 0. Base rates (used everywhere)
For a monthly salary `S` (basic + raise):

| Rate | Formula | Example, S = 9,000 |
|---|---|---|
| Day rate | S ÷ **30** | 300 |
| Hour rate | Day rate ÷ **8** | 37.50 |
| Minute rate | Hour rate ÷ 60 | 0.625 |

For a day laborer, the day rate is the worker's own daily wage (section 4), not S ÷ 30.

Payroll reads S or the daily wage from the pay row in force on each day, and only the wage fields of the type held that day (monthly staff: salary + raise; a day laborer: the daily wage), since an imported pay row may hold the other type's fields. A day with no such wage (no pay row in force, or no salary or daily wage on it) counts its wage as 0 and is flagged `no_wage`: any employed day of monthly staff, a worked day of a day laborer.

The divisor is always **30**, in every month (February and 31-day months included), and the periods are: office a calendar month, engineers and permanent workers the 26th → the 25th, day laborers 1–15 and 16 → the end. Both match every October formula (R26, 2026-11-06); the few Excel cells that divide by 31 are pasted values (`excel-system-analysis.md` error #7).

## 0a. Lateness deduction (every type) — decided 2026-11-03, multiplier per type 2026-11-06
Source: the owner's decision of 2026-11-03 (grace, divisor, wage). The multiplier is fixed per type (2026-11-06, R27), checked against every lateness formula in the October workbooks (office salary sheet, engineers and permanent workers, day laborers 1–15 Oct). The workbooks are reference only and not committed, because they name real employees.

```
chargeable late minutes = max(0, late minutes − 15)          per day, grace applied once
lateness deduction      = chargeable late minutes × wage ÷ divisor × multiplier
```

| Type | Multiplier | Divisor | Wage | October Excel |
|---|---|---|---|---|
| Office staff | **1** | 14,400 = 30 × 8 × 60 | monthly salary after the raise, in force on the day | all 27 rows: net late min × salary ÷ 30 ÷ 8 ÷ 60 |
| Engineers | **1.5** | 14,400 | monthly salary in force on the day | every engineer-template sheet with formulas (6; one more has pasted values) |
| Permanent workers | **2** | 14,400 | monthly salary in force on the day | every worker-template sheet (20) |
| Day laborers | **2** | 480 = 8 × 60 | the daily wage in force on the day | every sheet with a lateness formula (222; 7 have none) |

- The multiplier is the employee type's, never the person's or the job title's. It is already a setting per type in Settings → Rules (`lateness_multiplier_office`, `_engineer`, `_permanent_worker`, `_day_laborer`, seeded 1, 1.5, 2, 2 by `2026_10_19_000001_create_settings`), like `overtime_multiplier`. No sheet in October used another value: the earlier reading "1.5 or 2 per person" came from engineers and permanent workers using two different sheet templates.
- The wage is the system's wage in force on the day. No EGP-per-minute amount is imported or stored.
- Allowances, fares and transport are not part of the wage here.
- The grace is subtracted once. Late minutes stored on a day are the **raw** minutes after the shift start (R18, owner 2026-11-08). The October office sheet typed `=42-15`, minutes already net of grace, so imported office history must not be reduced again.
- Example, 08:00 start: arrival 08:15 → 0; arrival 08:20 → 5 chargeable minutes. Day laborer on 330 with multiplier 2: 5 × 330 ÷ 480 × 2 = **6.875**. Office, salary 9,000, multiplier 1: 5 × 9,000 ÷ 14,400 × 1 = **3.125**.
- Early leave and overtime have their own rules; they reuse neither the multiplier nor the grace.
- No rounding inside the formula (as everywhere), and none on the payable amount either: the net is paid as computed, shown to 2 decimals (owner, 2026-11-09, question 12). HR may override a net with a reason.
- ⚠️ Changes from the Excel: field types had no grace, and the permanent-worker template charged late **hours** × hour rate × 2 from the scheduled start. Both can move the October fixture; every difference goes in the Excel-difference report with its reason. Do not edit the fixture to make a test pass. (The 2026-11-03 rule of a multiplier per employee is withdrawn: 2026-11-06, R27.)

## 1. Office staff (موظفين مثبتين شركة): calendar month

**Salary parts**
- Salary = basic + raise. The raise is paid (this fixes Excel error #2).
- Incentives (حوافز) = salary − **insured basic**. The payslip shows insured basic + incentives.

**Lateness, from the fingerprint file**
- Shift **09:00–17:00** by default, with a per-employee override. Weekly off **Friday + Saturday**.
- Daily late minutes = max(0, check-in − shift start − **15 grace**)
- If an approved late permission covers that day, its late minutes = 0
- Net late minutes = Σ daily late − exemption (entered by HR), never below 0
- Lateness deduction = net late minutes × minute rate × **1** (the office multiplier, §0a). Each late minute costs its own day's salary, so the exemption waives the same share of every late minute: deduction = Σ daily lateness money × net late minutes ÷ Σ daily late minutes.
- The import flags missing punches and early leave (check-out before shift end); HR confirms or edits each flag.
- Office lateness from in time: max(0, in − shift start − **15** grace), where shift start is **09:00** (`office_shift_start`) or the pay-row override. It is shown as a minutes hint; the fingerprint import fills the same fields in Release 2.

**Additions**
- Overtime = OT minutes × minute rate × **1.5**. OT minutes are suggested from check-out − shift end; HR confirms.
- Travel allowance = travel days × day rate
- Tower transport = tower days × day rate ÷ 4. The 4 is a constant in code, not a setting. ⚠️ (client question 27)
- OT minutes, travel days and tower days are HR's period totals, with no date: when the salary changes inside the month, their minute and day rates come from the month's salary (each day's salary over the month, §10).
- Bonus = entered by HR

**Deductions**
- Absence = absence days × day rate
- Social insurance (employee share) = entered by HR; blank = 0 (section 5)
- Income tax = entered by HR; blank = 0
- Advance installment = section 6
- Negligence deduction = entered by HR. Office has no general deduction (the penalty of §2): the negligence deduction is HR's only penalty for office staff, as in the net below. ⚠️ (client question 26)

**Net**
```
Net = salary + travel + tower + overtime + bonus
      − insurance − tax − advance − negligence − lateness − absence
```
Example (S = 9,000): 60 late minutes after grace → 60 × 0.625 = **37.50** deducted; 2 absence days → **600** deducted; 120 OT minutes → 120 × 0.625 × 1.5 = **112.50** added.

## 2. Engineers (مهندسين): 26th → 25th
Each day records: site, arrival and departure, OT minutes, late minutes, minutes worked outside Alexandria, advance taken. Weekly off **Friday**.

- Arrival/departure default to the field shift on Present (§13). They suggest late = max(0, arrival − shift start) and
  OT = max(0, departure − shift end) as hints; the shift is the per-employee shift, else the field shift setting
  (`worker_shift_start`/`worker_shift_end`, §2b). The owner's field shift is **09:00–16:00** (R19, 2026-11-04), which HR
  sets in Settings at rollout; until then the setting holds **08:00–17:00** (migration `2026_11_02_000001_set_field_shift_end`).
  Overtime starts at the **shift end**, not after 8 h worked (owner, 2026-11-09, client question 20): shift 09:00–16:00,
  departure 17:30 → 90 OT minutes suggested.
  Since 2026-11-12 every write stores these minutes (owner, §13): the late minutes whenever the arrival changes, the
  overtime when HR writes the departure. A departure the site manager writes stores overtime 0 until HR clicks «اعتماد» on the
  day sheet. HR may type other minutes. Payroll reads only the stored minutes. Payroll takes the day's scheduled in and out
  from the same shift (the employee's, else the field shift), for engineers and day laborers, only for the early-departure flag.

- Day extra = OT min × minute rate × **1.5** − chargeable late min × minute rate × **1.5** (the engineer multiplier, §0a) + outside-Alex min × minute rate
- Day cost = day rate + day extra (used for site costing, section 8)
- Absence = days marked غ × day rate, as for office staff (owner, 2026-11-09, R21). The October Excel deducts no absence for engineers or permanent workers (the summary's غياب column is 0 for 20 of 22 people, and the other 2 link to a «خصم يوم اجازة» cell holding 0), so any October غ day is a line in the Excel-difference report.
- Early departure and half days are not deducted automatically (owner, 2026-11-09, R25), as in the Excel. Payroll shows the early-departure minutes (departure before the shift end) to HR as a flag; HR deducts by hand through the deduction field, with a reason.
- Deduction (الخصم) = penalty entered by HR with a reason
- Transport = the engineer's per-worker fare override in force for the day's site, in full, for every day worked at a work site; none on a leave day or without an override (owner, 2026-11-09). There is no half fare for engineers.
- Net = salary + Σ day extra + Σ transport − absence − deduction − insurance − tax − advance installments
- Engineers and permanent workers have no bonus input: HR pays a bonus by a net override. ⚠️ (client question 24)

Outside-Alex minutes are paid at ×1, with no multiplier, as in the Excel.

Every write, the site manager's screen (§2b) included, stores an engineer's late minutes as the raw minutes after the shift start, recomputed whenever the arrival changes (owner, 2026-11-12, §13); an open day (arrival, no departure) blocks the next day and the period lock.

## 2a. Derived off days
- Weekly off per type: **Friday + Saturday** for office staff, **Friday** for the field types.
- Each weekly off weekday is derived as the leave day-type with the same code: Friday → «اجازة الجمعة», Saturday → «اجازة السبت».
- Official holidays come from the holiday list. A holiday is a named period of one or more consecutive days (from, to; decided 2026-11-10), and every day in it is an official holiday. Two holidays never overlap; they may touch. A period may cross a payroll period boundary or a year end, and each day counts in its own period. When a weekly off day is also a holiday, the holiday wins.
- A derived day is a leave day: no scheduled times, and a permanent worker's transport is none.
- Derived on read while the period is open; stored at lock. HR can record the day instead (a site, if the person worked).
- The lock still includes archived employees (termination date before today) employed in the period, though they are hidden from the attendance screens by default (owner, 2026-11-11): their missing days need confirming, an open day of theirs blocks the lock, and their derived days are stored.
- Reopening keeps the stored days. A holiday can only be added, moved, shortened or deleted while every period of every group on each day of its old and new period is open (decided 2026-11-04, per day of a period 2026-11-10):
  - deleting, moving or shortening a holiday removes the days stored as that official holiday by a lock (source `derived`) on the dates it no longer covers. Those dates then derive again (a weekly off) or are missing, which the lock's confirm-missing step lists;
  - a new holiday day with no stored day derives and is stored at the next lock, as before;
  - stored weekly-off days are never refreshed, because the weekly-off setting has no effective date; days HR or the leave workflow wrote (source `entered`, `imported`, `leave`) are never touched;
  - a re-lock lists the holiday days removed and every day, inside the locked period, of the holidays added or changed since the last lock, and separately the leave days (annual, casual, sick, unpaid) that now fall on a holiday. It does not change those leave days; HR reviews them.
- The payroll sheet flags the same stored leave days (annual, casual, sick, unpaid on an official holiday) on the employee's line, `leave_on_holiday`, locked or not; it changes and deducts nothing. ⚠️ An absence (غ) on a holiday is not flagged; the owner is asked whether it should be.
- A derived day is never an absence.

## 2b. Field shift and the site manager's day — decided 2026-11-04

Applies to engineers, permanent workers and day laborers recorded on the site manager's screen (`/site-day`). Minutes only; money is §0a and §2–§4.

**Shift**
- The field shift is **09:00–16:00** (owner, 2026-11-04). It lives in Settings → Rules (`worker_shift_start`, `worker_shift_end`), which HR sets at rollout; no migration changes it. An employee's own shift on their pay row wins.
- «حضور الكل» fills the shift start as the arrival and leaves the departure empty. «انصراف الكل» fills the shift end for everyone present with an arrival and no departure. Typed times are kept.

**What a day stores**

| | Rule |
|---|---|
| Arrival | Required on a present day. A departure without an arrival, or before it, is refused. |
| Late minutes (engineers, day laborers) | **Raw**: max(0, arrival − shift start), with no grace. §0a subtracts the 15-minute grace once at payroll. Stored by every write, not only this screen, and recomputed whenever the arrival changes, even over a number HR typed (owner, 2026-11-12, §13). Permanent workers' lateness comes from `scheduled_in` and the arrival at payroll (§3). |
| Early departure | Not a stored field: payroll reads it from the departure and the shift end (`scheduled_out` for permanent workers). |
| Overtime | Departure after the shift end is shown as a difference only. Engineers' and day laborers' `ot_minutes` are stored as **0** until HR approves the overtime, and reset to 0 only when the departure changes. HR's day sheet shows the suggested minutes with «اعتماد», which stores them; a departure HR writes stores the overtime at once (owner, 2026-11-12, §13). |
| Missing departure | A field day with an arrival and no departure is **open** (office days never are). The site manager cannot save a later day for that person until it is completed. After the yesterday window (`manager_yesterday_until`, 12:00) the site manager may still enter the **departure only**, until the period is locked: the arrival and the status stay, the late minutes stay as stored, and HR hears of each such save at once (owner, 2026-11-05). A day HR recorded, or one outside the site manager's reach that day, is HR's to complete. HR gets the count of open days in the 21:00 alert. Locking a period is refused while any field day in it is open (since 2026-11-04), so a period locked since then has none; an arrival-only day left in a period locked before that rule never blocks a later save. Payroll reads every field work day with an arrival and no departure as open, whatever the lock: it pays it as a worked day with no overtime (it has no departure yet) and flags it `open_day`: a day laborer's wage, site allowance, fare and lateness; an engineer's or permanent worker's transport, outside-Alexandria pay and lateness. |

**Who changes a day**
- A day HR recorded, imported or materialized, or one an approved leave request wrote (sick included, even over a sick day the site manager wrote first), is read-only for the site manager.
- The site manager clears only their own days, at the site they are working on or on one of their leave types (absent, sick, rest, other).
- HR's «اعتماد» of the overtime is an HR write: the day becomes HR's (source `entered`), so the site manager can no longer change it (owner, 2026-11-12).

Worked example (shift 09:00–16:00, grace 15): arrival 09:25 → 25 late minutes stored, 10 chargeable at payroll; departure 15:00 → 60 minutes early; departure 17:00 → 60 minutes after hours, overtime 0 stored until HR clicks «اعتماد», which stores 60 (had HR written the 17:00 departure, 60 would be stored at once).

## 3. Permanent workers (عمال مثبتين): 26th → 25th
Each day records: scheduled in/out (default: the field shift, §2b), actual in/out, site, outside-Alex money, transport (full / half / none), advance. Weekly off **Friday**.

- Scheduled in/out are stored on each day; they default to the field shift setting (`worker_shift_start`/`worker_shift_end`: **09:00–16:00** once HR sets it, §2b; seeded **08:00–17:00**) or the pay-row override. Late minutes = max(0, actual in − scheduled in); OT minutes = max(0, actual out − scheduled out), as below. Both are shown as hints. Payroll reads a day without stored scheduled times at the same default.
- Late minutes do not wrap around midnight (owner, 2026-11-09, client question 14): scheduled 00:00 with arrival 23:50 the day before gives 1,430 late minutes; scheduled 23:50 with arrival 00:10 gives 0. HR overrides the rare case with a reason.
  The same family, by the rules below, for a permanent worker: scheduled 00:00–08:00, arrival 23:50, departure 08:00 also gives 1,440 OT minutes; scheduled 20:00–04:00, arrival 00:30, departure 05:00 gives 0 late and 0 OT minutes, with a false early-departure flag of 1,380 minutes; scheduled 23:50–08:00, arrival 00:10, departure 08:00 gives a false early-departure flag of 1,440 minutes. Both flags move no money, and they reach an engineer whose shift is that night shift too; an engineer's OT stays the minutes HR approved (§2). HR overrides these days with a reason. ⚠️ (client question 28)

- Hours worked = actual out − actual in. If out is earlier than in, the shift crossed midnight: add 24 h.
- Late hours = actual in − scheduled in (if positive)
- Lateness money = chargeable late minutes × minute rate × **2** (the permanent-worker multiplier, §0a; the Excel charged late hours × hour rate × 2, with no grace)
- OT minutes = max(0, actual out − scheduled out), overtime starting at the shift end (owner, 2026-11-09, client question 20). A time earlier than the day's in time is the next day (+24 h): actual out against actual in, scheduled out against scheduled in. The Excel used max(0, hours worked − 8 h); the two agree only when the worker arrived at the scheduled time of an 8-hour shift, so every other October day is an Excel-difference line. A day whose stored scheduled times equal its actual times gives 0 OT minutes. ⚠️ (client question 25)
- OT money = OT minutes × minute rate × **1.5** + outside-Alex money
- Transport = fare (section 4 fare rule; HR sets every fare) for full, fare ÷ 2 for half. HR picks per day for now (1c), the manager later. The attendance grid shows full by default, including on a missing or future work day, but payroll pays transport only on a recorded work day (an open one included): a missing day or a future day pays none. A leave day stores none.
- Day cost = day rate + OT money (site costing)
- Absence and early departure: as for engineers (§2; R21, R25, owner 2026-11-09).
- Net = salary + Σ OT money + Σ transport − Σ lateness − absence − deduction − insurance − tax − advance installments

The Excel worker template **adds** lateness to the net (Excel error #4) and leaves transport out. The system subtracts lateness and pays transport.

On the site manager's screen (§2b): a permanent worker's open day (arrival, no departure) blocks the next day and the period lock, as for the other field types; their late minutes still come from `scheduled_in` and the arrival.

## 4. Day laborers (عمال باليومية): 1–15 and 16–end
Each day records a site, arrival and departure (§2 defaults and hints), OT and late minutes. The worker has a **daily wage** (effective-dated) and a **residence**.

| Item | Formula |
|---|---|
| Daily wage | the worker's daily wage. 0 on non-work days (Friday, absent غ, rest) |
| Allowance (بدل) | the site's allowance rule: a share of the wage (e.g. Alex Apparel = wage × 0.25, Nestlé Sharm = wage × 2) or a fixed amount. Most sites 0 |
| Transport | fare: the worker's override for that site if set, else the residence × site fare, else 0 (no fare, or no residence). Engineers get transport only from an override, never the residence × site fare (owner default, 2026-11-05). HR sets every fare (the residence × site default, plus per-worker overrides); the team lead reads their own team's fares. Payroll flags `no_fare` when a worked day finds no fare (a permanent worker's day only when its transport is not none, §3); an engineer without an override gets 0 and no flag. ⚠️ the October Excel pays 0 transport to every day laborer: the October fixture runs with no day-laborer fares, and the difference goes in the Excel-difference report. |
| OT money | OT min × (wage ÷ 8 ÷ 60) × **1.5** |
| Lateness | chargeable late min × wage ÷ 480 × **2** (the day-laborer multiplier, §0a) |
| Day total | wage + allowance + transport + OT − lateness |

```
Net = Σ wage + Σ allowance + Σ transport + Σ OT + bonuses
      − Σ lateness − advance installments − deductions
```
Day laborers have no social insurance and no leave balance.
A partial day (half a day, or leaving early) earns the full daily wage, and only lateness is deducted, as in the October Excel (owner, 2026-11-09, R25). Payroll flags the early-departure minutes to HR, who may deduct by hand with a reason. OT minutes are the stored minutes: filled from the departure when HR writes it, and approved by HR when the site manager wrote it (§2, §13, owner 2026-11-12).
A worker's wage itself never changes by site; only the site allowance does (owner, 2026-11-12, client question 1; ADR 0002 stands).

Example: daily wage 330 at a site with no allowance, fare 35, 60 OT minutes → 330 + 0 + 35 + (60 × 330/480 × 1.5 = 61.875) = **426.875** for that day (shown 426.88).

## 5. Social insurance and income tax
- Employee insurance share, company insurance share and income tax are typed by HR per employee per period. Blank = 0. Nothing carries over from the previous period (owner, 2026-11-09, client question 11).
- The employee share and tax are deducted from net. The company share is a company cost (section 9).
- Day laborers have no insurance fields.

## 6. Advances (السلف)
- For every advance, including one logged on a timesheet day, HR chooses: deduct in full this period, or split into installments.
- Installment = advance amount ÷ number of installments
- Deduction this period = the installments due in this period
- Balance = Σ advances − Σ repayments

## 7. Leave balance (الإجازات)
Spec: `docs/superpowers/specs/2026-11-03-leave-design.md`. Attendance is the ledger: a day counts as leave when its stored attendance day carries the leave day-type, whether HR typed it or an approved leave request wrote it.

- Annual entitlement for year Y, for monthly staff (day laborers have none):
  - service on Jan 1 of Y ≥ 1 year → **21**
  - hired before Jan 1 of Y with less than 1 year → **10**
  - hired during Y → floor(**10** × M ÷ 12), M = full calendar months from hire to Dec 31 (the hire month counts when the hire day is the 1st). Hired July 1 → 5; hired in December after the 1st → 0. ⚠️ (L3, client question 10)
  - no hire date → no entitlement; the screen asks HR to record the hire date
  - HR may override the year's entitlement (with a reason)
- Casual cap = min(**6**, entitlement)
- Used = annual days + casual days stored in Y + opening used (typed by HR for days taken before the system; blank = 0)
- Casual used = casual days stored in Y + opening casual used
- Balance = entitlement − used (may go negative); casual balance = casual cap − casual used
- Sick days are counted apart and do not reduce the balance (L4). Unpaid days are counted apart too.
- The year starts Jan 1. Nothing carries over.
- Read date for year Y: D = the earliest of today, Dec 31 of Y and the termination date, moved up to the hire date when that is later (an employee HR created ahead of their start date). The type on D decides balance or none (day laborer → none); a mid-year monthly ↔ daily change is not pro-rated.
- The balances list for Y holds everyone employed at some point in Y (hired by Dec 31 or with no hire date, not terminated before Jan 1) whose type on D is monthly. A manager sees those in their reach today. Archived employees (termination date before today) are left out for every year (owner, 2026-11-11); their balance stays on their own page.
- Someone who passes 1 year of service mid-year: HR gets an alert on the anniversary and decides whether to change the year's entitlement.
  The alert fires on date D for an employee of a monthly type employed on D whose hire date plus one year is D (a Feb 29 hire: Feb 28), unless the hire date is Jan 1 (they already have 1 year on Jan 1). Each holder of `leave.decide.all` gets it once, dated D (`leave:alert-anniversary`, daily 08:00 Cairo). A run on day R covers every D from R − 6 to R, so the next run makes up a missed or failed one.

**Leave request days**
- A request's days = its dates, minus days not employed as a monthly type, the type's weekly off and official holidays (§2a). Those are not counted and not written.
- On approval the days are counted again (a holiday added since the request drops out). The earliest dates keep the kind; annual or casual days beyond the balance (for casual, beyond the smaller of the two balances) are written as unpaid leave (بدون أجر). ⚠️ (L2, client question 9)
- The split (and the balance the request preview and the decide dialog show) reads the balance without the request's own dates: a leave day already stored on one of them is replaced on approval, so it is not counted twice. A day HR stored there with the same leave type (the same code) keeps HR's source.
- A date that already holds another leave type than the one approval would write there (after the split, so HR's annual day on a date that becomes unpaid counts) is listed in the preview with its type. Approval refuses those dates unless HR ticks «استبدال هذه الأيام» for exactly the dates listed; replacing keeps the day's note and advance (decided 2026-11-04). A day stored by a lock (source `derived`) is replaced without asking.
- Cancelling an approved request clears the days it wrote that still hold its leave type (or unpaid) with source `leave`, so the balance goes back up by those days. A day changed since (moved to a site, or HR's own day of that type) stays and keeps counting. A day to clear that holds a note or an advance refuses the cancel and is listed; HR changes the day's type first, so no advance is lost (kept by the owner on 2026-11-04). A date in a locked period refuses the whole cancel.
- An employee with a pending or escalated request cannot be deleted; HR cancels or decides the requests first (decided 2026-11-04). Decided requests do not block the delete.

**Leave pay (Release 2 payroll)**
- Annual and casual leave are paid in full for monthly staff, like the weekly off.
- Sick leave is paid at **100%** of the day rate (`sick_leave_pay_percent`); below 100, each sick day deducts (100 − %) × day rate. ⚠️ (L1, client question 8; the Excel pays it in full)
- Unpaid leave deducts one day rate per day, like absence (غ), but is reported as unpaid leave.
- Rest (راحة) and other (أخرى) leave days are paid for monthly staff, since no rule names them, and flagged `leave_to_check:<code>` for HR.

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
  - For monthly staff each not-employed day deducts one day rate of the salary on the nearest employed day (the one before it, or the period's first employed day when none is before it), so it takes back what the period pays. The deduction never exceeds the salary. ⚠️ (client question 23: the Excel typed a leaver's absence days by hand, column Y, and the two can differ.)
  - The insured basic is the one on the pay row in force on the last day of the period that the employee holds the type. Incentives = the period's salary (before the not-employed deduction) − the insured basic.
- A salary change splits the period at its effective date; each part uses its own day rate.
- A type change starts on the first day of a period of the new type. ⚠️ (R32) When that day does not start a period of the old type (engineers or permanent workers ↔ office or day laborers; office → day laborer on the 16th), the old type's last period ends the day before, and its remaining days are not employed (first bullet). This is the default until the owner answers.
- End-of-service bonus suggestion = **0.5** month × years of service × last total wage (basic + incentives). HR edits it. ⚠️ Are fractions of a year counted? Which wage is the base? (The client's form drops the extra months.)
- Clearance is allowed only when the employee has no open custody items.

## 11. Reminders
- Reminder date = event date − **lead days** for that date type. Defaults: contract end 30, probation end 14, ID/licence/permit expiry 30.
- Probation end = hire date + **90** days

## 12. Permissions (requests)
- Late permission: max duration and max count per month are **settings** (blank = no limit). ⚠️ values from the client.
- Early leave: a one-day permission, or a permanent shift change that updates the employee's shift on approval.

## 13. Attendance day sheet
What the day sheet (كشف اليوم) shows for one group and date, then how it saves. The shown values are display only: none of them feeds payroll.

- Last site (آخر موقع) = the site of the employee's latest stored day from date − 7 to date − 1 whose site is an active work site. Derived days and leave day-types are skipped. Office staff have no last site. The 7-day window is a fixed constant, not a setting.
- Period totals per employee, over the period's dates up to today (later dates are not counted):
  - worked = stored days with no leave day-type (a site, or office present)
  - leave = stored or derived days with a leave day-type other than absent (غ)
  - absent = stored days marked absent (غ)
  - missing = days with no stored and no derived record (a derived day, such as a weekly off or an official holiday, is never missing)
- Missing per date = number of employees whose day on that date is missing. Future dates count 0.
- Office on time / late: a present office day is on time when the section 1 lateness hint is 0 and late when it is above 0. A present day with no in time counts as on time.
- Present on a field row at a work site, through the bulk write (the sheet, the attendance import, the manager's day), stores
  arrival = field shift start and departure = field shift end when the day is new or changes kind to a work day, for each time
  the row does not send (a sent empty time stays empty). Re-marking a present day, or moving it to another work site, fills
  nothing. A default that would end before it starts (a shift crossing midnight) is not filled for engineers and day laborers;
  a permanent worker's day may cross midnight. Office rows and leave days get none. Single-day entry stores what it sends.
- Late and overtime minutes (engineers and day laborers at a work site; owner, 2026-11-12): late = max(0, arrival − shift
  start), with no grace, empty without an arrival; overtime = max(0, departure − shift end), empty without both times, and 0
  when the site manager writes it (HR approves it, §2b). The shift is the employee's own, else the field shift, as for the hints.
  - Single-day entry stores the number it is sent; an empty value is always filled from the times, changed or not (the editor's
    empty box says «تُحسب من الوقت عند الحفظ»), so saving an old day with an empty box fills it.
  - A bulk write (the sheet, the import, the site manager's day) stores a value the row sends, even empty (the sheet's Undo). A
    value it does not send is filled when the day is new or becomes a work day; after that the late minutes follow a change of
    the arrival, and the overtime a change of the departure or an arrival going between empty and set. Otherwise P28 keeps the
    stored minutes. Present on a new day therefore stores 0 and 0.
  - A filled value is checked like a typed one: above the 960-minute bound the write is refused (`out_of_bounds` on that field).
  - Days stored before 2026-11-12 keep their minutes until a time is edited or the day is saved in the editor.
- A date is off (tinted on the strip and the grid) when it is the group's weekly off or an official holiday (§2a), whatever
  the days stored on it.

The sheet saves through bulk marking, under the same day rules as single-day entry:
- All or nothing: if any row is stale (changed since loading) or breaks a rule, nothing is saved and every stale row is reported.
- At most 500 rows per save. This is a technical limit in code, not a setting.
- Keep rule (P28) for values a row does not send: a day of the same kind (work, leave, office present) keeps the values its new site allows; a change of kind keeps only the note and the advance. It applies to bulk writes only; single-day entry stores an unsent value as empty.
- A row that changes nothing writes nothing: no new version and no audit entry. This also applies to single-day entry.

## 14. Excel import
How an uploaded attendance template (the grid: one row per employee, one column per period day, each cell a site or a leave day-type) becomes stored days. HR first sees a preview that saves nothing, then confirms by uploading the same file again. The import computes no amount.

- One write path (G1): each changed cell is saved as the day sheet saves a row that sets that site, leave day-type or office present, with source `imported`. The section 13 keep rule applies: a site → site change keeps the day's other values; a change of kind keeps only the note and the advance.
- A blank cell is no change (G2). It never clears a stored day; clearing stays in the app. Blank cells are not counted.
- An unchanged cell writes nothing (G3). A cell is unchanged when it still holds what the template filled in for a stored day or a derived weekly off or holiday (its site's Arabic name, even if that site was deleted since or a new site took the name; the present word in either language for office present), when it names the stored day's site, or when it names the leave day-type of a derived weekly off or holiday.
- Stale protection, per cell (G4): the hidden sheet `versions` holds each stored day's version (blank = no stored day), matched to the grid by employee code. A changed cell whose version is not the stored day's current version is a conflict and is not saved, so a day edited after the download is never overwritten. Uploading the same file twice saves nothing the second time: its cells are now unchanged.
- Cells the employee cannot take (G5) are blank and grey in the template: days not employed as the group's type, and future days. A value there is refused.
- Office cells (G6) take the present word («حاضر» or Present) or a leave day-type; office has no sites. Present saves what the day sheet's office Present saves (no times, so on time). Other groups take a work site or a leave day-type. A cell matches a site's Arabic name or its English name, with Arabic-Indic and Eastern digits read as 0–9, extra spaces collapsed and case ignored. An inactive site is refused. A deleted site, or a name that two sites share, is unknown.
- The header row (G7) holds the code label, the name label and then the period's dates, in order, and must match the requested group's period exactly; the `versions` sheet must have the same header. Anything else refuses the whole file. A header date that Excel turned into a date or a date number reads the same as the text.
- Every change and every error names its cell (G8): the Excel row number and column letter. A row error (unknown or repeated employee code) has the row number and no column.
- Limits (G9): 5 MB, `.xlsx` only, at most 2000 employee rows (a technical limit in code, not a setting). The file is read once per request and never stored.
- Order of the cell checks: an unknown or out-of-scope employee code, or a code already used on an earlier row, refuses the row; then a future date, then a day not employed as the group's type; then an unchanged cell is set aside; then, on every changed cell, a locked period, an unknown site or day type, a stale version, and the day rules of single-day entry. A refused cell is skipped and listed; the other cells are still saved.
- The import takes reach per cell: an own-scope user imports only the cells whose employee they reach on that cell's date.
- Archived employees (termination date before today) are not rows of the template (owner, 2026-11-11); someone whose termination date is today or later is still a row, grey after it. A row typed in with an archived employee's code is still matched, and its cells up to the termination date are accepted (later ones are `employee_not_active`).

### Site workbook (ملف الموقع) — decided 2026-11-12
How HR's per-site file becomes stored days: one site, one sheet per date, and per row a person, a status, times, an advance and a note. The owner's rules of 2026-11-12; design and API in `docs/superpowers/specs/2026-11-12-site-workbook-design.md`. The per-type template above does not change. The import computes no amount; its one sum is the total of the advances shown in the preview, added by the backend.

- W1. The file: HR downloads it for one active work site and a range of at most 31 days (the current half-month by default; future dates are allowed). It has one sheet per date, every row empty, in Arabic whatever the request language. A hidden sheet `lists` holds the statuses, the name list, the residences, and the site and range the file was made for. Only a user holding both attendance permissions at all scope (HR) downloads or uploads it; a site manager is refused.
- W2. Reading: the site and the range come from `lists`, never from the upload form. A missing or unreadable `lists`, an unknown or deleted site, or a bad range refuses the whole file. A visible sheet's date is the date of the range whose day and month (`dd-mm`) end the sheet name. A sheet that matches no date, two sheets for one date, or a header row that is not the ten headers refuses the whole file. A deleted day sheet is simply not imported. Cells are read as G6 reads them (extra spaces collapsed, Arabic-Indic and Eastern digits read as 0–9).
- W3. Rows: a row with every cell blank is ignored. A row with a name and no status is `status_required`; a row with other cells and no name is `name_required`.
- W4. The person is chosen by name. The name list is rebuilt for the file's site and range exactly as the download made it, and a name cell that is a whole entry (`name (type) · Employee ID`) is that employee. A text ending in ` · ` and digits that is no entry is `unknown_employee`: a wrong ID, an office employee of another site, an archived employee, or someone renamed since the download. Any other text is a new day laborer (W5). The same employee twice on one sheet: the second row is `duplicate_row`. On a row whose name is an entry, the national ID, mobile, residence and proposed wage columns are ignored, with no error: the person exists, and those columns describe a new day laborer only.
- W5. A new day laborer needs a 14-digit national ID (`national_id_required`). The ID of someone in the name list is that employee, flagged in the preview, never a duplicate. The ID of anyone else (an office employee not of this site, an archived or deleted employee) is `national_id_taken`. Otherwise the import creates one proposed laborer per national ID; a later row with that ID under another name is `national_id_name_mismatch`.
  - Hire date: the earliest date they have in the file. No home site; they are on this site's crew for the hire date, as any proposed laborer.
  - Optional: a mobile (the Egyptian mobile rule, else `bad_mobile`), a residence (a residence's name, else `unknown_residence`) and a proposed wage (a positive amount, else `bad_amount`). A blank wage creates no proposal: HR sets the wage on the employee's page. Rows of one new laborer that disagree on these are not refused: for each, the first non-blank value in date order wins (the name must still match, `national_id_name_mismatch`).
  - Gender: male, shown in the preview; HR changes it on the employee's page. ⚠️ A default until the owner confirms it (the file has no gender column).
  - Only a user who may create employees (`employee.create` at all scope) creates one; otherwise the row is `new_laborer_not_allowed`.
  - Pay before the wage is approved is as for any proposed laborer: a worked day with no pay row pays wage 0 and is flagged `no_wage` (§0), the fare and a fixed site allowance still pay (§4), and approving the wage writes it from the hire date.
- W6. Status: «حاضر» (the present word, G6) records the day at this site; for an office employee it is office present, with no site. Any other status is the active leave day-type of that name, matched as G6 matches; a work site's name or anything else is `unknown_status`. HR's file may write every active leave day-type, annual, casual and unpaid included, as the per-type template can.
- W7. Writing: each row is saved as the day sheet saves a row (G1), with source `imported`, as HR (no site manager reach or window applies). A blank cell in a filled row is no change for that field (the §13 keep rule): on a new present field day blank times take the field shift; on a stored day they keep what is stored. A blank never clears. A note is at most 500 characters, the day's limit; a longer one is `note_too_long`.
- W8. Times: an arrival or departure is `H:MM` or `HH:MM`, or a time Excel made of one typed in; anything else is `bad_time`. One without the other is `times_need_both`, for every type, so an imported day is never open. Times with a leave status are refused by the day rules (`field_not_allowed`).
- W9. Advance: a positive amount stored as the day's advance (else `bad_amount`), deducted as any advance logged on a day (§6).
- W10. Office rows take a status and times only: an advance or a note is refused by the day rules (`field_not_allowed`).
- W11. Late and overtime minutes come from the times (§13) for engineers and day laborers at a work site. For such a row with both times, the import also sends the overtime those times give, so HR's confirm approves it (§2b) even when the times repeat a site manager's day whose overtime is stored 0. A row with blank times approves nothing. Permanent workers and office staff store no late or overtime minutes (§1, §3).
- W12. The write path's refusals keep their codes: a locked period of the person's type (`period_locked`; the range is not tied to a pay period, so one date can be locked for engineers and open for day laborers), a date after today at upload (`future_date`), a day not employed (`employee_not_active`), a site made inactive since the download (`site_inactive`), `out_before_in`, `out_of_bounds`, and a concurrent change (`concurrency_conflict`). A refused row is skipped and listed; the other rows are still saved.
- W13. A day already recorded at another work site is a conflict, whatever the file's status: HR chooses per row to keep it (nothing is written) or replace it with the file's row (the same day, under the W7 keep rule). At confirm a conflict with no choice is not saved (`choice_required`). A stored day at this site, on a leave day-type or office present is an ordinary change. One attendance day per person per date stays the rule.
- W14. The day's site: a person from another site is recorded at this site for that date only. Their home site (worker) or manager assignment (engineer) never changes and no crew change is written (ADR 0004, amended 2026-11-12). Wage, site allowance, fare and day cost follow the day's site, as payroll already reads them (§4, §8).
- W15. Preview and confirm: the preview runs the confirm's writes and rolls them all back, so the change, late and overtime minutes it shows are what the write path stores; a conflict is shown as replaced. It returns a token over the stored days it read. The confirm checks the token first; a mismatch saves nothing (`preview_stale`) and HR previews again. A row that changes nothing writes nothing, so uploading the same file again after a confirm saves nothing.
- W16. Limits: 5 MB, `.xlsx` only, at most 2000 data rows per sheet (a technical limit in code, not a setting). The file is read once per request and never stored.
- W17. Order of the checks. The whole file: the upload rules, a readable file, the site and range in `lists`, then the sheet dates and header rows; any failure refuses the file. At confirm, the token. Then each row: a blank row is skipped; `name_required`, `status_required`; the person (`unknown_employee`, `national_id_required`, `national_id_taken`, `national_id_name_mismatch`, `new_laborer_not_allowed`); `duplicate_row`; the cells (`unknown_status`, `bad_time`, `times_need_both`, `bad_amount`, `note_too_long`, `bad_mobile`, `unknown_residence`); a conflict (`choice_required` at confirm, and keep writes nothing); then the write path (`future_date`, `employee_not_active`, `period_locked`, `concurrency_conflict`, then the day rules).

## Not calculated by the system
- Bonuses, negligence deductions, penalties, lateness exemptions, insurance shares and income tax: entered by HR.
- Bank transfers: HR only marks "paid" and writes how the money was received.
