# Official holiday periods

Owner request, 2026-11-10: «الإجازات الرسمية ممكن تبقى فترات زي العيد الكبير والعيد الصغير وغيره». HR enters an official holiday as a period of consecutive days under one name (from, to, name), not only as one date.

## Decisions (owner, 2026-11-10)

1. A holiday row carries the period: `date` (first day) and a new `end_date` (last day, inclusive). One day = `end_date` equal to `date`. Chosen over one row per day: a period keeps one identity, so edit and delete are one operation and one audit entry, and the readers change in three identical lines.
2. Holidays already entered stay as they are: each becomes a one-day period (`end_date = date`). No merging.
3. Two holidays never overlap (a date belongs to one holiday). Touching is allowed (26, then 27 to 29).
4. A weekly off day inside a period is an official holiday (existing §2a rule: the holiday wins).
5. A period may cross a payroll period boundary or a year end. Each day counts in its own period.
6. A period is at most 31 days long (a guard against a mistyped date, not a business setting).
7. Add, edit and delete are refused (423) while any day of the old or the new period falls in a locked attendance period of any group, as today for a single date. HR reopens first. To extend a holiday whose first days are locked, HR adds a touching holiday for the extra days.
8. No computed amount changes. Leave requests keep counting through `EffectiveDays::offDays`, so every day of a period drops out of a request's count, and approval recounts as today.

## Backend

Module `Attendance`. Follow `docs/coding-standards.md`, `backend/CLAUDE.md`, deptrac layers.

1. **Migration** (new file, do not edit the 2026-10-20 one): add `end_date date`, backfill `end_date = date`, set not null, add `check (end_date >= date)`. Replace the unique index `official_holidays_date_unique` with
   `alter table official_holidays add constraint official_holidays_no_overlap exclude using gist (daterange(date, end_date, '[]') with &&) where (deleted_at is null)`.
   `down()` restores the unique index and drops the column.
2. **One reader helper**: every date in `[start, end]` covered by a live holiday (rows with `date <= end and end_date >= start`, expanded, clipped). `EffectiveDays` (both places) and `AttendanceLocks` (`$holidayDates`) call it instead of `whereBetween('date')->pluck('date')`. `OffDays`, `LeaveDays`, `SiteDay`, the import and the template stay untouched.
3. **`AttendanceLocks` re-lock list**: `holidays_added` = the dates, clipped to the locked period, of holidays overlapping it that were created or updated since the last lock. `holidays_removed` and `leave_on_holidays` keep their logic over the expanded dates.
4. **`OfficialHolidays`**:
   - `list`: the year filter matches a period that overlaps the year.
   - `create`: `end_date` defaults to `date`; every day must be in an open period of every type; the range must not overlap a live holiday.
   - `update`: lock the row; every day of the old range and of the new range must be open; overlap check ignores the row itself; after the update, `clearStored` runs for each old day that is no longer in the range.
   - `delete`: every day open; `clearStored` for each day.
   - A racing overlapping write (exclusion violation, SQLSTATE 23P01) answers like the existing duplicate: 422 on `date`.
5. **Validation** (`HolidayController`): `end_date` is `date_format:Y-m-d`, `after_or_equal:date`. On POST it is optional. On PATCH `date` and `end_date` come together (each `required_with` the other); a name-only PATCH still works. A span over 31 days is a 422 on `end_date`. Overlap is a 422 on `date` with a message that says the dates overlap another official holiday (new `errors.*` key in both backend languages; keep `errors.duplicate_date` if still used).
6. The API row gains `end_date`. The audit entry comes from the `Audited` trait as before (one per write, before/after including `end_date`).

### PHPUnit (write the failing test first)

In `OfficialHolidaysTest` (and `AttendanceLockTest` / `LeaveRequestsTest` where the fixture lives):
- a 4-day period derives `official_holiday` on each of its days in the grid for a field group and for office, including the Friday inside it; the day before and after are not holidays;
- POST without `end_date` still creates a one-day holiday with `end_date = date`;
- overlap is 422 on `date`; touching is created; `end_date` before `date` is 422; 32 days is 422;
- a period crossing the engineers' 25th/26th boundary is 423 when either side is locked, and created when both are open;
- name-only PATCH; PATCH with `date` alone is 422;
- shrinking a period after lock + reopen soft-deletes the lock-stored derived holiday days of the dropped dates only; deleting it clears all of them; entered, imported and leave days stay;
- the year filter returns a period crossing 31 December for both years;
- update writes one audit entry with the old and new `end_date`;
- a re-lock lists every day of a period added since the last lock in `holidays_added`, and a stored leave day inside the period in `leave_on_holidays`;
- a leave request across the period counts its dates minus the weekly off and every day of the period.

Existing tests keep passing unchanged, except the one that moves a holiday with `date` alone (now sends both dates).

## Frontend

`frontend/src/pages/holidays.tsx` only, plus locales and its test.

- Dialog: «من تاريخ» (`date`), «إلى تاريخ» (`end_date`, native date input, `min` = from), «اسم الإجازة». On add, a blank «إلى تاريخ» means one day (send `end_date = date`). Edit loads and sends both. Field errors show under `date`, `end_date`, `name`; a 423 shows in the banner as today.
- Table: the period (one date for a one-day holiday, «من – إلى» otherwise, LTR digits via `ltrDates`), the number of days, the name, the row actions. It must fit 375px without horizontal page scroll.
- New i18n keys in `ar.json` and `en.json` (same keys): from, to, days count (with plural forms as the project already does them). Formal Egyptian business Arabic.
- Vitest: a period row renders its range and day count; add sends `date`, `end_date`, `name`; a blank «إلى» sends `end_date = date`.

## Docs (same PR)

- `docs/calculations.md` §2a: a holiday is a named period of one or more consecutive days; every day in it is an official holiday; periods do not overlap; the add/move/delete rule applies to every day of the old and the new period; shortening clears the lock-stored days of the dropped dates. §7 only if its wording needs "every day of a holiday period".
- `CONTEXT.md`: Official holiday = a named day or period of consecutive days off for everyone.
- `docs/hrms-module-plan.md`: one line under the settled decisions (2026-11-10).

## Verify

- Backend: the checks `backend/CLAUDE.md` lists (pint, phpstan, deptrac, phpunit).
- Frontend: `npm run test`, `npm run lint`, `npm run build`.
- Live (verify-hrms, throwaway DB per memory `worktree-gate-api-env`, never the dev `hrms` DB): HR adds a 4-day Eid; the settings list shows one row with the range and "4 days"; the attendance grid shows the four days as official holidays; a leave request across the period shows the reduced day count. Arabic, desktop and 375px.

## Not in scope

Merging old one-day rows, recurring or Hijri-calculated holidays, per-group holidays, a holiday import.
