<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = ['setting.view.all', 'setting.manage.all'];

    public function up(): void
    {
        Schema::create('settings', function (Blueprint $t): void {
            $t->standardColumns();
            $t->string('key', 64);
            $t->string('value', 64);
        });
        DB::statement('create unique index settings_key_unique on settings (key) where deleted_at is null');

        $now = now();
        $seed = [
            'month_days' => '30', 'day_hours' => '8', 'overtime_multiplier' => '1.5',
            'lateness_multiplier_office' => '1', 'lateness_multiplier_engineer' => '1.5',
            'lateness_multiplier_permanent_worker' => '2', 'lateness_multiplier_day_laborer' => '2',
            'office_grace_minutes' => '15', 'office_shift_start' => '09:00', 'office_shift_end' => '17:00',
            'weekly_off_office' => 'friday,saturday', 'weekly_off_field' => 'friday',
            'leave_days_under_one_year' => '10', 'leave_days_one_year_plus' => '21', 'leave_casual_days' => '6',
        ];
        DB::table('settings')->insert(array_map(
            fn (string $key, string $value) => ['id' => (string) Str::uuid7(), 'key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now],
            array_keys($seed),
            $seed,
        ));

        $ids = [];
        foreach (self::PERMISSIONS as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            $ids[$name] = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $ids[$name], 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
        }

        $grants = ['developer' => self::PERMISSIONS, 'hr' => self::PERMISSIONS, 'ceo' => ['setting.view.all']];
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach ($grants as $slug => $granted) {
            DB::table('role_permissions')->insert(array_map(
                fn (string $n) => ['role_id' => $roles[$slug], 'permission_id' => $ids[$n]],
                $granted,
            ));
        }
    }

    public function down(): void
    {
        // role_permissions rows go with the permissions (cascadeOnDelete).
        DB::table('permissions')->where('resource', 'setting')->delete();
        Schema::dropIfExists('settings');
    }
};
