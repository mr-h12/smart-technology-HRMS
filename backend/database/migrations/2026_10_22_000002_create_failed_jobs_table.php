<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel's stock schema (make:queue-failed-table) for config/queue.php's database-uuids failer: a job that fails its last try lands here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $t): void {
            $t->id();
            $t->string('uuid')->unique();
            $t->string('connection');
            $t->string('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
            $t->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
