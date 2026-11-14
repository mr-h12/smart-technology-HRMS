# إعادة بناء الـPDF: شكل الشركة وطريقة عمل واحدة

## ملخص للمالك
- الجولة دي فيها ٤ ملفات PDF: شهادة الخبرة، طلب الإجازة، طلب السلفة، طلب العمل الإضافي.
- التسعة الباقيين يتحذفوا من الكود ويتبنوا من جديد مع الموديول بتاعهم.
- طريقة واحدة لكل PDF زي الـCRM: «إنشاء» ثم «عرض» و«تنزيل PDF». مفيش طباعة من المتصفح.
- كل PDF في مكانه + صفحة «النماذج الصادرة» لـHR.
- كل شكل بيتعرض عليك جنب نموذج الشركة قبل ما يتثبت.
- الشغل ٤ خطوات، ومفيش خطوة بتبدأ قبل ما توافق على اللي قبلها:
  1. **التنظيف والأساس:** حذف التسعة، طريقة واحدة في الباك إند، واختيار الخط وجدول تصحيح الإملاء.
  2. **طريقة العمل في الواجهة:** نفس اللوحة (إنشاء ← عرض ← تنزيل) في كل الأماكن، نسخة مدير الموقع من السلفة، وصفحة «النماذج الصادرة».
  3. **نماذج الشركة الرسمية:** طلب الإجازة وشهادة الخبرة مطابقين لورق الشركة.
  4. **النماذج المصمَّمة:** طلب السلفة وطلب العمل الإضافي بطابع الشركة.

## Context
The owner rejected the 13 HR form PDFs merged in PR #61 (2026-11-13): layouts
do not match the company's forms, each form works differently (advance uses
`window.print`, the rest generate + download, none can be viewed), and one
could not be found. Owner's rules: layout matches `docs/templates/`; one
uniform method taken from the CRM's Pdf module; each PDF in its right place;
each PDF has View and Download PDF; forms whose module has not started wait.

## Decisions settled with the owner (2026-11-13)
1. Scope = `experience_certificate`, `leave_request`, `advance_request`,
   `overtime_request`.
2. Delete the other 9 types from the code now (late_permit,
   early_leave_request, resignation, warning_notice, sim_receipt, job_offer,
   end_of_service, mission_permit, leave_permit). Rebuilt later with their
   module. Clearance and payslip stay unbuilt.
3. Flow as in the CRM: Generate, then View (in-app dialog) and Download PDF.
   No browser print; `AdvancePrint` goes.
4. Site manager gets the same advance PDF without the salary line.
5. Places: certificate on the employee file (Documents tab); leave request in
   the leave dialog; advance + overtime on Attendance requests (advance also
   on the site-day row); plus one HR page «النماذج الصادرة».
6. Serial stays, small, in the margin. A4 for everything.
7. The company's wording exactly; only obvious spelling fixed (owner approves
   the correction table).
8. Horizontal STIS logo only on advance and overtime (copy
   `/Users/yousefhasabo/Projects/crm-system/crm/resources/pdf/letterhead/logo.png`,
   1600x351). Certificate keeps the company letterhead; leave request keeps
   the text «Smart Technology».
   Amended by the owner on 2026-11-14: the round "ST" logo in the certificate's
   letterhead is not the company's logo. The certificate carries the STIS
   logo instead, and the watermark of the round logo goes with it.
9. Advance and overtime are designed in the company's framed style; owner
   approves each from a rendered image.
10. An experience certificate may be issued for an archived employee.

## Design

### Data shape
- **Type registry.** `PdfType` enum (4 cases) + one `PdfDocument` class per
  type carrying everything that varies: typed fields, snapshot builder and
  reach check, view + data, signer rule, own-scope flag, pay gate, file name.
  One `match` (`PdfExports::document`) replaces five branch sites.
- **Generation record.** Keep `hr_form_exports` unchanged (it already has
  status, stage, serial, snapshot, CHECKs). No `pdf_generations` table.
- **Module.** `git mv app/Modules/Documents app/Modules/Pdf` with the CRM's
  names where a file exists anyway: `Domain/Contracts/PdfRendererInterface`,
  `Infrastructure/BrowsershotPdfRenderer`, `Infrastructure/FilePdfAssets`,
  `resources/pdf/{fonts,letterhead}`, `resources/views/pdf/`. Skipped (one
  implementation, no second caller): assets interface, repository interface,
  DTO/events, `config/pdf.php`, `PrivateFileResponse`.

### One API (replaces `backend/routes/api.php` lines 55-111; 18 routes go)
| Route | Permission | Does |
|---|---|---|
| `GET hr-form-exports` | `hr_forms.view` | paged list; filters type, subject, employee, stage, status, awaiting_approval, q; each row has `file_name` and server-computed `actions` |
| `POST hr-form-exports` | `hr_forms.generate` | `{type, subject_id, idempotency_key, fields}` → 202; locks subject, supersedes the latest unsigned row, next serial, CREATED audit |
| `GET hr-form-exports/{x}` | `hr_forms.view` | one row (polling) |
| `GET hr-form-exports/{x}/download` | `hr_forms.view` | the bytes; View and Download both read it as a blob |
| `POST hr-form-exports/{x}/approve` | approve permissions | the type's signer rule decides |
| `POST hr-form-exports/{x}/retry` | generate/approve | failed rows only |

Pay gate: an advance row made with salary is invisible (list, show, download,
retry) to callers without `employee.view` at scope All.

### One frontend
- `components/FileViewer.tsx`: extracted from `ViewerFile` in
  `pages/employee-documents.tsx` (~347-402); blob → iframe + Download.
- `components/PdfPanel.tsx` `({ type, subject })`: Generate form, 3 s polling
  while pending, then View / Download, Approve / Retry only from `actions`.
  Mounted in `employees.tsx`, `leave-dialogs.tsx`, `attendance-requests.tsx`,
  `site-day-row.tsx`.
- `IssuedFormsPage` at `/hr-forms` (nav «النماذج الصادرة», `hr_forms.view.all`):
  serial, type, employee, stage, status, issued at, View / Download / Approve /
  Retry; filters incl. «بانتظار اعتمادي». It replaces `FormApprovalsPage`.
- Deleted: `pages/manual-hr-forms.tsx`, `pages/attendance-request-pdf.tsx`,
  `pages/leave-request-pdf.tsx`, `CertificateDocuments`,
  `components/AdvancePrint.tsx`.

### Layouts
- One shell `resources/views/pdf/page.blade.php` with two paper families:
  **frame** (thick + thin page frame, centred underlined title; leave,
  advance, overtime) and **letterhead** (company letterhead + footer images,
  docx margins 2.79 / 1.0 / 2.54 / 1.25 cm; certificate).
- Serial as a small grey fixed element in the margin.
- Fonts decided by a bake-off against the official leave PDF, not by argument
  (IBM Plex goes). Start with fonts already on disk (CRM's Noto Naskh Arabic);
  any font download is asked first with file, source and size.
- Reference renders of the Word files via
  `soffice --headless --convert-to pdf`; `scripts/pdf-compare.sh` produces
  side-by-side + overlay PNGs and an anchor-position table for each gate.
- Existing rows of deleted types stay in the table untouched (no migration).
  Already-issued files are never re-rendered.

## Four steps (owner rule 2026-11-13: no step starts before the previous one is approved)
Each step = one branch `feature/pdf-step-N-…`, one PR into `main`, one gate
report in Arabic. The PR merges and the next step starts only on the owner's
explicit approval. Inside a step, tasks are separate commits built by
separate subagents; tasks marked ∥ run in parallel.

### Step 1: cleanup and foundation (backend)
| Task | What | Check |
|---|---|---|
| 1a ∥ | Delete the 9 postponed forms (classes, views, routes, tests, UI, i18n, job-offer nav); record the decisions in `docs/hrms-module-plan.md` and `docs/client-questions.md` | `git grep` for the 9 slugs is empty; suites green |
| 1b ∥ | Prep, no product code: `soffice` reference renders, `scripts/pdf-compare.sh`, font bake-off sheets, spelling-correction and gender-wording tables | sheets and tables exist |
| 1c | Reshape into `app/Modules/Pdf` around the registry, routes and tests unchanged | tests change imports only; smoke text equals trunk |
| 1d | The six routes; delete the three per-form controllers; archived-employee certificate; `PdfApiTest`; point the existing frontend components at the new API | `route:list` shows six + signatures |

**Gate 1, owner approves:** the four forms still generate and download on the
new API; the nine are gone; the owner picks the font and approves the
spelling and gender tables.

### Step 2: one working method in the UI
| Task | What | Check |
|---|---|---|
| 2a ∥ | Extract `components/FileViewer.tsx` | employee documents viewer unchanged |
| 2b | `components/PdfPanel.tsx` mounted on the employee file, leave dialog and attendance requests; old per-form components deleted | Generate → View → Download at each place |
| 2c ∥ | Site manager's advance copy without salary (grant `hr_forms.generate.own` / `view.own` to `manager`), panel on the site-day row, delete `AdvancePrint` | no `window.print`; manager PDF has no «راتب»; HR's copy is 404 for the manager |
| 2d ∥ | «النماذج الصادرة» page replaces form approvals; `CONTEXT.md` term **Issued form** | filters, CEO approves from the page |

**Gate 2, owner approves:** screenshots (375 / 1440, ar / en) of the same
panel at every place, the viewer dialog, the issued-forms page, and the
manager's and HR's advance PDFs (still in the old layout).

### Step 3: the company's official forms
| Task | What | Check |
|---|---|---|
| 3a | Shell `pdf/page.blade.php`, chosen fonts, serial in the margin, leave request rebuilt against `طلب اجازة.pdf` | one page; fonts embedded |
| 3b | Experience certificate rebuilt against `شهاده خبرة.docx` | one page; letterhead as of 2026-11-14 (STIS logo, no round logo or watermark) |

**Gate 3, owner approves:** side-by-side and overlay images of each form
against the company's (male / female, unsigned / signed, long values).

### Step 4: the forms we design
| Task | What | Check |
|---|---|---|
| 4a ∥ | Advance request in the framed company style with the STIS logo, HR copy and manager copy | one page; salary line only on HR's copy |
| 4b ∥ | Overtime request in the same style | one page |
| 4c | Remove the old layout, `attendance-request-css` and IBM Plex | `grep "pdf.layout"` empty |

**Gate 4, owner approves:** rendered design images; loop until approved.

## How it runs
- Main session plans, dispatches and reviews only. Every slice is built by a
  fresh Opus 5.5 subagent (`model: "opus"`) that loads ponytail, Karpathy
  guidelines, `superpowers:test-driven-development` and
  `docs/coding-standards.md` (UI slices also `ui-ux-pro-max`); a second Opus
  subagent reviews (`code-review`, plus `security-review` for tasks 1d and 2c)
  and captures the gate with `verify-hrms`. Parallel tasks use the Workflow
  tool. After each gate report the session stops and waits for the owner.
- First step after approval: save this plan to
  `docs/superpowers/plans/2026-11-13-uniform-hr-pdfs.md` and the data shape +
  API as `docs/superpowers/specs/2026-11-13-uniform-hr-pdfs-design.md`.
- Gate reports to the owner in Arabic, images under `.claude/gate-pdf-N/`.
  `CLAUDE_HANDOFF.md` is never staged. Stop and hand off at ~35% context.

## Verification
- Backend: `docker compose exec app php artisan test`, `vendor/bin/pint --test`,
  `vendor/bin/phpstan analyse`, deptrac; then `docker compose restart worker-pdf`.
- Frontend: `npm run test && npm run lint && npm run build`.
- Real render: `scripts/smoke-hr-forms.sh` per form; one page at maximum field
  length; `pdffonts` shows only the chosen families, all embedded.
- App: `verify-hrms` capture as hr / ceo / manager at 375 and 1440, ar and en.
- Guards: `git grep "window.print\|AdvancePrint" frontend/src` empty (PR7);
  `git grep` for the 9 deleted slugs empty (PR1);
  `php artisan route:list --path=hr-form` shows six routes + signatures (PR4);
  manager's advance PDF has no «راتب» and HR's copy returns 404 to the manager.

## Risks
- No open font matches Arial / Times New Roman: settled by the P0 bake-off
  before any layout code.
- Chromium cannot justify with kashida: shown to the owner at P0.
- Jobs queued under the old job class fail after PR3 deploys: drain the `pdf`
  queue first.
- Pay leak to a site manager: negative tests on list, show, download, retry.

## Appendix: references for the executing session (verify before relying on line numbers)

### Class mapping (task 1c / 1d)
| Today (`backend/app/Modules/Documents/`) | Becomes (`backend/app/Modules/Pdf/`) |
|---|---|
| `Application/ExperienceCertificates.php` (shared store for all forms) | `Application/PdfExports.php` + `Application/Types/ExperienceCertificatePdf.php` |
| `Application/LeaveRequestForms.php`, `AttendanceRequestForms.php` | `Types/LeaveRequestPdf.php`, `Types/AdvanceRequestPdf.php`, `Types/OvertimeRequestPdf.php` |
| `Application/RenderHrForm.php` (job, queue `pdf`, 3 tries, 75 s) | `Application/RenderPdf.php` |
| `Domain/FormRenderer.php` | `Domain/Contracts/PdfRendererInterface.php` |
| `Infrastructure/BrowsershotRenderer.php` | `Infrastructure/BrowsershotPdfRenderer.php` |
| three per-form controllers | `Presentation/PdfController.php` |
| `SignatureImages`, `SignatureController` | moved unchanged |
| `ManualFormDefinitions`, `ManualHrForms`, `EndOfServiceAmounts`, `ManualHrFormController` | deleted in task 1a |
| `isUnsignedOnly`, `standardPraise`, the `praise` input, `experience-certificate/defaults` route | removed |

Contracts: `enum PdfType` (`approvePermission(): ?string`, `ownScope(): bool`,
`fileName(int $serial, string $stage): string`); `interface PdfDocument`
(`rules()`, `snapshot($subjectId, $fields, ?$scope, bool $showsPay)`,
`subjectColumn()`, `view(array $snapshot)`, `approval($export, $actor): ?string`).
Subject id lives in `employee_id` (certificate) or `snapshot->leave_request_id`
/ `advance_request_id` / `overtime_request_id`. `showsPay` = `employee.view`
at scope All (today `AttendanceRequestForms::canViewPay`). Old advance rows
without a `shows_pay` key count as pay-bearing.

### Deletion list (task 1a)
- Backend: the 4 manual classes above; 9 views in
  `backend/resources/views/hr-forms/` (early-leave-request, end-of-service,
  job-offer, late-permit, leave-permit, mission-permit, resignation,
  sim-receipt, warning-notice); 9 tests `backend/tests/Feature/Hr{LatePermit,
  EarlyLeaveRequest,Resignation,WarningNotice,EndOfService,JobOffer,SimReceipt,
  MissionPermit,DeparturePermit}FormTest.php`; routes `api.php` ~59-71;
  `lang/{ar,en}/validation.php` keys `end_of_service_*`; manual branches in
  `RenderHrForm`, `ExperienceCertificates::retry/history`,
  `ExperienceCertificateController`; their cases in
  `backend/tests/Smoke/render-hr-form.php` and `scripts/smoke-hr-forms.sh`.
- Frontend: `pages/manual-hr-forms.tsx` + test; mounts in `App.tsx`,
  `components/layout/AppLayout.tsx` (job-offer nav), `pages/employees.tsx`;
  cases in `pages/employees.test.tsx` (~1150-1360); `hrForms.manual.*` keys.
- `AdvancePrint` goes in task 2c with its replacement:
  `components/AdvancePrint.tsx`, mounts in `pages/attendance-requests.tsx` and
  `pages/site-day-row.tsx`, the print test, keys `attendanceRequests.print`,
  `advanceForm`, `paper.*`.

### Frontend today
- Bearer token auth; `apiBlob` in `frontend/src/lib/api.ts`. Mount points:
  `pages/employees.tsx` ~523-525, `pages/leave-dialogs.tsx` ~224,
  `pages/attendance-requests.tsx` ~141-156, `pages/site-day-row.tsx` ~166.
- `pages/hr-forms.tsx`: `CertificateDocuments`, `FormApprovalsPage`,
  `SignaturePage` (stays), download helper lines 16-23.
- Permissions: `hr_forms.generate.all` (hr, developer), `hr_forms.view.all`
  (hr, ceo, developer), `hr_forms.approve.all` (ceo),
  `hr_forms.approve_leave_request.all` (hr, ceo), `hr_signatures.manage.own`.
  `ViewAs::BLOCKED` strips approve / signature.

### CRM reference (read-only): `/Users/yousefhasabo/Projects/crm-system/crm/`
`app/Modules/Pdf/` (renderer, `FilePdfAssets` base64 fonts + logo, ID-only job
with timeout between Browsershot's and `retry_after`, `failed()` → give up,
writes only while the row is queued); `resources/pdf/fonts/*.woff2` (Noto
Naskh Arabic 400/700, Inter) and `resources/pdf/letterhead/logo.png`;
frontend `resources/js/components/FileViewerDialog.vue`,
`components/pdf/QuotationPdfPanel.vue` (3 s polling). Do not copy: two
parallel stacks, `PageFill`, language picker, auto-generation, virus scan.
Gotcha: a Chrome `footerTemplate` cannot carry base64 fonts.

### Official leave request (`docs/templates/طلب اجازة.pdf`)
Thick-outer + thin-inner frame ~8.5 mm from the edge; «Smart Technology»
top-left, Calibri Bold underlined; title «نموذج طلب إجازة» ~20pt bold in a
scroll-shaped box; «مدير الشركة» right, bold underlined; «تحية طيبه وبعد،،،»
centred; «مقدم لسياتكم / ……»; «بيانات منح الطلب» centred underlined; «نوع
الإجازة (أعتيادية ○ - عارضة ○ -مرضي ○ )»; paragraph «برجاء من سياتكم الموافقة
على منحى أجازة لمدة (……يوم) ابتداء من يوم…… الموافق / / 20م الى يوم ……
الموافق / / 20م وذلك لظروف ……»; «وتفضلوا سياتكم بقبول فائق الأحترام،،،»
centred; five-line block left-centre (توقيع مقدم الطلب/ الوظيفة / القسم/
طريقة التبليغ/ مدير القسم/); 4x2 thin table (الاستعلام عن رصيد الاجازات / رأى
مدير القسم / اعتماد مدير القسم / توقيع الموظف الذى ينوب العمل); bottom «توقيع
مدير الشركة» right, «توقيع الموارد البشرية» left, dotted lines. Arial Bold.
Keep «بدون أجر» only when the leave is unpaid.

### Official experience certificate (`docs/templates/شهاده خبرة.docx`)
A4; letterhead image behind text + footer band (already in
`backend/resources/hr-forms/certificate-letterhead.png`, `certificate-footer.jpg`,
byte-identical); margins top 2.79, right 1.0, bottom 2.54, left 1.25 cm; title
«شهـــادة خبرة» 18pt bold underlined Times New Roman; «اليوم: … التاريخ: …»
18pt bold; body 15pt bold Arial, justified, double spacing: «هذه الشهادة مقدمة
من شركة/ … بأن الموظف المذكورة بياناته أدناه»; two two-field lines (أسم
الموظف/ + المسمي الوظيفى/; بطاقة رقم قومي/ + قسم/); ONE paragraph «بأنه انضم
للعمل بوظيفة … لدى فترة زمنية تجاوزت … وهو من أكفاء الموظفين…»; «تم تحرير هذه
الشهادة فى يوم … الموافق … بناء على رغبة الموظف دون أدنى مسئولية من الشركة.»;
«مدير الشركة» 17pt bold far left.

### Assumptions to confirm at the gates
- Site managers get the advance PDF only (no leave PDF), as today.
- On the advance form «سلفة سابقة» and finance remarks are optional and print
  blank (Gate 4).
- The certificate's praise is the fixed company paragraph, gender-worded.
- Manager reach is checked on the attendance request's date.
- `layout.blade.php:12` disables `liga/calt/rlig` for `pdftotext`; test «لا»
  both ways in task 1b and normalise in the smoke script instead.
- Font candidates (OFL): Noto Naskh Arabic (from the CRM, no download), Amiri,
  Noto Sans Arabic, Carlito. Ask the owner before any download (file, source,
  size).
