<?php

declare(strict_types=1);

use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Application\LeaveRequests;
use App\Modules\Pdf\Application\PdfExports;
use App\Modules\Pdf\Application\RenderPdf;
use App\Modules\Pdf\Application\SignatureImages;
use App\Modules\Pdf\Application\Types\LeaveRequestPdf;
use App\Modules\Pdf\Domain\PdfType;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Every entry is merged over DEFAULTS. The values, dates and national IDs are those of the approved renders (leave on 55aebe3,
// certificate on 1c247fd), so a case's PDFs can be compared with its approved twin; serials match because each case runs in a
// fresh database. A case without an approved twin says 'twin' => false: with HRMS_SMOKE_GOLDENS set, every other case must have one.
const DEFAULTS = [
    'twin' => true, 'gender' => 'female', 'kind' => 'annual', 'archived' => false, 'escalate' => false, 'national_id' => '12345678901234', 'today' => '2026-11-17', 'long' => null,
    'values' => [
        'name_ar' => 'سارة مثال للاختبار', 'department' => 'اختبار', 'reason' => 'سبب تجريبي', 'job_title' => 'مهندسة اختبار',
        'notification_method' => 'الهاتف', 'manager_opinion' => 'رأي تجريبي', 'substitute' => 'بديل تجريبي',
    ],
];
const MALE = ['name_ar' => 'أحمد مثال للاختبار', 'job_title' => 'مهندس اختبار'];
const CASES = [
    'leave-request' => [
        'female' => [],
        'male' => ['gender' => 'male', 'values' => MALE],
        'casual' => ['kind' => 'casual'],
        'unpaid' => ['kind' => 'unpaid'],
        'sick' => ['twin' => false, 'kind' => 'sick'],
        // HR escalates, the CEO decides and signs: the signature stands on the company manager's line.
        'escalate' => ['escalate' => true],
        // Long values that each still fit their line, so the copy prints at 16 pt.
        'everyday' => ['values' => ['name_ar' => 'سارة عبد الرحمن محمود عبد العزيز', 'job_title' => 'مدير إدارة الموارد البشرية', 'substitute' => 'محمود عبد الرحمن محمد السيدي']],
        'empty' => ['twin' => false, 'values' => ['department' => null, 'notification_method' => '', 'manager_opinion' => '', 'substitute' => '']],
        // The three values followed by a leader grow by one narrow letter per case: twelve value ends across one dot pitch.
        'leader-1' => ['twin' => false, 'values' => ['job_title' => 'مهندسة اختبار ا', 'department' => 'اختبار ا', 'notification_method' => 'الهاتف ا']],
        'leader-2' => ['twin' => false, 'values' => ['job_title' => 'مهندسة اختبار اا', 'department' => 'اختبار اا', 'notification_method' => 'الهاتف اا']],
        'leader-3' => ['twin' => false, 'values' => ['job_title' => 'مهندسة اختبار ااا', 'department' => 'اختبار ااا', 'notification_method' => 'الهاتف ااا']],
        'leader-4' => ['twin' => false, 'values' => ['job_title' => 'مهندسة اختبار اااا', 'department' => 'اختبار اااا', 'notification_method' => 'الهاتف اااا']],
        // Each run cuts every value from its pattern to its printable limit times the run's share (Latin, digits, brackets); a -wrap
        // run's reason is short repeated words, which wrap to more lines.
        'long' => ['gender' => 'male', 'long' => [
            'name_ar' => 'أحمد عبد الرحمن محمود مثال للاختبار ', 'department' => 'إدارة المشروعات والتشغيل (Site 2) ',
            'reason' => 'ظروف عائلية تتطلب السفر إلى القاهرة (Cairo 2026) لإنهاء إجراءات رسمية ', 'wrap' => 'ظروف مرضية ',
            'job_title' => 'QA/QC Engineer (Site) مهندس جودة ', 'notification_method' => 'Tel:01001234567/واتساب_الموظف',
            'manager_opinion' => 'لا مانع بشرط تسليم الأعمال الجارية (Report 12) ', 'substitute' => 'محمود علي حسن (Site Engineer) ',
        ]],
    ],
    'experience-certificate' => [
        'female' => ['national_id' => '68014522315405', 'today' => '2026-11-18'],
        'male' => ['gender' => 'male', 'values' => MALE, 'national_id' => '26569523878298', 'today' => '2026-11-18'],
        'archived-female' => ['archived' => true, 'national_id' => '67247010871558', 'today' => '2026-11-18'],
        'archived-male' => ['gender' => 'male', 'values' => MALE, 'archived' => true, 'national_id' => '94782826867964', 'today' => '2026-11-18'],
    ],
    'overtime-request' => ['female' => ['twin' => false]],
    'advance-request' => ['female' => ['twin' => false]],
    // The site manager's copy: a permanent worker in a real manager's reach, created at the manager's own scope, without the salary.
    'advance-request-no-pay' => ['female' => ['twin' => false]],
];

$form = getenv('HRMS_SMOKE_FORM') ?: 'experience-certificate';
$caseName = getenv('HRMS_SMOKE_CASE') ?: 'female';
$entry = CASES[$form][$caseName] ?? null;
if ($entry === null) {
    fwrite(STDERR, "Unknown case: {$form} {$caseName}\n");
    exit(1);
}
$case = [...DEFAULTS, ...$entry, 'values' => [...DEFAULTS['values'], ...$entry['values'] ?? []]];
$values = $case['values'];
$run = getenv('HRMS_SMOKE_RUN') ?: '.';
if ($case['long'] !== null && $run !== '.') {
    $share = (float) str_replace('-wrap', '', $run);
    $patterns = [...$case['long'], ...(str_ends_with($run, '-wrap') ? ['reason' => $case['long']['wrap']] : [])];
    foreach (LeaveRequestPdf::LIMITS as $key => $limit) {
        $values[$key] = mb_substr(str_repeat($patterns[$key], 40), 0, (int) floor($limit * $share));
    }
}
Carbon::setTestNow(Carbon::parse((getenv('HRMS_SMOKE_TODAY') ?: $case['today']).' 12:00', 'Africa/Cairo'));
$leave = $form === 'leave-request';
$noPay = $form === 'advance-request-no-pay';
$advance = $noPay || $form === 'advance-request';
$overtime = $form === 'overtime-request';
$ceo = fn (): User => User::firstOrCreate(['email' => 'ceo@synthetic.test'], ['name' => 'ceo', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'ceo')->value('id'), 'is_active' => true]);
try {
    if ($argv[1] === 'runs') {
        // The runs of the case, each rendered into its own folder under the case's ("." is the case's folder itself). The long
        // runs are the top of every value-size step (the 16 pt one is the longest reason it takes), in the approved order.
        $shares = [LeaveRequestPdf::REASON_AT_16[1] / LeaveRequestPdf::LIMITS['reason'], ...array_column(LeaveRequestPdf::SIZES, 0)];
        echo $case['long'] === null ? '.' : implode(' ', array_merge(...array_map(fn (float $share): array => [$share, "{$share}-wrap"], $shares)));
    } elseif ($argv[1] === 'twin') {
        echo $case['twin'] ? 'yes' : 'no';
    } elseif ($argv[1] === 'setup') {
        $employee = app(EmployeeService::class)->create([
            'name_ar' => $values['name_ar'], 'gender' => $case['gender'], 'type' => $noPay ? 'permanent_worker' : 'office', 'effective_from' => '2022-01-01',
            'hire_date' => '2022-01-01', 'salary' => '9000',
            // The runs of one case share a database: each later run's employee takes the next ID (the leave prints none).
            'national_id' => (string) ((int) $case['national_id'] + DB::table('employees')->count()), 'department' => $values['department'],
            ...($case['archived'] ? ['termination_date' => now('Africa/Cairo')->subMonth()->toDateString()] : []),
        ])['id'];
        $hr = User::firstOrCreate(['email' => 'hr@synthetic.test'], ['name' => 'hr', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'hr')->value('id'), 'is_active' => true]);
        if ($advance || $overtime) {
            $id = (string) Str::uuid7();
            $site = Site::create(['name_ar' => 'موقع تجريبي', 'kind' => 'work']);
            [$actor, $scope] = [$hr, null];
            if ($noPay) {
                app(EmployeeService::class)->assignSite($employee, ['site_id' => $site->id, 'effective_from' => '2022-01-01']);
                $actor = User::create(['name' => 'manager', 'email' => 'manager@synthetic.test', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'manager')->value('id'), 'is_active' => true]);
                DB::table('manager_sites')->insert(['manager_id' => $actor->id, 'site_id' => $site->id]);
                $scope = $actor->id;
            }
            DB::table('attendance_requests')->insert(['id' => $id, 'employee_id' => $employee, 'site_id' => $site->id,
                'date' => now('Africa/Cairo')->toDateString(), 'type' => $advance ? 'advance' : 'overtime', 'requested_by' => $actor->id,
                ...($advance ? ['amount' => '250.5'] : ['minutes' => 95, 'in_time' => '09:00', 'out_time' => '17:35'])]);
            [$row] = app(PdfExports::class)->create($advance ? PdfType::AdvanceRequest : PdfType::OvertimeRequest, $id, (string) Str::uuid7(), [
                'job_title' => 'مهندسة اختبار', 'reason' => 'سبب تجريبي',
                ...($advance ? ['repayment_method' => 'cash', 'previous_advance' => false, 'finance_remarks' => 'Synthetic finance remarks'] : []),
            ], $actor, $scope);
        } elseif ($leave) {
            // The migrations seed the annual, casual and unpaid leave day-types; the sick one comes from the sites seeder.
            Site::firstOrCreate(['kind' => 'leave', 'leave_code' => 'sick'], ['name_ar' => 'اجازة مرضي', 'active' => true]);
            $requests = app(LeaveRequests::class);
            $from = now('Africa/Cairo')->next('Monday')->toDateString();
            $request = $requests->submit(['employee_id' => $employee, 'kind' => $case['kind'], 'from_date' => $from, 'to_date' => $from, 'note' => $values['reason']], $hr->id, null);
            Auth::setUser($hr);
            if ($case['escalate']) {
                $request = $requests->escalate($request['id'], $request['version'], null, $hr);
                Auth::setUser($ceo());
                $requests->approve($request['id'], $request['version'], $ceo());
                Auth::setUser($hr);
            } else {
                $requests->approve($request['id'], $request['version'], $hr);
            }
            [$row] = app(PdfExports::class)->create(PdfType::LeaveRequest, $request['id'], (string) Str::uuid7(),
                array_intersect_key($values, array_flip(['job_title', 'notification_method', 'manager_opinion', 'substitute'])), $hr, null);
        } else {
            [$row] = app(PdfExports::class)->create(PdfType::ExperienceCertificate, $employee, (string) Str::uuid7(), ['job_title' => $values['job_title']], $hr, null);
        }
        RenderPdf::dispatch($row->id);
        echo $row->id;
    } elseif ($argv[1] === 'approve') {
        $decider = $leave && ! $case['escalate'] ? User::where('email', 'hr@synthetic.test')->firstOrFail() : $ceo();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAADwAAAAUCAYAAADRA14pAAAA2klEQVR4nO1YwQ2DMAy0rUzBDt2nI3af7pA1UiHhqo1CsA/apKQnIfGIz3c5QwScUqKRIDQYhAZD+Faj6XJ9PjvxfmM6c8LTi9nWEK9wvTw1ebItN0CsCxGRvYyx2zCSUo9mTYYR4bWa1mMtiHCr6J6S3TSMjqTW1WqOTNnLEbYWIGY/iVIPTzjBSppjJtYjSu+tTT0oaVnjz4/MWFjH+ceDR7hHTK2+NuLo5uVcceF5M3z0G9krCuGw9okLb2hldm8d2oPnhPem1OPxswbWkf414Sj4/8eDzo0Hs4qzCU6LdigAAAAASUVORK5CYII=');
        $tmp = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($tmp, $png);
        try {
            app(SignatureImages::class)->replace($decider->id, new UploadedFile($tmp, 'synthetic.png', 'image/png', null, true));
        } finally {
            unlink($tmp);
        }
        Auth::setUser($decider);
        $row = app(PdfExports::class)->approve($argv[2], $decider);
        RenderPdf::dispatch($row->id);
        echo $row->id;
    } elseif ($argv[1] === 'export') {
        $row = DB::table('hr_form_exports')->where('id', $argv[2])->firstOrFail();
        if ($row->status !== 'ready') {
            fwrite(STDERR, "PDF state: {$row->status}\n");
            exit(2);
        }
        $pdf = Storage::disk('local')->get($row->storage_path);
        if (! str_starts_with($pdf, '%PDF-') || hash('sha256', $pdf) !== $row->sha256 || strlen($pdf) !== $row->size_bytes) {
            exit(3);
        }
        echo $pdf;
    } else {
        throw new InvalidArgumentException('Expected runs, twin, setup, approve or export.');
    }

} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}
