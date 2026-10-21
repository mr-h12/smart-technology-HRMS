<?php

namespace Tests\Feature;

use App\Modules\Sites\Infrastructure\Eloquent\Fare;
use Database\Seeders\SitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('hr');
    }

    private function site(array $over = []): string
    {
        return $this->postJson('/api/v1/sites', ['name_ar' => 'سموحة الدولي', 'kind' => 'work', ...$over])->assertCreated()->json('data.id');
    }

    private function residence(string $name = 'دمنهور'): string
    {
        return $this->postJson('/api/v1/residences', ['name_ar' => $name])->assertCreated()->json('data.id');
    }

    private function employee(?string $residenceId = null): string
    {
        return $this->postJson('/api/v1/employees', [
            'name_ar' => 'أحمد', 'gender' => 'male', 'type' => 'day_laborer', 'daily_wage' => '330',
            'effective_from' => '2026-01-01', 'residence_id' => $residenceId,
        ])->assertCreated()->json('data.id');
    }

    public function test_name_is_normalised_and_collides(): void
    {
        $id = $this->site();
        $this->postJson('/api/v1/sites', ['name_ar' => "سموحة  الدولي \t", 'kind' => 'work'])->assertStatus(422);
        $this->postJson('/api/v1/sites', ['name_ar' => ' موقع   جديد ', 'kind' => 'work'])->assertCreated()->assertJsonPath('data.name_ar', 'موقع جديد');
        $other = $this->site(['name_ar' => 'آخر']);
        $this->patchJson("/api/v1/sites/$other", ['name_ar' => 'سموحة   الدولي'])->assertStatus(422);
        $this->patchJson("/api/v1/sites/$id", ['name_ar' => 'سموحة  الدولي', 'name_en' => 'Smouha'])->assertOk();

        $this->postJson('/api/v1/residences', ['name_ar' => 'دمنهور'])->assertCreated();
        $this->postJson('/api/v1/residences', ['name_ar' => ' دمنهور '])->assertStatus(422);
    }

    public function test_soft_deleted_name_can_be_reused(): void
    {
        $id = $this->site();
        $this->deleteJson("/api/v1/sites/$id")->assertNoContent();
        $this->getJson("/api/v1/sites/$id")->assertNotFound();
        $this->site();
    }

    public function test_leave_site_requires_leave_code(): void
    {
        $this->postJson('/api/v1/sites', ['name_ar' => 'غ', 'kind' => 'leave'])->assertStatus(422);
        $this->postJson('/api/v1/sites', ['name_ar' => 'غ', 'kind' => 'leave', 'leave_code' => 'bogus'])->assertStatus(422);
        $this->postJson('/api/v1/sites', ['name_ar' => 'س', 'kind' => 'work', 'leave_code' => 'sick'])->assertStatus(422);
        $id = $this->postJson('/api/v1/sites', ['name_ar' => 'غ', 'kind' => 'leave', 'leave_code' => 'absent'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/sites/$id", ['kind' => 'work'])->assertStatus(422);
        $this->patchJson("/api/v1/sites/$id", ['kind' => 'work', 'leave_code' => null])->assertOk();
    }

    public function test_list_filters_and_show(): void
    {
        $this->site();
        $this->postJson('/api/v1/sites', ['name_ar' => 'غ', 'kind' => 'leave', 'leave_code' => 'absent', 'active' => false])->assertCreated();
        // plus the Saturday migration's active «اجازة السبت»
        $this->getJson('/api/v1/sites')->assertJsonCount(3, 'data')->assertJsonPath('meta.pagination.total', 3);
        $this->assertContains('absent', $this->getJson('/api/v1/sites?kind=leave')->assertJsonCount(2, 'data')->json('data.*.leave_code'));
        $this->assertEqualsCanonicalizing(['leave', 'work'], $this->getJson('/api/v1/sites?active=true')->assertJsonCount(2, 'data')->json('data.*.kind'));
        $this->getJson('/api/v1/sites?active=false')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sites?kind=bogus')->assertStatus(422);
        $this->getJson('/api/v1/sites/not-a-uuid')->assertNotFound();
    }

    public function test_allowance_rules(): void
    {
        $id = $this->site();
        $this->postJson("/api/v1/sites/$id/allowance-rules", ['effective_from' => '2026-01-01', 'mode' => 'share_of_wage'])->assertStatus(422);
        $this->postJson("/api/v1/sites/$id/allowance-rules", ['effective_from' => '2026-01-01', 'mode' => 'fixed', 'value' => '-1'])->assertStatus(422);
        $this->postJson("/api/v1/sites/$id/allowance-rules", ['effective_from' => '2026-01-01', 'mode' => 'bogus', 'value' => '1'])->assertStatus(422);
        $this->postJson("/api/v1/sites/$id/allowance-rules", ['effective_from' => '2026-01-01', 'mode' => 'none'])->assertCreated()
            ->assertJsonPath('data.allowance_rules.0.value', '0.000000');
        $this->postJson("/api/v1/sites/$id/allowance-rules", ['effective_from' => '2026-01-01', 'mode' => 'fixed', 'value' => '5'])->assertStatus(422);
        $this->postJson("/api/v1/sites/$id/allowance-rules", ['effective_from' => '2026-02-01', 'mode' => 'share_of_wage', 'value' => '0.25'])->assertCreated()
            ->assertJsonCount(2, 'data.allowance_rules')->assertJsonPath('data.allowance_rules.1.value', '0.250000');
        $this->postJson('/api/v1/sites/'.fake()->uuid().'/allowance-rules', ['effective_from' => '2026-01-01', 'mode' => 'none'])->assertNotFound();
    }

    public function test_fare_create_and_list_filters(): void
    {
        $r1 = $this->residence();
        $r2 = $this->residence('العامرية');
        $s = $this->site();
        $this->postJson('/api/v1/fares', ['residence_id' => $r1, 'site_id' => $s, 'effective_from' => '2026-01-01', 'amount' => '40'])->assertCreated()->assertJsonPath('data.amount', '40.000000');
        $this->postJson('/api/v1/fares', ['residence_id' => $r1, 'site_id' => $s, 'effective_from' => '2026-01-01', 'amount' => '50'])->assertStatus(422);
        $this->postJson('/api/v1/fares', ['residence_id' => $r2, 'site_id' => $s, 'effective_from' => '2026-01-01', 'amount' => '60'])->assertCreated();
        $this->postJson('/api/v1/fares', ['residence_id' => $r2, 'site_id' => $s, 'effective_from' => '2026-01-01'])->assertStatus(422);
        $this->postJson('/api/v1/fares', ['residence_id' => fake()->uuid(), 'site_id' => $s, 'effective_from' => '2026-02-01', 'amount' => '1'])->assertStatus(422);

        $this->getJson('/api/v1/fares')->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/fares?residence_id=$r1")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/fares?site_id=$s&residence_id=$r2")->assertJsonCount(1, 'data');
    }

    public function test_fares_date_filter_returns_only_the_row_in_force_per_pair(): void
    {
        $r1 = $this->residence();
        $r2 = $this->residence('العامرية');
        $s = $this->site();
        foreach ([[$r1, '2026-01-01', '40'], [$r1, '2026-03-01', '45'], [$r1, '2999-01-01', '99'], [$r2, '2026-02-01', '60']] as [$r, $d, $a]) {
            $this->postJson('/api/v1/fares', ['residence_id' => $r, 'site_id' => $s, 'effective_from' => $d, 'amount' => $a])->assertCreated();
        }

        $res = $this->getJson('/api/v1/fares?date=2026-06-01')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.pagination.total', 2);
        $this->assertEqualsCanonicalizing(['45.000000', '60.000000'], array_column($res->json('data'), 'amount'));
        $this->getJson('/api/v1/fares?date=2025-01-01')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/fares', ['residence_id' => $r1, 'site_id' => $s, 'effective_from' => '2026-04-01', 'amount' => '9999999999999'])->assertStatus(422);
        $this->postJson("/api/v1/sites/$s/allowance-rules", ['effective_from' => '2026-01-01', 'mode' => 'fixed', 'value' => '9999999999999'])->assertStatus(422);
    }

    public function test_fare_lookup_override_default_none_and_future_dated_ignored(): void
    {
        $res = $this->residence();
        $s = $this->site();
        $emp = $this->employee($res);
        $url = "/api/v1/employees/$emp/fare?site_id=$s&date=2026-06-15";

        $this->getJson($url)->assertOk()->assertJsonPath('data.source', 'none')->assertJsonPath('data.amount', '0.000000');

        $this->postJson('/api/v1/fares', ['residence_id' => $res, 'site_id' => $s, 'effective_from' => '2026-01-01', 'amount' => '40'])->assertCreated();
        $this->postJson('/api/v1/fares', ['residence_id' => $res, 'site_id' => $s, 'effective_from' => '2026-09-01', 'amount' => '99'])->assertCreated();
        $this->getJson($url)->assertJsonPath('data.source', 'default')->assertJsonPath('data.amount', '40.000000');
        $this->getJson("/api/v1/employees/$emp/fare?site_id=$s&date=2026-09-01")->assertJsonPath('data.amount', '99.000000');

        $this->postJson("/api/v1/employees/$emp/fare-overrides", ['site_id' => $s, 'effective_from' => '2026-03-01', 'amount' => '25'])->assertCreated();
        $this->postJson("/api/v1/employees/$emp/fare-overrides", ['site_id' => $s, 'effective_from' => '2026-12-01', 'amount' => '1'])->assertCreated();
        $this->postJson("/api/v1/employees/$emp/fare-overrides", ['site_id' => $s, 'effective_from' => '2026-03-01', 'amount' => '2'])->assertStatus(422);
        $this->getJson($url)->assertJsonPath('data.source', 'override')->assertJsonPath('data.amount', '25.000000');
        $this->getJson("/api/v1/employees/$emp/fare?site_id=$s&date=2026-02-01")->assertJsonPath('data.source', 'default')->assertJsonPath('data.amount', '40.000000');

        $this->getJson("/api/v1/employees/$emp/fare")->assertStatus(422);
        $this->getJson('/api/v1/employees/'.fake()->uuid()."/fare?site_id=$s&date=2026-06-15")->assertNotFound();
        $this->postJson('/api/v1/employees/'.fake()->uuid().'/fare-overrides', ['site_id' => $s, 'effective_from' => '2026-03-01', 'amount' => '2'])->assertNotFound();
    }

    public function test_fare_lookup_requires_an_existing_site(): void
    {
        $emp = $this->employee();
        $this->getJson("/api/v1/employees/$emp/fare?date=2026-06-15")->assertStatus(422);
        $this->getJson("/api/v1/employees/$emp/fare?site_id=".fake()->uuid())->assertStatus(422);
        $s = $this->site();
        $this->deleteJson("/api/v1/sites/$s")->assertNoContent();
        $this->getJson("/api/v1/employees/$emp/fare?site_id=$s")->assertStatus(422);
    }

    public function test_employee_residence_must_exist_and_be_live(): void
    {
        $res = $this->residence();
        $emp = $this->employee();
        $base = ['name_ar' => 'س', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '1'];
        $this->postJson('/api/v1/employees', [...$base, 'residence_id' => fake()->uuid()])->assertStatus(422);
        $this->patchJson("/api/v1/employees/$emp", ['residence_id' => fake()->uuid()])->assertStatus(422);
        $this->patchJson("/api/v1/employees/$emp", ['residence_id' => $res])->assertOk();
        $this->deleteJson("/api/v1/residences/$res")->assertNoContent();
        $this->postJson('/api/v1/employees', [...$base, 'residence_id' => $res])->assertStatus(422);
    }

    public function test_employee_without_residence_gets_none(): void
    {
        $s = $this->site();
        $emp = $this->employee();
        $this->getJson("/api/v1/employees/$emp/fare?site_id=$s&date=2026-06-15")->assertJsonPath('data.source', 'none');
    }

    public function test_deleting_a_residence_nulls_employee_fk(): void
    {
        $res = $this->residence();
        $emp = $this->employee($res);
        \DB::table('residences')->where('id', $res)->delete();
        $this->assertNull(\DB::table('employees')->where('id', $emp)->value('residence_id'));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SitesSeeder::class);
        $this->seed(SitesSeeder::class);
        $this->assertSame(6, \DB::table('sites')->count());   // 5 seeded + the Saturday migration's row
        $this->assertSame(4, \DB::table('residences')->count());
        $this->assertSame('absent', \DB::table('sites')->where('name_ar', 'غ')->value('leave_code'));
        $this->assertSame(0, Fare::count());
    }

    public function test_lists_accept_per_page_from_the_query_string(): void
    {
        foreach (['/api/v1/sites', '/api/v1/residences', '/api/v1/fares'] as $url) {
            $this->getJson($url.'?per_page=100')->assertOk()->assertJsonPath('meta.pagination.per_page', 100);
        }
    }
}
