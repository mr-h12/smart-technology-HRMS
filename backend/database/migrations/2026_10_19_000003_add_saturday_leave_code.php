<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A data migration: Saturday leave used to be coded friday. It runs before the one-active-leave-type-per-code index,
// changes rows HR edits, so it writes their audit rows itself. Idempotent: a re-run matches nothing.
return new class extends Migration
{
    public function up(): void
    {
        $recoded = $this->liveLeave('friday')->where('name_ar', 'like', '%السبت%')->pluck('id');
        $this->recode($recoded->all(), 'friday', 'saturday');

        if (! $this->liveLeave('saturday')->exists()) {
            $row = ['id' => (string) Str::uuid7(), 'name_ar' => 'اجازة السبت', 'kind' => 'leave', 'leave_code' => 'saturday', 'active' => true, 'outside_alexandria' => false];
            DB::table('sites')->insert([...$row, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('CREATED', $row['id'], null, $row);
        }
    }

    public function down(): void
    {
        // A row up() inserted stays, as a leave type HR can deactivate.
        $this->recode(DB::table('sites')->where('kind', 'leave')->where('leave_code', 'saturday')->pluck('id')->all(), 'saturday', 'friday');
    }

    private function liveLeave(string $code): Builder
    {
        return DB::table('sites')->where(['kind' => 'leave', 'leave_code' => $code, 'active' => true])->whereNull('deleted_at');
    }

    /** @param  list<string>  $ids */
    private function recode(array $ids, string $from, string $to): void
    {
        DB::table('sites')->whereIn('id', $ids)->update(['leave_code' => $to, 'updated_at' => now()]);
        foreach ($ids as $id) {
            $this->audit('UPDATED', $id, ['leave_code' => $from], ['leave_code' => $to]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(string $event, string $id, ?array $old, array $new): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => null, 'event' => $event, 'entity_type' => 'sites', 'entity_id' => $id,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ]);
    }
};
