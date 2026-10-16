<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation;

use App\Modules\Audit\Application\AuditLogQuery;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController
{
    public function index(Request $request, AuditLogQuery $audit): JsonResponse
    {
        $f = $request->validate([
            'user_id' => ['sometimes', 'uuid'],
            'entity_type' => ['sometimes', 'string', 'max:64'],
            'entity_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $r = $audit->list($f);

        return ApiEnvelope::collection($request, $r['data'], $r['pagination']);
    }
}
