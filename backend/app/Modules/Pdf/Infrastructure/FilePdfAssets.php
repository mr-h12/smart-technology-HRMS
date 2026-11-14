<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Infrastructure;

use RuntimeException;

/** Fonts and images inlined as data URLs: the renderer blocks every network request. */
final class FilePdfAssets
{
    /** @param string $path relative to resources/pdf, e.g. `fonts/…` or `letterhead/…` */
    public static function asset(string $path, string $mime): string
    {
        $bytes = file_get_contents(resource_path("pdf/{$path}"));
        if ($bytes === false) {
            throw new RuntimeException('Form asset unavailable.');
        }

        return self::dataUrl($mime, $bytes);
    }

    public static function dataUrl(string $mime, string $bytes): string
    {
        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
