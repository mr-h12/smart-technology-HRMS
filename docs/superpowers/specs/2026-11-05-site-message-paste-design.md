# Paste a site attendance message: design

Release 1, item 5 of `docs/hrms-module-plan.md`: "HR pastes a site's attendance message in a fixed format, and the system parses it per person."
Settled with the owner on 2026-11-05, from one real message. Owner answers are marked **(owner)**. The rest are the builder's calls, marked **(call)**, and the owner can still change them.

## Why

Team leads send each site's day on WhatsApp. Today HR retypes it person by person. Pasting the message saves the typing. HR still sees every line and decides every doubtful one, so the paste never writes a wrong day silently.

## The message (the one real sample)

```
موقع الشيراتون 14/10
محمود سلطان تاخير 8.35 سهر 7
عمر اشرف تاخير 9
ايمن مصطفى
احمد حسن  سهره 7
```

- **Header, the first non-empty line:** «موقع», the site name, then the date as day/month with no year.
- **Every other non-empty line is one person**, by full name with no code. A named person was present at that site on that date.
- **«تاخير H.MM» or «تاخير H»** is the arrival time: «8.35» is 08:35 and «9» is 09:00. The separator can be `.`, `:` or `,` **(call)**. «تأخير» is the same word.
- **«سهر H» or «سهره H» (also «سهرة»)** means the person stayed until H in the evening: «سهر 7» is a departure at 19:00 **(owner)**.
- **A name alone** means a normal day: present, with no time sent, so the existing defaults apply.
- **Other words appear in real messages** (the owner said so on 2026-11-05) but no sample exists yet. The parser never guesses them: such a line is shown as **not understood**, with its raw text, and HR decides it the way she decides a missing worker. New words are added once the owner sends samples.

## Parsing rules

- Text is normalised with the existing `fold` (ASCII digits, lower case, أ/إ/آ→ا, ة→ه, ى→ي). Tatweel and Arabic diacritics are removed, and «عبد ال…» is joined to «عبدال…» **(call)**.
- **Date:** `d/m`, `d-m` or `d.m`, with an optional year. Without a year, the date is the most recent `d/m` that is not after today (Cairo) **(call)**. An impossible date (31/2) is a header error.
- **Site:** the header text minus «موقع» and the date, matched against the active sites' Arabic and English names after `fold`. One site whose name contains the text, or is contained in it, is chosen. Otherwise (none or several), HR picks the site from a list **(call)**.
- **Arrival hour:** 6–23 is used as written. 1–5 adds 12, since nobody arrives at 3 at night **(call)**.
- **Departure (سهر) hour:** the same time forms as arrival, so «سهر 7.30» is 19:30. An hour of 1–11 adds 12, so 7 → 19:00. Any other hour (0, 12, 13 and up) makes the line not understood, and so does past midnight; HR records those on the sheet **(call)**. A departure that is still not after the arrival is refused at save (422 `out_before_in`) and mapped back to its line.
- **Name and leftover words (call):** the name is the longest run of words at the start of the line that is the start of some field employee's Arabic name. It is not simply "everything before the first keyword". The keywords and their times are then read. **Any word left over makes the line not understood**: «محمد علي اجازة» is not understood when «محمد علي» is a name. When no starting run matches anyone, the whole text before the first keyword is the name, the line is unmatched, and the picker shows the raw line so HR can see any extra word before picking.
- **Matching:** a person matches when the message's words are the first words of their Arabic name, in order: «محمود سعد» matches «محمود سعد إبراهيم».
  - **Exactly one** field employee (engineer, permanent worker or day laborer) employed on that date matches: the line is **matched**.
  - **None or several:** HR picks the person from a list in the preview **(owner)**. The list shows the candidates when there are several, or a search over every field employee when there are none.
  - HR picks the person every time. **Nothing is remembered** between pastes **(owner)**.
- **The same person twice** (two lines, or a pick that equals another line's match): the second line is flagged, and only the first is saved **(call)**.
- At most **500 person lines**, the existing `MAX_BATCH` **(call)**.

## Flow and screens

1. **Entry point (call):** a «لصق رسالة موقع» button on the HR attendance day sheet (`attendance-sheet.tsx`), next to the existing paste button. It is shown to anyone who can edit attendance. The message carries its own date and site, so the sheet's group and date do not limit it.
2. **Paste step:** a textarea. The header (site, date) and the line count update as HR types.
3. **Preview step:** one row per person line, in message order. Each row shows:
   - the raw line;
   - the matched person (code and name), or a picker;
   - the new value: the site, the arrival and the departure;
   - when the person already has a stored day that differs, the old value next to the new one, as **old ← new** (site, arrival, departure);
   - a tick. It is on by default for every row that would change something, and HR can untick a row to keep what is stored **(owner)**.

   A time the message does not send shows the stored time when the day is already at work, and «shift» when the server will fill the employee's field shift (a new day, or a day moving from leave to work) **(call)**. The sheet does not return the shift, and the preview does not need it.
   A row whose group's period is locked on that date (read from `GET /attendance/period?group=…&date=…`, one read per group) is shown as **locked**, with no tick, so a locked group never blocks the others' save **(call)**.
   Unchanged rows say «no change» and carry no tick. Not-understood, unmatched and duplicate rows carry no tick until HR resolves them. A not-understood line is shown and never saved; HR records it on the sheet.
4. **Missing workers popup (owner):** pressing Confirm opens a popup first. It lists the workers whose **home site on that date is this site** and who are not named in the message (engineers have no home site, so they never appear here). Each worker shows their current day, and HR chooses one of:
   - **leave as is** (the default);
   - **absent**;
   - **sick**;
   - **present at another site** (a site picker).

   The popup is skipped when nobody is missing.
5. **Save:** one `POST /attendance/days/bulk` for the date, carrying every ticked row and every missing-worker choice. It is all or nothing, as the existing endpoint is: stale versions (409), rule violations (422 `rows.N.*`), a locked period or a future date refuse the whole save. The dialog stays open, maps each refused row back to its line, and shows why. On success, the dialog closes, the sheets are refetched, and a toast says how many days were saved.

**Nothing is written before Confirm.** The preview and the popup only read.

## What each row writes

These go through the existing bulk write path (`AttendanceDays::bulk`): version check, period lock, reach, day rules and audit, all unchanged.

| Line | Bulk row |
|---|---|
| name only | `site_id` = the message site |
| + تاخير | also `in_time` |
| + سهر | also `out_time` |
| missing → absent / sick | `site_id` = that leave type, as the sheet's quick actions send it |
| missing → another site | `site_id` = that site |

- An unsent time follows the existing rules (§ "Present on a field row" in `docs/calculations.md`). A new day, or a day moving from leave to work, gets the field shift defaults. A day already at work keeps its stored time.
- **Late and OT minutes are not written by the paste (call).** They stay what the system shows today: hints computed from the stored times, which HR confirms on the sheet, the same as for every other bulk entry (`docs/calculations.md` §2–3). Changing that is a payroll rule, not part of this item.
- A row with nothing new (same site, and every sent time equal to the stored one) is not sent.

## Backend change

The sheet rows (`GET /attendance/sheet`, built in `AttendanceGrid::sheet`) gain `home_site_id`: `Employment::homeSiteOn($employee, $date)` for permanent workers and day laborers, and `null` otherwise. Nothing else changes on the backend. The frontend reads the three field sheets (engineer, permanent_worker, day_laborer) for the message date. They give the candidates, their current day and version, and their home site.

## Not in scope

- Words other than تاخير and سهر, until the owner sends samples.
- Remembering name choices (owner: no).
- Undo of a paste: the sheet's session undo is per group. HR fixes mistakes on the sheet.
- Several sites or several dates in one message: a second header line is a not-understood line **(call)**.
- Office staff, and anything a team lead does.

## Tests

- **Vitest, `lib/site-message.ts`:**
  - the header (site, date, year inference, impossible date);
  - each keyword and time form;
  - the arrival and departure hour rules;
  - a not-understood line;
  - name prefix matching, «عبد ال» joining, none and several matches;
  - duplicates and the cap;
  - the real Sheraton sample as a fixture.
- **Vitest, the dialog:**
  - preview rows, old ← new and the tick;
  - a picker resolving a line;
  - the missing popup and its choices;
  - the bulk payload;
  - a 422 mapped back to its line.
- **PHPUnit:** the sheet returns `home_site_id`, dated, and `null` for engineers.
