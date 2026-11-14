<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\PdfType;
use App\Modules\Pdf\Infrastructure\FilePdfAssets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

final class RenderPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    public function __construct(public readonly string $exportId)
    {
        $this->onConnection('redis')->onQueue('pdf');
        $this->afterCommit();
    }

    public function handle(PdfRendererInterface $renderer): void
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
            $signature = FilePdfAssets::dataUrl((string) $image->mime_type, $bytes);
        }
        $type = PdfType::tryFrom((string) $export->form_type) ?? throw new RuntimeException('Unknown form template.');
        [$view, $data] = PdfExports::document($type)->view($snapshot);

        return view($view, [
            'snapshot' => $snapshot, 'serial' => $export->serial_number, 'signature' => $signature,
            'feminine' => ($snapshot['gender'] ?? null) === 'female',
            'font' => FilePdfAssets::asset('fonts/ibm-plex-sans-arabic-arabic-600-normal.woff2', 'font/woff2'),
            'boldFont' => FilePdfAssets::asset('fonts/ibm-plex-sans-arabic-arabic-700-normal.woff2', 'font/woff2'),
            ...$data,
        ])->render();
    }
}
