# Step 3 restart: the leave request and the experience certificate in the company's layout

Design spec, 2026-11-18. It replaces the first attempt's spec, `docs/superpowers/specs/2026-11-16-uniform-hr-pdfs-step-3-design.md`,
which exists only on branch `claude/pdf-step-3-official-forms-2fcd71` (head `1c247fd`). That branch is evidence of what the owner
approved and of what went wrong. No code is copied from it wholesale. The implementation plan is a separate document written
from this spec.

Owner plan: `docs/superpowers/plans/2026-11-13-uniform-hr-pdfs.md`, Step 3 (tasks 3a and 3b). Wording:
`docs/superpowers/specs/2026-11-13-hr-pdf-wording-tables.md`. Company forms: `docs/templates/طلب اجازة.pdf` (US Letter) and
`docs/templates/شهاده خبرة.docx` (A4).

## 1. What Step 3 delivers, and for whom

HR prints two forms from the system and hands them to employees and to the company manager. Today both print in the PR #61
look on `pdf.layout` in IBM Plex, which the owner rejected. After Step 3:

- HR generates a leave request from the leave dialog and gets a one-page A4 PDF laid out like the company's paper form. The
  decider signs it, and the signature image prints on the line of whoever approved (HR or the company manager).
- HR generates an experience certificate from the employee's Documents tab, archived employees included, and gets a one-page
  A4 certificate on the company letterhead with the company's own paragraph, worded for a man or a woman. The CEO signs it.
- Both PDFs look like the renders the owner approved on 2026-11-18. A visible difference from those renders is a finding to
  report at the gate, never a silent choice.

The next engineer inherits a page shell with two paper families, six Blade components that are the only way to place a typed
value, a dotted run, a kind circle, two fields on one line or a signature, `scripts/pdf-measure.py`, and a smoke that
measures every render against the approved PDFs in true points when it is given their folder. Step 4 builds the advance and
overtime forms from the same shell and components.

Not in Step 3: the advance and overtime forms, the removal of `pdf.layout` and IBM Plex (Step 4c), any API, queue, route,
permission or migration change, and any change to the approval flow.

## 2. The owner's decisions

| Date | The owner's words | What it settled |
|---|---|---|
| 2026-11-16 (Gate 3.0) | «اعتمد التوصيات» | Font Markazi Text Bold, scale 1, Carlito for Latin. The wording tables approved whole. Long values handled by printable limits (option A). The balance cell's text. |
| 2026-11-17 (Gate 3.1, first) | "accepted fix it start in new session with new context window and use same workflow the main chat is only for planing give any other task to multi subagents and make them use ponytail and karpathy skills" | Fix differences 2 (hand-written labels move with the value) and 3 (the unpaid kinds row spreads). |
| 2026-11-17 (Gate 3.1, second) | "edit firs in new session with new context window and same workflow the main chat is for planing only and use subagents for all the other work make sure they use ponytail and karpathy skills", with items 7 («الفراغ قبل النقاط») and 8 («النقطة أو النقطتان») picked on the card | The white before the first dot is small and even. Fewer than five dots after a value print none. Items 3 and 5 not picked. |
| 2026-11-18 (Gate 3.1, third) | «أعتمد» | The leave request approved as rendered on `55aebe3`. |
| 2026-11-18 (Gate 3.2) | «أعتمد» | The experience certificate approved as rendered on `1c247fd`. |
| 2026-11-18 (restart) | «أعد بناء الخطوة 3 كلها» | Step 3 rebuilt from zero on a new branch from `origin/main`. |
| 2026-11-18 (restart card) | «القرارات تبقى (موصى به)» | Gates 3.0, 3.1 and 3.2 stand. |
| 2026-11-18 (restart card) | «لا أستطيع» | The owner cannot export the certificate's Word file to PDF. The certificate's references are the Word file (its numbers and text) and the approved render of `1c247fd`. |

The owner approved these controller rulings by approving the images: the 16 pt line rule and the share steps, the name line
at 18 pt on a 16 pt copy, «بدون أجر» at the left end of the «بيانات منح الطلب» line, dots arrangement A, the certificate split
at 327.1 pt, the steps 15, 13 and 11, the 40 x 12 mm signature, the serial's place, no minimum body height,
`text-wrap: pretty` on ف٧, and the 7 pt gap between the halves.

The controller decided on 2026-11-18 that a render is accepted when each of the 46 approved PDFs has a twin that
`pdf-measure.py compare` reports identical within 0.10 pt and 0 changed pixels (section 9).

## 3. Data shapes

### Layout version

`PdfType::templateVersion(): int` is one `match`. The leave request and the certificate return 2. The advance and the overtime
return 1. `storeUnsigned()` writes it on every new unsigned row instead of the literal 1 at `PdfExports.php:387`. A signed row
copies its unsigned row's version, as it does today at `PdfExports.php:131`.

### Leave request snapshot, version 2

The keys stay those of version 1. One value changes meaning, and three values gain a limit.

| Key | Source |
|---|---|
| `name_ar`, `department`, `gender` | `EmployeeService::show`. A `name_ar` or `department` over 90 characters answers 422 `errors.leave_form_value_too_long` and writes no row. |
| `job_title`, `notification_method`, `manager_opinion`, `substitute` | The form fields, `''` when empty (`normalise()`, unchanged). |
| `leave_request_id`, `kind`, `from_date`, `to_date`, `days` | The leave request row. |
| `reason` | `leave_requests.note`. A stored note over 360 characters answers 422 and writes no row. |
| `balance` | `LeaveBalances::forEmployee($employeeId, $year, $dates)['balance']`, where `$dates` is `LeaveDays::dates($rosterRow, $from, $to)`. It is the balance before this leave, fixed at generation and not refreshed at signing. |
| `balance_year` | The year of `from_date`. |

The signed row adds `stamp_line`, `decider_id` and `signature_version`, as today.

### Experience certificate snapshot, version 2

`name_ar, national_id, department, gender, hire_date, termination_date, job_title, issued_on`, as today. Version 2 never reads
`praise`. A version 2 view only receives a version 2 snapshot (section 6), so the views need no branch on old keys.

### View data

| Form | `view()` returns |
|---|---|
| Leave request | `title`, `valueSize`, `duration` (2 days print «يومين», otherwise `ArabicServiceDuration::days()`), `from` and `to` (`ArabicFormDate::spaced()`), `balanceDays` (`null` prints «غير متاح», 0 prints «0 يوم») |
| Certificate | `title`, `letterhead`, `logo`, `footer`, `issuedOn` (`ArabicFormDate::spaced()`), `service` (`ArabicServiceDuration::format()`), `valueSize`, `lineSpacing` |

`RenderPdf::html()` adds `snapshot`, `serial`, `signature` and `feminine` as today, keeps `font` and `boldFont` for
`pdf.layout`, and adds `markaziArabic`, `markaziLatin` and `carlito` data URLs.

### Printable limits and value sizes

Each form keeps its numbers in class constants. One pure function picks a size from them.

| Constant | Values |
|---|---|
| `LeaveRequests::NOTE_MAX` (Leave module) | 360 |
| `LeaveRequestPdf::LIMITS` | `name_ar` 90, `department` 90, `job_title` 60, `notification_method` 60, `substitute` 60, `manager_opinion` 130, `reason` = `LeaveRequests::NOTE_MAX` |
| `LeaveRequestPdf::ONE_LINE_AT_16` | `name_ar` 47, `department` 62, `substitute` 28, `manager_opinion` 38 |
| `LeaveRequestPdf::REASON_AT_16` | 25 and 100 |
| `LeaveRequestPdf::SIZES` | share ≤ 0.45 prints 13 pt, ≤ 0.6 prints 11, ≤ 0.65 prints 10, otherwise 9 |
| `ExperienceCertificatePdf::LIMITS` | `name_ar` 255, `department` 255, `job_title` 160 (the record limits) |
| `ExperienceCertificatePdf::SIZES` | share ≤ 0.25 prints 15 pt at spacing 2.0, ≤ 0.6 prints 13 at 1.75, otherwise 11 at 1.5 |

The leave request prints at 16 pt while the reason is at most 100 characters and at most one value spills: a reason over 25
counts once, and each of the four `ONE_LINE_AT_16` values counts once per further full length. The job title and the
notification method never count. Otherwise the fullest value's share of its limit picks a step from `SIZES`. This is the
approved rule as it stands on `55aebe3` (`LeaveRequestPdf.php:95-120`), reproduced and not retuned. Changing which copy prints
at which size would be a visible difference nobody asked for. Wording keeps its size. Value lines take the value size.

The leave form rules become `job_title` required max 60, `notification_method` max 60, `manager_opinion` max 130,
`substitute` max 60, all read from `LIMITS`. The leave submit rule becomes `note` max `LeaveRequests::NOTE_MAX`.
`PdfPanel.tsx` gives the leave form its own job title field at 60 and the maxima 60, 130 and 60. The shared `jobTitle` at 160
stays for the certificate. `PdfPanel.test.tsx` keeps its hand-written `SPEC` table, as for every other PDF field.

## 4. Module map and signatures

Paths are under `backend/` unless they start with `frontend/`, `scripts/` or `docs/`.

### Domain

```php
// app/Modules/Pdf/Domain/PdfType.php (edit)
/** The layout new rows of this type are made with. A row renders, is signed and is retried only in its own version. */
public function templateVersion(): int;

// app/Modules/Pdf/Domain/ValueSize.php (new)
final class ValueSize
{
	/**
	 * @param array<string, int> $lengths characters per value
	 * @param array<string, int> $limits the printable limit per value
	 * @param non-empty-list<array{0: float, 1: int, 2?: float}> $steps share bound, size, optional line spacing
	 * @return array{0: float, 1: int, 2?: float} the first step whose bound holds the fullest share, else the last
	 */
	public static function byShare(array $lengths, array $limits, array $steps): array;
}
```

### Application

```php
// app/Modules/Leave/Application/LeaveRequests.php (edit)
public const NOTE_MAX = 360;

// app/Modules/Pdf/Application/Types/LeaveRequestPdf.php (edit; approval(), signable(), normalise(), subjectColumn() unchanged)
public function rules(): array;            // maxima from LIMITS
public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array; // the 422s, the balance before this leave
public function view(array $snapshot): array; // ['pdf.leave-request', view data of section 3]

// app/Modules/Pdf/Application/Types/ExperienceCertificatePdf.php (edit)
public function view(array $snapshot): array; // no praise; valueSize and lineSpacing from ValueSize::byShare

// app/Modules/Pdf/Application/PdfExports.php (edit)
// storeUnsigned(): 'template_version' => $type->templateVersion()
// approval(): the state 409 also fires when ! self::currentLayout($row), after the 404 and 403 gates
// retryable(): the same check as its last 409
// list() with awaiting_approval: each type's branch adds where template_version = that type's templateVersion()
private static function currentLayout(stdClass $row): bool;

// app/Modules/Pdf/Application/RenderPdf.php (edit)
// html(): refuses a row whose template_version is not PdfType::templateVersion() of its type; passes the three new font data URLs
```

`app/Modules/Leave/Presentation/LeaveRequestController.php:50`: `'note' => ['nullable', 'string', 'max:'.LeaveRequests::NOTE_MAX]`.
The Pdf module already imports `Leave\Application` (`LeaveRequestPdf.php:9`), so the constant adds no new dependency
direction.

### Shared contracts

```php
// app/Support/Localization/ArabicFormDate.php (edit; format() stays for the advance and overtime)
/** The weekday and «d / m / Y». Typed in that order in an RTL line, the year stands at the left. Never wrap it in one <bdi>. @return array{weekday: string, date: string} */
public static function spaced(string $date): array;

// app/Support/Localization/ArabicServiceDuration.php (edit; days() and hoursAndMinutes() unchanged)
/** Whole years and months in words after «تجاوزت»; days only under a month. */
public static function format(\DateInterval $interval): string;
```

`format()` has one caller, `ExperienceCertificatePdf.php:61`, so it changes in place. Years: 1 «سنة واحدة», 2 «سنتين», 3 to 10
«ثلاث سنوات» to «عشر سنوات» (8 «ثماني سنوات»), 11 and more «{n} سنة». Months: 1 «شهرًا واحدًا», 2 «شهرين», 3 to 10 «ثلاثة أشهر»
to «عشرة أشهر», 11 «أحد عشر شهرًا». Years and months join with « و». Under a month: 0 and 1 «يومًا واحدًا», 2 «يومين», 3 to 10
«… أيام», 11 and more «{n} يومًا».

### Lang

`lang/ar/errors.php` and `lang/en/errors.php`: `leave_form_value_too_long`, naming the field and the limit, and
`leave_form_note_too_long` for the stored note, which cannot be edited.

### Views

| File | Content |
|---|---|
| `resources/views/pdf/page.blade.php` (new) | `<html lang="ar" dir="rtl">`, the title «{title} - الرقم المسلسل N», the CSP of `pdf.layout`, three `@font-face` rules, `@page { size: A4; margin: 0 }`, the stack `MarkaziArabic, MarkaziLatin, Carlito` at weight 700 with ligatures on, `bdi { overflow-wrap: anywhere }`, the CSS of every component, the paper family (`frame` or `letterhead`) and the serial. |
| `resources/views/components/pdf/field.blade.php` | A hand-written row with a typed value. Props `at`, `label`, `value`, `dots`. |
| `resources/views/components/pdf/blank.blade.php` | A hand-written row with no value. Props `at`, `label`, `dots`. |
| `resources/views/components/pdf/dots.blade.php` | A zero-width dotted run. Props `count`, `pitch` (default 1.38). |
| `resources/views/components/pdf/choice.blade.php` | One kind item ending at its circle. Props `width`, `circle` (w, h), `gap`, `marked`. |
| `resources/views/components/pdf/pair.blade.php` | Two fields on one line. Props `split`, `right`, `left`. |
| `resources/views/components/pdf/signature.blade.php` | A signature label with an image, dots or nothing. Props `label`, `image`, `dots`. |
| `resources/views/pdf/leave-request.blade.php` (rewrite) | `@extends('pdf.page')`, frame family, sections in the company's order. |
| `resources/views/pdf/experience-certificate.blade.php` (rewrite) | `@extends('pdf.page')`, letterhead family, ف١ to ف٨. |

`pdf/layout.blade.php`, the advance and overtime views and `attendance-request-css.blade.php` do not change. Gendered wording
stays as inline `$feminine` ternaries in the two form views, as the approved views have it.

### Assets

`resources/pdf/fonts/markazi-text-arabic-700-normal.woff2` (23,000 B, sha256 `43f5b89a…`), `markazi-text-latin-700-normal.woff2`
(15,704 B, `99a6340f…`) and `Carlito-Bold.ttf` (690,516 B, `0f62ab34…`), copied from OLDNOTES `fonts-2/files/`.
`LICENSE-MarkaziText.txt` from `fonts-2/files/LICENSE-MarkaziText-fontsource.txt`. `LICENSE-Carlito.txt` from
`git show claude/pdf-step-3-official-forms-2fcd71:backend/resources/pdf/fonts/LICENSE-Carlito.txt`. Nothing is downloaded.
IBM Plex stays until Step 4c.

### Tests, smoke and tools

| Path | Change |
|---|---|
| `tests/Unit/PdfValueSizeTest.php` | New. |
| `tests/Feature/HrFormViewsTest.php` | New. The structure lever of section 5. |
| `tests/Feature/HrLeaveRequestFormTest.php`, `HrExperienceCertificateTest.php` | Rewritten for the new markup and wording. |
| `tests/Feature/PdfApiTest.php`, `LeaveRequestsTest.php`, `tests/Unit/ArabicFormDateTest.php`, `ArabicServiceDurationTest.php` | Edited. |
| `tests/Smoke/render-hr-form.php` | One `CASES` table keyed by `HRMS_SMOKE_FORM` and `HRMS_SMOKE_CASE`, a pinned clock, pinned national IDs, a seeded `sick` kind. |
| `scripts/smoke-hr-forms.sh` | Page count, fonts, phrases, title, and the golden compare per case when `HRMS_SMOKE_GOLDENS` names a folder. |
| `scripts/pdf-measure.py` | New. NOTES `baseline/measure.py` plus a `check` mode. The 46 approved PDFs are not committed (section 13). |
| `frontend/src/components/PdfPanel.tsx`, `PdfPanel.test.tsx` | The leave maxima. |

## 5. Layout components and the rule each one enforces

Every fix round of the first attempt traced to a construct that was invented after a gate showed it missing, or to a fix that
moved a neighbour. Each construct now exists once, from the first build, and a view has no other way to do the same thing.

| Fix round | Root cause | Day-one rule | Where it lives |
|---|---|---|---|
| 1 | Sizes and places guessed, then scaled to page fractions | Every company number is a true-point number taken from the approved render, and every build measures itself against the approved PDFs | Component props; `pdf-measure.py compare` in the smoke |
| 2 and 2b | Size picked by character share alone, so everyday copies printed at 10 pt | The approved two-mode rule, unit-tested at each bound, with an `everyday` case in the scenario set | `ValueSize`, `LeaveRequestPdf::SIZES`, `PdfValueSizeTest` |
| 3 (Gate 3.1 first) | A label was plain inline text, so a value moved it. Circles placed by `space-between` | A label stands in a slot with a minimum width (`at`). Each kind is a fixed-width item ending at its circle. «بدون أجر» has its own decided place. | `field`, `blank`, `choice` |
| 3d | A typed value had no dots after it, so the rows' left edge went ragged | A value is always followed by the leader to the slot's end | `field` |
| 3.1e (Gate 3.1 second) | The leftover of one dot pitch landed beside the value, and one or two dots printed | The leader is the approved construct written once. It is LTR, justified inter-character, with `margin-right: -0.6pt`, a five-dot `nowrap` unit, and a period count that always overfills. | `field` |
| 2b (certificate) | The smoke mapped «ىن» to «نى» and so would pass a misspelled page | Phrases are written as Poppler extracts a correct page. The matcher maps no letters. PHPUnit proves spelling on the HTML. | `scripts/smoke-hr-forms.sh`, feature tests |
| 2c (certificate) | Two fields on one line had no gutter, and the white between neighbours was never measured | Two fields on one line always have a gutter (`padding-left: 7pt` on the right half). The compare measures the white between every pair of neighbours. | `pair`; `pdf-measure.py` |

The `field` leader is this CSS, written only in `page.blade.php`: `contain: inline-size; align-self: baseline; height: 1lh;
margin-right: -0.6pt; overflow: hidden; direction: ltr; font-size: 16pt; letter-spacing: 1.38pt; line-break: anywhere;
text-align: justify; text-justify: inter-character`. Its content is a zero-width space, a five-period `nowrap` unit, and more
periods. The period count is `5 + ceil(at / 4.4)`. 4.4 pt is under the smallest pitch, so the run always overfills and the
clipped tail never shows. The approved build used 30 periods. If the derived count changes the compare, use 30.

A value wider than its slot prints no dots and pushes its own label left. This is approved (the `everyday` job title moves
«الوظيفة» by 29 pt). A value never moves an element of another row. A wrapped value moves later rows down, as approved.

`HrFormViewsTest` makes these rules a build failure for the two form views:
- no run of three or more literal periods in the view source;
- every line that prints `$snapshot[` does so inside a `<bdi>` or as a component attribute;
- both views `@extends('pdf.page')`;
- `Blade::render` of each component: `field` with an empty value prints `dots` periods and no `<bdi>`; with a value it prints
  the `<bdi>` and then the leader whose first five periods are one `nowrap` unit; `pair` always carries the gutter; `choice`
  renders marked and unmarked circles.

### Layout numbers

These are the approved values (first-attempt evidence on `55aebe3` and `1c247fd`). The compare against the goldens is the
judge. Where a number is missing here, the builder reads the approved view on the first-attempt branch as evidence.

Leave request, frame family:
- Frame inset 24 pt, outer border 2.9 pt, inner 0.5 pt at 0.9 pt inside. `main` padding `47.3pt 63.5pt 34.3pt`, base 16 pt,
  line height 1.115, paragraph gap 15.9 pt.
- «Smart Technology»: Carlito 20 pt, underlined, LTR, top left.
- Title scroll: the SVG traced from the company form, `viewBox="189.58 72 250 74"`, stroke 2.04, box 250 x 74 pt at
  `left: 8.58pt`, SVG `top: 4.47pt`. «نموذج طلب إجازة» 28 pt, `padding-top: 23.7pt`.
- «مدير الشركة» 18 pt underlined right. «تحية طيبة وبعد،،،» 18 pt centred. The name line at 18 pt on a 16 pt copy; below 16 pt
  the value takes the value size. «بيانات منح الطلب» 18 pt centred underlined.
- Kinds: `annual` width 162.99 pt, circle 25.4 x 25, gap 8; `casual` 118.32, 29 x 26.2, gap 23.7; `sick` 122.4, 32.2 x 25,
  gap 17.4; `padding-inline: 23.5pt`; «)» 12.5 pt after. Circle border 2.25 pt; marked = black fill with a 3 pt inset white
  ring. Circle centres +61.08, −55.56, −176.28 pt from the page centre. Unpaid prints «- بدون أجر ●» at the left end of the
  «بيانات منح الطلب» line.
- Body paragraph `margin-inline: -26pt`, line height 1.4, justified, dates `nowrap`. An empty reason prints 18 periods at
  16 pt. Below 16 pt the reason moves to its own block at line height 1.25. «وتفضلوا سيادتكم بقبول فائق الاحترام،،،» 34.7 pt above.
- Hand-written rows 15.4 pt apart: «توقيع مقدم الطلب/» (feminine «مقدمة») with 21 dots (feminine 20) at 197.36 pt; «الوظيفة / »
  at 155.04; «القسم/» at 148.32, 25 dots when empty; «طريقة التبليغ/» at 183.6, 25 dots when empty; «مدير القسم/» with 24 dots
  at 172.4.
- Table width 478.8 pt, `margin: 14.9pt -5.4pt 0`, fixed layout, rows at least 21.4 pt, 0.5 pt borders, `th` 203.5 pt.
  Rows: «الاستعلام عن رصيد الإجازات» then «الرصيد قبل هذه الإجازة: <bdi>{days}</bdi> (سنة <bdi>{year}</bdi>)»; «رأي مدير القسم»
  then the opinion at value size; «اعتماد مدير القسم» empty; «توقيع الموظف الذي ينوب العمل» (always masculine) then the substitute
  and 20 dots at 16 pt.
- Signatures at 18 pt: «توقيع مدير الشركة» right with 23 dots, «توقيع الموارد البشرية» left (`padding-left: 4pt`) with 30 dots,
  `letter-spacing: 1.55pt`, 15.8 pt below. Signed: the 40 x 12 mm image on the line `stamp_line` names; the other line keeps
  its dots.
- Serial «الرقم المسلسل <bdi>N</bdi>», 7 pt, `#666`, fixed, bottom 8 pt, left 26 pt.

Experience certificate, letterhead family:
- `certificate-letterhead.png` full page; `logo.png` top 6 mm, left 14.3 mm, width 98 mm; `certificate-footer.jpg` bottom,
  210 x 20 mm. `main` padding is the Word margins `79.15pt 28.35pt 72pt 35.45pt`. Serial bottom `20mm + 4pt`, left 35.45 pt.
- Base 15 pt, line height 1.24, paragraphs 8 pt apart. Body line height `1.15 × lineSpacing`, justified.
- ف١ «شهـــادة خبرة» 18 pt, underlined, centred, `margin-top: 27.7pt`. ف٢ «اليوم:{weekday} التاريخ: {date}» 18 pt, no «م».
- ف٤ and ف٥ in `pair` with `split` 327.1 pt; the right half has `padding-left: 7pt`; the second labels' box edge stays at
  240.56 pt. ف٤ «اسم الموظف/» (feminine «اسم الموظفة/») and «المسمى الوظيفي/ »; ف٥ «بطاقة رقم قومي/ » and «قسم/ ».
- ف٦ and ف٧ as in the wording tables (٢-أ, ٢-ب), «المسئولين» and «مسئولية» kept. `.closing { text-wrap: pretty }` on ف٧.
- ف٨ «مدير الشركة» 17 pt, left, `margin-top: 13.9pt`, line height 1.725. Signed: the 40 x 12 mm image under it.

Wording for both forms: the corrections of tables ١-أ and ٢-أ and the gender rows of ١-ب and ٢-ب, all approved at Gate 3.0.

## 6. Rows issued before this PR

Rule: a row renders, is signed and is retried only in the layout version it was made for. A stored file is never rendered
again (`RenderPdf.php:45` already skips an existing file). No version 1 view of the two forms is kept.

| Row made before this PR (version 1) | After deploy |
|---|---|
| `ready`, unsigned or signed | View and Download work as today. An unsigned one answers 409 to `approve`, has no `approve` action and is not in «بانتظار اعتمادي». |
| `pending` or `failed`, unsigned or signed | The worker refuses it (`render_failed`, no file). `retry` answers 409 and `actions` is empty. It stays in «النماذج الصادرة». |
| Any of these, for a caller who may not see or approve it | 404 or 403 first, unchanged. The version check sits after the read and permission gates. |

HR fixes any such copy by generating the form again. The new copy takes a new serial and version 2, and the decider signs it.

Why this rule and not "render each row in the layout it was made with":
- Step 4c removes `pdf.layout`, and both version 1 views extend it. Step 4 therefore needs this rule for every version 1 row
  anyway. Keeping version 1 views now only defers the rule and keeps about 100 lines of old view code alive until then.
- The other rule would let HR sign an old copy in the IBM Plex look the owner rejected.
- The system runs only on a test server, so few rows are affected.

HR sees one change here: an old unsigned copy loses Approve, and an old failed copy loses Retry. The owner hears this in
Arabic at the slice 1 gate.

Deploy note (PR body, run by whoever deploys):
1. Before the deploy, let the `pdf` queue drain and retry failed leave and certificate rows.
2. After the deploy, run `docker compose restart worker-pdf`. An old worker refuses every version 2 row.
3. Run the listing below. HR generates each listed form again, and the decider signs the new copy.
4. Count the leave requests whose note is over 360 characters and the employees whose `name_ar` or `department` is over 90.
   A leave form for them answers 422 until HR shortens the name or the department. A filed note cannot be edited, so that
   request's form stays refused.

```sql
select u.form_type, u.serial_number, u.employee_id, u.status, s.status as signed_status, u.created_at
from hr_form_exports u
left join hr_form_exports s on s.unsigned_export_id = u.id and s.deleted_at is null
where u.form_type in ('leave_request', 'experience_certificate') and u.template_version = 1
	and u.stage = 'unsigned' and u.deleted_at is null
	and not exists (select 1 from hr_form_exports r where r.regenerated_from_id = u.id)
	and coalesce(s.status, 'none') <> 'ready'
order by u.form_type, u.serial_number;
```

The listing keys on the unsigned row. It covers unsigned `pending` and `failed` rows, unsigned `ready` rows never signed, and
signed `pending` and `failed` rows. The first attempt's listing missed the last group.

## 7. Printable limits (option A)

- Generation refuses a leave form when `name_ar` or `department` is over 90 characters or the stored note is over 360
  (422 `errors.leave_form_value_too_long`, or `errors.leave_form_note_too_long` for the note, no row, no serial taken).
- The leave form fields refuse more than 60, 60, 130 and 60 characters (job title, notification method, opinion, substitute).
- A new leave note refuses more than 360 characters at submit.
- The certificate takes no new refusal. Its record limits (255, 255, 160) only pick the size step.

With every value at its limit, each form stays on one A4 page in the approved scenario set. Contrived values can still reach
two pages (section 12).

## 8. Smoke scenarios

`tests/Smoke/render-hr-form.php` holds one `CASES` table. Each entry sets gender, kind, the values, archived, escalate, the
national ID, and whether it renders the long shares. The values are the fixture strings of `55aebe3` and `1c247fd`
(read them with `git show 1c247fd:backend/tests/Smoke/render-hr-form.php`), typed again as data.

- `HRMS_SMOKE_FORM` and `HRMS_SMOKE_CASE` together pick the entry, because case names such as `female` repeat across
  forms.
- `HRMS_SMOKE_TODAY` pins the clock at setup: 2026-11-17 for every leave case, 2026-11-18 for every certificate case. The
  dates live in the snapshot, so the render needs no clock. The leave dates are the Monday after (2026-11-23).
- National IDs per certificate scenario, read from the approved PDFs: `female` 68014522315405, `male` 26569523878298,
  `archived-female` 67247010871558, `archived-male` 94782826867964, `long/0.25` 59390832739947, `long/0.6` 75742786499134,
  `long/1` 21823199372246.
- Serials equal the twins' because each case runs in a fresh database and the long shares run in one database in the
  approved order, as the approved capture did.
- A `sick` leave kind is seeded.

`scripts/smoke-hr-forms.sh` renders each case unsigned and signed into `<out>/<form>/<case>/{unsigned,signed}.pdf`, mirroring
the golden paths, and fails on the first of these:
- page count other than 1, or a page size other than A4;
- `pdffonts` other than the form's allow-list, or a font not embedded (leave `MarkaziText-Bold` and `Carlito-Bold`;
  certificate `MarkaziText-Bold`; the advance and overtime unchanged);
- a title without «الرقم المسلسل N»;
- a phrase missing. Phrases are matched without whitespace, with direction marks stripped and presentation forms folded by
  NFKC, and are written as Poppler extracts a correct page («دون أدىن مسئولية»). No letter is mapped;
- when `HRMS_SMOKE_GOLDENS` names a folder laid out as NOTES `baseline/approved/` and the case has a twin in it,
  `scripts/pdf-measure.py compare <golden> <render>` not exiting 0;
- when the case has no twin, `scripts/pdf-measure.py check` failing (section 9).

Without `HRMS_SMOKE_GOLDENS`, a twin case still checks one A4 page, the embedded fonts, the title and the phrases, and the
smoke prints that it ran no golden compare.

The smoke runs `pdf-measure.py` with `/usr/bin/python3`, the Python that has Pillow. Under a Python without Pillow the compare prints
"RASTER not run", claims nothing about pixels and exits 1.

## 9. Verification

### Tests first

One build agent writes the tests red before the code. The renderer is mocked, so these prove markup and wording, never place.

- `PdfValueSizeTest`: every bound of both step tables, and the last step past the last bound.
- `HrFormViewsTest`: section 5.
- `HrLeaveRequestFormTest`: per kind and gender, the marked circle; «بدون أجر» only for unpaid; «يومين»; the date «d / m / Y»م
  twice; each typed value in a `<bdi>`; an empty department and method print dots; the same balance before and after the
  leave's approval (a 3-day annual leave of a 21-day employee prints 21); «غير متاح»; «0 يوم»; the image only on the
  `stamp_line` box; the form rule maxima; a name over 90 and a stored note over 360 answer 422 with no row; the 16 pt rule at
  reason lengths 25 and 26, 100 and 101, and at each one-line length; a reason «0» prints «0»; the gendered phrases of table ١-ب
  for each gender and the corrections of table ١-أ.
- `HrExperienceCertificateTest`: both genders under a fixed clock with the gendered phrases of table ٢-ب and the corrections of
  table ٢-أ; a termination date ends the duration; the image only when signed; an archived employee; values in `<bdi>`; each
  step's value size and line spacing; `praise` in a snapshot is not printed.
- `PdfApiTest`: new leave and certificate rows store 2, advance and overtime 1; a signed row copies its version; a version 1
  leave or certificate row fails to render with no file, `approve` answers 404 and 403 before 409, `retry` answers 409,
  `actions` holds neither, the awaiting filter skips it, and download works; the advance and overtime HTML still holds
  `PlexArabic`.
- `LeaveRequestsTest`: a 361-character note is refused at submit.
- `ArabicFormDateTest::spaced`, `ArabicServiceDurationTest::format`: 1 to 11 years, 1 to 11 months, the day cases.
- `PdfPanel.test.tsx`: the leave maxima.

### Real-render smoke and goldens

The fixed scenario set. Each case is rendered unsigned and signed. Directory names mirror the goldens.

| Form | Twin cases (must be identical) | No-twin cases (checked, shown to the owner as new) |
|---|---|---|
| Leave request | `female`, `male`, `casual`, `unpaid`, `escalate`, `everyday`, `long/{0.27777777777778, 0.45, 0.6, 0.65, 1}` and each with `-wrap` (32 PDFs) | `sick`; `empty` (no department, notification method, opinion or substitute); `leader-1` to `leader-4` (three values, each growing by one narrow letter per case, 12 samples across one dot pitch) |
| Certificate | `female`, `male`, `archived-female`, `archived-male`, `long/{0.25, 0.6, 1}` (14 PDFs) | `halves-full` (a value filling each right half) |

Slice 2 renders the 32 leave twins again; they must still be identical.

The goldens are the 46 approved PDFs in NOTES `baseline/approved/`. They are not committed. The verifier passes that folder
as `HRMS_SMOKE_GOLDENS`, saves each gate's compare output under NOTES, and the PR body summarises it. Their provenance is
recorded in `INDEX.tsv`: leave on `55aebe3`, certificate on `1c247fd`, Chromium 156.0.8078.79 in `hrms-php`.

Tolerances. A twin passes when `compare` reports `identical within 0.10 pt and 0 pixels`. That covers the page size and count,
the fonts, each word, image and shape (x from the page centre line, y from the top, never stretched), the white between every
pair of neighbours on a row, any new pair closer than 1 pt, and a 100 dpi raster diff. If an ID cannot be pinned, only that
element may differ. Any other line in the report is a finding. A tolerance is never loosened to pass.

`pdf-measure.py check FORM PDF` covers the no-twin cases:
- on each hand-written row with a value, either no dot or at least five; the last dot's box edge on the dotted rows' left
  edge within 0.1 pt; the box white from the value to the first dot between 0.7 and 0.9 pt (approved 0.79 to 0.80);
- no two words of a row closer than 1 pt, except a value and its leader; on the certificate, at least 7 pt before each left
  label.
`sick` compared with `female` differs only in the circle fills. `empty` compared with `female` keeps every hand-written
label within 0.1 pt.

Before the first compare, the runner checks that Chromium in `hrms-php` is 156.0.8078.79 and that the font sha256 values
match section 4. If not, it reports and stops.

### What the proof cannot show

- That the approved renders match the company's forms. They are what the owner approved. Their known differences from the
  company forms are approved, not defects. The certificate has no true company render (no Word on this machine).
- Ink. Word boxes are Poppler glyph boxes. The raster diff sees ink changes but does not name the element.
- Correct pairing after a large reflow. Repeated words pair nearest first. The page count, the "only in" lists and the raster
  count are the backstop.
- Fonts beyond their names and embedding.
- Values outside the scenario set. Contrived values can reach two pages (section 12).
- A different Chromium. A rebuilt image may move glyphs, and the compare reports it as a difference, rightly.
- What the owner saw. The owner saw screenshots at 110 to 150 dpi. The compare reads the PDFs, which is stricter.

## 10. Slices and gates

One PR into `main`. The controller commits each verified unit without a rebase. One backend runner at a time.

Slice 1, foundation and the leave request:
1. The smoke lever: `CASES` with every case except `long` and `halves-full`, `HRMS_SMOKE_FORM` with `HRMS_SMOKE_CASE`,
   `HRMS_SMOKE_TODAY`, pinned IDs, `sick` seeded, page count, font allow-list, the phrase matcher, `scripts/pdf-measure.py`
   run by `/usr/bin/python3`, the golden compare. The `long` cases are cut from `LIMITS`, so they wait for steps 3 and 6.
   Check, run by the build agent before any view changes: on the old views, the smoke writes both PDFs of `female`, `male`,
   `casual`, `sick` and `unpaid` and exits 1 at the font allow-list, because the old views embed IBM Plex. The smoke stops
   at its first failure and the allow-list comes before the compare, so a hand `pdf-measure.py compare` of the `female`
   render against its twin proves the compare instead. It exits 1 and lists the font change.
2. Layout version: `templateVersion()` returning 1 for every type, the three gates and the render check, their tests. Check:
   the full suite.
3. `ValueSize`, `ArabicFormDate::spaced()`, printable limits with the 422s, `NOTE_MAX`, the frontend maxima, the balance before
   this leave, each with its tests, and the leave `long` case in `CASES`. Check: PHPUnit and vitest.
4. The shell, the fonts, the six components, the leave view and data, the leave request at version 2, `HrFormViewsTest`,
   `HrLeaveRequestFormTest`. Check: PHPUnit, pint, PHPStan, deptrac; the 32 leave twins identical; `sick`, `empty` and
   `leader-*` pass `check`; one page everywhere.

Each slice has one build agent. It works through the slice's steps in order, writes each step's tests first, and hands back
the files of each step. One independent verifier, which changes nothing, then runs every step's check and the plan's ten
lanes. The controller commits each step by explicit path as its own commit. Steps that edit the same file share one commit,
and the plan names them. A fix round is a fresh build agent with the consolidated scope, then the verifier again. One capture
agent makes the images before each gate.

Owner gate, slice 1 (images labelled in Arabic): approved beside new for `female`, `male`, `unpaid`, `escalate` signed and
`long/1`, each with its measured result («مطابق حتى 0.1 نقطة، صفر بكسل»); the new `sick` and `empty` copies; the old-rows
change of section 6 in plain words.

Slice 2, the experience certificate:
5. `ArabicServiceDuration::format` and its unit test.
6. The certificate view and data at version 2, `HrExperienceCertificateTest`, and the certificate `long` case and
   `halves-full` in `CASES`. Check: as step 4, for the 14 certificate twins,
   the 32 leave twins again, and `halves-full`.

Owner gate, slice 2: approved beside new for `female`, `male`, `archived-male` and `long/1`, unsigned and signed, with the
measured results.

Closing: `docs/hrms-module-plan.md` Step 3 status, the smoke paragraph in `backend/CLAUDE.md`, the deploy note in the PR body,
the `code-review` and `security-review` skills, then the PR.

## 11. Alternatives rejected

- Absolute placement from a measured geometry table. The approved renders move later bands down when a value wraps
  («الوظيفة» y 472.58 on `female`, 504.83 on `long/0.45`, 506.33 on `long/1-wrap`) and let a long value push its own label
  (cx −99.76 on `female`, −70.80 on `everyday`). PHP cannot measure shaped Arabic, so absolute y would overlap neighbours.
- Geometry as a JSON table with flow for y (x anchors in JSON, a parser class, Blade parts reading it, a checker reading the
  same file). The goldens already compare every element at 0.1 pt, so the table serves only the no-twin cases. It adds a
  parser, an untyped element bag that every view author must learn, and a second place for gendered labels.
- Keeping the version 1 views and rendering each row in its own layout. Section 6 gives the reasons.
- One JSON file of printable limits read by PHP and vitest. It made the Leave module import the Pdf module while Pdf already
  imports Leave. The limits stay as constants, Leave owns the note limit, and the frontend keeps its hand mirror.
- A test that parses the wording-tables Markdown. The tables are approved and frozen, and the twin compare covers both genders.
- Render, count pages and step the size down in the job. It would remove the heuristic and the two-page limit, but it changes
  which copies print at which size (a visible difference) and runs up to five renders per long copy. It is an owner choice.

## 12. Risks and known limits

Risks:
- Blade components can add whitespace between inline boxes, and one stray space moves a label. The components are written
  without whitespace between tags. The first compare catches it.
- The data must equal the twins' (dates, IDs, fixture strings, serials). A mismatch shows as changed text and is fixed in
  `CASES`, never by tolerance.
- `text-wrap: pretty` and `text-justify: inter-character` depend on the Chromium version. The version check before the first
  compare makes a rebuilt image visible.
- The derived leader count could render differently from the approved 30 periods. The compare decides.

Known limits, accepted:
- Contrived values (60-letter tokens, runs of «@», the widest letters) can reach two pages. Production checks no page count.
- `RenderPdf` reads about 730 KB of the three new fonts for every form, the advance and overtime included, until Step 4c.
- The advance PDF still embeds DejaVu for Latin (Step 4).
- An old copy issued before this PR keeps its old look, and HR generates a new one to sign it (section 6).
- `pdf-measure.py` needs Poppler and a Python with Pillow on the host. Without Pillow it prints "RASTER not run", claims
  nothing about pixels and exits 1.

## 13. Decisions for the controller

1. The old-rows rule of section 6, told to the owner in Arabic at the slice 1 gate.
2. Decided 2026-11-18: the 46 approved PDFs stay out of the repo. They are 5,692,073 bytes against a 7.67 MB pack, for a
   one-time proof tied to one Chromium build. The PR ships `scripts/pdf-measure.py` and the smoke's `HRMS_SMOKE_GOLDENS`
   switch, and its body summarises the compare saved under NOTES. A reviewer without NOTES cannot rerun the pixel compare.
3. No shared limits file. Leave owns `NOTE_MAX`, and the frontend mirrors the leave maxima by hand, checked by its `SPEC` table.
