<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Success and error response shapes; every response carries meta.request_id. */
final class ApiEnvelope
{
    /** Mirrors App\Http\Middleware\AddRequestId::ATTRIBUTE (kept as a string so modules need not import it). */
    public const REQUEST_ATTRIBUTE = 'request_id';

    /**
     * @param  array<mixed>  $data
     * @param  array<string, mixed>  $meta  merged under request_id, never over it
     */
    public static function single(Request $request, array $data, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse(
            ['data' => $data, 'meta' => [...$meta, 'request_id' => self::requestId($request)]],
            $status,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool}  $pagination
     */
    public static function collection(Request $request, array $data, array $pagination): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => ['pagination' => $pagination, 'request_id' => self::requestId($request)],
        ]);
    }

    /** @param  list<array{field?: string, code: string, message: string}>  $details */
    public static function error(Request $request, int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(
            ['error' => $error, 'meta' => ['request_id' => self::requestId($request)]],
            $status,
        );
    }

    private static function requestId(Request $request): ?string
    {
        $id = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
