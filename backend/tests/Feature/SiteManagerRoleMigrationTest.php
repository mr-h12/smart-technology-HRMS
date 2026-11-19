<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SiteManagerRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_04_120000_rename_manager_role_to_site_manager.php');
    }

    /** @return array{slug: string, name: string, name_ar: string} */
    private function role(): array
    {
        $row = DB::table('roles')->where('slug', 'manager')->first(['id', 'slug', 'name', 'name_ar']);
        $this->assertNotNull($row);

        return ['slug' => $row->slug, 'name' => $row->name, 'name_ar' => $row->name_ar];
    }

    public function test_the_manager_role_is_shown_as_site_manager_and_keeps_its_slug_and_grants(): void
    {
        $this->assertSame(['slug' => 'manager', 'name' => 'Site manager', 'name_ar' => 'مدير الموقع'], $this->role());
        $this->assertSame(1, DB::table('roles')->where('slug', 'manager')->count());
        $this->assertSame(10, DB::table('role_permissions')->join('roles', 'roles.id', '=', 'role_permissions.role_id')->where('roles.slug', 'manager')->count());
    }

    public function test_down_restores_the_old_name_and_both_directions_are_audited(): void
    {
        $id = DB::table('roles')->where('slug', 'manager')->value('id');

        $this->migration()->down();
        $this->assertSame(['slug' => 'manager', 'name' => 'Manager', 'name_ar' => 'مدير'], $this->role());
        $this->migration()->up();
        $this->migration()->up();   // a re-run matches nothing
        $this->assertSame(['slug' => 'manager', 'name' => 'Site manager', 'name_ar' => 'مدير الموقع'], $this->role());

        // One row from RefreshDatabase's migrate, then down and one effective up.
        $audits = DB::table('audit_log')->where(['entity_type' => 'roles', 'entity_id' => $id, 'event' => 'ROLE_UPDATED'])->orderBy('id')->get();
        $this->assertCount(3, $audits);
        $this->assertNull($audits[1]->user_id);
        $this->assertSame(['name' => 'Site manager', 'name_ar' => 'مدير الموقع'], json_decode($audits[1]->old_values, true));
        $this->assertSame(['name' => 'Manager', 'name_ar' => 'مدير'], json_decode($audits[1]->new_values, true));
    }

    public function test_a_name_edited_by_hand_is_left_alone(): void
    {
        DB::table('roles')->where('slug', 'manager')->update(['name' => 'Site lead', 'name_ar' => 'مشرف']);

        $this->migration()->down();
        $this->migration()->up();

        $this->assertSame(['slug' => 'manager', 'name' => 'Site lead', 'name_ar' => 'مشرف'], $this->role());
    }
}
