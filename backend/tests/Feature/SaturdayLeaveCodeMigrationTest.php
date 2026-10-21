<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaturdayLeaveCodeMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('drop index sites_active_leave_code_unique');   // DDL rolls back with the test transaction
        DB::table('sites')->where('leave_code', 'saturday')->delete();   // the row the migration made during RefreshDatabase
    }

    private function up(): void
    {
        (require database_path('migrations/2026_10_19_000003_add_saturday_leave_code.php'))->up();
    }

    private function leaveSite(string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('sites')->insert(['id' => $id, 'name_ar' => $name, 'kind' => 'leave', 'leave_code' => 'friday', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function code(string $id): ?string
    {
        return DB::table('sites')->where('id', $id)->value('leave_code');
    }

    private function activeSaturdays(): Collection
    {
        return DB::table('sites')->where(['kind' => 'leave', 'leave_code' => 'saturday', 'active' => true])->whereNull('deleted_at')->get();
    }

    private function audits(string $event, string $id): Collection
    {
        return DB::table('audit_log')->where(['entity_type' => 'sites', 'event' => $event, 'entity_id' => $id])->get();
    }

    public function test_a_saturday_row_coded_friday_is_recoded(): void
    {
        $friday = $this->leaveSite('اجازة الجمعة');
        $saturday = $this->leaveSite('اجازه السبت');

        $this->up();
        $this->up();

        $this->assertSame('saturday', $this->code($saturday));
        $this->assertSame('friday', $this->code($friday));
        $this->assertSame([$saturday], $this->activeSaturdays()->pluck('id')->all());
        $audits = $this->audits('UPDATED', $saturday);
        $this->assertCount(1, $audits);
        $this->assertNull($audits[0]->user_id);
        $this->assertSame(['leave_code' => 'friday'], json_decode($audits[0]->old_values, true));
        $this->assertSame(['leave_code' => 'saturday'], json_decode($audits[0]->new_values, true));
        $this->assertCount(0, DB::table('audit_log')->where('entity_id', $friday)->get());
    }

    public function test_a_missing_saturday_leave_type_is_created(): void
    {
        $friday = $this->leaveSite('اجازة الجمعة');

        $this->up();
        $this->up();

        $saturdays = $this->activeSaturdays();
        $this->assertCount(1, $saturdays);
        $this->assertSame('اجازة السبت', $saturdays[0]->name_ar);
        $this->assertFalse((bool) $saturdays[0]->outside_alexandria);
        $this->assertCount(1, $this->audits('CREATED', $saturdays[0]->id));
        $this->assertSame('friday', $this->code($friday));
        $this->assertCount(0, DB::table('audit_log')->where('entity_id', $friday)->get());
    }
}
