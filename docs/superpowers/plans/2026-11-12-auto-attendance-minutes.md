# Plan: late and overtime minutes filled from the times

Spec: `docs/superpowers/specs/2026-11-12-auto-attendance-minutes-design.md`. One PR. Two tasks on separate files, run in parallel.

## Task A: backend rule and docs
Files: `backend/app/Modules/Attendance/Application/AttendanceDays.php`, `SiteDay.php`, `backend/tests/Feature/*`,
`docs/calculations.md`, `docs/client-questions.md`.

1. Failing tests first (PHPUnit, in the existing feature test files):
   - sheet bulk row with only `in_time` 08:40 on an engineer's stored day (shift 08:00–17:00) stores `late_minutes` 40;
     with only `out_time` 18:30 stores `ot_minutes` 90; the same for a day laborer;
   - changing the arrival recomputes over a typed number; a number sent in the same write is stored as sent;
   - a write that changes no time keeps the stored minutes (bulk) and a PUT stores what it sends;
   - Present on a new day (bulk, import) stores 0 and 0; a day without a departure stores empty overtime;
   - the site manager's departure after the shift end stores overtime 0, then HR's bulk row `ot_minutes: 90` stores 90;
   - payroll (`PayrollSheetTest` or `PayrollApiTest`): a day typed only through the bulk write shows the lateness and the overtime.
2. `AttendanceDays::write`: after `assertRules` and the dry-run return, for an engineer or day laborer on a work site, fill
   `late_minutes` / `ot_minutes` by the spec's table. Reuse `EffectiveDays::fieldShift`, `DayRules::lateMinutes`,
   `DayRules::workerMinutes`. Compare times as `HH:MM`.
3. `SiteDay::values`: delete its own minutes computation; `SiteDaySaveTest` must stay green unchanged (except where a test
   pins the old «stores nothing» behaviour, which the spec replaces).
4. Update existing tests that pin the old behaviour, each with the reason in the PR notes.
5. Docs: `docs/calculations.md` §2, §2b, §4, §13; `docs/client-questions.md` question 20.
6. Green: full PHPUnit suite, pint, phpstan, deptrac.

## Task B: frontend
Files: `frontend/src/pages/attendance-sheet-rows.tsx`, `attendance-sheet.tsx`, `attendance-day.tsx`, `frontend/src/lib/day-sheet.ts`,
their tests, `frontend/src/locales/{ar,en}.json`.

1. Failing Vitest tests first:
   - an engineer's row shows the stored late minutes under the arrival (the hint when none is stored);
   - stored overtime above 0 shows «إضافي N د»; stored 0 or empty with a suggested N shows «إضافي N د غير معتمد» and an
     «اعتماد» button on an editable row only; the click sends one bulk row with `ot_minutes: N` and the row's version and site;
   - the day editor empties the late and overtime boxes when the arrival changes and the overtime box when the departure
     changes, shows the «تُحسب من الوقت عند الحفظ» placeholder, and the suggestion button fills the overtime box.
2. Implement with the existing row write (`onCommit` / the sheet's bulk save) and the existing `Hint` and button components.
3. i18n keys in both locales.
4. Green: `npm run test`, `npm run lint`, `npm run build`.

## After both
Full suites, `code-review`, before/after screenshots of an engineer's day sheet row on a throwaway copy of the dev data, PR into `main`.
