<?php

declare(strict_types=1);

namespace App\Modules\Documents\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Documents\Domain\FormRenderer;
use App\Support\Localization\ArabicFormDate;
use App\Support\Localization\ArabicServiceDuration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

final class RenderHrForm implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public function __construct(public readonly string $exportId)
    {
        $this->onConnection('redis')->onQueue('pdf');
        $this->afterCommit();
    }

    public function handle(FormRenderer $renderer): void
    {
        try {
            DB::transaction(function () use ($renderer): void {
                $export = DB::table('hr_form_exports')->where('id', $this->exportId)->whereNull('deleted_at')->lockForUpdate()->first();
                if ($export === null || $export->status !== 'pending') {
                    return;
                }
                $disk = Storage::disk('local');
                $path = (string) $export->storage_path;
                if (! $disk->exists($path)) {
                    $pdf = $renderer->pdf($this->html($export));
                    if (! str_starts_with($pdf, '%PDF-')) {
                        throw new RuntimeException('Invalid PDF output.');
                    }
                    $temporary = 'hr-forms/tmp/'.Str::uuid7().'.pdf';
                    try {
                        if (! $disk->put($temporary, $pdf) || ! $disk->move($temporary, $path)) {
                            throw new RuntimeException('PDF storage unavailable.');
                        }
                    } finally {
                        $disk->delete($temporary);
                    }
                }

                $pdf = $disk->get($path);
                if ($pdf === null || ! str_starts_with($pdf, '%PDF-')) {
                    throw new RuntimeException('Invalid PDF output.');
                }
                DB::table('hr_form_exports')->where('id', $this->exportId)->update([
                    'status' => 'ready', 'sha256' => hash('sha256', $pdf), 'size_bytes' => strlen($pdf), 'updated_at' => now(),
                ]);
                AuditLog::record('GENERATED', 'hr_form_exports', $this->exportId, null, [
                    'form_type' => $export->form_type, 'serial_number' => $export->serial_number, 'stage' => $export->stage,
                ], (string) ($export->impersonator_id ?? $export->requested_by));
            });
        } catch (Throwable) {
            throw new RuntimeException('hr_form_render_failed');
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('hr_form_exports')->where('id', $this->exportId)->where('status', 'pending')->update([
            'status' => 'failed', 'failure_code' => 'render_failed', 'updated_at' => now(),
        ]);
        Log::warning('hr_form_render_failed', ['export_id' => $this->exportId]);
    }

    private function html(stdClass $export): string
    {
        if ((int) $export->template_version !== 1) {
            throw new RuntimeException('Unknown form template.');
        }
        $snapshot = json_decode((string) $export->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $signature = null;
        if ($export->signature_image_id !== null) {
            $image = DB::table('hr_signature_images')->where('id', $export->signature_image_id)->firstOrFail();
            $bytes = Storage::disk('local')->get((string) $image->storage_path);
            if ($bytes === null || ! hash_equals((string) $image->checksum, hash('sha256', $bytes))) {
                throw new RuntimeException('Signature file unavailable.');
            }
            $signature = self::dataUrl((string) $image->mime_type, $bytes);
        }

        $common = [
            'snapshot' => $snapshot, 'serial' => $export->serial_number, 'signature' => $signature,
            'feminine' => ($snapshot['gender'] ?? null) === 'female',
            'font' => self::asset('ibm-plex-sans-arabic-arabic-600-normal.woff2', 'font/woff2'),
            'boldFont' => self::asset('ibm-plex-sans-arabic-arabic-700-normal.woff2', 'font/woff2'),
        ];
        if ($export->form_type === AdvanceRequestForms::FORM) {
            return view('hr-forms.advance-request', [
                ...$common, 'plainPaper' => true,
                'requestDate' => ArabicFormDate::format($snapshot['request_date']),
                'hireDate' => $snapshot['hire_date'] === null ? null : ArabicFormDate::format($snapshot['hire_date']),
                'amount' => bcround($snapshot['amount'], 2),
                'salary' => $snapshot['salary'] === null ? null : bcround($snapshot['salary'], 2),
            ])->render();
        }
        if ($export->form_type === LeaveRequestForms::FORM) {
            $remaining = $snapshot['balance']['remaining'] ?? null;

            return view('hr-forms.leave-request', [
                ...$common, 'plainPaper' => true,
                'duration' => ArabicServiceDuration::days((int) $snapshot['days']),
                'from' => ArabicFormDate::format($snapshot['from_date']),
                'to' => ArabicFormDate::format($snapshot['to_date']),
                'balanceDays' => match ($remaining) {
                    null => 'غير متاح', 0 => '0 يوم', default => ArabicServiceDuration::days((int) $remaining)
                },
            ])->render();
        }
        if ($export->form_type !== 'experience_certificate') {
            throw new RuntimeException('Unknown form template.');
        }

        $issued = Carbon::parse($snapshot['issued_on'], 'Africa/Cairo');
        $start = Carbon::parse($snapshot['hire_date'], 'Africa/Cairo');
        $end = $snapshot['termination_date'] === null ? $issued : Carbon::parse($snapshot['termination_date'], 'Africa/Cairo');
        $service = ArabicServiceDuration::format($start->diff($end));

        return view('hr-forms.experience-certificate', [
            ...$common,
            'footer' => self::asset('certificate-footer.jpg', 'image/jpeg'),
            'letterhead' => self::asset('certificate-letterhead.png', 'image/png'),
            'issuedOn' => ArabicFormDate::format($snapshot['issued_on']),
            'service' => $service === '' ? 'أقل من يوم' : $service,
            'praise' => $snapshot['praise'] ?? ExperienceCertificates::standardPraise($snapshot['gender']),
        ])->render();
    }

    private static function asset(string $name, string $mime): string
    {
        $bytes = file_get_contents(resource_path("hr-forms/{$name}"));
        if ($bytes === false) {
            throw new RuntimeException('Form asset unavailable.');
        }

        return self::dataUrl($mime, $bytes);
    }

    private static function dataUrl(string $mime, string $bytes): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
