<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_intake_deliveries')) {
            return;
        }
        $columns = [
            'message_type' => fn (Blueprint $table) => $table->string('message_type', 30)->default('clarification')->index(),
            'approved_by' => fn (Blueprint $table) => $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete(),
            'approved_at' => fn (Blueprint $table) => $table->timestamp('approved_at')->nullable(),
            'order_id' => fn (Blueprint $table) => $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete(),
            'order_fingerprint' => fn (Blueprint $table) => $table->char('order_fingerprint', 64)->nullable(),
            'approval_audit_id' => fn (Blueprint $table) => $table->foreignId('approval_audit_id')->nullable()->constrained('operation_audits')->restrictOnDelete(),
        ];
        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('ai_intake_deliveries', $name)) {
                Schema::table('ai_intake_deliveries', $definition);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_intake_deliveries') && Schema::hasColumn('ai_intake_deliveries', 'message_type')) {
            if (DB::table('ai_intake_deliveries')->where('message_type', '!=', 'clarification')->orWhereNotNull('approved_at')->exists()) {
                throw new RuntimeException('Kundenkommunikation enthält Freigabebelege; Rücknahme würde Historie löschen.');
            }
            Schema::table('ai_intake_deliveries', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('approval_audit_id');
                $table->dropConstrainedForeignId('order_id');
                $table->dropConstrainedForeignId('approved_by');
                $table->dropColumn(['message_type', 'approved_at', 'order_fingerprint']);
            });
        }
    }
};
