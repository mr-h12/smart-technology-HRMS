<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Audit\Infrastructure\AuditLog;
use finfo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SignatureImages
{
    /** @return array{version: int, created_at: string}|null */
    public function current(string $owner): ?array
    {
        $image = DB::table('hr_signature_images')->where('owner_id', $owner)->whereNull('deleted_at')
            ->orderByDesc('version')->first(['version', 'created_at']);

        return $image === null ? null : ['version' => (int) $image->version, 'created_at' => (string) $image->created_at];
    }

    /** @return array{path: string, mime: string} */
    public function file(string $owner): array
    {
        $image = DB::table('hr_signature_images')->where('owner_id', $owner)->whereNull('deleted_at')
            ->orderByDesc('version')->first(['storage_path', 'mime_type']) ?? throw new ModelNotFoundException;

        return ['path' => (string) $image->storage_path, 'mime' => (string) $image->mime_type];
    }

    /** @return array{version: int, created_at: string} */
    public function replace(string $owner, UploadedFile $file): array
    {
        $bytes = $file->getContent();
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            default => throw ValidationException::withMessages(['file' => __('errors.signature_type')]),
        };
        $dimensions = @getimagesizefromstring($bytes);
        if ($dimensions === false || $dimensions['mime'] !== $mime || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw ValidationException::withMessages(['file' => __('errors.signature_type')]);
        }
        if ($dimensions[0] > intdiv(4_000_000, $dimensions[1])) {
            throw ValidationException::withMessages(['file' => __('errors.signature_pixels')]);
        }

        if (@imagecreatefromstring($bytes) === false) {
            throw ValidationException::withMessages(['file' => __('errors.signature_type')]);
        }

        $id = (string) Str::uuid7();
        $name = "{$id}.{$extension}";
        $directory = 'hr-signatures';
        $path = "{$directory}/{$name}";
        $checksum = hash('sha256', $bytes);
        $disk = Storage::disk('local');
        if ($disk->putFileAs($directory, $file, $name) === false) {
            throw new RuntimeException('Signature storage unavailable.');
        }

        try {
            return DB::transaction(function () use ($id, $owner, $path, $mime, $checksum): array {
                DB::table('users')->where('id', $owner)->lockForUpdate()->firstOrFail();
                $version = (int) DB::table('hr_signature_images')->where('owner_id', $owner)->max('version') + 1;
                $now = now();
                DB::table('hr_signature_images')->insert([
                    'id' => $id, 'owner_id' => $owner, 'version' => $version, 'storage_path' => $path,
                    'mime_type' => $mime, 'checksum' => $checksum, 'created_by' => $owner, 'updated_by' => $owner,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                AuditLog::record($version === 1 ? 'CREATED' : 'REPLACED', 'hr_signature_images', $id, null, ['version' => $version]);

                return ['version' => $version, 'created_at' => $now->toIso8601String()];
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }
}
