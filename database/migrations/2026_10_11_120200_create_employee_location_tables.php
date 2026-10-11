<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_location_installations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('revoked_at', 6)->nullable();
            $table->uuid('session_id')->nullable();
            $table->timestamp('session_started_at', 6)->nullable();
            $table->uuid('permit_id')->nullable();
            $table->timestamp('permit_expires_at', 6)->nullable();
            $table->unsignedBigInteger('consent_id')->nullable();
            $table->unsignedBigInteger('work_time_entry_id')->nullable();
            $table->unsignedInteger('work_revision')->nullable();
            $table->timestamp('last_sample_at', 6)->nullable();
            $table->timestamp('next_sample_at', 6)->nullable();
            $table->timestamp('last_seen_at', 6)->nullable();
            $table->string('quality_status', 30)->nullable();
            $table->timestamps(6);
            $table->index(['user_id', 'revoked_at']);
        });
        Schema::create('employee_location_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installation_id')->nullable()->constrained('mobile_location_installations')->nullOnDelete();
            $table->date('local_date');
            $table->string('purpose', 40)->default('work_routing');
            $table->unsignedInteger('explanation_version');
            $table->char('explanation_hash', 64);
            $table->string('decision', 10);
            $table->timestamp('accepted_at', 6)->nullable();
            $table->timestamp('expires_at', 6);
            $table->timestamp('withdrawn_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['user_id', 'local_date', 'purpose']);
            $table->index(['user_id', 'expires_at']);
        });
        Schema::create('mobile_location_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installation_id')->nullable()->constrained('mobile_location_installations')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('purpose', 12);
            $table->timestamp('expires_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamps(6);
        });
        Schema::create('employee_location_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consent_id')->constrained('employee_location_consents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('device_assignment_id')->nullable();
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->string('action', 20);
            $table->unsignedInteger('explanation_version');
            $table->char('explanation_hash', 64);
            $table->text('explanation_text');
            $table->timestamp('occurred_at', 6);
            $table->timestamps(6);
            $table->index(['user_id', 'occurred_at']);
        });
        Schema::create('employee_location_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installation_id')->constrained('mobile_location_installations')->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consent_id')->constrained('employee_location_consents')->cascadeOnDelete();
            $table->foreignId('work_time_entry_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->text('position');
            $table->decimal('accuracy_m', 8, 2);
            $table->timestamp('measured_at', 6);
            $table->timestamp('received_at', 6);
            $table->timestamps(6);
            $table->unique(['installation_id', 'event_id']);
            $table->index(['user_id', 'measured_at']);
            $table->index('measured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_location_points');
        Schema::dropIfExists('employee_location_consent_events');
        Schema::dropIfExists('mobile_location_codes');
        Schema::dropIfExists('employee_location_consents');
        Schema::dropIfExists('mobile_location_installations');
    }
};
