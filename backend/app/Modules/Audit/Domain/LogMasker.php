<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

/**
 * Masks what the request and error logs store: a key rule (secrets by substring, sensitive fields by exact name) at any depth,
 * then a shape rule (a run of 7+ digits, Arabic-Indic and Persian digits included) on every remaining string, keys included, and
 * on every int or float. A whole UUID is kept: its hex groups often hold 7 digits in a row. The spec's "Masking" section is the
 * source, these build-time additions included.
 */
final class LogMasker
{
    public const MASK = '[masked]';

    private const SECRET_FRAGMENTS = ['password', 'token', 'secret', 'authorization', 'cookie'];

    /** Exact names, not fragments: `pay`/`rate`/`basic` would also hide `pay_method`, `allowance_mode` and `fares`. */
    private const SENSITIVE_KEYS = ['salary', 'raise', 'insured_basic', 'daily_wage', 'value', 'amount', 'advance_request', 'advance_amount', 'current_pay', 'national_id',
        'insurance_no', 'bank_account', 'phone',
        'warning_incident', 'warning_period', 'warning_prior_occurrence', 'warning_explanation_request'];

    /** Western, Arabic-Indic and Persian digits, mixed in one run too: HR types on Arabic keyboards. `/u` is safe after mb_scrub. */
    private const DIGIT = '[0-9٠-٩۰-۹]';

    private const DIGIT_RUN = '/'.self::DIGIT.'{7,}/u';

    /** DIGIT_RUN for text, with a whole UUID (not glued to more hex or any digit) matched first so text() can hand it back unchanged. */
    private const UUID_OR_DIGIT_RUN = '/(?<![a-f]|'.self::DIGIT.')[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?![a-f]|'
        .self::DIGIT.')|'.self::DIGIT.'{7,}/iu';

    private const MAX_BYTES = 65536;

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function data(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            // The key rules see the cleaned key ("pass\0word" is "password"); a numeric key string ("0") turns back into an int key.
            $key = self::text((string) $key);
            $out[$key] = match (true) {
                self::sensitive(strtolower($key)) => self::MASK,
                is_array($value) => self::data($value),
                is_string($value) => self::text($value),
                (is_int($value) || is_float($value)) && preg_match(self::DIGIT_RUN, (string) $value) === 1 => self::MASK,
                default => $value,
            };
        }

        return $out;
    }

    public static function text(string $text): string
    {
        return (string) preg_replace_callback(
            self::UUID_OR_DIGIT_RUN,
            fn (array $m) => str_contains($m[0], '-') ? $m[0] : self::MASK, // only the UUID branch matches a '-'
            (string) mb_scrub(str_replace("\0", '', $text), 'UTF-8'),
        );
    }

    /**
     * @param  array<mixed>|null  $data
     *
     * @throws \JsonException
     */
    public static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }
        // NULs are already gone from every key and string (text()), so no `\u0000` reaches Postgres jsonb.
        $json = json_encode(self::data($data), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        return strlen($json) > self::MAX_BYTES
            ? json_encode(['_truncated' => true, 'bytes' => strlen($json)], JSON_THROW_ON_ERROR)
            : $json;
    }

    private static function sensitive(string $key): bool
    {
        foreach (self::SECRET_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return in_array($key, self::SENSITIVE_KEYS, true) || str_ends_with($key, '_amount');
    }
}
