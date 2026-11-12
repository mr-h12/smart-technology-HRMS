# Late and overtime minutes filled from the times

Owner request, 2026-11-12, during HR training: «from now on عايز الدقايق تتحسب تلقائي سواء كان تاخير او وقت اضافي».

## Why
On HR's day sheet an engineer's arrival 08:40 (shift 08:00) shows «متأخر 40 د» but stores nothing; payroll reads only the stored
`late_minutes` / `ot_minutes` of engineers and day laborers, so the lateness costs nothing until HR types 40 a second time under
«التفاصيل». Same for overtime. The site manager's screen already stores the late minutes itself.

## Scope
Engineers and day laborers only. Permanent workers' minutes come from the times at payroll (§3) and office lateness from the
arrival (§1); neither changes. Office overtime stays HR's period total. Payroll code does not change, so the October fixtures
do not move.

## Owner's decisions (2026-11-12)
1. **Lateness**: stored automatically from the arrival, and recomputed whenever the arrival changes, even over a number HR typed
   by hand. HR may type another number afterwards.
2. **Overtime**: stored automatically only when HR writes the departure. A departure the site manager writes still stores 0;
   HR's sheet shows the suggested minutes with a one-click «اعتماد» that stores them. This replaces "HR confirms by typing the
   minutes" (§2, §2b, client question 20).
3. **Existing days** are left as they are. The rule applies to a day saved from now on; an old day changes only when HR edits its
   time, or saves it in the day editor with an empty minutes box (A below).

## The rule (one place: `AttendanceDays::write`, which every write path goes through)
For an engineer's or a day laborer's day at a work site:

| Field | Single-day entry (PUT) | Bulk (sheet, import, site manager) | Value |
|---|---|---|---|
| `late_minutes` | always, when the value sent is empty | when the row does not send the key, and the day is new, becomes a work day, or its arrival changes | raw `max(0, arrival − shift start)`, no grace (§0a takes it at payroll); empty without an arrival |
| `ot_minutes` | always, when the value sent is empty | when the row does not send the key, and the day is new, becomes a work day, its departure changes, or its arrival goes between empty and set | `max(0, departure − shift end)` as the hint computes it; **0 when the source is `manager`**; empty without both times |

Review fixes (2026-11-12):
- A. Single-day entry fills an empty value from the times whether or not they changed: the editor's empty box says
  «تُحسب من الوقت عند الحفظ», so a time edited and typed back never erases stored minutes. Saving an old day in the editor with
  an empty box fills it.
- B. In a bulk row, a key that is sent, even empty, is stored as sent (the sheet's Undo restores an old day's empty minutes).
- C. Overtime follows the departure, not a change of the arrival's value, so HR correcting only the arrival of a site manager's
  day leaves its overtime 0 (unapproved), and a typed overtime stays.
- D. The computed minutes are checked like typed ones: a value above the 960-minute bound refuses the write (`out_of_bounds` on
  that field), so a stored value never exceeds it.
- The shift is the employee's own shift, else the field shift setting (`EffectiveDays::fieldShift`), the same the hints use.
- HR's «اعتماد» is an HR write: the day becomes HR's (source `entered`) and the site manager can no longer change it.
- Paths covered: the day sheet (time cells, Present, bulk), the day editor, the Excel import (its Present default gives 0 and 0),
  and the site manager's day. `SiteDay::values` stops computing minutes itself; its behaviour is unchanged.

## Screens
- **Day sheet row** (engineers, day laborers): under the arrival, the stored late minutes (the hint when none is stored, as an old
  day shows today). Under the departure: the stored overtime when above 0; else, when the times suggest overtime, «إضافي N د غير
  معتمد» with an «اعتماد» button on an editable row, which sends `ot_minutes: N` through the bulk write.
- **Day editor**: changing the arrival empties «دقائق التأخير»; changing the departure empties «دقائق الوقت الإضافي». An empty
  box reads «تُحسب من الوقت عند الحفظ», and the hint under a time hides while that time differs from the stored one. Next to the
  overtime suggestion, «استخدام الرقم» fills the box with it; nothing is stored until Save.
- The site manager's screen does not change.

## Not in this change
Backfilling stored days; office overtime; permanent workers; any payroll formula.

## Tests
- PHPUnit: sheet time cell stores late and overtime; arrival change recomputes over a typed number; a typed number in the same
  write is kept; unchanged times keep the stored minutes; a manager's departure stores 0 and HR's approve stores the minutes;
  the import's Present gives 0/0; payroll shows the lateness and overtime of a day typed only through the sheet.
- Vitest: the row shows stored minutes; the approve button appears only for unapproved overtime and sends the minutes; the editor
  empties the minute boxes on a time change.

## Docs
`docs/calculations.md` §2, §2b, §4, §13; `docs/client-questions.md` question 20 (note the 2026-11-12 change).
