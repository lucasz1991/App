<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_identity_syncs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('device_id');
            $table->foreignId('device_assignment_id');
            $table->foreignId('user_id')->nullable();
            $table->string('operation', 16)->default('apply')->index();
            $table->string('status', 32)->default('queued');
            $table->char('deduplication_key', 64);
            $table->uuid('correlation_id');
            $table->string('provider_job_id', 191)->nullable();
            $table->json('account_assignment_ids');
            $table->json('profile_assignment_ids');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->longText('result')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->timestamps();

            $table->unique('public_id', 'dev_id_sync_public_uq');
            $table->unique('correlation_id', 'dev_id_sync_corr_uq');
            $table->unique('deduplication_key', 'dev_id_sync_dedupe_uq');
            $table->index(['status', 'requested_at'], 'dev_id_sync_status_idx');
            $table->index(['device_id', 'device_assignment_id'], 'dev_id_sync_assignment_idx');

            $table->foreign('device_id', 'dev_id_sync_device_fk')
                ->references('id')->on('devices')->cascadeOnDelete();
            $table->foreign('device_assignment_id', 'dev_id_sync_assignment_fk')
                ->references('id')->on('device_assignments')->cascadeOnDelete();
            $table->foreign('user_id', 'dev_id_sync_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('requested_by', 'dev_id_sync_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_identity_syncs');
    }
};
