<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_request_notifications')) {
            return;
        }
        if (! Schema::hasColumn('employee_request_notifications', 'push_claim_token')) {
            Schema::table('employee_request_notifications', fn (Blueprint $table) => $table->uuid('push_claim_token')->nullable());
        }
        if (! Schema::hasColumn('employee_request_notifications', 'push_claimed_at')) {
            Schema::table('employee_request_notifications', fn (Blueprint $table) => $table->timestamp('push_claimed_at')->nullable());
        }
        if (! Schema::hasColumn('employee_request_notifications', 'mail_failure_version')) {
            Schema::table('employee_request_notifications', fn (Blueprint $table) => $table->unsignedInteger('mail_failure_version')->default(0));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_request_notifications') && DB::table('employee_request_notifications')->exists()) {
            throw new RuntimeException('Benachrichtigungsreceipts enthalten Zustellversuche. Recovery-Spalten werden nicht automatisch entfernt.');
        }
        foreach (['push_claim_token', 'push_claimed_at', 'mail_failure_version'] as $column) {
            if (Schema::hasColumn('employee_request_notifications', $column)) {
                Schema::table('employee_request_notifications', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
