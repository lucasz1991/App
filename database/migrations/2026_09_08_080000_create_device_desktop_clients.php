<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_desktop_clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('device_id')->constrained('devices', 'id', 'ddc_device_fk')->restrictOnDelete();
            $table->foreignId('device_assignment_id')->constrained('device_assignments', 'id', 'ddc_assignment_fk')->restrictOnDelete();
            $table->uuid('instance_id')->nullable()->unique();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('status', 24)->default('unpaired')->index();
            $table->unsignedInteger('policy_revision')->default(1);
            $table->longText('policy');
            $table->string('client_version', 40)->nullable();
            $table->string('reported_name', 120)->nullable();
            $table->longText('last_report')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users', 'id', 'ddc_creator_fk')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('device_desktop_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('device_desktop_clients', 'id', 'dde_client_fk')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('device_desktop_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('client_id')->constrained('device_desktop_clients', 'id', 'ddj_client_fk')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users', 'id', 'ddj_requester_fk')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('status', 24)->default('queued')->index();
            $table->longText('payload');
            $table->text('justification');
            $table->uuid('lease_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->longText('result')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_desktop_jobs');
        Schema::dropIfExists('device_desktop_enrollments');
        Schema::dropIfExists('device_desktop_clients');
    }
};
