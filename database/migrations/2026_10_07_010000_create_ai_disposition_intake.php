<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_intakes')) {
            Schema::create('ai_intakes', function (Blueprint $t): void {
                $t->id(); $t->uuid('public_id')->unique();
                $t->string('source_type', 20); $t->string('status', 30)->default('received')->index();
                $t->string('title', 180)->nullable(); $t->text('summary')->nullable();
                $t->unsignedBigInteger('customer_id')->nullable(); $t->foreign('customer_id', 'aii_customer_fk')->references('id')->on('customers')->restrictOnDelete();
                $t->unsignedBigInteger('customer_contact_id')->nullable();
                $t->unsignedBigInteger('supervising_user_id')->nullable(); $t->foreign('supervising_user_id', 'aii_supervisor_fk')->references('id')->on('users')->restrictOnDelete();
                $t->unsignedBigInteger('created_by')->nullable(); $t->foreign('created_by', 'aii_creator_fk')->references('id')->on('users')->restrictOnDelete();
                $t->string('match_method', 30)->nullable(); $t->unsignedBigInteger('latest_inbound_message_id')->nullable();
                $t->unsignedInteger('revision')->default(1); $t->unsignedInteger('source_revision')->default(1); $t->unsignedInteger('processed_source_revision')->nullable();
                $t->unsignedInteger('question_round')->default(0); $t->json('inquiry_ids')->nullable(); $t->json('missing_fields')->nullable(); $t->longText('analysis')->nullable();
                $t->decimal('confidence', 5, 4)->nullable(); $t->string('error_code', 100)->nullable(); $t->timestamp('paused_at')->nullable(); $t->timestamp('last_analyzed_at')->nullable(); $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_intake_messages')) {
            Schema::create('ai_intake_messages', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('intake_id'); $t->foreign('intake_id', 'aim_intake_fk')->references('id')->on('ai_intakes')->restrictOnDelete();
                $t->string('direction', 20)->default('inbound'); $t->char('receipt_key', 64)->nullable()->unique();
                $t->text('sender_email')->nullable(); $t->text('reply_to_email')->nullable(); $t->string('external_message_id', 191)->nullable()->index();
                $t->longText('body'); $t->string('raw_path', 255)->nullable(); $t->char('raw_hash', 64)->nullable(); $t->longText('metadata')->nullable(); $t->timestamps();
                $t->index(['intake_id', 'direction', 'id'], 'aim_intake_direction_idx');
            });
        }
        if (! Schema::hasTable('ai_intake_attachments')) {
            Schema::create('ai_intake_attachments', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('intake_id'); $t->foreign('intake_id', 'aia_intake_fk')->references('id')->on('ai_intakes')->restrictOnDelete();
                $t->unsignedBigInteger('message_id'); $t->foreign('message_id', 'aia_message_fk')->references('id')->on('ai_intake_messages')->restrictOnDelete();
                $t->string('file_name', 180); $t->string('file_mime', 120); $t->unsignedBigInteger('file_size'); $t->char('file_hash', 64);
                $t->string('file_path', 255); $t->string('kind', 20); $t->string('status', 30)->default('stored'); $t->longText('extracted_text')->nullable(); $t->longText('metadata')->nullable(); $t->timestamps();
                $t->unique(['message_id', 'file_hash'], 'aia_message_hash_unique');
            });
        }
        if (! Schema::hasTable('ai_intake_runs')) {
            Schema::create('ai_intake_runs', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('intake_id'); $t->foreign('intake_id', 'air_intake_fk')->references('id')->on('ai_intakes')->restrictOnDelete();
                $t->string('kind', 20)->default('analysis'); $t->string('status', 30)->default('running'); $t->unsignedInteger('source_revision'); $t->unsignedInteger('settings_revision');
                $t->unsignedBigInteger('supervising_user_id'); $t->foreign('supervising_user_id', 'air_supervisor_fk')->references('id')->on('users')->restrictOnDelete();
                $t->char('input_hash', 64); $t->longText('input_snapshot')->nullable(); $t->longText('result')->nullable(); $t->longText('configuration')->nullable();
                $t->string('error_code', 100)->nullable(); $t->string('model', 180)->nullable(); $t->string('provider_request_id', 180)->nullable(); $t->decimal('cost_usd', 14, 8)->nullable();
                $t->timestamp('started_at'); $t->timestamp('finished_at')->nullable(); $t->timestamps();
                $t->index(['intake_id', 'source_revision', 'kind'], 'air_intake_source_idx');
            });
        }
        if (! Schema::hasTable('ai_intake_proposals')) {
            Schema::create('ai_intake_proposals', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('intake_id'); $t->foreign('intake_id', 'aip_intake_fk')->references('id')->on('ai_intakes')->restrictOnDelete();
                $t->unsignedInteger('position_index'); $t->unsignedInteger('revision')->default(1); $t->unsignedInteger('source_revision'); $t->string('status', 30)->default('proposed');
                $t->unsignedBigInteger('inquiry_id')->nullable(); $t->foreign('inquiry_id', 'aip_inquiry_fk')->references('id')->on('operation_inquiries')->restrictOnDelete(); $t->unsignedInteger('inquiry_revision')->nullable();
                $t->char('inquiry_fingerprint', 64)->nullable(); $t->longText('payload'); $t->unsignedBigInteger('approved_by')->nullable(); $t->foreign('approved_by', 'aip_approver_fk')->references('id')->on('users')->restrictOnDelete(); $t->timestamp('approved_at')->nullable();
                $t->unsignedBigInteger('demand_id')->nullable(); $t->foreign('demand_id', 'aip_demand_fk')->references('id')->on('order_demands')->restrictOnDelete();
                $t->json('applied_shift_ids')->nullable(); $t->timestamp('applied_at')->nullable(); $t->string('error_code', 100)->nullable(); $t->timestamps();
                $t->unique(['intake_id', 'source_revision', 'position_index'], 'aip_source_position_unique');
            });
        }
        if (! Schema::hasTable('ai_intake_deliveries')) {
            Schema::create('ai_intake_deliveries', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('intake_id'); $t->foreign('intake_id', 'aid_intake_fk')->references('id')->on('ai_intakes')->restrictOnDelete();
                $t->unsignedBigInteger('message_id')->nullable(); $t->foreign('message_id', 'aid_message_fk')->references('id')->on('ai_intake_messages')->restrictOnDelete();
                $t->unsignedBigInteger('customer_id')->nullable(); $t->foreign('customer_id', 'aid_customer_fk')->references('id')->on('customers')->restrictOnDelete(); $t->unsignedBigInteger('contact_id')->nullable();
                $t->text('recipient_email'); $t->string('subject', 180); $t->longText('body'); $t->string('status', 30)->default('pending'); $t->char('dedup_key', 64)->unique();
                $t->unsignedInteger('settings_revision'); $t->unsignedInteger('intake_revision'); $t->unsignedInteger('source_revision')->default(1); $t->unsignedInteger('question_round')->default(0);
                $t->unsignedInteger('attempts')->default(0); $t->timestamp('attempted_at')->nullable(); $t->timestamp('sent_at')->nullable();
                $t->string('message_id_header', 191)->nullable()->index(); $t->string('in_reply_to', 191)->nullable(); $t->json('references')->nullable(); $t->string('failure_code', 100)->nullable(); $t->longText('metadata')->nullable(); $t->timestamps();
            });
        }
        foreach (['operation_inquiries' => ['created_by', 'updated_by'], 'order_demands' => ['created_by'], 'operation_audits' => ['actor_id']] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable()->change());
                }
            }
        }
        foreach (['actor_kind' => fn (Blueprint $t) => $t->string('actor_kind', 20)->default('human'), 'automation_run_id' => fn (Blueprint $t) => $t->unsignedBigInteger('automation_run_id')->nullable(), 'supervising_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('supervising_user_id')->nullable()] as $column => $definition) {
            if (! Schema::hasColumn('operation_audits', $column)) {
                Schema::table('operation_audits', $definition);
            }
        }
    }

    public function down(): void
    {
        foreach (['ai_intake_deliveries', 'ai_intake_proposals', 'ai_intake_runs', 'ai_intake_attachments', 'ai_intake_messages', 'ai_intakes'] as $table) {
            if (Schema::hasTable($table) && Schema::getConnection()->table($table)->exists()) {
                throw new RuntimeException('AI-Eingang enthält Belege; Rücknahme würde Historie löschen.');
            }
        }
        foreach (['ai_intake_deliveries', 'ai_intake_proposals', 'ai_intake_runs', 'ai_intake_attachments', 'ai_intake_messages', 'ai_intakes'] as $table) {
            Schema::dropIfExists($table);
        }
        // Nullable authorship and provenance are additive historical compatibility and remain.
    }
};
