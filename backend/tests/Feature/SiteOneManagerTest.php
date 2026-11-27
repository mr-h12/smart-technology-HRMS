<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
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

    /** @return list<string> */
    private function managersOf(string $site): array
    {
        return DB::table('manager_sites')->where('site_id', $site)->orderBy('manager_id')->pluck('manager_id')->map(fn ($id) => (string) $id)->all();
    }

    /** A holder who is no longer a manager candidate: deactivated, or moved to a role without site.view.own (review of #75, 2026-11-22). */
    private function noLongerCandidate(User $u, string $how): void
    {
        $how === 'deactivated'
            ? User::whereKey($u->id)->update(['is_active' => false])
            : User::whereKey($u->id)->update(['role_id' => Role::where('slug', 'hr')->value('id')]);
    }

    public function test_a_holder_no_longer_a_candidate_neither_counts_as_a_conflict_nor_blocks_removing_another(): void
    {
        foreach (['deactivated', 'role_changed'] as $how) {
            $site = $this->workSite("Site-$how");
            [$a, $b, $gone] = [$this->managerOf($site), $this->managerOf($site), $this->managerOf($site)];   // from before the rule
            $this->noLongerCandidate($gone, $how);
            $this->signIn('hr');

            $card = collect($this->getJson('/api/v1/sites/manager-conflicts')->assertOk()->json('data'))->firstWhere('id', $site);
            $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($card['managers'], 'id'), $how);   // the card lists live candidates only
            // an older card that still sends the gone holder: accepted, and the save clears that row
            $this->putJson("/api/v1/sites/$site/managers", ['manager_ids' => [$b->id, $gone->id]])->assertOk();
            $this->assertSame([$b->id], $this->managersOf($site), $how);
            $old = json_decode((string) DB::table('audit_log')->where(['event' => 'SITE_MANAGERS_UPDATED', 'entity_id' => $site])->latest('occurred_at')->value('old_values'), true);
            $this->assertContains($gone->id, $old['manager_ids'] ?? [], $how);   // the audit keeps who was there
        }
    }

    public function test_a_site_whose_only_holder_is_no_longer_a_candidate_takes_a_new_manager_from_the_employee_form(): void
    {
        $this->cairo('2026-08-10 10:00');
        $this->makeUser('developer');   // a role manager must remain
        foreach (['deactivated', 'role_changed'] as $i => $how) {
            $site = $this->workSite("Site-$how");
            $this->noLongerCandidate($this->managerOf($site), $how);
            $this->signIn('hr');
            $this->assertNull(collect($this->getJson('/api/v1/sites/manager-conflicts')->json('data'))->firstWhere('id', $site));

            $res = $this->postJson('/api/v1/employees/'.$this->employee('engineer').'/site-manager', ['email' => "new$i@smart.test", 'password' => 'abcd1234', 'site_ids' => [$site]])
                ->assertCreated();
            $this->assertSame([(string) $res->json('data.site_manager.id')], $this->managersOf($site), $how);
        }
    }
}
