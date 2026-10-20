<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application;

use Illuminate\Validation\ValidationException;

/** A day DayRules refuses: a 422 like any ValidationException, that also keeps each violation's code for the import's per-cell errors. */
final class DayRulesViolated extends ValidationException
{
    /** @var list<array{field: string, code: string}> */
    public array $violations = [];

    /** @param  non-empty-list<array{field: string, code: string}>  $violations */
    public static function of(array $violations): self
    {
        $messages = [];
        foreach ($violations as $v) {
            $messages[$v['field']][] = (string) __('attendance.rule.'.$v['code']);
        }
        $e = self::withMessages($messages);
        $e->violations = $violations;

        return $e;
    }
}
