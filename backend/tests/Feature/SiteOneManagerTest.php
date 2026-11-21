<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AttendanceFixtures;
use Tests\TestCase;

/** One manager per site (owner, 2026-11-19, client Q19): a second one is refused, and HR resolves the sites that still have several. */
final class SiteOneManagerTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    public function test_a_second_manager_is_refused_and_names_the_current_one(): void
    {
        $site = $this->workSite('سموحة');
        $current = $this->managerOf($site);
        $next = $this->makeUser('manager');
        $this->signIn('hr');
        $audited = DB::table('audit_log')->where('event', 'SITE_MANAGERS_UPDATED')->count();

        foreach ([[$current->id, $next->id], [$next->id, $current->id]] as $ids) {
            $this->putJson("/api/v1/sites/$site/managers", ['manager_ids' => $ids])->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'manager_ids')
                ->assertJsonPath('error.details.0.message', __('errors.site_has_manager', ['site' => 'سموحة', 'name' => $current->name]));
        }
        $this->assertSame([$current->id], DB::table('manager_sites')->where('site_id', $site)->pluck('manager_id')->all());
        $this->assertSame($audited, DB::table('audit_log')->where('event', 'SITE_MANAGERS_UPDATED')->count());

        $this->putJson("/api/v1/sites/$site/managers", ['manager_ids' => [$next->id]])->assertOk()   // a replacement in one save
            ->assertJsonCount(1, 'data.managers')->assertJsonPath('data.managers.0.id', $next->id);
    }

    public function test_hr_lists_the_sites_with_several_managers_and_resolves_them_by_removing_one(): void
    {
        [$shared, $single] = [$this->workSite('Site-A'), $this->workSite('Site-B')];
        $a = $this->managerOf($shared);                   // from before the rule
        $b = $this->managerOf($shared, $single);
        $this->signIn('hr');

        $this->getJson('/api/v1/sites/manager-conflicts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shared)->assertJsonPath('data.0.name_ar', 'Site-A')->assertJsonCount(2, 'data.0.managers')
            ->assertJsonPath('data.0.managers.0.email', min($a->email, $b->email));
        $this->putJson("/api/v1/sites/$shared/managers", ['manager_ids' => [$a->id, $b->id]])->assertOk();   // saving it unchanged is no addition
        $this->putJson("/api/v1/sites/$shared/managers", ['manager_ids' => [$a->id]])->assertOk();
        $this->getJson('/api/v1/sites/manager-conflicts')->assertOk()->assertJsonPath('data', []);
        $this->assertSame([$b->id], DB::table('manager_sites')->where('site_id', $single)->pluck('manager_id')->all());
    }

    public function test_only_who_assigns_managers_sees_the_conflicts(): void
    {
        $site = $this->workSite();
        $this->actingAs($this->managerOf($site), 'api');
        $this->getJson('/api/v1/sites/manager-conflicts')->assertForbidden();
    }
}
