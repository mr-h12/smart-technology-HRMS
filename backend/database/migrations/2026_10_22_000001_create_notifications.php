<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t): void {
            $t->standardColumns();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('type', 64);
            $t->date('date');
            $t->jsonb('data');
            $t->timestampTz('read_at')->nullable();
            // Plain, not partial: it is what makes notifyOnce idempotent. It also serves the inbox lookup by user.
            $t->unique(['user_id', 'type', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
