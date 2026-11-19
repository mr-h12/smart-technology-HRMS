<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Text\Digits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every numeric-entry field takes English, Arabic-Indic and Persian digits (owner, 2026-11-19): before validation, the string values
 * of numeric fields (amounts, wages, phone, national ID, code, minutes, days, times, dates, paging, search) read as ASCII. Free text
 * (names, notes, reasons, addresses) and secrets are never touched: only keys on the list below change, at any depth (`rows.*.in_time`).
 */
final class NormalizeDigits
{
    private const NUMERIC_KEY = '/^(?:from|to|q|returns_on|expires_at|daily_wage|salary|raise|insured_basic|amount|advance_request|advance_amount|phone|national_id|insurance_no|code|search|value|bonus|deduction|negligence|advances|income_tax|insurance_employee|insurance_company|entitlement|opening_used|opening_casual_used|version|page|per_page|year|date|in_time|out_time|scheduled_in|scheduled_out|effective_from)$|_(?:minutes|days|hours|multiplier|percent|amount|start|end|until|date)$|^(?:lateness_multiplier_|leave_days_)/D';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->query->replace(self::walk($request->query->all()));
        $body = $request->isJson() ? $request->json() : $request->request;
        $body->replace(self::walk($body->all()));

        return $next($request);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, ?string $parent = null): array
    {
        foreach ($data as $key => $value) {
            // a list item keeps its list's key: `ids[]` is as numeric as `ids`
            $name = is_int($key) ? $parent : $key;
            if (is_array($value)) {
                $data[$key] = self::walk($value, $name);
            } elseif (is_string($value) && $name !== null && preg_match(self::NUMERIC_KEY, $name) === 1) {
                $data[$key] = Digits::ascii($value);
            }
        }

        return $data;
    }
}
