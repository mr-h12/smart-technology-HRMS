<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_the_seeded_business_rules(): void
    {
        $this->signIn('hr');
        $this->getJson('/api/v1/settings')->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('data.month_days', '30')
            ->assertJsonPath('data.day_hours', '8')
            ->assertJsonPath('data.overtime_multiplier', '1.5')
            ->assertJsonPath('data.lateness_multiplier_office', '1')
            ->assertJsonPath('data.lateness_multiplier_engineer', '1.5')
            ->assertJsonPath('data.lateness_multiplier_permanent_worker', '2')
            ->assertJsonPath('data.lateness_multiplier_day_laborer', '2')
            ->assertJsonPath('data.office_grace_minutes', '15')
            ->assertJsonPath('data.office_shift_start', '09:00')
            ->assertJsonPath('data.office_shift_end', '17:00')
            ->assertJsonPath('data.weekly_off_office', 'friday,saturday')
            ->assertJsonPath('data.weekly_off_field', 'friday')
            ->assertJsonPath('data.leave_days_under_one_year', '10')
            ->assertJsonPath('data.leave_days_one_year_plus', '21')
            ->assertJsonPath('data.leave_casual_days', '6');
    }

    public function test_put_saves_changed_rules_exactly_and_audits_each(): void
    {
        $hr = $this->signIn('hr');
        $this->putJson('/api/v1/settings', ['office_grace_minutes' => '20', 'month_days' => '30', 'overtime_multiplier' => '1.333333'])
            ->assertOk()
            ->assertJsonPath('data.office_grace_minutes', '20')
            ->assertJsonPath('data.overtime_multiplier', '1.333333');
        $id = DB::table('settings')->where('key', 'office_grace_minutes')->value('id');
        $row = DB::table('audit_log')->where(['entity_type' => 'settings', 'entity_id' => $id, 'event' => 'UPDATED'])->first();
        $this->assertSame(['value' => '15'], json_decode($row->old_values, true));
        $this->assertSame(['value' => '20'], json_decode($row->new_values, true));
        $this->assertSame($hr->id, $row->user_id);
        $this->assertSame(2, DB::table('audit_log')->where(['entity_type' => 'settings', 'event' => 'UPDATED'])->count()); // month_days did not change
    }

    public function test_put_rejects_malformed_values_under_their_field(): void
    {
        $this->signIn('hr');
        $bad = ['overtime_multiplier' => '1,5', 'month_days' => '30.5', 'office_shift_start' => '25:00', 'weekly_off_field' => 'fri', 'lateness_multiplier_office' => 1.5];
        foreach ($bad as $key => $value) {
            $this->putJson('/api/v1/settings', [$key => $value])->assertStatus(422)->assertJsonPath('error.details.0.field', $key);
        }
        $this->assertSame('1.5', DB::table('settings')->where('key', 'overtime_multiplier')->value('value'));
        $this->assertSame('1', DB::table('settings')->where('key', 'lateness_multiplier_office')->value('value'));
    }

    public function test_view_and_manage_are_separate_permissions(): void
    {
        $this->signIn('manager');
        $this->getJson('/api/v1/settings')->assertStatus(403);
        $this->signIn('ceo');
        $this->getJson('/api/v1/settings')->assertOk();
        $this->putJson('/api/v1/settings', ['office_grace_minutes' => '20'])->assertStatus(403);
    }
}
