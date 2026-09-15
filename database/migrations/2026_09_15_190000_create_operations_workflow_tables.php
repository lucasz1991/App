<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('published_revision')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->json('published_snapshot')->nullable();
            $table->unsignedInteger('planned_break_minutes')->default(0);
        });
        Schema::table('shift_assignments', function (Blueprint $table): void {
            $table->unsignedInteger('plan_revision')->default(1);
        });
        DB::table('shifts')->whereNotIn('status', ['draft', 'cancelled'])->update(['published_revision' => 1]);

        Schema::create('operation_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('channel', 20);
            $table->string('source_reference', 190)->nullable();
            $table->string('title', 180);
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('contact_name', 180)->nullable();
            $table->string('contact_email', 254)->nullable();
            $table->string('contact_phone', 80)->nullable();
            $table->text('original');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 80)->default('Europe/Berlin');
            $table->string('location_name', 180)->nullable();
            $table->string('role_name', 160)->nullable();
            $table->unsignedInteger('required_staff')->nullable();
            $table->string('status', 30)->default('new')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('verified_revision')->nullable();
            $table->json('offer')->nullable();
            $table->unsignedInteger('accepted_revision')->nullable();
            $table->text('acceptance_note')->nullable();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('operation_inquiries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['channel', 'source_reference']);
        });
        Schema::create('operation_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->unsignedInteger('revision')->nullable();
            $table->json('data');
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id', 'id'], 'operation_audit_subject');
        });
        Schema::create('qualification_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('employee_qualifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('qualification_type_id')->constrained()->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_until');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->string('evidence_path', 500)->nullable();
            $table->string('evidence_name', 255)->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'valid_until'], 'employee_qualification_validity');
        });
        Schema::create('shift_qualification_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qualification_type_id')->constrained()->restrictOnDelete();
            $table->unique(['shift_id', 'qualification_type_id'], 'shift_qualification_unique');
        });
        Schema::create('absence_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 80)->default('Europe/Berlin');
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'starts_at'], 'absence_person_window');
        });
        Schema::create('operations_rule_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('minimum_rest_minutes');
            $table->unsignedInteger('maximum_shift_minutes');
            $table->unsignedInteger('break_after_minutes');
            $table->unsignedInteger('minimum_break_minutes');
            $table->boolean('is_active')->default(false)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
        });
        Schema::create('work_time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_assignment_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('running')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('pause_seconds')->default(0);
            $table->string('timezone', 80);
            $table->json('plan_snapshot');
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        Schema::create('work_time_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_time_entry_id')->constrained()->restrictOnDelete();
            $table->uuid('event_key')->unique();
            $table->string('kind', 30);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->json('data')->nullable();
        });
        Schema::create('work_time_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_time_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 30);
            $table->json('snapshot');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['work_time_entry_id', 'revision', 'action'], 'work_time_revision_action');
        });
        Schema::create('work_time_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('schema_version')->default(1);
            $table->timestamp('created_at');
        });
        Schema::create('work_time_export_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_time_export_id')->constrained()->restrictOnDelete();
            $table->foreignId('work_time_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->unique(['work_time_entry_id', 'revision'], 'work_time_export_once');
        });
    }

    public function down(): void
    {
        foreach (['work_time_export_items', 'work_time_exports', 'work_time_revisions', 'work_time_events', 'work_time_entries', 'operations_rule_profiles', 'absence_requests', 'shift_qualification_requirements', 'employee_qualifications', 'qualification_types', 'operation_audits', 'operation_inquiries'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('shift_assignments', fn (Blueprint $table) => $table->dropColumn('plan_revision'));
        Schema::table('shifts', fn (Blueprint $table) => $table->dropColumn(['revision', 'published_revision', 'published_at', 'published_snapshot', 'planned_break_minutes']));
    }
};
