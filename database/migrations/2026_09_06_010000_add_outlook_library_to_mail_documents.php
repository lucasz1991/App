<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->boolean('is_outlook_template')->default(false);
            $table->boolean('outlook_released')->default(false);
            // NULL for every non-default row: at most one explicit Outlook
            // default, independently of the existing system-mail default.
            $table->boolean('outlook_default')->nullable()->unique();
            $table->index(['kind', 'is_outlook_template', 'outlook_released'], 'mail_documents_outlook_library_index');
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('mail_documents')->where('is_outlook_template', true)->exists()) {
            throw new RuntimeException('Outlook-Vorlagen müssen vor dem Rückbau ausdrücklich entfernt werden.');
        }

        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->dropUnique(['outlook_default']);
            $table->dropIndex('mail_documents_outlook_library_index');
            $table->dropColumn(['is_outlook_template', 'outlook_released', 'outlook_default']);
        });
    }
};
