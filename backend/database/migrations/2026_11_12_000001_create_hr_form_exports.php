<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const GRANTS = [
        'hr_forms.generate.all' => ['hr', 'developer'],
        'hr_forms.view.all' => ['hr', 'ceo', 'developer'],
        'hr_forms.approve.all' => ['ceo'],
        'hr_signatures.manage.own' => ['hr', 'ceo'],
    ];

    public function up(): void
    {
        Schema::create('hr_signature_images', function (Blueprint $table): void {
            $table->standardColumns();
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('storage_path')->unique();
            $table->string('mime_type', 32);
            $table->char('checksum', 64);
            $table->unique(['owner_id', 'version']);
            $table->unique(['id', 'owner_id']);
        });

        Schema::create('hr_form_serial_counters', function (Blueprint $table): void {
            $table->string('form_type', 64)->primary();
            $table->unsignedBigInteger('last_serial');
        });

        Schema::create('hr_form_exports', function (Blueprint $table): void {
            $table->standardColumns();
            $table->string('form_type', 64);
            $table->unsignedBigInteger('serial_number');
            $table->string('stage', 16);
            $table->string('status', 16);
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('template_version');
            $table->jsonb('snapshot');
            $table->string('storage_path')->unique();
            $table->uuid('unsigned_export_id')->nullable()->unique();
            $table->uuid('regenerated_from_id')->nullable()->unique();
            $table->foreignUuid('impersonator_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('signature_image_id')->nullable();
            $table->foreign(['signature_image_id', 'approved_by'], 'hr_form_exports_approver_signature_fk')
                ->references(['id', 'owner_id'])->on('hr_signature_images')->restrictOnDelete();
            $table->unique(['form_type', 'serial_number', 'stage']);
            $table->unique(['requested_by', 'idempotency_key']);
            $table->index(['employee_id', 'created_at']);
            $table->index(['stage', 'status', 'created_at']);
        });

        Schema::table('hr_form_exports', function (Blueprint $table): void {
            $table->foreign('unsigned_export_id')->references('id')->on('hr_form_exports')->restrictOnDelete();
            $table->foreign('regenerated_from_id')->references('id')->on('hr_form_exports')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE hr_form_exports ADD CONSTRAINT hr_form_exports_stage_check CHECK (
            (stage = 'unsigned' AND unsigned_export_id IS NULL AND approved_by IS NULL AND approved_at IS NULL AND signature_image_id IS NULL AND impersonator_id IS NULL)
            OR (stage = 'signed' AND unsigned_export_id IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND signature_image_id IS NOT NULL AND regenerated_from_id IS NULL)
        )");
        DB::statement("ALTER TABLE hr_form_exports ADD CONSTRAINT hr_form_exports_status_check CHECK (
            (status = 'pending' AND sha256 IS NULL AND size_bytes IS NULL AND failure_code IS NULL)
            OR (status = 'ready' AND sha256 IS NOT NULL AND size_bytes IS NOT NULL AND size_bytes > 0 AND failure_code IS NULL)
            OR (status = 'failed' AND sha256 IS NULL AND size_bytes IS NULL AND failure_code IS NOT NULL)
        )");

        $now = now();
        $roles = DB::table('roles')->pluck('id', 'slug');
        foreach (self::GRANTS as $name => $slugs) {
            [$resource, $action, $scope] = explode('.', $name);
            $id = (string) Str::uuid7();
            DB::table('permissions')->insert(['id' => $id, 'resource' => $resource, 'action' => $action, 'scope' => $scope, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('role_permissions')->insert(array_map(fn (string $slug) => ['role_id' => $roles[$slug], 'permission_id' => $id], $slugs));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            [$resource, $action, $scope] = explode('.', $name);
            DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->delete();
        }

        Schema::dropIfExists('hr_form_exports');
        Schema::dropIfExists('hr_form_serial_counters');
        Schema::dropIfExists('hr_signature_images');
    }
};
