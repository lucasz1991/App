<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_workplaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->restrictOnDelete();
            $table->string('profile_key', 40)->default('railtime_basic');
            $table->unsignedInteger('revision')->default(1);
            $table->string('ownership', 16);
            $table->string('management_state', 40)->default('awaiting_enrollment');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('device_management_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('device_assignments')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('scope_hash', 64);
            $table->longText('scope');
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'assignment_id', 'revision'], 'dm_consent_binding_idx');
        });
        Schema::create('support_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('device_desktop_clients')->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->string('category', 32);
            $table->text('subject');
            $table->string('status', 24)->default('open')->index();
            $table->longText('diagnostics')->nullable();
            $table->timestamp('diagnostics_expires_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id'], 'support_request_dedupe');
        });
        Schema::create('support_case_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->longText('body');
            $table->boolean('from_support')->default(false);
            $table->timestamps();
            $table->unique(['support_case_id', 'user_id', 'request_id'], 'support_message_dedupe');
        });
        Schema::create('support_case_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('support_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->text('name');
            $table->string('mime_type', 80);
            $table->unsignedInteger('size');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::create('support_remote_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('support_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('device_assignments')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('requested')->index();
            $table->boolean('file_transfer')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->longText('provider_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['support_remote_sessions', 'support_case_attachments', 'support_case_messages', 'support_cases', 'device_management_consents', 'device_workplaces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
