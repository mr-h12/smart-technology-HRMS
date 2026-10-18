<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

use RuntimeException;

/** Rendered into the error envelope in bootstrap/app.php from its reason. */
final class AttendanceRefused extends RuntimeException
{
    /** @param  array<string, string>  $params  lang placeholders */
    private function __construct(public readonly AttendanceRefusal $reason, public readonly array $params)
    {
        parent::__construct('Attendance refused: '.$reason->value);
    }

    /** @param  array<string, string>  $params */
    public static function because(AttendanceRefusal $reason, array $params = []): self
    {
        return new self($reason, $params);
    }
}
