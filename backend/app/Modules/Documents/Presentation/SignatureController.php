<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation;

use App\Modules\Documents\Application\SignatureImages;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SignatureController
{
    public function __construct(private readonly SignatureImages $images) {}

    public function show(Request $request): JsonResponse
    {
        return ApiEnvelope::single($request, ['signature' => $this->images->current((string) $request->user()?->getAuthIdentifier())]);
    }

    public function file(Request $request): StreamedResponse
    {
        $image = $this->images->file((string) $request->user()?->getAuthIdentifier());
        $disk = Storage::disk('local');
        abort_unless($disk->exists($image['path']), 404);

        return $disk->response($image['path'], null, [
            'Content-Type' => $image['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', File::types(['png', 'jpg', 'jpeg'])->max(2048)]]);
        /** @var UploadedFile $file */
        $file = $request->file('file');

        return ApiEnvelope::single($request, ['signature' => $this->images->replace((string) $request->user()?->getAuthIdentifier(), $file)], 201);
    }
}
