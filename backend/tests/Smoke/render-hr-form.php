<?php

declare(strict_types=1);

use App\Modules\Documents\Application\AttendanceRequestForms;
use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Documents\Application\LeaveRequestForms;
use App\Modules\Documents\Application\ManualHrForms;
use App\Modules\Documents\Application\RenderHrForm;
use App\Modules\Documents\Application\SignatureImages;
use App\Modules\Employees\Application\EmployeeService;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Leave\Application\LeaveRequests;
use App\Modules\Sites\Infrastructure\Eloquent\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$leave = getenv('HRMS_SMOKE_FORM') === 'leave-request';
$advance = getenv('HRMS_SMOKE_FORM') === 'advance-request';
$overtime = getenv('HRMS_SMOKE_FORM') === 'overtime-request';
$latePermit = getenv('HRMS_SMOKE_FORM') === 'late-permit';
$earlyLeave = getenv('HRMS_SMOKE_FORM') === 'early-leave-request';
$resignation = getenv('HRMS_SMOKE_FORM') === 'resignation';
$warningNotice = getenv('HRMS_SMOKE_FORM') === 'warning-notice';
$missionPermit = getenv('HRMS_SMOKE_FORM') === 'mission-permit';
$departurePermit = getenv('HRMS_SMOKE_FORM') === 'leave-permit';
try {
    if ($argv[1] === 'setup') {
        $employee = app(EmployeeService::class)->create([
            'name_ar' => 'سارة مثال للاختبار', 'gender' => 'female', 'type' => 'office', 'effective_from' => '2022-01-01',
            'hire_date' => '2022-01-01', 'salary' => '9000', 'national_id' => '12345678901234', 'department' => 'اختبار',
        ])['id'];
        $hr = User::create(['name' => 'hr', 'email' => 'hr@synthetic.test', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'hr')->value('id'), 'is_active' => true]);
        if ($advance || $overtime) {
            $id = (string) Str::uuid7();
            $site = Site::create(['name_ar' => 'موقع تجريبي', 'kind' => 'work']);
            DB::table('attendance_requests')->insert(['id' => $id, 'employee_id' => $employee, 'site_id' => $site->id,
                'date' => now('Africa/Cairo')->toDateString(), 'type' => $advance ? 'advance' : 'overtime', 'requested_by' => $hr->id,
                ...($advance ? ['amount' => '250.5'] : ['minutes' => 95, 'in_time' => '09:00', 'out_time' => '17:35'])]);
            $row = app(AttendanceRequestForms::class)->create($id, $hr->id, (string) Str::uuid7(), [
                'job_title' => 'مهندسة اختبار', 'reason' => 'سبب تجريبي',
                ...($advance ? ['repayment_method' => 'cash', 'previous_advance' => false, 'finance_remarks' => 'ملاحظات تجريبية'] : []),
            ], null, $advance ? 'advance' : 'overtime');
        } elseif ($missionPermit) {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('mission_permit', $employee, $hr->id, (string) Str::uuid7(), [
                'job_title' => 'مهندسة اختبار',
                'mission_date' => '2026-11-15',
                'mission_reason' => 'متابعة موقع الاختبار.',
                'department_manager' => 'مدير الاختبار',
            ], null);
        } elseif (getenv('HRMS_SMOKE_FORM') === 'sim-receipt') {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('sim_receipt', $employee, $hr->id, (string) Str::uuid7(), [
                'sim_number' => '01000000000', 'issued_on' => '2026-11-15',
            ], null);
        } elseif (getenv('HRMS_SMOKE_FORM') === 'job-offer') {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('job_offer', null, $hr->id, (string) Str::uuid7(), [
                'issued_on' => '2027-02-01', 'candidate_name' => 'نور مثال للاختبار', 'address' => 'شارع الاختبار',
                'job_title' => 'مهندسة اختبار', 'monthly_salary' => '12345.6', 'evaluation_months' => 4,
                'reply_deadline' => '2027-02-05', 'start_date' => '2027-02-08',
            ], null);
        } elseif ($departurePermit) {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('leave_permit', $employee, $hr->id, (string) Str::uuid7(), [
                'addressee' => 'مدير الاختبار', 'permit_date' => '2026-11-16', 'start_time' => '14:30', 'end_time' => '16:30',
                'reason' => 'موعد طبي', 'job_title' => 'مهندسة اختبار', 'responsible_manager' => 'مسؤول القسم', 'issued_on' => '2026-11-15',
            ], null);
        } elseif ($warningNotice) {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('warning_notice', $employee, $hr->id, (string) Str::uuid7(), [
                'job_title' => 'مهندسة اختبار', 'warning_date' => '2026-11-15',
                'warning_incident' => 'لم يتم تسليم ملف الاختبار.', 'warning_period' => 'لمدة يوم واحد.',
                'warning_prior_occurrence' => 'هذه أول مرة.', 'warning_explanation_request' => 'يرجى توضيح السبب.',
            ], null);
        } elseif ($resignation) {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('resignation', $employee, $hr->id, (string) Str::uuid7(), [
                'confirm_declarations' => 'on',
            ], null);
        } elseif ($earlyLeave) {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('early_leave_request', $employee, $hr->id, (string) Str::uuid7(), [
                'regular_start' => '08:30', 'regular_end' => '17:30', 'requested_end' => '16:00', 'issued_on' => '2026-11-15',
            ], null);
        } elseif ($latePermit) {
            Auth::setUser($hr);
            $row = app(ManualHrForms::class)->create('late_permit', $employee, $hr->id, (string) Str::uuid7(), [
                'addressee' => 'مدير الاختبار', 'permit_date' => '2026-11-16', 'start_time' => '09:00', 'end_time' => '11:00',
                'reason' => 'موعد طبي', 'job_title' => 'مهندسة اختبار', 'responsible_manager' => 'مسؤول القسم', 'issued_on' => '2026-11-15',
            ], null);
        } elseif ($leave) {
            $requests = app(LeaveRequests::class);
            $from = now('Africa/Cairo')->next('Monday')->toDateString();
            $request = $requests->submit(['employee_id' => $employee, 'kind' => 'annual', 'from_date' => $from, 'to_date' => $from, 'note' => 'سبب تجريبي'], $hr->id, null);
            Auth::setUser($hr);
            $requests->approve($request['id'], $request['version'], $hr);
            $row = app(LeaveRequestForms::class)->create($request['id'], $hr->id, null, (string) Str::uuid7(), [
                'job_title' => 'مهندسة اختبار', 'notification_method' => 'الهاتف', 'manager_opinion' => 'رأي تجريبي', 'substitute' => 'بديل تجريبي',
            ]);
        } else {
            $row = app(ExperienceCertificates::class)->create($employee, $hr->id, (string) Str::uuid7(), 'مهندسة اختبار', null);
        }
        RenderHrForm::dispatch($row->id);
        echo $row->id;
    } elseif ($argv[1] === 'approve') {
        $decider = $leave ? User::where('email', 'hr@synthetic.test')->firstOrFail() : User::create(['name' => 'ceo', 'email' => 'ceo@synthetic.test', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'ceo')->value('id'), 'is_active' => true]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAADwAAAAUCAYAAADRA14pAAAA2klEQVR4nO1YwQ2DMAy0rUzBDt2nI3af7pA1UiHhqo1CsA/apKQnIfGIz3c5QwScUqKRIDQYhAZD+Faj6XJ9PjvxfmM6c8LTi9nWEK9wvTw1ebItN0CsCxGRvYyx2zCSUo9mTYYR4bWa1mMtiHCr6J6S3TSMjqTW1WqOTNnLEbYWIGY/iVIPTzjBSppjJtYjSu+tTT0oaVnjz4/MWFjH+ceDR7hHTK2+NuLo5uVcceF5M3z0G9krCuGw9okLb2hldm8d2oPnhPem1OPxswbWkf414Sj4/8eDzo0Hs4qzCU6LdigAAAAASUVORK5CYII=');
        $tmp = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($tmp, $png);
        try {
            app(SignatureImages::class)->replace($decider->id, new UploadedFile($tmp, 'synthetic.png', 'image/png', null, true));
        } finally {
            unlink($tmp);
        }
        Auth::setUser($decider);
        $row = $leave ? app(LeaveRequestForms::class)->approve($argv[2], $decider->id)
            : app(ExperienceCertificates::class)->approve($argv[2], $decider->id);
        RenderHrForm::dispatch($row->id);
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
        throw new InvalidArgumentException('Expected setup, approve or export.');
    }

} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}
