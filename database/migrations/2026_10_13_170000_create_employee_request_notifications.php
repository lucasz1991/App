<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_request_notifications')) {
            return;
        }

        Schema::create('employee_request_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 190)->unique();
            $table->string('request_type', 20);
            $table->unsignedBigInteger('request_id');
            $table->unsignedInteger('request_revision');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('kind', 30);
            $table->unsignedBigInteger('message_id')->nullable()->unique();
            $table->unsignedBigInteger('mail_id')->nullable()->unique();
            $table->timestamp('message_created_at')->nullable();
            $table->timestamp('mail_enqueued_at')->nullable();
            $table->timestamp('mail_skipped_at')->nullable();
            $table->timestamp('push_enqueued_at')->nullable();
            $table->timestamp('push_skipped_at')->nullable();
            $table->timestamp('enqueued_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 80)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_class', 190)->nullable();
            $table->timestamps();
            $table->index(['request_type', 'request_id', 'request_revision'], 'employee_request_source_index');
            $table->index(['enqueued_at', 'skipped_at'], 'employee_request_pending_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_request_notifications') && DB::table('employee_request_notifications')->exists()) {
            throw new RuntimeException('Benachrichtigungsreceipts enthalten Nachweise und werden nicht automatisch gelöscht.');
        }
        Schema::dropIfExists('employee_request_notifications');
    }
};
