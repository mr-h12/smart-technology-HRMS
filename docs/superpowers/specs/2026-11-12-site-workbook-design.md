# Site workbook (ملف الموقع): design

Date: 2026-11-12. Builds item 10 of the client's revision 1 (Excel attendance per site). The owner approved the rules below on
2026-11-12 after a grilling round and four sample files («تمام كده الشكل الحالي مقبول»). A read of the code then settled what the
rules left open and corrected what the code does differently; each such point is listed under "Differences from the hand-over".
The rule text is `docs/calculations.md` §14, W1–W17; the decision record is `docs/hrms-module-plan.md` § Site workbook.

## Goal
HR downloads an empty Excel file for one site and a range of days and hands it to the site manager, who fills in, per day, who
worked at the site, their status, times, advance and note, and any new day laborer. HR uploads it, sees what it would change,
chooses where it conflicts with a day recorded elsewhere, and confirms. Every row goes through the one attendance write path, as
HR.

## Non-goals
- No change to the per-type template, its import or their tests (§14 G1–G9).
- No change to the day rules, any payroll formula or the October fixtures.
- No new site manager permission, no change to the site manager's write window, no notifications.
- No crew change for a visitor and no change to `day_crew_changes` (see "A visitor's day").
- No columns for outside-Alexandria money or minutes, or for transport: they stay in the app.
- No prefill, no roster in the rows, no `versions` sheet.
- No missing-days list: the 21:00 alert already counts missing days.

## Owner's rules (2026-11-12)
1. **HR downloads and uploads.** No new site manager permission, no change to the site manager's write window. Guarded like the
   template routes (`attendance.view` + `attendance.edit`). Every row is HR's write, source `imported`.
2. **One site and a from–to range per file,** at most 31 days, the current half-month by default. One sheet per day.
3. **The file comes out empty:** no roster, nothing prefilled. The site manager fills every row.
4. **Columns:** الاسم · الحالة · حضور · انصراف · سلفة · ملاحظات · الرقم القومي · الموبايل · محل الإقامة · الأجر المقترح. No ID,
   type or site column: the file is the site, and «حاضر» means present at it.
5. **People are chosen by name, never by ID.** الاسم is a dropdown of `name (type) · Employee ID`: this site's own people first
   (workers and office staff whose dated site is this site, engineers of the site's managers), then every other active day
   laborer, permanent worker and engineer, each group sorted by name. Office staff of other sites or of none are not listed. The
   ID only tells equal names apart and lets the import find the person.
6. **Anyone picked is recorded at this site for that date only.** The home site and the engineer's manager assignment never
   change through the file. Wage, site allowance, fare and site cost follow the day's site, as payroll already does.
7. **Already recorded that day at another site: HR chooses** per row in the preview (the message names the recorded site): keep
   the recorded day, or replace it with the file's row. One attendance day per person per date stays the rule. Already recorded at
   this site: an ordinary change.
8. **A typed name not in the list is a new day laborer.** Name and 14-digit national ID required; mobile, residence and proposed
   wage optional. A national ID that already exists resolves to that employee, never a duplicate. Created through the existing
   proposed-laborer flow. Only day laborers can be new.
9. **Office staff:** status and times only; an advance on an office row is refused.
10. **Advance:** stored as the day's advance, deducted in full in the same period as today. The preview lists every advance and the
    total. (Reverses, for this file, the 2026-10-20 note that times and advances stay in the app.)
11. **Late and overtime** come from the times (the PR #56 rule). HR's confirm is the overtime approval: stored on confirm, no
    per-day «اعتماد». The preview shows each row's late and overtime minutes.
12. **Refused in the preview:** a row in a locked period of that person's type (the lock is per type and period; the range is not
    tied to a pay period), a date still in the future at upload, times with a leave status, the same person twice on one sheet, an
    unknown status.
13. A blank row is ignored. Absence is an explicit غ. Times are optional with «حاضر».
14. **Pay by site is the site allowance only** (client question 1, answered with its default; ADR 0002 stands). Permanent workers
    get no site allowance. No payroll formula changes; the October fixtures do not move.
15. Outside-Alexandria money and minutes and transport stay in the app. No notifications (HR's own action). Audited as any
    attendance write.

## Differences from the hand-over
The hand-over is the planning session's note of the rules above. Each point below corrects or adds to it, with the code that
decided it. Here and below, paths are under `backend/app/Modules/` unless they start with `backend/` or `frontend/`.

1. **Pay before the wage is approved** (rule 8 said the days stay out of payroll). They do not: payroll takes every employee of the
   type (`Payroll/Application/PayrollSheet.php:52`); a day with no pay row pays wage 0 and is flagged `no_wage`
   (`Payroll/Application/PayrollInputs.php:150-164`, `Payroll/Domain/DayLaborerEngine.php:58`); the fare and a fixed site allowance still pay;
   approval writes the pay row from the hire date (`Employees/Application/DayCrew.php:223-224`). Kept as for any proposed laborer
   today; no code change.
2. **A blank proposed wage makes no proposal** (rule 8 made the wage optional). `employee_wage_proposals.daily_wage` is not null
   and must be above 0 (`backend/database/migrations/2026_11_04_200001_create_day_crew.php:35,42`). The preview marks the laborer
   «بدون أجر مقترح»; HR sets the wage on the employee's page.
3. **Gender defaults to male** (not in the hand-over). `employees.gender` is required and not null
   (`backend/database/migrations/2026_10_16_000001_create_employees_tables.php:17`; the owner kept it required for proposed
   laborers on 2026-11-05) and the approved layout has no gender column. The preview shows it; HR changes it on the employee's
   page. Awaiting the owner (see Open).
4. **An existing national ID resolves only to someone in the file's name list** (rule 8 said it resolves to that employee).
   `national_id` is unique over every row, soft-deleted ones included (`create_employees_tables.php:18`; the API rule at
   `Employees/Application/EmployeeRules.php:64` has no `whereNull('deleted_at')`), so a deleted employee's ID can be neither found by a
   live lookup nor reused. Anyone outside the name list (an office employee not of this site, an archived or deleted employee) is
   `national_id_taken`. Several rows with one new national ID are one person; a different name on a later row is
   `national_id_name_mismatch`.
5. **All scope only** (rule 1 named the template routes' guard). The import writes with `ownerId = null`, which skips the site
   manager's reach and home-site guard (`Attendance/Application/AttendanceDays.php:456-464`); `RequestScope::narrowestOwnerId`
   returns an owner for an own-scope caller (`Identity/Presentation/RequestScope.php:26-38`). So an own-scope caller gets 403 on
   all three routes. A row that creates a new laborer also needs `employee.create` at all scope (`backend/routes/api.php:51`).
   (The template routes are at `backend/routes/api.php:114-116`; the hand-over's 109-111 is stale.)
6. **A note on an office row is refused too** (rule 9 named the advance). The day rules give office staff the in and out times
   only (`Attendance/Domain/DayRules.php:14-19`), so both are `field_not_allowed`.
7. **One time without the other is refused** (rule 13 made times optional). Today only a permanent worker's departure without an
   arrival is refused (`DayRules.php:57-60`); an arrival alone makes an open day (§2b), which blocks the person's next site
   manager day and the period lock. An imported day is never left open: both times or neither, `times_need_both`, every type.
8. **The import sends the overtime** (rule 11). A bulk write keeps the stored overtime while the departure and the arrival's
   presence are unchanged (`AttendanceDays.php:347-364`), so a site manager's day stored with overtime 0 would stay 0 when the file
   repeats its times. For an engineer or day laborer whose row gives both times, the import sends `ot_minutes` from the times.
9. **The row cap stays per sheet** (the hand-over expected 31 sheets to pass `MAX_ROWS`). The reader already counts per sheet
   (`Attendance/Infrastructure/XlsxAttendanceReader.php:86-111`, the check at L105): 2000 data rows per sheet.
10. **Stale protection is a preview token** (the layout has no `versions` sheet). The per-type import reads versions from its
    hidden `versions` sheet (`Attendance/Application/AttendanceImport.php:95-102`, G4). Here the preview returns a token over the
    stored days it read, and the confirm refuses a changed state (`409 preview_stale`).
11. **The preview is the confirm, rolled back** (the hand-over said it dry-runs every write). The dry run returns before storing
    and gives back no computed values (`AttendanceDays.php:366-369`), so it could not show late and overtime minutes.
12. **Conflicts:** a day stored at another work site is a conflict whatever the file's status; the preview shows it replaced; at
    confirm a conflict with no choice is not saved (`choice_required`).
13. **`lists` holds the site id and ISO dates** (the sample wrote the site name and a `from..to` text). A site name can change or
    be shared by two sites (the per-type import calls a shared name unknown, `AttendanceImport.php:199-232`). The upload form
    sends no site or range.
14. **Name list details** (rule 5 said "active"). Both groups are taken on the range's first day; the others are those employed on
    some day of the range; archived employees are left out, as from every roster set (`Employees/Application/EmployeeRoster.php:24-25`).
    The type label is the type on the first day of the range the person is employed. A site manager who is an employee is listed
    like anyone: this is HR's file, and `EmployeeReach::exceptOwn` (`Employees/Application/EmployeeReach.php:48-51`) applies to
    reach only. The entry ends with the numeric Employee ID (`employees.code`, digits only, `create_employees_tables.php:13`); the
    sample's `E-01` style was illustrative. The type labels are new backend keys, worded as the sample (يومية, موظف مكتب), since
    only the frontend has type labels today (`frontend/src/locales/ar.json:204-208`).
15. **Always Arabic** (not in the hand-over). The per-type writer follows the request language
    (`XlsxTemplateWriter::write(array $grid, string $locale)`, `Attendance/Infrastructure/XlsxTemplateWriter.php:30`); this file
    is for site managers and the approved sample is Arabic.
16. **The visitor's day, the hand-over's open point,** is settled below. One exception needs no new code: a new laborer gets the
    crew row `DayCrew::propose` already writes for the hire date (`Employees/Application/DayCrew.php:145`).

## A visitor's day (the hand-over's open point)
**Decision:** HR's import records a person from another site with the attendance day alone (`attendance_days.site_id` = the file's
site). No `day_crew_changes` row, no home-site move, no manager assignment.

**Why:** pay, fare, site allowance and site cost read only the day's `site_id` (`Payroll/Application/PayrollInputs.php:59-125`:
allowance L200-210, fare L176-190; the per-day `site_id` and `day_cost` of `Payroll/Domain/DayLine`). Nothing in pay reads
`day_crew_changes` or the home site. An imported day is HR's and read-only to every site manager (`SiteDay::hrDay`,
`Attendance/Application/SiteDay.php:262-265`), so a crew row would only change whose list shows the day.

| | Day only (chosen) | Day plus a crew row |
|---|---|---|
| Pay and site cost | right | right |
| Visited site's manager | does not list the visitor | lists them, read-only |
| Home site's manager | lists the day read-only at the visited site (`SiteDay::load`, L380-405) | no longer lists them that day |
| Engineers | the same: read-only on their own manager's sites | impossible: a crew is day laborers only |
| Permanent workers | the same | outside the definition of Crew (`CONTEXT.md`) |
| The 21:00 alert | unchanged | counts HR's imports as site manager crew changes (`AttendanceGrid::missingCounts`, `Attendance/Application/AttendanceGrid.php:287-307`) |
| Keep or replace | writes the day only | must also move or delete the crew row, in `DayCrew::locked()` |
| Module boundary | Attendance writes its own table | Attendance writes an Employees table |

A home-site move, which ADR 0004 asked for a one-day helper, is ruled out by owner rule 6. ADR 0004 and the `CONTEXT.md` entry
«Home site» carry a dated amendment (2026-11-12).

## The file
A test can assert every line of this section. It matches the approved sample (`build.php` of the planning session), except where
"Differences" 13 and 14 say otherwise.

**Day sheets**, one per date of the range, in date order, the first one selected:
- Name `<weekday> dd-mm` with the Arabic weekday: السبت, الأحد, الاثنين, الثلاثاء, الأربعاء, الخميس, الجمعة (e.g. «السبت 07-11»).
- Right to left; frozen at B3 (rows 1–2 and column A stay in view).
- Row 1: `موقع <site name_ar> — <weekday> dd-mm-yyyy` in A1, merged A1:J1; bold, size 14, fill `DDEBF7`.
- Row 2: the ten headers in A2:J2, bold, in the order of rule 4.
- Rows 3 to 2 + N, with N = max(30, 2 × the number of the site's own people in the name list): empty. Column A holds an empty
  text cell, since OpenSpout drops a row whose cells are all empty.
- Every cell has the text format `@`, so times, the national ID and the mobile stay text and a name starting with «=» is never a
  formula.
- Fills: A3:A(last) `FFF2CC`; G3:J(last) `FFF9E5`; B–F none.
- Column widths, in characters: max(8, the longest of the header and, for A, B and I, the values of their list) + 3. The title is
  not measured.
- Validations on every day sheet, rows 3 to the last:
  - A: list `lists!$B$1:$B$<names>`, error alert off (`showErrorMessage: false`) so a typed name is kept; prompt title «إضافة»,
    prompt «اختار الاسم من القايمة، أو اكتب اسم عامل يومية جديد وبياناته».
  - B: list `lists!$A$1:$A$<statuses>`, blocking.
  - I: list `lists!$C$1:$C$<residences>`, blocking.

**`lists`**, hidden, after the day sheets:

| Column | Holds |
|---|---|
| A | «حاضر», then the Arabic name of every active leave day-type |
| B | the name list |
| C | every residence's name |
| D | D1 the site id (UUID), D2 `from`, D3 `to` (`Y-m-d`) |

**The name list**:
- Entry: `<name_ar> (<type label>) · <Employee ID>`, e.g. «سيد حسن (يومية) · 112». Labels: مهندس, عامل مثبت, يومية, موظف مكتب.
- Group 1, this site's own people on the range's first day: workers and office staff whose dated site is the site, and the
  engineers assigned to any of the site's managers. Group 2: every other day laborer, permanent worker and engineer employed on
  some day of the range. Each group sorted by Arabic name.
- Left out: archived employees, and office staff of other sites or of none.

The file is Arabic whatever the request language. No `versions` sheet.

## Endpoints and permissions
| Route | Input | Answer |
|---|---|---|
| `GET /api/v1/attendance/site-workbook` | query `site_id`, `from`, `to` | the `.xlsx`, deleted after it is sent (as the template) |
| `POST /api/v1/attendance/site-workbook/preview` | multipart `file` | 200, the preview below |
| `POST /api/v1/attendance/site-workbook/confirm` | multipart `file`, `token`, `choices[<key>]` = `keep` or `replace` | 200, the result below |

- Middleware `permission:attendance.view` and `permission:attendance.edit`, as the template routes. Then, before any file is read
  or written, the controller answers 403 `permission_denied` unless both are held at all scope
  (`RequestScope::narrowestOwnerId($request, 'attendance.view', 'attendance.edit') !== null`). A site manager is refused all three.
- A row that would create a new day laborer is refused (`new_laborer_not_allowed`) unless the caller holds `employee.create` at all
  scope, checked by name through `AuthorizeAction::decide` (`Identity/Application/Rbac/AuthorizeAction.php:18`). The rest of the
  file goes on. HR holds it today.
- Download: `site_id` must be a live, active work site; `from` and `to` are `Y-m-d`, `from ≤ to`, at most 31 days. Future dates are
  allowed. Anything else is 422 `validation_failed`.
- Upload, as `AttendanceController::upload` (`Attendance/Presentation/AttendanceController.php:145-156`): `file` required, at most
  5120 KB, `mimes:xlsx` (the real MIME type) and `extensions:xlsx`, read from PHP's temp path and never stored. Confirm adds
  `token` (required) and `choices` (optional; each value `keep` or `replace`).

**Security notes**
- The own-scope refusal is the boundary that keeps `ownerId = null` writes away from site managers; it has its own negative test.
- The national ID appears only in HR's preview (new laborers and matched rows). It is never in an error message. The request log
  keeps bodies of 4xx answers only, through `LogMasker`, which masks the `national_id` and `phone` keys and
  long digit runs (`Audit/Domain/LogMasker.php:20-26`). The file is never stored.
- Cells are read as text and every write goes through the day rules; nothing in the file is evaluated.

## Reading the upload (W2, W3)
- `XlsxAttendanceReader` reads the visible sheets and `lists`, keeping visibility; other hidden sheets are ignored. `lists!D1:D3`
  give the site and range. A missing or unreadable `lists`, an unknown or deleted site, or a bad range (not ISO dates, `from` after
  `to`, over 31 days) refuses the file (`template_mismatch`). A site made inactive since the download is not a file error: each
  row at it is refused by the write (`site_inactive`).
- Each visible sheet's date is the one date of the range whose `dd-mm` ends the sheet name; a range of at most 31 days never
  repeats a `dd-mm`. A visible sheet with no date, two sheets for one date, or a row 2 that is not the ten headers (read through
  `text()`) refuses the file. Row 1 is not read.
- At most 2000 data rows per sheet (`too_many_rows`).
- Cell text goes through `XlsxAttendanceReader::text()` (L78-83). Columns C and D also accept what Excel makes of a typed time: a
  time fraction, a `DateTime` or a `DateInterval`. Today `value()` (L115-131) turns those into a date, a decimal string and
  `0 08:30:00`, so the reader gets a time mode for these columns; the per-type reading does not change.

## Resolving the person (W4, W5)
- The import rebuilds the name list for the file's site and range with the function the download uses, and looks the whole cell
  text up after `text()`. A hit is that employee.
- No hit, and the text ends in ` · ` and digits: `unknown_employee`.
- No hit otherwise: a new day laborer. The national ID (G) must be 14 digits (`national_id_required`). It is looked up among all
  employees, soft-deleted included (`Employee::withTrashed()`): someone in the name list → that employee, `matched_by_national_id`
  in the preview; anyone else → `national_id_taken`; nobody → new, one person per national ID across the file. A later row (sheet
  order) whose name differs from the first row's is `national_id_name_mismatch`.
- `new_laborer_not_allowed` is checked once the row is known to create a laborer.
- After resolution, the same employee (or the same new national ID) twice on one sheet: the second row is `duplicate_row`.
- Columns G–J filled on a row whose name is an entry of the list are ignored, with no error: the person exists, and those
  columns describe a new day laborer only (controller, 2026-11-12).

## Creating a new day laborer (W5)
- Through `Employees/Application/DayCrew::propose` (L139-150), widened to take `national_id`, `residence_id` and a nullable wage.
  With a wage it writes the pending proposal as today; without one it writes none. Gender `male`. Hire date = the earliest date
  the person has in the file. It already writes the employee, the type from the hire date, the crew row for the hire date at the
  file's site and the Employee ID (`EmployeeCodes::issueNext`), and sends no notification (the notification is in
  `SiteDayCrew::propose`, `Attendance/Application/SiteDayCrew.php:97-111`, which the import does not call).
- Mobile: `EmployeeRules::MOBILE` (`^01[0125]\d{8}$`), else `bad_mobile`. Residence: a residence's name, else
  `unknown_residence`. Proposed wage: the money format of the day (`^\d{1,12}(\.\d{1,6})?$`) and above 0, else `bad_amount`.
- Rows of one new laborer that disagree on mobile, residence or proposed wage are not refused: for each field the first
  non-blank value in date order wins. The name must still match (`national_id_name_mismatch`) (controller, 2026-11-12).
- Calling an Employees application class from Attendance is what `SiteDayCrew` already does; deptrac checks layers only, and the
  coding standard (§3.2) forbids writing another module's tables, not calling its use cases.

## Writing a row (W6–W12)
Each row is one `AttendanceDays::writeCell($employee, $date, $input, ownerId: null, source: 'imported')`
(`AttendanceDays.php:177-184`), its own savepoint. `$input` holds:
- `site_id`: the file's site for «حاضر» (field types); null for office present; the leave day-type's id for any other status
  (matched as `AttendanceImport::choices()` matches, limited to the present word and the active leave day-types).
- `version`: the live version read for the token, null when there is no live day.
- `in_time` and `out_time`: only when the row gives both. Unsent otherwise, so the keep rule and the field-shift fill of the
  write path apply (`AttendanceDays.php:321-345`).
- `ot_minutes`: for an engineer or day laborer at the work site whose row gives both times, the overtime those times give against
  the employee's shift, else the field shift (`EffectiveDays::fieldShift`, `DayRules::workerMinutes`: what the write path itself
  uses, L355-362).
- `advance_amount` and `note`: only when given (advance in the money format and above 0, else `bad_amount`; a note longer than
  the day's 500 characters, `AttendanceDays::FORMAT` L46, is `note_too_long`, controller 2026-11-12).

Nothing else is sent; the late minutes follow the arrival in the write path. The write path's refusals keep their codes (W12),
and a row whose write changes nothing writes nothing (no version, no audit row).

## Conflicts (W13)
A row whose resolved employee has a live day on the date at a work site other than the file's site is a conflict, whatever its
status. Its `key` is `<employee_id>/<date>`. Preview applies it as `replace` and shows `conflict_site`. At confirm:
`choices[key] = keep` writes nothing and counts it as kept; `replace` writes the row over the stored day (same row and version);
no choice is `choice_required`. A new laborer's row can never conflict.

## Preview, confirm and the token (W15)
Both run the same steps over the uploaded file:
1. Read the file and check it whole; any failure refuses the request.
2. Rebuild the name list; resolve every row; list the row errors found so far.
3. Read the live day of each resolved (employee, date) once. The token is the SHA-256, in hex, of those reads (employee id, date,
   live version or none, site id) in `employee_id, date` order, followed by each new national ID and its resolution.
4. Confirm only: if the token differs from the one posted, answer 409 `preview_stale` and save nothing.
5. In one transaction: create the new laborers, then apply the rows in `employee_id, date` order, each in its own savepoint,
   passing the version read in step 3. A change after that read is the row's `concurrency_conflict`, never an overwrite. Read
   back each written day for the preview's `to`, with its late and overtime minutes.
6. Preview: roll back everything (laborers, proposals, crew rows, days, audit rows). Confirm: commit.

The first task of the plan proves that the write path and `DayCrew::propose` have no effect outside the transaction (queue,
mail, cache, events). If one has, it moves to after commit, or the preview falls back to the existing `dryRun` flag for existing
employees.

`advances_total` is the backend's decimal-string sum of the advances on the non-refused rows; the frontend never adds money.
Uploading the same file again after a confirm saves nothing: its rows are unchanged and its new national IDs now resolve to the
employees created.

**Preview** (inside the usual envelope):
```json
{
  "site": {"id": "0193f0a2-…", "name_ar": "برج العرب", "name_en": "Borg El Arab"},
  "from": "2026-11-07",
  "to": "2026-11-13",
  "token": "3f7a…c21e",
  "summary": {"rows": 12, "changes": 10, "unchanged": 0, "errors": 1, "conflicts": 2, "new_laborers": 1, "advances_total": "700.000000"},
  "changes": [
    {
      "key": "0193f2c1-…/2026-11-07", "sheet": "السبت 07-11", "row": 5, "date": "2026-11-07",
      "employee_id": "0193f2c1-…", "code": 104, "name_ar": "محمود علي", "name_en": null, "type": "permanent_worker",
      "new_laborer": false, "matched_by_national_id": false, "conflict_site": null,
      "from": null,
      "to": {"site": "برج العرب", "in_time": "09:20", "out_time": "18:00", "late_minutes": null, "ot_minutes": null, "advance_amount": "500.000000", "note": null}
    },
    {
      "key": "0193f2c9-…/2026-11-07", "sheet": "السبت 07-11", "row": 11, "date": "2026-11-07",
      "employee_id": "0193f2c9-…", "code": 131, "name_ar": "رضا فتحي", "name_en": null, "type": "day_laborer",
      "new_laborer": false, "matched_by_national_id": false, "conflict_site": "العلمين",
      "from": {"site": "العلمين", "in_time": "09:00", "out_time": "16:00", "late_minutes": 0, "ot_minutes": 0, "advance_amount": null, "note": null},
      "to": {"site": "برج العرب", "in_time": "09:00", "out_time": "16:00", "late_minutes": 0, "ot_minutes": 0, "advance_amount": null, "note": "من موقع العلمين"}
    },
    {
      "key": null, "sheet": "السبت 07-11", "row": 14, "date": "2026-11-07",
      "employee_id": null, "code": null, "name_ar": "حسن محمد إبراهيم", "name_en": null, "type": "day_laborer",
      "new_laborer": true, "matched_by_national_id": false, "conflict_site": null,
      "from": null,
      "to": {"site": "برج العرب", "in_time": "09:00", "out_time": "16:00", "late_minutes": 0, "ot_minutes": 0, "advance_amount": null, "note": null}
    }
  ],
  "new_laborers": [
    {"name_ar": "حسن محمد إبراهيم", "national_id": "29501010212345", "phone": "01012345678", "residence": "العامرية",
     "proposed_wage": "350.000000", "gender": "male", "first_date": "2026-11-07", "days": 1}
  ],
  "errors": [
    {"sheet": "الأحد 08-11", "row": 4, "date": "2026-11-08", "name": "منى خالد (موظف مكتب) · 3", "error": "field_not_allowed", "message": "…"}
  ]
}
```
`from` and `to` name the site or leave day-type in the request language, or the present word for office present. `from` is null
when there is no live day. A new laborer with no wage has `"proposed_wage": null` and is shown «بدون أجر مقترح».

**Confirm**:
```json
{
  "applied": 10, "unchanged": 0, "kept": 1, "advances_total": "700.000000",
  "new_laborers": [{"employee_id": "0193f3aa-…", "code": 1207, "name_ar": "حسن محمد إبراهيم", "proposal": true}],
  "errors": [{"sheet": "الأحد 08-11", "row": 4, "date": "2026-11-08", "name": "منى خالد (موظف مكتب) · 3", "error": "field_not_allowed", "message": "…"}]
}
```
`advances_total` is the sum on the rows the confirm saved. A stale token answers 409 with `error.code` `preview_stale`.

## Error codes
Whole file or request (nothing is saved):

| Code | Status | When | |
|---|---|---|---|
| `permission_denied` | 403 | a route permission is missing, or either attendance permission is held at own scope only | existing code; the own-scope case is new |
| `validation_failed` | 422 | download query; upload rules; confirm without `token`; a choice other than `keep` or `replace` | existing |
| `unreadable_file` | 422 | not a readable `.xlsx` | existing |
| `template_mismatch` | 422 | `lists` missing or unreadable; site unknown or deleted; bad range; a sheet with no date; two sheets for one date; a wrong header row | existing code, new causes |
| `too_many_rows` | 422 | a sheet with more than 2000 data rows | existing |
| `preview_stale` | 409 | confirm: the stored days changed since the preview | new |

Row (listed in `errors`; the other rows go on):

| Code | When | |
|---|---|---|
| `name_required` | other cells filled, no name | new |
| `status_required` | a name and no status | new |
| `unknown_employee` | a text ending in ` · ` and digits that is no entry | existing (`AttendanceImport::OWN_CODES`, L28), new causes |
| `national_id_required` | a new laborer without a 14-digit national ID | new |
| `national_id_taken` | the national ID belongs to someone outside the name list | new |
| `national_id_name_mismatch` | one new national ID under two names | new |
| `new_laborer_not_allowed` | a new laborer and no `employee.create` at all scope | new |
| `duplicate_row` | the same person twice on one sheet | existing |
| `unknown_status` | a status that is neither the present word nor an active leave day-type | new |
| `bad_time` | a time that is not `H:MM`, `HH:MM` or an Excel time | new |
| `times_need_both` | one time without the other | existing code (day rules), new check for every type |
| `bad_amount` | an advance or proposed wage not a positive amount | new |
| `note_too_long` | a note longer than 500 characters | new |
| `bad_mobile` | a mobile failing the mobile rule | new |
| `unknown_residence` | a residence that is no residence's name | new |
| `choice_required` | confirm: a conflict with no choice | new |
| `future_date`, `employee_not_active`, `period_locked` | the write path's guard (`AttendanceDays.php:440-450`) | existing |
| `concurrency_conflict` | the day changed after step 3 | existing |
| `field_not_allowed`, `site_inactive`, `out_before_in`, `out_of_bounds` | the day rules (`DayRules.php:28-66`) | existing |

New codes get `attendance.import.<code>` messages in `backend/lang/{ar,en}` and `attendance.import.error.<code>` keys in the
frontend.

## Code: reused and generalised
Reused as is:
- `AttendanceDays::writeCell` and everything behind it: period guard, version, keep rule, field-shift fill, late and overtime
  derivation, day rules, audit (`Audited`).
- `XlsxAttendanceReader::text()`; the upload rules; `RequestScope::narrowestOwnerId`; `AuthorizeAction::decide`;
  `EmployeeRules::MOBILE`; the error shape and its messages.

Generalised (the per-type template and import keep their behaviour; their tests are the check):
- **Writer:** `XlsxTemplateWriter` writes one visible sheet with one validation on sheet 0 (`XlsxTemplateWriter.php:30-37, 62-93`).
  It learns N sheets with per-sheet validations, merges, fills, widths and views, all set on `Options` by `sheetIndex` before the
  writer opens (`backend/vendor/openspout/openspout/src/Writer/XLSX/Options.php:64-75, 100-110`).
- **Reader:** `XlsxAttendanceReader::read` keeps only the sheets `attendance` and `versions` (L50). It learns to return the
  visible sheets and `lists` with their visibility, and a time mode for chosen columns (above).
- **Status matching:** the private `AttendanceImport::choices()` (L199-232) is shared, limited here to the present word and the
  active leave day-types.
- **`DayCrew::propose`:** widened (above). Its site manager caller keeps its behaviour.
- **Name list:** a new `EmployeeRoster` method returns the two groups for a site and range without manager reach (there is no
  HR-level `onSite`: L120-127 requires a manager). Group 2 reuses `allOfType` (L111-114). The site's managers come from a new
  `SiteService` read: today there is only `idsManagedBy` (`Sites/Application/SiteService.php:188-191`).
- **New:** `Attendance/Application/SiteWorkbookTemplate.php` and `SiteWorkbookImport.php`, three actions on
  `AttendanceController`, the routes beside the template's, backend keys for the four type labels.

## Frontend
- `frontend/src/pages/attendance-import.tsx`: a per-type / per-site switch at the top of the import tab. Per type is today's tab,
  unchanged.
- Per site, in its own component file: a work-site picker (`FilterSelect`, `frontend/src/components/common/Filters.tsx:22`, over
  `WORK_SITES`, `frontend/src/lib/query.ts:27`) and from/to date inputs (`TextField type="date"`,
  `frontend/src/components/form.tsx:137`), defaulting to the current
  half-month from `cairoToday()` and limited to 31 days; download through `apiBlob`. Then choose a file (the same 5 MB and
  `.xlsx` check), preview, confirm.
- The preview shows the site and range read from the file; the changes, from → to, with late and overtime minutes; the advances
  with the backend's total; the new laborers with gender and «بدون أجر مقترح» where it applies; the errors; and for each conflict
  a keep/replace choice, plus one control that sets them all. Confirm stays disabled until every conflict has a choice. A
  `preview_stale` answer clears the preview and asks HR to preview again.
- Every string under `attendance.import.*` in `ar.json` and `en.json`.

## Tests
`backend/tests/Feature/SiteWorkbookTemplateTest.php` (download read back with OpenSpout and the sheet XML, as
`AttendanceTemplateTest` does):
- Sheets: one visible sheet per date named `<weekday> dd-mm`, in order, then the hidden `lists`; right to left, frozen at B3;
  A1:J1 merged with the title; row 2 the ten headers.
- Dropdowns: on every day sheet, A on `lists!$B` with the error alert off and the prompt, B on `lists!$A` blocking, I on
  `lists!$C` blocking.
- List order: a worker of the site, an office employee of the site and an engineer of one of its managers come first, then the
  others, each group by Arabic name; entries `name (type) · ID`; an archived employee and an office employee of another site are
  absent; a site manager's own employee row is present.
- Row count max(30, 2 × own), every cell `@`, the fills of A and G–J; `lists!D1:D3`; Arabic with an English request.
- Refusals: an inactive or leave site, `from` after `to`, 32 days; a site manager gets 403.

`backend/tests/Feature/SiteWorkbookImportTest.php` (download, edit, rewrite, upload, as `AttendanceImportTest` does):
- Person resolution: a picked entry; a wrong ID, an office employee of another site, an archived and a renamed employee are
  `unknown_employee`.
- New laborer: rows on two dates make one employee, hired on the earliest, male, no home site, a crew row on the hire date only, a
  proposal with a wage and none without; `national_id_required`; a listed employee's national ID resolves to them (flagged); an
  archived or soft-deleted employee's is `national_id_taken`; `national_id_name_mismatch`; `new_laborer_not_allowed` without
  `employee.create`.
- One day at this site: a worker of another home site and an engineer of another manager are stored at the file's site; no
  home-site change, no crew row, no assignment change.
- Another site: keep writes nothing; replace overwrites the same day; no choice is `choice_required`.
- The advance is stored; the preview's `advances_total`.
- Late and overtime are stored on confirm, also over a site manager's day with overtime 0 and the same times.
- Office: «حاضر» stores office present; an advance and a note are refused.
- A date locked for engineers and open for day laborers; a future date; the same person twice on one sheet; `times_need_both`; a
  time Excel stored as a time value is read.
- Whole file: a renamed sheet, a missing `lists`, a changed header; `too_many_rows` per sheet.
- Own scope: 403 on download, preview and confirm, nothing written.
- A day changed after the preview: 409 `preview_stale`, nothing saved.
- The preview saves nothing (no employee, day, proposal, crew row or audit row); uploading again after a confirm saves nothing.
- Untouched: `AttendanceTemplateTest` and `AttendanceImportTest` pass unchanged; `PayrollOctoberTest` passes on unchanged
  fixtures.

Frontend (vitest, beside `attendance-import.test.tsx`): the switch; the download query; the preview tables; confirm disabled until
every conflict has a choice, and the set-all control; `preview_stale`; locale parity.

## Open
**For the owner (the only open item):** the new laborer's gender. Built default: male, shown in the preview, changed by HR on the
employee's page. The alternative is one more dropdown column (ذكر/أنثى), which changes the approved layout.

## Known limits
- A file with a new laborer holds the lock `EmployeeCodes::issueNext` takes on `employees`
  (`Employees/Application/EmployeeCodes.php:17-24`) until the preview rolls back or the confirm commits; other employee writes
  wait meanwhile.
- Each row costs about 8 queries through the write path (the `ponytail:` note at `AttendanceImport.php:175`), and the file is
  applied twice (preview, confirm), so a full 31-day file is slow.
- The token covers the stored days, not the file: a different file over the same people and dates confirms what it says. The
  per-type import also re-reads the file at confirm.
- A mobile that Excel stores as a number loses its leading 0 and is `bad_mobile`; the cells are text, so a typed mobile keeps it.
- The file never clears a day; a deleted sheet imports nothing for its date.

## Docs
`docs/calculations.md` §14 (W1–W17) and §4 (client question 1); `docs/hrms-module-plan.md` (the 2026-10-20 note, gap row 10, §
Site workbook); `CONTEXT.md` («Site workbook», «Home site», «Proposed laborer»); `docs/adr/0004-employees-belong-to-sites.md`
(2026-11-12 amendment); `docs/client-questions.md` (question 1 struck).
