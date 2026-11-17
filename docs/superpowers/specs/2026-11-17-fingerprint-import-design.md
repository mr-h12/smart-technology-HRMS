# Fingerprint import (design and contract)

Date: 2026-11-17. Release 2, item 1 of `docs/hrms-module-plan.md`. The plan that builds it is
`docs/superpowers/plans/2026-11-17-fingerprint-import.md` (PRs FP-1 to FP-6). This document and the types it names merge first, as
FP-0. Line numbers are as of `main` at `b6c8162`. Paths are under `backend/app/Modules/` unless they start with `backend/`,
`frontend/` or `docs/`.

## ملخص للمالك
- الـHR ترفع ملف جهاز البصمة (Excel) زي ما بيطلع، والنظام يحوّل البصمات لحضور وانصراف لموظفي المكتب.
- المطابقة بـ«اسم البصمة» اللي في ملف الموظف، من غير فرق بين الحروف الكبيرة والصغيرة والمسافات. الاسم اللي مالوش موظف يظهر مرة واحدة في قائمة، وصفوفه تتساب، والاستيراد يكمل.
- قبل الحفظ فيه معاينة: كل يوم بحالته (جديد، تحديث، بدون تغيير، تعارض، إجازة، فترة مقفولة، مش موظف مكتب)، والتأخير، وتنبيه «بصمة ناقصة» و«انصراف مبكر».
- اليوم اللي اتكتب قبل كده من البصمة يتحدّث لوحده. اليوم اللي كتبته الـHR أو مدير الموقع يفضل زي ما هو ويظهر «تعارض»، ويوم الإجازة يفضل ويظهر «إجازة»، وكل واحد بخانة اختيار؛ الحفظ يستبدل اللي اتعلّم عليه بس.
- يوم فيه بصمة واحدة: تتحسب حضور لو أقرب لبداية الوردية، وانصراف لو أقرب لنهايتها، ويتعلّم «بصمة ناقصة». أكتر من بصمة: أول بصمة حضور وآخر بصمة انصراف. بصمتين بينهم أقل من دقيقتين يتحسبوا بصمة واحدة.
- استبدال يوم إجازة معتمدة: رصيد الإجازة يرجع لوحده (الرصيد بيتحسب من أيام الحضور)، وطلب الإجازة ونموذجه المطبوع يفضلوا من غير تغيير، بعدد الأيام والحالة الأصلية (قرارك).
- مش داخل في الشغل ده: اقتراح الإضافي، طلب إذن التأخير، والربط المباشر بالجهاز.

## Context
Office attendance comes from the fingerprint device and HR, never from site managers (`CLAUDE.md`, Users and permissions). Today
HR types office days by hand. Nothing reads the device's file yet. The client's raw export arrived on 2026-11-17 and answers F1 in
`docs/client-questions.md`.

What already exists and is reused, not rebuilt:
- `employees.fingerprint_name` (nullable, unique on the exact text, `backend/database/migrations/2026_10_16_000001_create_employees_tables.php:16`) is the only join key.
- An office day stores `in_time` and `out_time` on `attendance_days` and nothing else (`Attendance/Domain/DayRules.php:15`).
- Every day is written through `AttendanceDays::writeCell()` (`Attendance/Application/AttendanceDays.php:208`): future date, employment, period lock and row lock (`guard()`, line 471), the version check, `DayRules`, the audit row (the model's).
- Office late minutes are `DayRules::officeLateMinutes(in, shift start, office_grace_minutes)` (`DayRules.php:68`), the shift being `Employment::shiftOn()` or the settings `office_shift_start` and `office_shift_end` (`EffectiveDays.php:173-175`).
- The grid import's pattern: preview is a dry run of every write, confirm re-reads the same file and writes in one transaction (`Attendance/Application/AttendanceImport.php`), the upload rules (`Presentation/AttendanceController.php:32`).

## The file
The client's export for October 2026 (`سحب بصمه شهر 10.xlsx`, kept out of git because it names real people) was read cell by cell.

- Six sheets. Each sheet's row 1 is `Date | ID Number | Name | IN | OUT | IN | OUT | IN | OUT | More`. Three sheets keep Excel's default names. No banner, merged cells, formulas or totals.
- One row per person per day. A is an Excel date serial (number format 14). B is the device user ID, a number. C is the fingerprint name, one Latin token in mixed case (for example `NadimFares`, `Ziadhalabi`). D to I are day fractions with seconds (number format 21). D is always filled, H and I never. J is the text `More` with a hyperlink to a private LAN address. J is missing on 3 of 548 rows. The import ignores column J and never opens the link.
- IN and OUT are positions, not punch types. A lone 17:00 punch sits under IN. There is no marker of the punch type.
- The sheets overlap on their boundary dates. 102 person-days appear twice. Usually the earlier row is a prefix of the later one, but in 2 cases each row holds a punch the other lacks. The import merges a person-day's rows as the union of their punches, de-duplicated on (device user ID, date, time).
- Double taps a few seconds apart are common. Of the gaps between consecutive punches of one person-day, 26 are under 1 minute, 1 is 64 seconds, and the next shortest is 23 minutes. Two person-days hold only a double tap (`08:53:25, 08:53:26` and `08:50:22, 08:50:24`).
- Absent days and weekends have no row. The file runs 2026-09-27 to 2026-10-25: 548 rows, 959 punch cells (857 after de-duplication), 446 person-days, 28 people. One device user ID always carries the same name.

## Decisions settled with the owner (2026-11-17)
1. **Match by fingerprint name.** The file's name (column C) matches `employees.fingerprint_name`, ignoring letter case and the six ASCII whitespace characters (space, tab, line feed, carriage return, form feed, vertical tab), inner ones included: `Sara Nabil`, ` saranabil` and `SARANABIL` are one name. Every other character, a no-break space (U+00A0) included, is part of the name. The normalised form is those six characters removed, then lower-cased ("The FP-2 seam" gives the PHP and SQL). The device user ID is not stored on the employee; it is shown only.
2. **Existing days.** A day the import wrote before (source `fingerprint`) is refreshed from the new file without asking. A day HR or a site manager entered (any other source except `derived`) is kept and listed as `conflict` (`leave` when it sits on a leave day-type, `unchanged` when its times already equal the file's). HR ticks the days the file should replace; confirm replaces only those.
3. **One-punch day.** It is imported. The punch is `in_time` when it is closer to the employee's shift start than to the shift end, otherwise `out_time`; the other time stays empty and the day is flagged `missing_punch`. With 2 or more punches, `in_time` is the earliest and `out_time` the latest; the rest are ignored. (A double tap counts as one punch; "The FP-3 seam" has the exact rules.)
4. **Leave days are replaceable.** A leave day the file has punches for is listed with status `leave` and a tick box, exactly like a typed day. Confirm replaces only the ticked ones; unticked leave days are kept. What a replacement does to the leave request and the balance is in "Replacing a leave day" below.
5. **A replaced leave day leaves its request as it was.** The leave request keeps its original `days` count and its status, its leave-request PDF keeps printing that count, and its unpaid split stays as it was; only the balance changes (it follows attendance). No operation to release one day of an approved request is built in the Leave module. Attendance is the ledger; the request records what was approved.

## Planner's calls (2026-11-17)
- **A new source `fingerprint`** on `attendance_days`, so the import recognises its own earlier writes (decision 2). The check constraint gains the value in FP-4's migration, as `2026_11_04_200000_add_manager_attendance_rules.php` added `manager`.
- **Flags are derived, never stored.** `missing_punch` is exactly one of `in_time` and `out_time` present. `early_leave` is an `out_time` before the shift end. They exist in the preview only. Late minutes come from `DayRules::officeLateMinutes()`.
- **Unmatched names do not block.** Each unmatched name is listed once, with its device user IDs and day count, and its punches are skipped.
- **Not office on that date.** A matched employee who is not employed as office staff on the date (another type, before hire, after termination) is skipped with status `not_office`.
- **Locked period.** A day in a locked office period is skipped with status `locked`.
- **Period-agnostic.** The import writes whatever dates the file holds, across periods.
- **Out of scope.** An overtime suggestion into `payroll_entries.ot_minutes`, the late-permission request type, any live device connection.
- **A refresh never clears a stored time.** On a `refresh` day the time written is the file's `in_time` when the file has one, else the stored `in_time`; `out_time` likewise. A partial file uploaded after a full one must not erase a punch. Example: the stored `fingerprint` day is 08:55 to 17:02; a later file holds only `17:05:00` for that day, which reduces to out 17:05 with no in. The refresh writes 08:55 to 17:05. The preview row shows that merged day: its `in_time`, `out_time`, `flags` and `late_minutes` are the merged day's, its `punch_count` the file's.
- **Double tap at a shift edge.** When all of a day's punches fall within `DOUBLE_TAP_SECONDS` (a one-punch day), the punch is classified as check-in or check-out from the truncated `H:i` of the earliest punch. The time used is then the earliest punch for a check-in and the latest punch for a check-out. A departure tap at `16:59:58, 17:00:01` with shift end 17:00 is classified from 16:59 as a check-out and gives out 17:00, with no `early_leave`.
- **Distances use the truncated minute.** The one-punch rule compares distances in whole minutes from the `H:i` value, never from the time with seconds. With shift 09:00 to 17:01, the punch `13:00:45` is 240.75 minutes after the start and 240.25 before the end with seconds, but its `13:00` is 240 and 241, so it is `in_time` 13:00.
- **The fingerprint actions live in the existing controller.** `fingerprintPreview` and `fingerprintConfirm` are added to `Attendance/Presentation/AttendanceController.php`, reusing its private `FILE` rule (line 32) and `assertAllScope()` (lines 208-213). There is no new controller.
- **`Punch` carries no file position.** It has no `sheet` or `row`: no planned output uses them, and a row error's position comes from the reader's own `errors` list.

## FP-0's calls (the owner may overrule any of them)
- **Double tap.** A person-day whose latest punch is less than 120 seconds after its earliest is a one-punch day. Without this, the two double-tap-only days in the October file would be stored with `in_time` equal to `out_time` and no flag. The 120 seconds is a technical constant (`OfficePunches::DOUBLE_TAP_SECONDS`), not a setting, chosen from the gaps above. Which of its punches is used is the planner's call "Double tap at a shift edge" above.
- **Seconds are truncated.** `08:59:59` becomes `08:59`. A device shows minutes the same way; truncation never adds a late minute.
- **A tie goes to `out_time`.** A single punch exactly between shift start and end (13:00 on 09:00 to 17:00) is a departure.
- **A derived day is no entry.** A day the system filled (weekly off or official holiday, stored or not, source `derived`) is overwritten as `new`, as the leave workflow already treats it (`AttendanceDays.php`, `leaveCodesOn()` doc).
- **`late_minutes` in the preview is chargeable,** after the grace, the same number the period grid's hint shows (`EffectiveDays.php:175`). It is null without an `in_time`, and for `not_office` rows. Flags are also empty for `not_office` rows, which have no office shift.
- **A file row with an error gives no punches** and one error, so a half-read row never builds a wrong day.
- **Each tick carries the version** of the day HR saw. A day changed since the preview is refused with `concurrency_conflict` and kept. This is the rule every other attendance write follows (`docs/coding-standards.md` §7).
- **Uniqueness ignores deleted employees, not archived ones.** Review item 13-E7 is about a soft-deleted employee blocking reuse (`docs/reviews/2026-11-08-release-1-review.md:118`). An archived employee still owns their fingerprint name, so a file that holds their last days before termination still matches them, as the grid import includes archived employees (`AttendanceImport.php:92`). The national-ID half of 13-E7 stays open: `EmployeeRoster::idWithNationalId()` (`EmployeeRoster.php:184`) relies on that column being unique over every row.

## Replacing a leave day
How a leave day reaches `attendance_days`. Approving a leave request writes one row per counted date with source `leave` on the leave type's site (`Leave/Application/LeaveRequests.php:160-163`, `AttendanceDays::writeLeaveDay()`). HR can also type a leave day-type on the grid or day sheet (source `entered`); the system stores derived Fridays and holidays at lock (source `derived`).

What `writeCell()` does on a leave day today, verified by reading `AttendanceDays::write()`:
- It does not refuse. The leave checks run only for the leave workflow (`$leaveDay`, line 330) or a site manager (`$ownerId`, line 347). With HR's call (`$ownerId` null) the leave row is overwritten.
- The row keeps its id, takes `site_id` null, the new times and the source passed in (line 421; line 418 keeps `leave` only when the site or leave code is unchanged). The version goes up by one and the model writes the audit row with before and after.
- A change of kind keeps only `note` and `advance_amount` (line 452), and only where the office type allows them, which it does not (`DayRules::FIELDS['office']`, line 15). An office leave day never holds either, so nothing is lost.

What follows for the Leave module, with no Leave code changed:
- **The balance is right at once.** `LeaveBalances` counts the stored attendance days on counted leave codes (`LeaveBalances.php:21`, `counts()` at line 158). The replaced day is no longer one, so the balance gives the day back.
- **Cancelling the request later still works.** `clearLeaveDays()` clears only rows with source `leave` on the request's leave types (`AttendanceDays.php:256`). The replaced day is source `fingerprint` with no site, so a cancel leaves it and clears the request's other days.
- **The request record does not change.** The request stays `approved` with the `days` it was approved with (`LeaveRequests.php:165`), and its leave-request PDF prints that count (`Pdf/Application/Types/LeaveRequestPdf.php:51`). Nothing computes from `days`; it is display only.
- **The unpaid split is not recomputed.** If the request wrote some dates as unpaid because the balance ran out, replacing one of its annual dates frees a day of balance but leaves those unpaid dates unpaid.

The Leave module exposes no operation to shorten an approved request or release one of its days (its public methods are `preview`, `submit`, `list`, `show`, `approve`, `refuse`, `escalate`, `cancel`, `assertNoOpenRequests`, `openRequests`). Attendance must not write `leave_requests` itself (`docs/coding-standards.md` §3.2), and Leave already depends on Attendance, so a call from the import into Leave would also make the two modules depend on each other.

FP-4 therefore replaces a ticked leave day through `writeCell()` exactly as it replaces a typed day, and proves the balance and the audit row in its tests. The stale request count and PDF are accepted by the owner (decision 5).

A related effect, not a leave-day replacement: a **pending** request whose dates the import fills with office present cannot be approved afterwards, because approval refuses dates that hold a work day or office present (`LeaveRequests.php:151`, `leaveBlockedDates()`). HR sees the refusal and edits the days first. This is how every other office-present write already behaves.

## Contract

### Domain types (merged in FP-0)
All under `backend/app/Modules/Attendance/Domain/`. Domain imports nothing from Laravel (deptrac).

| Type | File | Shape |
|---|---|---|
| `Punch` | `Punch.php` | readonly `{int deviceUserId, string deviceName, string date (Y-m-d), string time (H:i:s)}` |
| `PunchFileReaderInterface` | `Contracts/PunchFileReaderInterface.php` | `read(string $path, int $maxRows, string $today): array{punches: list<Punch>, errors: list<array{sheet: string, row: int, code: PunchRowError}>}`; a row dated after `$today` (Y-m-d) gives a `future_date` error and no punches |
| `PunchRowError` | `PunchRowError.php` | enum `bad_date`, `bad_device_user_id`, `missing_name`, `bad_time`, `future_date`, all raised by the reader. `future_date` is the reader's too, because a `Punch` has no sheet or row and only the reader can give the error a position; the import passes `EffectiveDays::today()` as `$today` |
| `PunchedDay` | `PunchedDay.php` | readonly `{?string inTime (H:i), ?string outTime (H:i), list<PunchFlag> flags, int punchCount}` |
| `PunchFlag` | `PunchFlag.php` | enum `missing_punch`, `early_leave` |
| `FingerprintDayStatus` | `FingerprintDayStatus.php` | enum `new`, `refresh`, `unchanged`, `conflict`, `leave`, `locked`, `not_office` |

The interface sits in `Contracts/` as `Pdf/Domain/Contracts/PdfRendererInterface.php` does, and is required by `docs/coding-standards.md` §12 (fingerprint-file parsing behind an adapter). FP-4 binds it to FP-1's class in `app/Providers/AppServiceProvider.php`.

### The FP-3 seam (built in FP-3)
`Attendance/Domain/OfficePunches.php`:

```php
final class OfficePunches
{
    public const DOUBLE_TAP_SECONDS = 120;

    /** @param non-empty-list<string> $times H:i:s, distinct, any order; $shiftStart and $shiftEnd H:i */
    public static function reduce(array $times, string $shiftStart, string $shiftEnd): PunchedDay;
}
```

Rules, applied in order. `punchCount` is `count($times)`. Times sort ascending. Every time in the result is truncated to `H:i` (seconds cut, never rounded), and every comparison below uses the truncated `H:i`.
1. One punch, or the latest less than `DOUBLE_TAP_SECONDS` after the earliest (both measured with seconds): a one-punch day. Classify it from the earliest punch's `H:i`: check-in when its distance in whole minutes to the shift start is smaller than to the shift end, else check-out (a tie is check-out). A check-in is `inTime` = the earliest punch; a check-out is `outTime` = the latest punch. Flag `missing_punch`.
2. Otherwise `inTime` is the earliest and `outTime` the latest.
3. Flag `early_leave` when `outTime` is set and earlier than the shift end. Flags come in enum order.

| Times | Shift | in | out | flags | count |
|---|---|---|---|---|---|
| `08:53:25` | 09:00-17:00 | 08:53 | null | missing_punch | 1 |
| `17:05:00` | 09:00-17:00 | null | 17:05 | missing_punch | 1 |
| `16:30:10` | 09:00-17:00 | null | 16:30 | missing_punch, early_leave | 1 |
| `13:00:00` (tie) | 09:00-17:00 | null | 13:00 | missing_punch, early_leave | 1 |
| `13:00:45` (240 and 241 minutes from its `13:00`) | 09:00-17:01 | 13:00 | null | missing_punch | 1 |
| `08:53:25, 08:53:26` (double tap, check-in) | 09:00-17:00 | 08:53 | null | missing_punch | 2 |
| `16:59:58, 17:00:01` (double tap, check-out) | 09:00-17:00 | null | 17:00 | missing_punch | 2 |
| `08:51:58, 08:51:59, 17:27:01, 17:27:02` | 09:00-17:00 | 08:51 | 17:27 | none | 4 |
| `08:52:04, 15:59:04, 16:00:08` | 09:00-17:00 | 08:52 | 16:00 | early_leave | 3 |
| `17:30:00, 08:59:59` | 09:00-17:00 | 08:59 | 17:30 | none | 2 |

`DayRules` needs no change. It accepts an office day with only `in_time` or only `out_time`: `times_need_both` applies to permanent workers alone (`DayRules.php:55-59`), and `out_before_in` needs both times (line 61). The office engine treats a day without `in_time` as on time (`Payroll/Domain/OfficeEngine.php:77-79`).

### The FP-2 seam (built in FP-2)
`Employees/Application/EmployeeRoster.php`:

```php
/**
 * @param  list<string>  $names  as the file spells them
 * @return array<string, array<string, mixed>>  keyed by the name as given; a name with no employee is absent
 */
public function byFingerprintNames(array $names): array;
```

- A name matches when both sides are equal after normalising: the six ASCII whitespace characters (space, tab, line feed, carriage return, form feed, vertical tab) removed, then lower-cased. In PHP that is `mb_strtolower((string) preg_replace('/[ \t\n\r\f\x0B]+/', '', $name))` (`EmployeeRules::fingerprintKey()`); in PostgreSQL `lower(regexp_replace(fingerprint_name, '[ \t\n\r\f\v]+', '', 'g'))`. Both strings are written as the code holds them, the PHP pattern in single quotes and the SQL with `standard_conforming_strings` on (PostgreSQL's default), so each backslash reaches the regex engine. The two classes are the same six bytes. PHP writes the vertical tab as `\x0B` because PCRE's `\v` also matches other vertical space, and without the `u` flag that includes the byte 0x85 inside UTF-8 letters such as Arabic `م` (D9 85); PostgreSQL's `\v` is the vertical tab alone. A no-break space (U+00A0) and every other non-ASCII space are therefore part of the name on both sides: `Sara` U+00A0 `Nabil` does not match `Sara Nabil`. Checked on the dev database on 2026-11-17: the SQL above removes a space, a tab and a newline and keeps U+00A0. The database expression is the source of truth for uniqueness; the PHP form must agree with it on every name HR can type. Archived employees match; soft-deleted ones never do. The lookup queries `Employee::with(...)` directly as `find()` does (`EmployeeRoster.php:176-181`), not through the private `employees()` builder (line 252), which leaves archived employees out unless `withArchived()`. Normalised uniqueness (below) guarantees at most one match.
- Each value is the standard roster row, `EmployeeRoster::row()` (lines 267-298): `id, code, name_ar, name_en, department, hire_date, termination_date, archived, types, shifts, pays, sites, crew`. That is what `writeCell()` takes as `$employee`, what `Employment::typeOn()` reads for the office check and `Employment::shiftOn()` for the shift, so the import needs no second query per employee.
- One query for all names, eager-loading the same relations as `find()`.
- `fingerprint_name` is unique on the normalised value among rows with `deleted_at` null, in the database (a unique expression index on `lower(regexp_replace(fingerprint_name, '[ \t\n\r\f\v]+', '', 'g'))` replacing `employees_fingerprint_name_unique`), in `EmployeeRules::identity()` (line 62) and in `MasterDataImport`'s in-file check (`DISTINCT`, lines 59 and 248-257). This closes 11-F6 and the fingerprint half of 13-E7. The migration first counts names that collide after normalising and, if any do, fails with a message naming each colliding normalised name, so no row is changed or dropped; the owner runs the same check on the servers before merging and deploying (plan, "Verdict and merge").

### API (built in FP-4)
Both routes sit in the existing `Route::prefix('attendance')` block of `backend/routes/api.php` with the grid import's middleware, `['permission:attendance.view', 'permission:attendance.edit']` (`routes/api.php:144-145`). They call two new actions, `fingerprintPreview` and `fingerprintConfirm`, in the existing `Attendance/Presentation/AttendanceController.php` (no new controller), and each action starts with the controller's `assertAllScope()` check (`AttendanceController.php:208-213`), as `siteWorkbookPreview` does. Office staff are in no site manager's reach, so only holders of both permissions at `.all` scope may import. The upload rule is the controller's private `FILE`, `['required', 'file', 'max:5120', 'mimes:xlsx', 'extensions:xlsx']` (`AttendanceController.php:32`): 5 MB, `.xlsx` only, read from PHP's temp path and never stored. No new permission.

| Method and path | Body (multipart) | 200 `data` |
|---|---|---|
| `POST /api/v1/attendance/fingerprint-import/preview` | `file` | the preview below; nothing is saved |
| `POST /api/v1/attendance/fingerprint-import/confirm` | `file` (the same file), `replace[i][employee_id]`, `replace[i][date]`, `replace[i][version]` (optional list) | the confirm result below |

The TypeScript shapes are in `frontend/src/lib/fingerprint-import.ts`. The preview:

```text
from, to                Y-m-d of the first and last punch read; null when there is none
summary                 {punches, days, unmatched, errors, new, refresh, unchanged, conflict, leave, locked, not_office}
unmatched[]             {device_name, device_user_ids[], days}, one per normalised name, ordered by name
days[]                  {employee {id, code, name_ar, name_en}, date, in_time, out_time, punch_count, late_minutes,
                         flags[], status, existing {in_time, out_time, source, leave_code, version} | null}
                        ordered by employee code, then date
errors[]                {sheet, row, code, message}, in file order
```

The `summary` counts. `punches` is every punch read after de-duplication, matched or not, so an unmatched name's punches count. `days` is `days.length`. `unmatched` is the number of unmatched device names, `unmatched.length`. `errors` is `errors.length`. `new`, `refresh`, `unchanged`, `conflict`, `leave`, `locked` and `not_office` each count the `days` rows with that status, so the seven add up to `days`.

A **stored day** is the employee's `attendance_days` row for the date that is not soft-deleted (`deleted_at` null). A soft-deleted row and a stored `derived` row count as no day. The **times to write** are the reduced day's times (`OfficePunches::reduce()`), except on a stored `fingerprint` day, where they are the refresh merge (planner's call "A refresh never clears a stored time"). Times compare as `H:i`. A person-day's status is the first row that applies:

| # | Status | When | Confirm does |
|---|---|---|---|
| 1 | `not_office` | the employee is not employed as office staff on the date | nothing |
| 2 | `locked` | the date's office period is locked | nothing |
| 3 | `new` | no stored day, or the stored day has source `derived` | writes the day |
| 4 | `unchanged` | the stored day is office present (`site_id` null) and holds exactly the times to write | nothing |
| 5 | `refresh` | the stored day has source `fingerprint` | writes the merged times |
| 6 | `leave` | the stored day is on a leave day-type (its site's kind is `leave`, a soft-deleted site included) | replaces it when ticked |
| 7 | `conflict` | the stored day has source `entered`, `imported` or `manager` | replaces it when ticked |

Every stored-day state maps to exactly one status. The sources are `entered`, `imported`, `derived`, `leave`, `manager` and `fingerprint`. With rows 1 and 2 not applying:

| Stored day | Status |
|---|---|
| none, or only a soft-deleted row | `new` (3) |
| source `derived` (a stored weekly off or holiday), any times | `new` (3) |
| source `fingerprint`, merged times equal to the stored ones | `unchanged` (4) |
| source `fingerprint`, merged times differ | `refresh` (5) |
| source `leave` (always a leave day-type, written by the leave workflow) | `leave` (6) |
| source `entered`, `imported` or `manager`, on a leave day-type | `leave` (6) |
| source `entered`, `imported` or `manager`, office present, the file's times equal to the stored ones | `unchanged` (4) |
| source `entered`, `imported` or `manager`, office present, other times | `conflict` (7) |
| source `entered`, `imported` or `manager`, on a work site (the employee's type changed) | `conflict` (7) |

The `leave` row reads the stored day's site with soft-deleted sites included, so a leave type whose site was deleted still gives `leave`. FP-4 reads it through `Site::withTrashed()`, as `AttendanceDays::write()` reads the stored day's kind (`AttendanceDays.php:328`).

A `fingerprint` day is always office present: the import writes only office present, and any other writer that changes the day stores its own source (`AttendanceDays::write()`, line 421; line 422 skips a write that changes nothing), so a day HR retypes stops being `fingerprint`. A leave day is never office present, so it is never `unchanged`.

`existing` is the stored day for `unchanged`, `refresh`, `leave` and `conflict`, and null for `new`, `locked` and `not_office`. `leave_code` is set for a leave day-type. Every write is office present (`site_id` null) with `in_time` and `out_time` both sent, source `fingerprint`, through `writeCell()` with HR's scope (`$ownerId` null). On a `new`, `conflict` or `leave` day a null time is sent as null, which clears that field; on a `refresh` day the merge means a stored time is never cleared.

**Versions.** Every write (new, refresh, ticked replace) sends the stored day's `version`, and null when there is no row or the row is soft-deleted, because `writeCell()` checks it with `assertVersion()` (`AttendanceDays.php:497-503`): a live row needs its exact version, no live row needs null. A `derived` day that becomes `new` sends that row's version. Confirm reads these versions from the database itself; HR's ticks carry a version only so that a ticked replacement is refused with `concurrency_conflict` when the tick's version differs from the stored one.

The confirm result is `{written, refreshed, replaced, unchanged, skipped, errors[]}`. `written` counts `new` days, `refreshed` the `refresh` days, `replaced` the ticked `conflict` and `leave` days. `skipped` counts every other day plus each refused write. `errors[]` is `{employee_id, date, code, message}`, one per write refused at confirm. Confirm plans again from the database, so a day that changed since the preview takes its new status. A tick on a day that is not `conflict` or `leave` at confirm does nothing. Writes run in one transaction in (employee id, date) order, as `AttendanceImport::confirm()` does, so a repeat confirm of the same file finds every day `unchanged`.

### Error codes
| Where | Code | Status |
|---|---|---|
| Whole request | `validation_failed` (the file rules, `replace.*`) | 422 |
| Whole request | `permission_denied` (missing permission, or own scope) | 403 |
| Whole request | `unreadable_file`, `too_many_rows` (existing `AttendanceRefusal`) | 422 |
| Whole request | `not_fingerprint_file`, no sheet whose row 1 is the device header (new `AttendanceRefusal` case, FP-1) | 422 |
| `errors[]` of the preview | `bad_date`, `bad_device_user_id`, `missing_name`, `bad_time`, `future_date` (`PunchRowError`) | in 200 |
| `errors[]` of confirm | `concurrency_conflict` (version), `period_locked`, `employee_not_active`, a `DayRules` code | in 200 |

`FingerprintImport::MAX_ROWS` is 5000 data rows across all sheets, a technical cap like `AttendanceImport::MAX_ROWS`. The October file has 548.

### Frontend contract
- `frontend/src/lib/fingerprint-import.ts`: the preview, replacement and confirm types (types only).
- `frontend/src/lib/fingerprint-import.fixture.ts`: a synthetic preview with every status and flag (days ordered by employee code, then date, as the API orders them), an empty preview (a file with no punch), and a confirm result. It is TypeScript, not JSON, so `npm run build` checks it against the types.
- FP-5's strings. The locale files are shared (`docs/hrms-module-plan.md`, Shared files), so FP-5 adds no key to them. Its page ships `frontend/src/pages/fingerprint-import.locales.json` as `{"ar": {...}, "en": {...}}` holding its keys under `attendance.fingerprint.*` (plus `attendance.source.fingerprint`), and its test loads them with `i18n.addResourceBundle()`. FP-6 merges them into `ar.json` and `en.json` and deletes the snippet. The parity test (`frontend/src/locales/locales.test.ts`) reads only `ar.json` and `en.json`, so it is checked at FP-6, along with its rule that Arabic copy holds no Latin letters outside the allowed keys.

### Test workbook (merged in FP-0)
`backend/tests/XlsxFiles.php` gains `fingerprintFile(?array $sheets = null): string` and `FINGERPRINT_SHEETS`. It writes the device layout with synthetic names: two sheets (`Sheet1`, `Page 2`) overlapping on 2026-09-29, one overlap a prefix and one where each row holds a punch the other lacks, a double tap, a one-punch day on a row without a `More` cell, date serials with format 14, day fractions with format 21, and `More` hyperlinks to a documentation address (`192.0.2.10`). `backend/tests/Feature/FingerprintFileTest.php` proves that layout. Read back through a reader, the file gives these punches after de-duplication (13 of 14 cells):

| Device ID, name | Date | Punches |
|---|---|---|
| 7 SaraNabil | 2026-09-27 | 08:55:10, 17:02:30 |
| 11 HadiSalem | 2026-09-27 | 08:59:01, 08:59:03, 17:30:00 |
| 7 SaraNabil | 2026-09-28 | 16:45:00 |
| 7 SaraNabil | 2026-09-29 | 09:20:00, 17:10:00 |
| 11 HadiSalem | 2026-09-29 | 08:50:00, 12:00:00, 17:05:00 |
| 12 MonaAdel | 2026-09-30 | 09:01:00, 17:00:00 |

## Out of scope
- Overtime suggestions into `payroll_entries.ot_minutes`.
- The late-permission request type and its effect on lateness.
- Any live connection to the device. Column J's link is never opened.
- A device user ID field on the employee.
- Storing flags. HR sees them in the preview only.
- Changing the leave request when one of its days is replaced (decision 5).

## Open client questions
Added to `docs/client-questions.md` as F2 to F5.
- **F2.** Office lateness period. The file and HR's own sheets run 26 September to 25 October, while `CLAUDE.md` says office is a calendar month.
- **F3.** Which file is the monthly upload. The workbook looks like six pastes from the device's web page; 2026-10-07 was incomplete for 9 people and 2026-10-25 was cut mid-day. Can HR export once after the period ends?
- **F4.** Are all 28 device users office staff, and who are device user IDs 9, 13, 14, 19 and 22?
- **F5.** Was Thursday 2026-10-08 an official holiday?

## Owner decision needed
- **FP-0's calls** above (double tap at 120 seconds, truncated seconds, tie to `out_time`, derived days as `new`, chargeable `late_minutes`). Each is a default until the owner overrules it.
