<?php

declare(strict_types=1);

use App\Modules\Documents\Application\AdvanceRequestForms;
use App\Modules\Documents\Application\ExperienceCertificates;
use App\Modules\Documents\Application\LeaveRequestForms;
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
try {
    if ($argv[1] === 'setup') {
        $employee = app(EmployeeService::class)->create([
            'name_ar' => 'سارة مثال للاختبار', 'gender' => 'female', 'type' => 'office', 'effective_from' => '2022-01-01',
            'hire_date' => '2022-01-01', 'salary' => '9000', 'national_id' => '12345678901234', 'department' => 'اختبار',
        ])['id'];
        $hr = User::create(['name' => 'hr', 'email' => 'hr@synthetic.test', 'password' => Str::random(24), 'role_id' => Role::where('slug', 'hr')->value('id'), 'is_active' => true]);
        if ($advance) {
            $id = (string) Str::uuid7();
            $site = Site::create(['name_ar' => 'موقع تجريبي', 'kind' => 'work']);
            DB::table('attendance_requests')->insert(['id' => $id, 'employee_id' => $employee, 'site_id' => $site->id,
                'date' => now('Africa/Cairo')->toDateString(), 'type' => 'advance', 'amount' => '250.5', 'requested_by' => $hr->id]);
            $row = app(AdvanceRequestForms::class)->create($id, $hr->id, (string) Str::uuid7(), [
                'job_title' => 'مهندسة اختبار', 'reason' => 'سبب تجريبي', 'repayment_method' => 'cash',
                'previous_advance' => false, 'finance_remarks' => 'ملاحظات تجريبية',
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
