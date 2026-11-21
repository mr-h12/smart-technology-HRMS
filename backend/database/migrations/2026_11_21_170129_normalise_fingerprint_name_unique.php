<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // FP-2: a fingerprint name is unique ignoring letter case and ASCII whitespace, among employees not soft-deleted.
    // The expression is copied, not imported, so this migration never changes once it has run: EmployeeRules::FINGERPRINT_KEY_SQL holds the same text.
    private const KEY = <<<'SQL'
        lower(regexp_replace(fingerprint_name, '[ \t\n\r\f\v]+', '', 'g'))
        SQL;

    public function up(): void
    {
        $collisions = DB::select('select '.self::KEY.' k, count(*) from employees where deleted_at is null and fingerprint_name is not null group by 1 having count(*) > 1');
        $this->refuse($collisions, 'These fingerprint names are held by more than one employee once letter case and spaces are ignored');

        DB::statement('alter table employees drop constraint employees_fingerprint_name_unique');
        DB::statement('create unique index employees_fingerprint_key_unique on employees (('.self::KEY.')) where deleted_at is null and fingerprint_name is not null');
    }

    public function down(): void
    {
        // The new rule lets a new employee take a soft-deleted employee's name; the old constraint, over every row, refuses that.
        $collisions = DB::select('select fingerprint_name k, count(*) from employees where fingerprint_name is not null group by 1 having count(*) > 1');
        $this->refuse($collisions, 'These fingerprint names are held by more than one employee, soft-deleted ones included');

        DB::statement('drop index employees_fingerprint_key_unique');
        DB::statement('alter table employees add constraint employees_fingerprint_name_unique unique (fingerprint_name)');
    }

    /** @param  array<object{k: string, count: int}>  $collisions  changes no row: HR fixes those employees and tries again */
    private function refuse(array $collisions, string $what): void
    {
        if ($collisions === []) {
            return;
        }
        $list = implode(', ', array_map(fn (object $c) => sprintf('"%s" (%d)', $c->k, $c->count), $collisions));

        throw new RuntimeException("$what: $list. Fix those employees first, then try again.");
    }
};
