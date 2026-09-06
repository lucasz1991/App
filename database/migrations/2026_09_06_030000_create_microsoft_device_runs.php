<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('microsoft_device_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 24)->index();
            $table->string('active_key', 96)->nullable()->unique('ms_run_active_unique');
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('configuration_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('queue_job_id')->nullable()->index();
            $table->string('status', 24)->index();
            $table->string('outcome', 48)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_device_runs');
    }
};
