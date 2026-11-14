# Uniform HR PDFs, Step 1: data shape and the one API (design)

Date: 2026-11-13. Implements Step 1 (tasks 1c and 1d) of the owner-approved plan
`docs/superpowers/plans/2026-11-13-uniform-hr-pdfs.md`. Line numbers below are as of `main` at `9d0cd2a`; task 1a (deleting the
nine postponed forms) runs in parallel and shifts some of them. Paths are under `backend/app/Modules/` unless they start with
`backend/`, `frontend/` or `docs/`.

## Scope and settled decisions
- **Four types:** `experience_certificate`, `leave_request`, `advance_request`, `overtime_request` (owner decision 1). The nine
  others are deleted from the code in task 1a (decision 2); this spec never mentions them again except to filter their rows out.
- **No new table.** `hr_form_exports` and `hr_form_serial_counters` stay exactly as created by
  `backend/database/migrations/2026_11_12_000001_create_hr_form_exports.php` (stage and status CHECKs, unique
  `(form_type, serial_number, stage)`, unique `(requested_by, idempotency_key)`, unique `unsigned_export_id`, unique
  `regenerated_from_id`). No migration in Step 1.
- **Old rows of deleted types stay** untouched. Nothing lists form types in the schema, so every query filters
  `form_type IN (the four values)`; a row of another type is `404 not_found` on every route. `PdfType::from()` is never called on an
  unfiltered row (use the filter, or `tryFrom()` and 404).
- Already-issued files are never re-rendered; the storage path stays `hr-forms/{id}.pdf` on the `local` disk.
- An experience certificate may be issued for an archived employee (decision 10).

Glossary (`CONTEXT.md` § HR forms): **Form serial**, **Form snapshot**, **Signature image**. A row of `hr_form_exports` is an
*export*; its *subject* is what it was made from (below).

## Task split
- **1c (reshape, no behaviour change):** `git mv app/Modules/Documents app/Modules/Pdf`, build the registry below, move the
  per-type code out of `ExperienceCertificates`, `LeaveRequestForms`, `AttendanceRequestForms` and `RenderHrForm` into the four
  `PdfDocument` classes and `PdfExports`. The three per-form controllers and the routes stay; they call the new classes with
  today's arguments (the certificate's `regenerated_from_id` and `praise` still pass through). Tests change imports only; the
  real-render smoke text equals trunk (`.claude/gate-pdf-1/baseline/`).
- **1d (the one API):** `PdfController` with the six routes; delete the three per-form controllers and the routes in "Removed";
  apply every change in "Behaviour changes in 1d"; `PdfApiTest`; re-point the three frontend components.

## Data shape

### Where each class lives (deptrac: Presentation → Application, Domain; Application → Infrastructure, Domain; Domain → nothing)
| Class | File (under `Pdf/`) | Replaces (under `Documents/`) |
|---|---|---|
| `enum PdfType` | `Domain/PdfType.php` | the form-type strings and constants (`FORM`, `ADVANCE`, `OVERTIME`) |
| `interface PdfRendererInterface` (`pdf(string $html): string`) | `Domain/Contracts/PdfRendererInterface.php` | `Domain/FormRenderer.php` |
| `interface PdfDocument` | `Application/Types/PdfDocument.php` | (new) |
| `ExperienceCertificatePdf`, `LeaveRequestPdf`, `AdvanceRequestPdf`, `OvertimeRequestPdf` | `Application/Types/*.php` | per-type parts of `ExperienceCertificates`, `LeaveRequestForms`, `AttendanceRequestForms`, `RenderHrForm::html()` |
| `PdfExports` | `Application/PdfExports.php` | `ExperienceCertificates` (the shared store) + the controllers' gates |
| `RenderPdf` (job) | `Application/RenderPdf.php` | `Application/RenderHrForm.php` |
| `SignatureImages`, `SignatureController` | same names, moved unchanged | same |
| `BrowsershotPdfRenderer` | `Infrastructure/BrowsershotPdfRenderer.php` | `Infrastructure/BrowsershotRenderer.php` (body unchanged) |
| `FilePdfAssets` | `Infrastructure/FilePdfAssets.php` | `RenderHrForm::asset()` / `dataUrl()` (data URLs of fonts and images) |
| `PdfController` (1d) | `Presentation/PdfController.php` | the three per-form controllers |

`backend/app/Providers/AppServiceProvider.php:29` binds `PdfRendererInterface` to `BrowsershotPdfRenderer`. Resources move with the
module: `resources/views/hr-forms/*` → `resources/views/pdf/*` (view names `pdf.*`, e.g. `pdf.layout`), `resources/hr-forms/` fonts
and licence → `resources/pdf/fonts/`, `certificate-letterhead.png` and `certificate-footer.jpg` → `resources/pdf/letterhead/`
(bytes unchanged). Not created (owner plan): an assets interface, a repository interface, DTOs/events, `config/pdf.php`,
`PrivateFileResponse`.

### `enum PdfType: string` (Domain, no Illuminate import)
| Case | Value (`form_type`) | `approvePermission(): ?string` | `ownScope(): bool` | `fileName(int $serial, string $stage): string` |
|---|---|---|---|---|
| `ExperienceCertificate` | `experience_certificate` | `hr_forms.approve` | false | `experience-certificate-{serial}-{stage}.pdf` |
| `LeaveRequest` | `leave_request` | `hr_forms.approve_leave_request` | false | `leave-request-{serial}-{stage}.pdf` |
| `AdvanceRequest` | `advance_request` | null (never signed) | true | `advance-request-{serial}-{stage}.pdf` |
| `OvertimeRequest` | `overtime_request` | null (never signed) | false | `overtime-request-{serial}-{stage}.pdf` |

`fileName` is today's download name (`ExperienceCertificateController.php:79`, `frontend/src/pages/hr-forms.tsx:20`):
`str_replace('_', '-', value)."-{$serial}-{$stage}.pdf"`. `ownScope()` says whether a caller holding the route's `hr_forms`
permission at own scope only may use the type at all (on employees in their reach). No role holds an own-scope `hr_forms` grant
today; task 2c grants `hr_forms.generate.own` and `hr_forms.view.own` to `manager`.

### `interface PdfDocument` (Application; one class per type)
```php
public function rules(): array;            // Laravel rules for the keys of `fields`, without the `fields.` prefix
/** Locks the subject row, checks reach, builds the snapshot. @return array{0: string, 1: array<string, mixed>} [employee_id, snapshot] */
public function snapshot(string $subjectId, array $fields, ?string $scope, bool $showsPay): array;
public function subjectColumn(): string;   // a where() column: 'employee_id' or 'snapshot->leave_request_id' etc.
/** @return array{0: string, 1: array<string, mixed>} [view name, type-specific view data] */
public function view(array $snapshot): array;
/** The signer rule: the stamp line for the signed snapshot (null = none); throws the refusal when $actor may not sign. */
public function approval(stdClass $export, Authenticatable $actor): ?string;
```
`$scope` is the owner id of an own-scope caller (`RequestScope` semantics), null at all scope. `view()` holds today's per-type
branches of `RenderHrForm::html()` (`RenderHrForm.php:107-157`) verbatim; `RenderPdf` adds the common data (snapshot, serial,
signature, `feminine`, fonts) and checks `template_version === 1` as today.

### The four types
| | Experience certificate | Leave request | Advance request | Overtime request |
|---|---|---|---|---|
| Subject (`subject_id`) | the employee; `subjectColumn()` = `employee_id` | `snapshot->leave_request_id` | `snapshot->advance_request_id` | `snapshot->overtime_request_id` |
| Subject row locked | `employees` | `leave_requests` | `attendance_requests` with `type = 'advance'` | `attendance_requests` with `type = 'overtime'` |
| Subject missing, deleted or of the other request type | 404 | 404 | 404 | 404 |
| `fields` rules (today's validators) | `job_title`: required, string, max:160 | `job_title`: required, string, max:160; `notification_method`: nullable, string, max:160; `manager_opinion`: nullable, string, max:360; `substitute`: nullable, string, max:160 (null stored as `''`) | `job_title`: required, string, max:160; `reason`: required, string, max:300; `repayment_method`: required, in:receipts,cash,salary_deduction; `previous_advance`: required, boolean (stored as bool); `finance_remarks`: nullable, string, max:300 (null stored as `''`); `paper_decision_date`: missing | `job_title`: required, string, max:160; `reason`: required, string, max:300; `paper_decision_date`: missing |
| Rules today | `ExperienceCertificateController.php:34-39` (minus `praise`, `regenerated_from_id`) | `LeaveRequestFormController.php:20-30` | `AttendanceRequestFormController.php:27-42` | same file, non-advance branch |
| Snapshot (as today) | `name_ar, national_id, department, gender, hire_date, termination_date, job_title, issued_on`; 422 when `national_id`, `department`, `gender` or `hire_date` is empty (`errors.certificate_employee_data`) | `LeaveRequestForms.php:50-55` | `AttendanceRequestForms.php:58-71` + `shows_pay` | `AttendanceRequestForms.php:58-71` |
| Signer rule (`approval()`) | any holder of `hr_forms.approve`; returns null (no stamp line) | the leave request (locked) must be `approved`, else 409; its `decided_by` must be the actor, else 403; returns `hr` when `escalated_at` is null, else `company_manager` (`LeaveRequestForms.php:73-87`) | not signed | not signed |
| Own-scope callers | no | no | yes, employee in reach today | no |
| Bears pay | no | no | yes, unless the snapshot says `"shows_pay": false` | no |
| Archived employee | allowed | allowed (as today) | allowed (as today) | allowed (as today) |

`paper_decision_date: missing` keeps today's refusal (`AttendanceRequestFormController.php:36`) with the message
`errors.advance_paper_date_pending`, now on the field `fields.paper_decision_date`.

Advance in Step 1: a caller without `employee.view` at scope All is refused (403, as today
`AttendanceRequestFormController.php:26`); `snapshot()` throws it when `$showsPay` is false. Every advance row Step 1 creates has
`"shows_pay": true`. Task 2c lifts the refusal and builds the copy without the salary.

### `PdfExports` (Application)
- `static document(PdfType $type): PdfDocument`: the one `match`, resolving the class from the container. It replaces the branch
  sites `RenderHrForm.php:107-143`, `ExperienceCertificates.php:110,142,160,167`, `ExperienceCertificateController.php:57,60,124`,
  `AttendanceRequestForms.php:41-43,56-65,80-86`, `AttendanceRequestFormController.php:25-37,56-59`, `LeaveRequestForms.php:86`.
- `create`, `approve`, `retry`, `list`, `visible` (find one row with every read gate), `actions`, `find`, and the private
  `storeUnsigned` (today's `ExperienceCertificates::storeUnsigned`, `:72-90`: next serial from `hr_form_serial_counters` locked
  `FOR UPDATE`, insert `stage = unsigned`, `status = pending`, `template_version = 1`). `approve` keeps today's signed-row insert
  and `APPROVED` audit (`ExperienceCertificates.php:92-136`), including `impersonator_id` and the stamp-line keys (`stamp_line`,
  `decider_id`, `signature_version`) added only when the stamp line is not null.
- `canViewPay(Authenticatable)`: `employee.view` at scope All through `AuthorizeAction::decide` (today
  `AttendanceRequestForms.php:102-105`).
- Every 403 throws `AuthorizationRefused::of('<permission>')` (`Identity/Domain/Rbac/AuthorizationRefused.php`), never `abort(403)`.

### `RenderPdf` (Application, the job)
Today's `RenderHrForm` renamed, body unchanged except: `PdfRendererInterface` instead of `FormRenderer`, `FilePdfAssets` instead of
`asset()`, and `PdfExports::document(PdfType::tryFrom(form_type))->view($snapshot)` instead of the branches (an unknown type throws
"Unknown form template." as today). Input: the export UUID only. Redis connection, queue `pdf`, `afterCommit`, `$tries = 3`,
`$timeout = 75`; writes only while the row is `pending` (idempotent); stores the file, `sha256`, `size_bytes`, status `ready` and the
`GENERATED` audit; `failed()` sets `failed` / `render_failed` and logs `hr_form_render_failed` with the export id only.

## The one API (1d)
Six routes inside the `auth` group of `backend/routes/api.php`, replacing lines 55-94 and 102-112 (lines 95-100, the attendance
decide routes, stay). The signature routes (lines 49-53) are unchanged.

| Route | Middleware | Answer |
|---|---|---|
| `GET hr-form-exports` | `permission:hr_forms.view` | 200, page of rows |
| `POST hr-form-exports` | `permission:hr_forms.generate` | 202, the row (new or replayed) |
| `GET hr-form-exports/{export}` | `permission:hr_forms.view`, `whereUuid` | 200, the row |
| `GET hr-form-exports/{export}/download` | `permission:hr_forms.view`, `whereUuid` | 200, the PDF bytes |
| `POST hr-form-exports/{export}/approve` | `permission:hr_forms.approve,hr_forms.approve_leave_request`, `whereUuid` | 202, the signed row |
| `POST hr-form-exports/{export}/retry` | `permission:hr_forms.generate,hr_forms.approve,hr_forms.approve_leave_request`, `whereUuid` | 202, the row |

`permission:a,b` passes when the caller holds **any** of them (`Identity/Presentation/AuthorizePermission.php:29-36`), so approve
and retry re-check the type's own permission inside the use case (below). After each use case the controller dispatches
`RenderPdf` when the returned row is `pending` (today's `ExperienceCertificateController::queue`).

### The row (every JSON answer but the download)
```json
{
  "id": "0193f3aa-…", "type": "leave_request", "serial_number": 14, "stage": "unsigned", "status": "ready",
  "employee_id": "0193f2c1-…", "unsigned_export_id": null, "regenerated_from_id": "0193f39e-…",
  "approved_by": null, "approved_at": null, "impersonator_id": null,
  "failure_code": null, "sha256": "9c1e…", "size_bytes": 48211, "created_at": "…",
  "file_name": "leave-request-14-unsigned.pdf",
  "actions": ["download", "approve"]
}
```
Today's keys (`ExperienceCertificateController.php:132-138`) with `form_type` renamed `type`, plus `file_name`
(`PdfType::fileName`) and `actions`. Never the snapshot. `actions` ⊆ `download | approve | retry`, computed for the caller:
- `download`: `status = ready`.
- `approve`: the type has an approve permission, the caller holds it at All, the row is `unsigned` and `ready`, no row has it as
  `unsigned_export_id` or `regenerated_from_id`, and `approval()` does not refuse.
- `retry`: `status = failed`, and the caller passes the retry checks below.

`actions` replaces `is_decider` (`LeaveRequestForms.php:70`) and every client-side permission test for these buttons.

### `GET hr-form-exports` (list)
Query, all optional: `type` (one of the four; required with `subject_id`), `subject_id` (uuid), `employee_id` (uuid), `stage`
(`unsigned|signed`), `status` (`pending|ready|failed`), `awaiting_approval` (boolean), `q` (string, max 100), `page` (integer ≥ 1),
`per_page` (integer 1-100, default 25). Anything else invalid is 422 `validation_failed`.
- Always: `form_type IN` the four, `deleted_at IS NULL`.
- Pay gate: without `employee.view` at All, rows `form_type = 'advance_request' AND coalesce(snapshot->>'shows_pay', 'true') <> 'false'`
  are left out.
- Own scope (`RequestScope::ownerId` not null): only types with `ownScope()`, and `employee_id IN EmployeeReach::ids(owner, today)`
  (`Employees/Application/EmployeeReach.php:54`).
- `subject_id`: `where(document(type)->subjectColumn(), subject_id)`. `employee_id`, `stage`, `status`: equality.
- `awaiting_approval=1`: `unsigned`, `ready`, no successor (as `ExperienceCertificates::awaitingApproval`, `:165-173`), of the
  types whose approve permission the caller holds at All. The leave signer rule is not in the filter; `actions` tells.
- `q`: `serial_number = q` when q is digits, or `employee_id IN` the employees matching q through the `Employee::matching` scope
  (`name_ar`/`name_en` ilike, `code` when digits; no national ID).
- Order: `serial_number desc, stage desc, id desc` (the leave panel's `latest`, `leave-request-pdf.tsx:52`, relies on it).
- Answer: `ApiEnvelope::collection` with `meta.pagination` (`page, per_page, total, total_pages, has_next_page, has_previous_page`).

### `POST hr-form-exports` (create)
Body: `{"type": "advance_request", "subject_id": "<uuid>", "idempotency_key": "<uuid>", "fields": {…}}`. `type` required, one of
the four; `subject_id` and `idempotency_key` required uuids; `fields` required array, validated with `document(type)->rules()`
prefixed `fields.`; only validated keys are kept. Then, in this order:
1. Own-scope caller and `!type->ownScope()`: 403 `permission_denied`.
2. One transaction: lock the caller's `users` row (as today); a row with `(requested_by, idempotency_key)` is a replay: same type,
   same subject, `unsigned` and `array_intersect_key(snapshot, fields) == fields` → return it, else 409 `concurrency_conflict`.
3. `document(type)->snapshot(subject_id, fields, scope, canViewPay(caller))`: locks the subject row, 404 when missing or out of
   reach, 403 for an advance without pay (Step 1), 422 for missing certificate data.
4. Supersede: the latest (`serial_number desc`) `unsigned` row of the same type and subject (`subjectColumn()`), whatever its
   status, becomes the new row's `regenerated_from_id`. The subject lock serialises this, so the unique `regenerated_from_id`
   never collides.
5. `storeUnsigned` with the next serial of the type; then `AuditLog::record('CREATED', 'hr_form_exports', id, null, {form_type,
   serial_number, stage, regenerated_from_id, fields})`. Never the snapshot (it carries salary).
No `employee.scope` middleware: it reads `route('employee')` (`Employees/Presentation/EnsureEmployeeInScope.php:28`), which this
route has not, and its archived refusal (`:33`, 409 `employee_archived`) would stop the certificate. Reach lives in `snapshot()`.

### `GET hr-form-exports/{export}` and `/download`
`PdfExports::visible(id, caller, scope)`: 404 when the row is missing, soft-deleted, of an unknown type, pay-bearing for a caller
without pay (`canViewPay`), or (own scope) of a type without `ownScope()` or of an employee out of reach today. Download also 404s
unless `status = ready` and the file exists; it streams with `Content-Type: application/pdf`, `Content-Disposition` attachment
`file_name`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff` (today's headers). View and Download in the UI
both fetch this as a blob.

### `POST hr-form-exports/{export}/approve`
One transaction, in this order:
1. Find with lock; `visible` with the scope of the type's approve permission → 404.
2. Type without approve permission (advance, overtime): 409 `concurrency_conflict`.
3. Caller lacks the type's approve permission (`AuthorizeAction::decide`; view-as strips it, `Identity/Domain/Authentication/ViewAs.php:22`): 403.
4. Not `unsigned` + `ready`, or a regenerated successor exists: 409.
5. `approval(row, caller)`: leave 409 / 403 as in the table.
6. A signed row already points to it: return that row (replay).
7. No signature image: 422 `validation_failed` on `signature` (`errors.certificate_signature_required`).
8. Insert the signed row (same serial), `APPROVED` audit; answer the signed row.

### `POST hr-form-exports/{export}/retry`
Failed rows only. One transaction: find with lock; the permission needed is `hr_forms.generate` for an `unsigned` row and the
type's approve permission for a `signed` row; caller lacks it: 403; `visible` with that permission's scope: 404; `status` not
`failed`: 409; a `signed` row whose `approved_by` is not the caller: 409. Then `status = pending`, `failure_code = null` (no audit
row, as today; `GENERATED` follows the render). Who may retry:

| Row | Who | Today |
|---|---|---|
| unsigned, failed (any type) | holder of `hr_forms.generate` (own scope: advance only, employee in reach) | advance/overtime only (`retry-{form}`); certificate and leave could only regenerate |
| signed, failed (certificate, leave) | its approver, holding the type's approve permission | leave: its approver; certificate: any `hr_forms.approve` holder |

### Error codes (all existing)
| Code | Status | Source | When |
|---|---|---|---|
| `validation_failed` | 422 | `backend/bootstrap/app.php:65` | bad query or body; `fields` rules; certificate data missing; no signature image on approve |
| `permission_denied` | 403 | `AuthorizationRefused`, rendered at `bootstrap/app.php:91` | route permission missing; type's approve/generate permission missing; own scope on a type without `ownScope()` (create); advance without pay (create, Step 1); leave signer not the decider |
| `not_found` | 404 | `bootstrap/app.php:73` | missing or deleted row or subject; unknown type; pay gate; own scope out of reach or type not own-scope (read, approve, retry); download not ready or file missing |
| `concurrency_conflict` | 409 | `App\Support\Database\ConcurrencyConflict`, `bootstrap/app.php:104` | idempotency key reused with another payload; approve of an unsignable row or state; leave request not approved; retry of a row not failed or by another approver |
| `unauthenticated` | 401 | `bootstrap/app.php:77` | no session |

## Pay gate
An advance row made with salary is invisible to callers without `employee.view` at scope All: left out of the list, and 404 on
show, download and retry (today 403, `ExperienceCertificateController.php:124`, `AttendanceRequestFormController.php:57`). Pay-bearing
= `advance_request` and the snapshot's `shows_pay` is not `false`; no snapshot has the key today, so every existing advance row is
pay-bearing. Negative tests on list, show, download and retry.

## Archived employee
The certificate may be issued for an archived employee (owner decision 10; today the `employee.scope` middleware refuses it with 409
`employee_archived`). The other three types never checked and still do not. Reach for own-scope callers is checked in
`snapshot()` (create) and in `visible()` / the list filter (reads), never by the middleware.

## Behaviour changes in 1d
| Today | After 1d |
|---|---|
| Certificate regeneration needs the client's `regenerated_from_id` and refuses a second one (`ExperienceCertificates.php:49-55`) | the server supersedes the latest unsigned row of the type and subject, for all four |
| Certificate `create` locks only the caller's `users` row | also the subject row, as the others |
| `CREATED` audit only for manual forms | every create |
| `awaiting-approval` lists certificates only | `awaiting_approval` filter, every signable type |
| Leave history answers `is_decider` | `actions` on every row |
| Per-form retry rules | one rule (table above) |
| Advance rows: 403 to a caller without pay | 404 / left out |
| `abort(403)` answers without an error code | `permission_denied` |
| Certificate for an archived employee: 409 | allowed |
| `praise` input and `standardPraise` | the fixed gender-worded paragraph lives in `pdf.experience-certificate`; a snapshot that already has `praise` keeps printing it (`''` = none) |

## Removed in 1d
`praise` input, `ExperienceCertificates::standardPraise`, the `defaults` route, `AttendanceRequestForms::isUnsignedOnly`,
`ExperienceCertificateController`, `LeaveRequestFormController`, `AttendanceRequestFormController`, and the routes below
(`backend/routes/api.php` at `9d0cd2a`). 22 routes become six; 18 paths go.

| Old route (line) | New |
|---|---|
| `GET employees/{employee}/hr-forms/experience-certificate/defaults` (55) | removed |
| `POST employees/{employee}/hr-forms/experience-certificate` (57) | `POST hr-form-exports` `type=experience_certificate`, `subject_id` = employee |
| `GET hr-manual-form-definitions`, `POST`/`GET hr-forms/manual-candidate/{form}`, `POST employees/{employee}/hr-forms/manual/{form}`, `POST hr-form-exports/{export}/retry-manual/{form}` (59-71) | removed in task 1a |
| `GET employees/{employee}/hr-form-exports` (72) | `GET hr-form-exports?employee_id=` or `?type=experience_certificate&subject_id=` |
| `GET hr-form-exports/awaiting-approval` (74) | `GET hr-form-exports?awaiting_approval=1` |
| `GET hr-form-exports/{export}/download`, `GET hr-form-exports/{export}`, `POST …/approve`, `POST …/retry` (76-84) | kept paths, every type, new permissions as above |
| `POST leave/requests/{leaveRequest}/hr-forms/leave-request` (86) | `POST hr-form-exports` `type=leave_request` |
| `GET leave/requests/{leaveRequest}/hr-form-exports` (88) | `GET hr-form-exports?type=leave_request&subject_id=` |
| `POST hr-form-exports/{export}/approve-leave-request` (90) | `POST hr-form-exports/{export}/approve` |
| `POST hr-form-exports/{export}/retry-leave-request` (92) | `POST hr-form-exports/{export}/retry` |
| `POST attendance/requests/{id}/hr-forms/advance-request` (102), `…/overtime-request` (109) | `POST hr-form-exports` `type=advance_request` / `overtime_request` |
| `GET attendance/requests/{id}/hr-form-exports` (104) | `GET hr-form-exports?type=advance_request&subject_id=` (or `overtime_request`) |
| `POST hr-form-exports/{export}/retry-advance-request` (106), `…/retry-overtime-request` (111) | `POST hr-form-exports/{export}/retry` |

## Frontend re-pointing (1d, `frontend/src/pages/`)
Minimal changes; task 2b replaces these components with `PdfPanel`.
- `hr-forms.tsx`: `download(id, fileName)` takes the row's `file_name` (`:16-23`). `CertificateDocuments`: list
  `/hr-form-exports?type=experience_certificate&subject_id={employee}&page=` (`:37`); the `defaults` call, the praise textarea,
  `source` and the per-row regenerate button go (`:40-42,78-85,102-104`); create posts `/hr-form-exports` with
  `{type, subject_id, idempotency_key, fields: {job_title}}` (`:53`); Download and Retry show from `row.actions`.
  `FormApprovalsPage` lists `/hr-form-exports?awaiting_approval=1&type=experience_certificate&page=` (`:134`); approve and show
  keep their paths (`:140,:178`).
- `leave-request-pdf.tsx`: list `/hr-form-exports?type=leave_request&subject_id={id}&per_page=100` (rows from the collection,
  not `data.exports`); create as above with the four leave fields; Approve and Retry from `row.actions`, on
  `/hr-form-exports/{id}/approve` and `/retry` (`:74-79`).
- `attendance-request-pdf.tsx`: list `/hr-form-exports?type={type}_request&subject_id={id}&per_page=100` (`:17-18`); create
  (`:43`); retry `/hr-form-exports/{id}/retry` (`:83`), from `row.actions`.
- `useSave` refreshes the first path segment (`lib/query.ts:68`), so every write refreshes every `hr-form-exports` list.
- Update the exact URLs in `hr-forms.test.tsx` and `attendance-requests.test.tsx`; drop i18n keys left unused (`hrForms.praise`,
  `hrForms.cancel` if unused) from both locale files.

## Tests (1d)
`backend/tests/Feature/PdfApiTest.php`, plus the four `Hr*FormTest` files re-pointed (non-PDF tests in them stay as they are):
- `route:list --path=hr-form` shows exactly the six routes; `--path=hr-signature` shows the three signature routes.
- Create per type: 202, serial, `regenerated_from_id` set by the server, `CREATED` audit without salary; replay returns the same row;
  a changed payload with the same key is 409.
- Certificate for an archived employee: 202.
- Pay gate: a caller without `employee.view.all` (a role granted `hr_forms.view.all` only) gets no advance row in the list and 404
  on show, download and retry.
- Own scope: a type without `ownScope()` is 403 on create and 404 on read; an advance of an employee out of reach is 404.
- Approve: CEO approves a certificate; HR holding only `approve_leave_request` gets 403 on a certificate; approving an advance is
  409; a leave PDF signed by someone other than the decider is 403; view-as is 403.
- Retry: unsigned failed certificate by HR; signed failed leave by another approver is 409; a ready row is 409.
- `actions` for HR, CEO and a viewer without approve; a row of a deleted type is absent from the list and 404 by id.

## Deploy note
The job class changes (`RenderHrForm` → `RenderPdf`), and a queued payload names its class. Before deploying 1c: stop issuing forms,
check `select count(*) from hr_form_exports where status = 'pending'` is 0 and the Redis `pdf` queue is empty, deploy, then
`docker compose restart worker-pdf`. A row left `pending` is re-queued with `RenderPdf::dispatch($id)` in tinker. Old `failed_jobs`
payloads name the old class and cannot be retried with `queue:retry`; a `failed` row is retried through the API instead.

## Known gaps for task 2c (not fixed in Step 1)
- Leave PDF reach: `LeaveRequestForms.php:33,65` call `EmployeeService::show($id, $scope)`, which trims fields but checks no reach.
  Unreachable in Step 1 (`ownScope()` is false); if a type ever turns own-scope, its `snapshot()` must call `isManagedBy`.
- Manager's advance copy: the snapshot reads `$employee['pay_changes']` (`AttendanceRequestForms.php:61`), which
  `EmployeeService::show` returns only at all scope (`Employees/Application/EmployeeService.php:173`), and the 403 at
  `AttendanceRequestFormController.php:26` stays in Step 1. 2c lifts it, skips the salary when `$showsPay` is false and writes
  `"shows_pay": false`.
- Reach is checked against today (`AttendanceRequestForms.php:97`, `EmployeeService::isManagedBy` default date), not the request's
  date (owner plan assumption). Same in the list filter.

## Decisions made in this spec
Each keeps today's behaviour unless the owner plan says otherwise; the rejected alternative follows "not".
1. `ownScope()` is true for the advance only (plan: site managers get the advance PDF only); not overtime, which today's code would reach-check.
2. An unsigned failed row of any type is retryable with `hr_forms.generate` (plan: retry = generate/approve, failed rows only); not "certificate and leave regenerate only".
3. A signed failed row is retried only by its approver, for both signable types; not "any `hr_forms.approve` holder" for the certificate.
4. Approving a type that is never signed is 409 `concurrency_conflict` (today's certificate route answer); not 404.
5. Every 403 is `AuthorizationRefused` → `permission_denied`; not `abort(403)`, which answers without an error code.
6. Advance create without pay stays 403 in Step 1; not building the no-salary copy before 2c.
7. New advance snapshots carry `shows_pay` (always true in Step 1); not deriving it from the caller at read time.
8. No type refuses an archived employee; not adding a refusal to leave, advance or overtime.
9. Create keeps the `users`-row lock besides the subject lock (serialises same-key replays); not the subject lock alone.
10. `snapshot()` returns `[employee_id, snapshot]`; not a second lookup of the subject's employee in `PdfExports`.
11. The `CREATED` audit holds `form_type, serial_number, stage, regenerated_from_id, fields`; not the snapshot.
12. `type` replaces `form_type` in the JSON (the column keeps its name); not both keys.
13. Every list orders `serial_number desc, stage desc, id desc`; not `created_at desc` (today's employee history).
14. `per_page` defaults to 25 (today's history and `ExportPages`), max 100; the two request panels ask for 100 (their lists were unpaginated); not the project's usual 20.
15. `q` matches the serial or the employee through `Employee::matching` without national ID; not a new search rule.
16. `subject_id` requires `type`; not an OR over the four subject columns.
17. `awaiting_approval` filters by permission only, the leave decider rule shows in `actions`; not a per-type SQL hook on `PdfDocument`.
18. Own-scope lists filter with `EmployeeReach::ids(owner, today)`; not requiring a `subject_id`.
19. The certificate's fixed praise paragraph moves into the view; a stored `praise` still prints (a retry reproduces what was approved); not ignoring it.
20. Supersede takes the latest unsigned row of the type and subject whatever its status or approval (today's leave and attendance rule).
21. `actions.download` means `status = ready`; the file is checked only by the download itself.
22. Retry of a superseded failed row is allowed (today for advance); approve still refuses a superseded row (today).
23. Retry writes no audit row (today); not a new `RETRIED` event.
24. `ownScope()`, `approvePermission()` and `fileName()` live on the enum (plan's Contracts line); not on `PdfDocument` (plan's Data shape bullet).
25. `approval()` returns the stamp line and throws its refusal; `actions` treats a throw as "no approve", so the route and the button share one rule.
