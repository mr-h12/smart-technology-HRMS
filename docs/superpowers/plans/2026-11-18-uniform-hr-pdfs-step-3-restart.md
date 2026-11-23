# Step 3 restart plan for the leave request and the experience certificate

HR prints the leave request and the experience certificate from the system. Today both print in the IBM Plex look the owner
rejected. This plan rebuilds both from `origin/main` (`1ba0ab8`) so that each one renders as the owner approved on
2026-11-18 (leave on `55aebe3`, certificate on `1c247fd`). The rule it enforces is that each of the 46 approved PDFs has a
twin that `scripts/pdf-measure.py compare` reports identical within 0.10 pt and 0 pixels, and each new no-twin case passes
`scripts/pdf-measure.py check`. The spec is `docs/superpowers/specs/2026-11-18-uniform-hr-pdfs-step-3-restart-design.md`.
There is one PR, `step-3`, into `main`, on branch `claude/pdf-step-3-restart-4b9d0f`. It holds two slices, each ending at an
owner gate (3.1-R for the leave request, 3.2-R for the certificate).

## How to read this

One box is one unit of work. Every box names the evidence that checks it. A nested box is a sub-step of the box above it.
Check a box only when its evidence exists, a file, a log line, a screenshot, a test run, or a SHA. The body is a how-to. The
appendices explain and record.

The program runs `pstack/skills/poteto-mode/playbooks/feature.md` once per slice, driven by the controller chat. The owner
approves both gates, then the controller opens the PR. The other developer reviews it, and it merges only on the owner's
explicit approval.

Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

The owner's standing rules override the pstack skeleton in these places. Each deviation below has that one reason.

- The controller chat only plans, judges and commits. It commits by explicit path. It does no rebase, no force-push, and no
  push and no PR before the owner approves both forms. It never stages `CLAUDE_HANDOFF.md`.
- The execution playbook is `playbooks/feature.md` per slice, not `playbooks/autopilot-full.md` or
  `playbooks/autopilot-stack.md`.
- Each slice has ONE build agent (`pstack:poteto-agent`, `model: "opus"`, `effort: "high"`). It works through the slice's
  steps in order, writes each step's tests first, and hands back a list of files per step. ONE independent verifier per
  slice follows. It changes nothing and runs every step's checks and the ten lanes. Then the controller commits each step by
  explicit path, and steps that edit the same file share one commit (see the commit box of each slice). ONE capture agent
  per slice makes the gate images. A fix round is a fresh build agent with the consolidated scope, then the verifier again.
- Every brief and every follow-up tells the agent to load `ponytail:ponytail` and `andrej-karpathy-skills:karpathy-guidelines`
  and to read `docs/coding-standards.md`.
- One agent at a time runs the backend. A test run and a smoke run never overlap.
- The ten live lanes are ten named scenarios of the real-render smoke. One verifier runs them in sequence. They are not ten
  agents and not ten VMs.
- The review gate shows the owner images sent as files, with Arabic labels, and asks one question answerable in a word.
  There is no video, because a PDF does not move.
- There is no audit tick, no Bugbot and no cloud VM. The plan writes the adapter's "Skipped in Claude Code" line at each.
- At about 35% context the controller writes a hand-over in NOTES and starts the next session on the same worktree and
  branch.
- Step 3 is one PR, so its two slices sit inside one PR section and the sub-blocks repeat once per slice.
- The PR merges with a merge commit, not a squash.

## Program checklist

### Arm the program

- [ ] Commit C0 before execution starts. It holds only the spec and this plan.
  - [ ] `git add -- docs/superpowers/specs/2026-11-18-uniform-hr-pdfs-step-3-restart-design.md docs/superpowers/plans/2026-11-18-uniform-hr-pdfs-step-3-restart.md`, then commit. Evidence is the C0 SHA in NOTES `decisions.tsv`.
- [ ] State the protocol and this plan to the owner in Arabic, then stop. Include the old-rows change of spec section 6 in plain words. Start execution only on the owner's explicit go. Evidence is the owner's words, logged in `decisions.tsv`.
- [ ] Read these from trunk at program start. Re-read them at the start of every session and at every step boundary.
  - [ ] `git show origin/main:CLAUDE.md`
  - [ ] `git show origin/main:backend/CLAUDE.md`
  - [ ] `git show origin/main:docs/coding-standards.md`
  - [ ] `git show origin/main:docs/superpowers/plans/2026-11-13-uniform-hr-pdfs.md`
  - [ ] `git show origin/main:docs/superpowers/specs/2026-11-13-hr-pdf-wording-tables.md`
  - [ ] The pstack files, read from the plugin cache because pstack is not in this repo. `poteto-mode/SKILL.md`, `playbooks/feature.md`, `playbooks/opening-a-pr.md`, `docs/harness/claude-code.md`, and `technical-writing`, `unslop` and `no-comments` under `/Users/yousefhasabo/.claude/plugins/cache/cursor-plugins/pstack/0.15.29/`.
- [ ] Skipped in Claude Code: the `/loop 1h` audit tick. The owner's standing rule has the controller audit the run against this plan at each step boundary instead, and log a row in `decisions.tsv`.
- [ ] Post a status message to the owner, in Arabic, only at a gate, on a blocker, or on a decision only the owner can make. Evidence is the message and its `decisions.tsv` row.
- [ ] On the owner's hold or stand-down, send every running agent a zero-writes order at once.
- [ ] At about 35% context, write NOTES `HANDOVER-step-3r-<n>.md` with the last SHA, the next unchecked box, and every open finding. Start the next session on the same worktree and branch, and have it read `CLAUDE.md`, `CLAUDE_HANDOFF.md`, NOTES `brief-common.md`, this plan and the hand-over first.

### Spawn owners

- [ ] Confirm that Feature playbook steps 1 and 2 are done. NOTES `how-code.md` and `how-first-attempt.md` are the `how` output. NOTES `design/runner-A.md`, `design/runner-B.md` and `design/judgment.md` are the `architect` output.
- [ ] Write the Feature throughput checkpoint into `decisions.tsv`.
  - [ ] Blocking first steps. Step 1 (the smoke lever) blocks every view step, and step 2 (the layout version) blocks step 4.
  - [ ] Independent workstreams. n/a, one backend runner at a time and shared files (`PdfExports.php`, `RenderPdf.php`, `LeaveRequestPdf.php`) across steps.
  - [ ] Shared mutable state. The worktree, the Docker daemon and `hrms-wt-pg`. Serialized by the owner's rule, one agent at a time.
  - [ ] Smallest safe decomposition. One build agent per slice works through its steps in order, and each step still ends in its own check and its own red and green logs. One verifier per slice, one capture agent per gate.
- [ ] Brief every agent with NOTES `brief-common.md`, the slice's boxes from this plan, the spec sections it names, the data shape it builds, and the line "load `ponytail:ponytail` and `andrej-karpathy-skills:karpathy-guidelines`, read `docs/coding-standards.md`". Say "you are the backend runner" only to the one agent that runs containers now. Evidence is the brief file under NOTES `briefs/`.
- [ ] Follow this order. Each arrow waits for the box before it.
  - [ ] C0, then the slice 1 build agent through steps 1, 2, 3 and 4, then the slice 1 verifier, then commit C1-4.
  - [ ] Gate 3.1-R after step 4. Slice 2 starts only after the owner answers it, because slice 2 reuses the slice 1 components.
  - [ ] The slice 2 build agent through steps 5 and 6, then the slice 2 verifier, then commits C5 and C6, then gate 3.2-R, then the closing.
- [ ] Hold the file boundaries. The PR touches only the paths in its **Files.** blocks and in the closing docs commit. Nothing under `.claude/`, no lock file, no migration, no route and no permission. A step that needs another path stops and asks the controller.
- [ ] Hold the review gate. Both slices change what HR prints. Each waits for the owner's word on its images before the next slice or the PR.

### PR mechanics, for every PR

- [ ] Resolve the forge once. Run `command -v origin`. When it fails, use `gh` and record the fallback in `decisions.tsv`.
- [ ] Open the PR ready, never draft, per **Opening a PR**, with `gh pr create --base main`, and only after the owner approves both 3.1-R and 3.2-R.
- [ ] Run pint, PHPStan, `npm run lint` and `npm run build` once before the PR-facing push. Push with hooks on.
- [ ] Run the pstack `no-comments` skill over the diff before review. `deslop` from `cursor-team-kit` is not installed here, so the verifier runs `ponytail:ponytail-review` over the diff in its place.
- [ ] Skipped in Claude Code: Bugbot triage. The repo has no Bugbot check. Triage any human review thread per `references/bugbot-triage.md` all the same.
- [ ] Do not rebase. When `git merge-tree --write-tree origin/main HEAD` reports a conflict, or `main` moves before the PR, the controller merges `origin/main` into the branch with a merge commit and the verifier reruns the closing checks.

### Verdict and merge, for every PR

- [ ] The verifier runs the ten live lanes on each slice's build before its commits, and once more at the PR head during the closing. A fix round gets the verifier again on the fixed working tree, which reruns the lanes the fix can touch and the full twin compare.
- [ ] Clean only when every lane passes. A finding goes back to a fresh build agent with the original brief, every later order, and the verifier's report. A finding filed as a note is still a finding.
- [ ] The owner approves Step 3 before the merge (owner plan, section "Four steps"). The PR merges with a merge commit. No squash and no rebase.

### Boot recipe, for every live lane

The verifier runs every lane in WORKTREE on the slice's build, one after another. Skipped in Claude Code: one cloud VM per lane. The owner's standing rule runs the lanes in sequence on this Mac.

- [ ] Set the paths. Quote them, because they hold spaces.
  ```sh
  WT="/Users/yousefhasabo/HRMS-Smart TECH/.claude/worktrees/pdf-step-3-restart-4b9d0f"
  N="/Users/yousefhasabo/Library/Application Support/Claude/side-session-notes/local_4b561e1b-c82a-40a2-a11d-71b5443202a4/step3r"
  ENV="$N/env"; B="$N/baseline"; V="$N/verify/<gate>"
  ```
- [ ] Record `git -C "$WT" rev-parse HEAD` and `git -C "$WT" status --short` in `$V/head.txt`. The controller commits only after the verifier reports clean, so the head is the last commit, and `git status --short` must list only the files the build agents handed back since it.
- [ ] Check the renderer and the fonts before the first compare. `docker run --rm --pull=never hrms-php /usr/local/bin/hrms-chromium --version` prints 156.0.8078.79, and `shasum -a 256 "$WT"/backend/resources/pdf/fonts/{markazi-text-arabic-700-normal.woff2,markazi-text-latin-700-normal.woff2,Carlito-Bold.ttf}` starts with `43f5b89a`, `99a6340f` and `0f62ab34`. On a mismatch, report and stop.
- [ ] Render one case with `HRMS_PDF_IMAGE=hrms-php HRMS_SMOKE_FORM=<form> HRMS_SMOKE_CASE=<case> HRMS_SMOKE_GOLDENS="$B/approved" bash "$WT/scripts/smoke-hr-forms.sh" "$V" > "$V/<form>/<case>/smoke.log" 2>&1`. The smoke writes `$V/<form>/<case>/{unsigned,signed}.pdf` and runs the compare or the check itself.
- [ ] Make each image with `pdftoppm -r 150 -png -singlefile <pdf> <pdf without .pdf>`. Keep the compare's own `report.txt` and `diff.png`.
- [ ] Save every screenshot under `$V/lanes/` and return the paths with the report. Never write under `.claude/`.

## Rebuild the leave request and the experience certificate (step-3)

**Depends on.** None. The branch starts at `origin/main` `1ba0ab8`. Steps 1 and 2 of the owner plan are merged (PRs #63 and #64).

### Slice 1. Build the foundation and the leave request

**Files.**

- [ ] Edit `backend/tests/Smoke/render-hr-form.php` and `scripts/smoke-hr-forms.sh` (steps 1, 3 and 4).
- [ ] Create `scripts/pdf-measure.py` from NOTES `baseline/measure.py`, plus a `check` mode (step 1).
- [ ] Edit `backend/app/Modules/Pdf/Domain/PdfType.php`, `backend/app/Modules/Pdf/Application/PdfExports.php` and `backend/app/Modules/Pdf/Application/RenderPdf.php` (steps 2 and 4).
- [ ] Create `backend/app/Modules/Pdf/Domain/ValueSize.php` and `backend/tests/Unit/PdfValueSizeTest.php` (step 3).
- [ ] Edit `backend/app/Support/Localization/ArabicFormDate.php` and `backend/tests/Unit/ArabicFormDateTest.php` (step 3).
- [ ] Edit `backend/app/Modules/Leave/Application/LeaveRequests.php`, `backend/app/Modules/Leave/Presentation/LeaveRequestController.php` and `backend/tests/Feature/LeaveRequestsTest.php` (step 3).
- [ ] Edit `backend/app/Modules/Pdf/Application/Types/LeaveRequestPdf.php` (steps 3 and 4).
- [ ] Edit `backend/lang/ar/errors.php` and `backend/lang/en/errors.php` (step 3).
- [ ] Edit `frontend/src/components/PdfPanel.tsx` and `frontend/src/components/PdfPanel.test.tsx` (step 3).
- [ ] Create `backend/resources/pdf/fonts/markazi-text-arabic-700-normal.woff2`, `markazi-text-latin-700-normal.woff2`, `Carlito-Bold.ttf`, `LICENSE-MarkaziText.txt` and `LICENSE-Carlito.txt` from the sources in spec section 4 (step 4). Nothing is downloaded.
- [ ] Create `backend/resources/views/pdf/page.blade.php` and the six components `backend/resources/views/components/pdf/{field,blank,dots,choice,pair,signature}.blade.php` (step 4).
- [ ] Rewrite `backend/resources/views/pdf/leave-request.blade.php` (step 4).
- [ ] Create `backend/tests/Feature/HrFormViewsTest.php`. Edit `backend/tests/Feature/HrLeaveRequestFormTest.php` and `backend/tests/Feature/PdfApiTest.php` (steps 2, 3 and 4).

**Build.**

- [ ] Step 1, in commit C1-4. Build the smoke lever (spec sections 8 and 10.1). The data shape is one `CASES` table keyed by form and case, each entry holding gender, kind, values, archived, escalate, national ID and today.
  - [ ] Write the lever's own checks first. `pdf-measure.py compare "$B/approved/leave-request/female/unsigned.pdf"` against itself exits 0. Against OLDNOTES `gate-3.1d/female/unsigned.pdf` it exits 1 with the 11 differences of `$B/proof/leave-vs-fd76a36.txt`. `pdf-measure.py check leave-request` exits 0 on the approved female copy and 1 on the `gate-3.1d` copy, which prints single dots.
  - [ ] Build `CASES` with every non-long case of spec section 8 (`female`, `male`, `casual`, `unpaid`, `escalate`, `everyday`, `sick`, `empty`, `leader-1` to `leader-4`, and the four ordinary certificate cases), `HRMS_SMOKE_CASE`, `HRMS_SMOKE_TODAY`, the pinned national IDs, and a seeded `sick` kind. The long cases wait for the limits of steps 3 and 6.
  - [ ] Add the page-count check, the font allow-list, the phrase matcher that maps no letter, and the golden compare that runs only when `HRMS_SMOKE_GOLDENS` names a folder. The script calls `/usr/bin/python3`, the Python with Pillow.
  - [ ] At the end of step 1, the build agent runs the smoke for `female`, `male`, `casual`, `sick` and `unpaid` on the old views and saves each log and PDF under `$N/verify/s1/step-1/`. The old views are gone by the time the verifier runs, so the verifier reads those logs and runs `pdf-measure.py compare` by hand on the saved `female` render against its twin.
- [ ] Step 2, in commit C1-4. Add the layout version (spec sections 3 and 6). The data shape is `PdfType::templateVersion()`, one `match` that returns 1 for every type in this step.
  - [ ] Write these `PdfApiTest` cases first. `test_new_rows_store_their_types_layout_version`, `test_a_signed_row_copies_its_unsigned_rows_layout_version`, `test_the_worker_refuses_a_row_outside_its_types_layout_version`, `test_approve_on_an_old_layout_row_answers_404_and_403_before_409`, `test_retry_on_an_old_layout_row_answers_409_and_lists_no_action`, `test_the_awaiting_approval_filter_skips_an_old_layout_row`, `test_an_old_layout_ready_row_still_downloads`. An old-layout row is one whose `template_version` differs from its type's `templateVersion()`.
  - [ ] Build `templateVersion()`, `storeUnsigned()` writing it, `PdfExports::currentLayout()` checked in `approval()` after the 404 and 403 gates and in `retryable()`, the awaiting filter per type, and the `RenderPdf::html()` refusal.
- [ ] Step 3, in commit C1-4. Add the sizes, the date, the limits and the balance (spec sections 3, 4 and 7). The data shapes are the `LIMITS`, `ONE_LINE_AT_16`, `REASON_AT_16` and `SIZES` constants and `ValueSize::byShare()`.
  - [ ] Write these tests first. `PdfValueSizeTest::test_each_bound_picks_its_step` (both step tables, at and just past each bound) and `test_past_the_last_bound_picks_the_last_step`. `ArabicFormDateTest::test_spaced_returns_the_weekday_and_the_day_month_year`. `LeaveRequestsTest::test_a_note_over_360_characters_is_refused_at_submit`. `HrLeaveRequestFormTest::test_the_form_fields_have_printable_limits`, `test_a_name_or_department_over_90_or_a_stored_note_over_360_refuses_generation_with_no_row`, and `test_the_balance_is_the_one_before_this_leave` (a 3-day annual leave of a 21-day employee stores 21, before and after its approval). `PdfPanel.test.tsx` changes its `SPEC` row for the leave to 60, 60, 130 and 60.
  - [ ] Build `ValueSize`, `ArabicFormDate::spaced()`, `LeaveRequests::NOTE_MAX` and its use at `LeaveRequestController.php:50`, the leave `LIMITS` and form rules, the 422 `errors.leave_form_value_too_long` in both languages, the balance from `LeaveBalances::forEmployee($id, $year, LeaveDays::dates(...))`, and the frontend leave maxima.
  - [ ] Add the `long` leave case to `CASES`. It runs the five shares and their `-wrap` copies in one database in the approved order.
- [ ] Step 4, in commit C1-4. Build the shell, the fonts, the six components and the leave view at version 2 (spec section 5). The data shape is the six components, whose props are the company's numbers in true points.
  - [ ] Write `HrFormViewsTest` first. `test_the_form_views_hold_no_run_of_three_periods`, `test_every_snapshot_value_prints_inside_a_bdi_or_a_component_attribute`, `test_both_form_views_extend_the_page_shell`, `test_an_empty_field_prints_its_dots_and_no_bdi`, `test_a_filled_field_prints_the_value_then_a_leader_whose_first_five_periods_are_one_nowrap_unit`, `test_a_pair_always_carries_the_gutter`, `test_a_choice_renders_marked_and_unmarked_circles`.
  - [ ] Rewrite `HrLeaveRequestFormTest` first, per spec section 9. `test_each_kind_and_gender_marks_its_circle_and_prints_its_wording` (tables ١-أ and ١-ب), `test_only_an_unpaid_leave_prints_bidun_ajr`, `test_two_days_print_yawmayn_and_each_date_prints_twice`, `test_each_typed_value_is_in_a_bdi_and_an_empty_department_and_method_print_dots`, `test_the_balance_cell_prints_unavailable_and_zero_days`, `test_the_signature_prints_only_on_the_stamp_line`, `test_a_copy_prints_at_16_pt_at_each_bound` (reason 25 and 26, 100 and 101, and each one-line length), `test_a_reason_of_zero_prints_zero`. Keep the existing scope, supersede, cancellation and escalation tests, edited for the new markup.
  - [ ] Add to `PdfApiTest` first. `test_a_version_1_leave_row_is_view_only` and `test_advance_and_overtime_html_still_holds_plex_arabic`.
  - [ ] Build the fonts, `page.blade.php`, the components, the leave view and `LeaveRequestPdf::view()`, `templateVersion()` returning 2 for the leave, and the three font data URLs in `RenderPdf::html()`. Write the leader period count as `5 + ceil(at / 4.4)`, and fall back to 30 if the compare shows a difference.
- [ ] Commit C1-4. After the slice 1 verifier reports clean, the controller commits steps 1 to 4 as ONE commit, with `git add -- <every file of the four lists>` and a message in the repo's style ending in the attribution line. No step separates by path. Steps 1, 3 and 4 all edit `render-hr-form.php` and `smoke-hr-forms.sh`. Steps 2 and 4 both edit `PdfType.php`, `PdfExports.php`, `RenderPdf.php` and `PdfApiTest.php`. Steps 3 and 4 both edit `LeaveRequestPdf.php` and `HrLeaveRequestFormTest.php`. Each step keeps its own evidence in the build agent's red and green logs. Evidence is the SHA in `decisions.tsv`.

**You see.**

- [ ] After step 1, the smoke writes both PDFs for each of the five cases, passes the page check, and exits 1 at the font allow-list, because the old views embed IBM Plex. The hand compare of `female` exits 1 and lists the font change, which proves the compare reads the smoke's output.
- [ ] After step 2, the full suite passes with the seven new `PdfApiTest` cases, and every type still stores version 1. Step 4 moves the leave to version 2, so the evidence is the build agent's `step-2-green.log`.
- [ ] After step 3, a leave form with a 61-character job title answers 422, a 361-character note is refused at submit, and the vitest `SPEC` row shows 60, 60, 130 and 60.
- [ ] After step 4, the smoke exits 0 for every leave case with `HRMS_SMOKE_GOLDENS` set. Each twin's `report.txt` ends in `RESULT identical within 0.10 pt and 0 pixels`.
- [ ] The day-one layout rules hold on every leave render. The verifier measures each one and writes the number into `$V/rules.txt`.
  - [ ] Every rendered leave copy is one A4 page, 595.92 x 841.92 pt, and `pdffonts` lists only `MarkaziText-Bold` and `Carlito-Bold`, all embedded.
  - [ ] Each hand-written label keeps its place when its value changes. On `empty` against `female`, every label's right edge is within 0.1 pt.
  - [ ] A value moves only its own label. On `everyday`, the job title moves `الوظيفة /` by the approved 29 pt and no element of another row moves.
  - [ ] After each typed value there is no dot or at least five dots, on every `leader-*` copy.
  - [ ] The last dot's box edge lies on the dotted rows' left edge within 0.1 pt.
  - [ ] The box white from a value to its first dot is between 0.7 and 0.9 pt.
  - [ ] No two words of a row are closer than 1 pt, except a value and its leader. This is the white between neighbours, from `WHITE BETWEEN NEIGHBOURS` and from `check`.
  - [ ] The three kind circles keep their places on every copy, within 0.1 pt of the twin, and `sick` against `female` differs only in the circle fills.
  - [ ] `بدون أجر` prints only on `unpaid`, at the left end of the `بيانات منح الطلب` line.
  - [ ] A wrapped value moves later rows down and overlaps nothing, on every `long/*-wrap` copy.
  - [ ] The signature image prints only on the line `stamp_line` names (HR's on `female` signed, the company manager's on `escalate` signed).
  - [ ] The serial prints once, 7 pt grey, at the bottom left.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Each step's new tests fail before its code and pass after it. The build agent saves both runs under `$N/verify/s1/step-<n>-{red,green}.log`.
- [ ] Run the HR-form subset on the slice 1 build. `bash "$ENV/wt-run.sh" php artisan test --filter='HrLeaveRequestFormTest|HrExperienceCertificateTest|HrOvertimeRequestFormTest|HrAdvanceRequestFormTest|HrSignatureTest|PdfApiTest|PdfManagerAdvanceTest|ArabicFormDateTest|ArabicServiceDurationTest|PdfValueSizeTest|HrFormViewsTest|LeaveRequestsTest'`. Pass when it reports 0 failed.
- [ ] Run the full backend suite. `bash "$ENV/wt-run.sh" php artisan test`. Pass when 0 failed and the count is above the trunk's 1088.
- [ ] Run `bash "$ENV/wt-run.sh" vendor/bin/pint --test`, `bash "$ENV/wt-run.sh" vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `bash "$ENV/wt-run.sh" vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml --no-progress`. Pass when pint passes, PHPStan reports no errors and deptrac reports 0 violations.
- [ ] Run `cd "$WT/frontend" && npm run test`. Pass when 0 failed.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `opus high` at the PR head, per the boot recipe, run in sequence by the slice 1 verifier. Here the PR head is the slice 1 build in the working tree (or its last fix round), before commit C1-4. `$V` is `$N/verify/3.1-R`.

- [ ] Lane 1. Regression lane against trunk. Trunk has no official layout, so the lane reads the trunk render in `$ENV/baseline-main/leave-request/` and records that its `pdffonts` lists IBM Plex and DejaVu, not Markazi or Carlito. It then gates the head's `female` case (unsigned and signed), the load-bearing scenario. Save `lane-01-trunk-female.png` and `lane-01-head-female.png`. Pass when the trunk record is written and the head smoke exits 0 with both twins identical.
- [ ] Lane 2. Render `male`. Save `lane-02-male.png`. Pass when the smoke exits 0 and both twins are identical within 0.10 pt and 0 pixels.
- [ ] Lane 3. Render `casual`. Save `lane-03-casual.png`. Pass when the smoke exits 0 and both twins are identical.
- [ ] Lane 4. Render `unpaid`. Save `lane-04-unpaid.png`. Pass when the smoke exits 0, both twins are identical, and `بدون أجر` stands on the `بيانات منح الطلب` line.
- [ ] Lane 5. Render `escalate`. Save `lane-05-escalate-signed.png`. Pass when the smoke exits 0, both twins are identical, and the signature image sits on the company manager's line.
- [ ] Lane 6. Render `everyday`. Save `lane-06-everyday.png`. Pass when the smoke exits 0, both twins are identical, and the copy prints at 16 pt.
- [ ] Lane 7. Render `long`, which writes the five shares and their `-wrap` copies (20 PDFs). Save `lane-07-long-1-signed.png`. Pass when the smoke exits 0, all 20 twins are identical, and every copy is one page.
- [ ] Lane 8. Render `sick`, a no-twin case. Save `lane-08-sick.png`. Pass when `pdf-measure.py check` exits 0 and `pdf-measure.py compare` against the `female` twin lists only the circle fills.
- [ ] Lane 9. Render `empty`, a no-twin case. Save `lane-09-empty.png`. Pass when `check` exits 0, the empty department and notification rows print their 25 dots, and every hand-written label is within 0.1 pt of `female`.
- [ ] Lane 10. Render `leader-1` to `leader-4`, the twelve samples across one dot pitch. Save `lane-10-leader-4.png`. Pass when `check` exits 0 on all four, with no dot or at least five, the last dot within 0.1 pt of the left edge, and the value-to-dot white between 0.7 and 0.9 pt.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. The wall time of one smoke run for one form case (render unsigned and signed, then the checks), in seconds. Measure the leave `female` case, which trunk also renders as its default, and the `overtime-request` form, which this PR does not change but which now loads the three new fonts. Separately, the diff-added tool work is the wall time of one `pdf-measure.py compare`.
- [ ] Probe. Export trunk once with `mkdir -p "$N/trunk" && git -C "$WT" archive origin/main | tar -x -C "$N/trunk"`, then `cp -Rc "$WT/backend/vendor" "$N/trunk/backend/vendor"` and `cp "$WT/backend/.env" "$N/trunk/backend/.env"`. Run `/usr/bin/time -p` around `HRMS_PDF_IMAGE=hrms-php HRMS_SMOKE_FORM=leave-request bash "$N/trunk/scripts/smoke-hr-forms.sh" <out>` and around the head command with `HRMS_SMOKE_CASE=female` and no `HRMS_SMOKE_GOLDENS`, interleaved trunk, head, trunk, head, trunk, head. Repeat with `HRMS_SMOKE_FORM=overtime-request`. Time `pdf-measure.py compare` on the `female` unsigned pair three times. Save every timing in `$V/perf.tsv`.
- [ ] Baseline. Record the trunk median first. The environment agent measured about 5 s per form on trunk (`env/README.md`); the probe's own trunk median is the number the rule uses.
- [ ] Rule. Fail when the head median for leave `female` exceeds the trunk median by more than 1.5 s (30% of the 5 s baseline), when the head median for `overtime-request` exceeds its trunk median by more than 1.0 s, or when the compare median exceeds 2.0 s per PDF (the baseline proof ran 46 compares in about 30 s). Before acting on a failure, explain the number per the pstack `benchmark-checklist` skill.

**Review gate.** The operator (the owner) reviews slice 1 before slice 2 starts. Gate 3.1-R.

- [ ] Ask the slice 1 build agent and the capture agent, separately, what looks worse by eye than the approved render. Settle every answer first, with a measurement or a fix round, and log each in `decisions.tsv`.
- [ ] The capture agent, now the only backend runner, builds one HTML sheet per image with Arabic labels and screenshots it with the Chromium in `hrms-php`, as OLDNOTES `gate-3.1e/src/sheet*.html` did. Save each screenshot as `$N/gates/3.1-R/3.1-R-<slug>.png`.
  - [ ] `approved beside new` for `female` (unsigned and signed), `male`, `unpaid`, `escalate` signed and `long/1` signed, each labelled `مطابق حتى 0.1 نقطة، صفر بكسل` from its own `report.txt`.
  - [ ] The new `sick` and `empty` copies, labelled as new copies that have no approved twin.
- [ ] Write the old-rows change in Arabic plain words, from spec section 6. Draft «النسخ القديمة من طلب الإجازة وشهادة الخبرة تبقى للعرض والتنزيل كما هي. النسخة القديمة غير الموقعة لن يظهر عليها زر الاعتماد، والنسخة القديمة الفاشلة لن يظهر عليها زر إعادة المحاولة. لتوقيعها تُصدر الموارد البشرية النموذج من جديد برقم مسلسل جديد ثم يوقّعه صاحب القرار.»
- [ ] Send the images to the operator as files, with that text and one question, «هل تعتمد طلب الإجازة كما في الصور؟». No video, because a PDF does not move. Stop and wait. Log the owner's word in `decisions.tsv`.

### Slice 2. Build the experience certificate

**Files.**

- [ ] Edit `backend/app/Support/Localization/ArabicServiceDuration.php` and `backend/tests/Unit/ArabicServiceDurationTest.php` (step 5).
- [ ] Edit `backend/app/Modules/Pdf/Application/Types/ExperienceCertificatePdf.php` and `backend/app/Modules/Pdf/Domain/PdfType.php` (step 6).
- [ ] Rewrite `backend/resources/views/pdf/experience-certificate.blade.php` (step 6).
- [ ] Edit `backend/tests/Feature/HrExperienceCertificateTest.php`, `backend/tests/Feature/PdfApiTest.php`, `backend/tests/Smoke/render-hr-form.php` and `scripts/smoke-hr-forms.sh` (step 6). The certificate's phrase list lives in the smoke script, and the new title «شهـــادة خبرة» no longer extracts as trunk's phrase `شهادة خبرة`.

**Build.**

- [ ] Step 5, commit C5. Rewrite `ArabicServiceDuration::format()` in place (spec section 4). Its one caller is `ExperienceCertificatePdf.php:61`. The data shape is the word tables for years, months and days.
  - [ ] Write `ArabicServiceDurationTest::test_format_years_months_and_days` first, with a provider for 1 to 11 years, 1 to 11 months, years with months, and the day cases under a month.
  - [ ] Build `format()` to the words of spec section 4. `days()` and `hoursAndMinutes()` stay unchanged.
- [ ] Step 6, commit C6. Build the certificate view and data at version 2 (spec sections 3 and 5). The data shape is the view data of spec section 3 and `ExperienceCertificatePdf::LIMITS` and `SIZES`.
  - [ ] Rewrite `HrExperienceCertificateTest` first. `test_both_genders_print_the_companys_wording_under_a_fixed_clock` (tables ٢-أ and ٢-ب), `test_a_termination_date_ends_the_duration`, `test_the_signature_prints_only_on_the_signed_copy`, `test_an_archived_employee_gets_a_certificate`, `test_each_value_prints_in_a_bdi`, `test_each_step_sets_the_value_size_and_line_spacing`, `test_a_stored_praise_is_not_printed`. Keep the postponed-types, logo and view-as tests, edited for the new markup.
  - [ ] Add `test_a_version_1_certificate_row_is_view_only` to `PdfApiTest` first.
  - [ ] Build `view()` without `praise`, `valueSize` and `lineSpacing` from `ValueSize::byShare`, the letterhead view with `pair` at `split` 327.1 pt, and `templateVersion()` returning 2 for the certificate.
  - [ ] Add the certificate `long` case (shares 0.25, 0.6 and 1, in one database in the approved order) and `halves-full` to `CASES`.
- [ ] Commits C5 and C6. After the slice 2 verifier reports clean, the controller commits step 5 as C5 and step 6 as C6, each by explicit path from the build agent's per-step list, with the SHA in `decisions.tsv`. They separate by path, because step 5 edits only `ArabicServiceDuration.php` and its test, which step 6 does not touch.

**You see.**

- [ ] After step 5, `format()` prints `أربع سنوات وعشرة أشهر` for 2022-01-01 to 2026-11-18, the approved female duration.
- [ ] After step 6, the smoke exits 0 for every certificate case with `HRMS_SMOKE_GOLDENS` set, and all 14 certificate twins and all 32 leave twins are identical.
- [ ] The day-one layout rules hold on every certificate render. The verifier measures each one into `$V/rules.txt`.
  - [ ] Every certificate copy is one A4 page, and `pdffonts` lists only `MarkaziText-Bold`, embedded.
  - [ ] The second labels' box edge stays at 240.56 pt on every copy.
  - [ ] There are at least 7 pt of white before each left label, on `halves-full` and on every long copy.
  - [ ] No two words of a row are closer than 1 pt.
  - [ ] The closing paragraph never leaves `الشركة.` alone on its last line.
  - [ ] The signature image prints under `مدير الشركة` only on the signed copy.

**Verify, unit.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Each step's new tests fail before its code and pass after it, saved under `$N/verify/s2/step-<n>-{red,green}.log`.
- [ ] Run the HR-form subset command of slice 1 on the slice 2 build. Pass when 0 failed.
- [ ] Run the full backend suite, pint, PHPStan and deptrac with the slice 1 commands. Pass on the same predicates.

**Verify, live.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked. Ten lanes on `opus high` at the PR head, per the boot recipe, run in sequence by the slice 2 verifier. Here the PR head is the slice 2 build in the working tree (or its last fix round), before commits C5 and C6. `$V` is `$N/verify/3.2-R`.

- [ ] Lane 1. Regression lane against trunk. Trunk has no official layout, so the lane reads `$ENV/baseline-main/experience-certificate/` and records that its `pdffonts` lists IBM Plex, not Markazi. It then gates the head's `female` certificate (unsigned and signed). Save `lane-01-trunk-female.png` and `lane-01-head-female.png`. Pass when the trunk record is written and the head smoke exits 0 with both twins identical.
- [ ] Lane 2. Render certificate `male`. Save `lane-02-male.png`. Pass when the smoke exits 0 and both twins are identical.
- [ ] Lane 3. Render `archived-female`. Save `lane-03-archived-female.png`. Pass when the smoke exits 0 and both twins are identical.
- [ ] Lane 4. Render `archived-male`. Save `lane-04-archived-male.png`. Pass when the smoke exits 0 and both twins are identical.
- [ ] Lane 5. Render the certificate `long` case and read share 0.25. Save `lane-05-long-0.25-signed.png`. Pass when both 0.25 twins are identical and the halves keep 7 pt of white.
- [ ] Lane 6. Read share 0.6 of the same run. Save `lane-06-long-0.6-signed.png`. Pass when both 0.6 twins are identical.
- [ ] Lane 7. Read share 1 of the same run. Save `lane-07-long-1-signed.png`. Pass when both twins are identical, the copy is one page, and the signature sits at the approved y.
- [ ] Lane 8. Render `halves-full`, a no-twin case. Save `lane-08-halves-full.png`. Pass when `check` exits 0 with at least 7 pt before each left label and one page.
- [ ] Lane 9. Render every leave case again with the slice 2 components. Save `lane-09-leave-female-signed.png`. Pass when all 32 leave twins are identical and `sick`, `empty` and `leader-*` pass `check`.
- [ ] Lane 10. Render `overtime-request`, `advance-request` and `advance-request-no-pay`. Save `lane-10-advance.png`. Pass when each smoke exits 0 and `pdffonts` still lists IBM Plex for all three, so Step 4's forms are unchanged.

**Verify, perf.** Tests alone are not sufficient verification. A PR is verified only when its unit, live, and perf boxes are all checked.

- [ ] Metric. The wall time of one smoke run for the certificate `female` case, which trunk renders as its default, and of one `pdf-measure.py compare` on the certificate pair.
- [ ] Probe. Reuse the trunk export of slice 1. Interleave `/usr/bin/time -p` runs of `HRMS_SMOKE_FORM=experience-certificate` at trunk and at head (head with `HRMS_SMOKE_CASE=female` and no goldens), three each, and time the compare three times. Save the timings in `$V/perf.tsv`.
- [ ] Baseline. Record the trunk median first, near the 5 s per form of `env/README.md`.
- [ ] Rule. Fail when the head median exceeds the trunk median by more than 1.5 s, or when the compare median exceeds 3.0 s per PDF (each certificate embeds the letterhead image, so its raster pass is the slowest). Explain any failing number per `benchmark-checklist` before acting on it.

**Review gate.** The operator (the owner) reviews slice 2 before the PR. Gate 3.2-R.

- [ ] Ask the slice 2 build agent and the capture agent, separately, what looks worse by eye than the approved render. Settle every answer first and log it.
- [ ] The capture agent builds the sheets as in 3.1-R and saves each screenshot as `$N/gates/3.2-R/3.2-R-<slug>.png`. `approved beside new` for `female`, `male`, `archived-male` and `long/1`, each unsigned and signed, each labelled with its measured result.
- [ ] Send the images to the operator as files, with one question, «هل تعتمد شهادة الخبرة كما في الصور؟». No video, because a PDF does not move. Stop and wait. Log the owner's word.

### Close the PR

- [ ] Run the full backend suite, pint, PHPStan and deptrac at the PR head, with the slice 1 commands. Save the logs under `$N/verify/close/`.
- [ ] Run `bash "$ENV/wt-run.sh" php artisan route:list --path=hr-form`. Pass when it lists the same 6 routes as trunk.
- [ ] Run `cd "$WT/frontend" && npm run test && npm run lint && npm run build`. Pass when all three exit 0.
- [ ] Run the real-render smoke for every case of both forms and for the three Step 4 forms. Pass when each rebuilt form is one A4 page with only its approved fonts embedded, and the Step 4 forms still exit 0.
- [ ] Run the 46-twin compare loop of NOTES `baseline/BASELINE.md` section 6 with `"$WT/scripts/pdf-measure.py"` and `NEW="$N/verify/close"`. Pass when all 46 print no `DIFF` line. Save the reports under `$N/verify/close/compare/`.
- [ ] Run the repo's `code-review` skill against `origin/main`, both axes, to the end. The first attempt's review never finished. Each finding goes to a fresh build agent, and the verifier reruns what it touches.
- [ ] Run the `security-review` skill over the branch. It covers the approve and retry gates, the 422 refusals, the font and image data URLs under the CSP, and the worker logs. Settle each finding the same way.
- [ ] Commit C7, docs only, by explicit path.
  - [ ] `docs/hrms-module-plan.md`, a Step 3 status line after the Step 2 line (`:115`), naming this branch and the two gates.
  - [ ] `backend/CLAUDE.md`, the smoke paragraph with `HRMS_SMOKE_FORM`, `HRMS_SMOKE_CASE`, `HRMS_SMOKE_GOLDENS`, `HRMS_PDF_IMAGE=hrms-php`, and `scripts/pdf-measure.py` with Poppler and `/usr/bin/python3` with Pillow.
  - [ ] `docs/superpowers/specs/2026-11-13-hr-pdf-wording-tables.md`, the Gate 3.0 approval line, which exists only on the first-attempt branch (`168f9a4`).
  - [ ] Record in `decisions.tsv` that `docs/calculations.md` and `CONTEXT.md` need no change, because no formula and no glossary term changes. Evidence is `git grep -n` for the touched terms.
- [ ] Update `CLAUDE_HANDOFF.md` for the next session, and never stage it.
- [ ] Write the deploy note for the PR body from spec section 6. Drain the `pdf` queue and retry failed leave and certificate rows first, run `docker compose restart worker-pdf` after the deploy, run the listing SQL so HR can generate each listed form again, and count the notes over 360 and the names or departments over 90.
- [ ] Run **Opening a PR**. The title is `feat(pdf): rebuild the leave request and the experience certificate in the company layout`. The body has the sections Why, What changed, Scope, Tradeoffs, Blast Radius and Verification, then the deploy note. Verification names the 46 identical twins and the perf medians, and says that the compare output lives in the developer's notes, not in the repo. Write it with `technical-writing`, then `unslop`. Open it with `gh pr create --base main`, ready, and post the URL.

**Merge.**

- [ ] The verifier's clean report at the exact PR head SHA, with every lane, perf and closing box above checked.
- [ ] Skipped in Claude Code: Bugbot triage at merge. The repo has no Bugbot check. The other developer's review threads are settled instead.
- [ ] No rebase. `git merge-tree --write-tree origin/main HEAD` reports no conflict, or `origin/main` is merged in and the closing checks pass again.
- [ ] The owner approves Step 3 in chat, and the PR merges with a merge commit (owner plan, section "Four steps").

## Close the program

- [ ] Every box above is checked with its evidence.
- [ ] Reply to the owner in Arabic with the PR link `https://github.com/mr-h12/smart-technology-HRMS/pull/<number>`, both gate results, the deploy note, and what stays unproven (Appendix A).

## Appendix A. Prototype evidence

The approved renders are the prototypes. They settle what each form must look like, and the baseline proof settles that the
measuring tool sees a defect.

- The leave request, 32 PDFs rendered on `55aebe3` (branch `claude/pdf-step-3-official-forms-2fcd71`), approved at Gate 3.1
  on 2026-11-18. Copies are in NOTES `baseline/approved/leave-request/`, with provenance in `approved/INDEX.tsv`.
- The certificate, 14 PDFs rendered on `1c247fd`, approved at Gate 3.2 on 2026-11-18, in `baseline/approved/experience-certificate/`.
- The tool proof in NOTES `baseline/proof/`. Each approved PDF against itself is identical (`leave-self.txt`, `cert-self.txt`).
  The approved leave against its re-render a day later is identical (`leave-*-vs-1c247fd-rerender.txt`), so a pinned render
  is repeatable on this Chromium. The approved leave against `fd76a36` shows 11 differences and 637 pixels
  (`leave-vs-fd76a36.txt`). The certificate long 0.25 against `01c01a9` shows 106 differences, including the 0.36 pt touch
  of the halves (`cert-long025-vs-01c01a9.txt`). The tool therefore finds both defects the first attempt found only by eye.
- Runner B's measurement that y is flow, verified by the judge in `baseline/tables/leave-request/` (`الوظيفة` at y 472.58,
  504.83 and 506.33 across three copies).

These stay unproven until slice 1 renders.

- Whether a Blade anonymous component adds whitespace between inline boxes and moves a label. The first compare at step 4 shows it.
- Whether the derived leader count `5 + ceil(at / 4.4)` renders the same as the approved 30 periods. The first compare decides.
- Whether the pinned clock, national IDs and serials reproduce the twins exactly. A mismatch shows as changed text and is
  fixed in `CASES`, never by a tolerance.
- That the approved renders match the company's own forms. They are what the owner approved, and the certificate has no true
  company render.

## Appendix B. Alternatives rejected

| Alternative | Why it lost |
|---|---|
| Runner B's geometry table (x anchors in JSON, a parser class, Blade parts reading it, a checker reading the same file) | The approved renders move later bands down when a value wraps, and PHP cannot measure shaped Arabic, so absolute y would overlap neighbours. The goldens already compare every element at 0.1 pt, so the table would serve only the no-twin cases, where five numbers in `pdf-measure.py check` do the same job. It also adds a second place for gendered labels. |
| The 46 approved PDFs committed under `scripts/pdf-goldens/` | They are 5.7 MB against a 7.67 MB pack, for a one-time proof tied to one Chromium build. The tool ships, the smoke reads the goldens from a folder it is given, and the PR body reports the result. The cost is that a reviewer without NOTES cannot rerun the pixel compare. |
| Version 1 views kept, each row rendered in its own layout | Step 4c removes `pdf.layout`, which both version 1 views extend, so Step 4 needs the version rule anyway. Keeping them would let HR sign an old copy in the IBM Plex look the owner rejected, and keeps about 100 lines alive until Step 4c. |
| One JSON file of limits read by PHP and vitest | The Leave module would import the Pdf module while Pdf already imports Leave. Leave owns `NOTE_MAX`, `LeaveRequestPdf::LIMITS` holds the rest, and the frontend keeps its hand mirror checked by the `SPEC` table. |
| Render, count pages and step the size down in the job | It changes which copies print at which size, a visible difference, and runs up to five renders per long copy. It is an owner choice, not a refactor. |
| Ten lane agents or ten VMs | One backend runner at a time is the owner's rule, and the lanes share one Docker daemon. |

## Appendix C. Risks

| Risk | Lands in | What the owner of the step watches |
|---|---|---|
| Blade component whitespace moves a label | Step 4 | The first `female` compare. Components are written with no whitespace between tags. |
| Fixture data differs from the twins (dates, IDs, fixture strings, serials) | Steps 1, 3, 4, 6 | `ELEMENTS` lines that show changed text. Fix `CASES`, never the tolerance. |
| The derived leader count renders differently from 30 periods | Step 4 | The compare on every twin with a typed value. Fall back to 30. |
| `text-wrap: pretty` and `text-justify: inter-character` change with Chromium | Every lane | The version check in the boot recipe. A rebuilt `hrms-php` image stops the run. |
| `pdf-measure.py` runs under a Python without Pillow, prints `RASTER not run` and exits 1 | Step 1, every lane | The smoke calls `/usr/bin/python3`. A lane that prints `RASTER not run` fails. |
| Slice 2 edits a shared component and moves the leave request | Step 6 | Lane 9 of slice 2 renders all 32 leave twins again. |
| An old worker refuses every version 2 row after deploy | Deploy | The deploy note restarts `worker-pdf`. |
| Old leave notes over 360 or names over 90 now answer 422 | Deploy | The deploy note counts them before HR meets them. |
| The cloned `vendor` and `node_modules` go stale | Any step | Lock files must stay unchanged. A step that needs a lock change stops and asks. |
| The controller's context fills before a gate | Any step | The 35% hand-over box. |
| A visible difference from the approved render | Both gates | It is a finding shown to the owner, never a silent choice. |

Known limits the owner accepted stay as spec section 12 lists them. Contrived values can reach two pages, `RenderPdf` reads
about 730 KB of fonts for every form until Step 4c, and the advance PDF still embeds DejaVu.

## Appendix D. Links and reading list

- Spec. `docs/superpowers/specs/2026-11-18-uniform-hr-pdfs-step-3-restart-design.md`.
- Owner plan. `docs/superpowers/plans/2026-11-13-uniform-hr-pdfs.md`, Step 3.
- Wording. `docs/superpowers/specs/2026-11-13-hr-pdf-wording-tables.md`.
- Company forms. `docs/templates/طلب اجازة.pdf` and `docs/templates/شهاده خبرة.docx`.
- NOTES `/Users/yousefhasabo/Library/Application Support/Claude/side-session-notes/local_4b561e1b-c82a-40a2-a11d-71b5443202a4/step3r/`.
  Read `brief-common.md`, `env/README.md`, `baseline/BASELINE.md`, `how-code.md`, `how-first-attempt.md` and
  `design/judgment.md` before a step.
- The first attempt, read-only. Branch `claude/pdf-step-3-official-forms-2fcd71` (head `1c247fd`) and OLDNOTES
  `/Users/yousefhasabo/Library/Application Support/Claude/side-session-notes/local_5a07da38-a4ac-4979-9b11-02be6c9b5d3f/`.
- `how` ran as NOTES `how-code.md` and `how-first-attempt.md`. `architect` ran as two runners and a judge under NOTES
  `design/`. `interrogate` is not run, because the judge's cross-review settled the contested points and the controller
  ruled on the last three.
- The decision trail is NOTES `decisions.tsv`, one row per decision, kept local per `show-me-your-work`.
