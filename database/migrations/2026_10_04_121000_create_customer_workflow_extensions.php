<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_contacts')) {
            Schema::create('customer_contacts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision')->default(1);
                $table->string('name', 180);
                $table->string('email', 254)->nullable();
                $table->string('phone', 80)->nullable();
                $table->json('roles');
                $table->boolean('is_active')->default(true);
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('customer_conditions')) {
            Schema::create('customer_conditions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision')->default(1);
                $table->string('code', 50);
                $table->string('label', 180);
                $table->string('unit', 30);
                $table->unsignedBigInteger('unit_price_cents');
                $table->date('valid_from');
                $table->date('valid_until')->nullable();
                $table->text('terms')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['customer_id', 'code', 'valid_from']);
            });
        }
        if (! Schema::hasTable('customer_locations')) {
            Schema::create('customer_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision')->default(1);
                $table->string('name', 180);
                $table->string('street', 180)->nullable();
                $table->string('postal_code', 20)->nullable();
                $table->string('city', 100)->nullable();
                $table->char('country', 2);
                $table->text('access_note')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('inquiry_process_details')) {
            Schema::create('inquiry_process_details', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('operation_inquiry_id')->unique()->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision')->default(1);
                $table->foreignId('customer_contact_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('priority', 16)->default('normal');
                $table->dateTime('due_at')->nullable();
                $table->string('timezone', 80);
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('inquiry_follow_ups')) {
            Schema::create('inquiry_follow_ups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('operation_inquiry_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision')->default(1);
                $table->string('kind', 24);
                $table->string('title', 180);
                $table->text('note')->nullable();
                $table->dateTime('due_at');
                $table->string('timezone', 80);
                $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('status', 20)->default('open');
                $table->text('completion_note')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['status', 'due_at']);
            });
        }
        if (! Schema::hasTable('commercial_offer_revisions')) {
            Schema::create('commercial_offer_revisions', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_type', 30);
                $table->unsignedBigInteger('subject_id');
                $table->unsignedInteger('revision');
                $table->unsignedInteger('state_version')->default(1);
                $table->string('kind', 20);
                $table->string('status', 24)->default('draft');
                $table->json('snapshot');
                $table->unsignedBigInteger('total_cents');
                $table->date('valid_until')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->dateTime('issued_at')->nullable();
                $table->dateTime('accepted_at')->nullable();
                $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('acceptance_note')->nullable();
                $table->timestamps();
                $table->unique(['subject_type', 'subject_id', 'revision']);
            });
        }
        if (! Schema::hasTable('employee_document_versions')) {
            Schema::create('employee_document_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_document_requirement_id')->constrained()->restrictOnDelete();
                $table->foreignId('file_id')->unique()->constrained()->restrictOnDelete();
                $table->unsignedInteger('revision');
                $table->json('snapshot');
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->dateTime('withdrawn_at')->nullable();
                $table->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->dateTime('acknowledged_at')->nullable();
                $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->dateTime('created_at');
                $table->unique(['employee_document_requirement_id', 'revision'], 'employee_document_requirement_revision_unique');
            });
        }
    }

    public function down(): void
    {
        $tables = ['employee_document_versions', 'commercial_offer_revisions', 'inquiry_follow_ups', 'inquiry_process_details', 'customer_locations', 'customer_conditions', 'customer_contacts'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Customer/personnel workflow history must be retained; use a reviewed forward migration.');
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
