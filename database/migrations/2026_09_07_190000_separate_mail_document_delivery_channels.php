<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->dropUnique(['outlook_default']);
            $table->unique(['kind', 'outlook_default'], 'mail_documents_kind_outlook_default_unique');
            $table->unsignedInteger('delivery_revision')->default(0);
        });

        // Preserve the exact pre-migration audience; no draft becomes visible.
        DB::table('mail_documents')->where('kind', 'template')->where('is_outlook_template', false)
            ->whereNotNull('published_at')->whereRaw("TRIM(COALESCE(published_html, '')) <> ''")
            ->update(['outlook_released' => true]);
        // Outlook previously always used the system signature. Pin that same
        // snapshot once, so future system-default changes no longer change it.
        DB::table('mail_documents')->where('kind', 'signature')->where('is_active', true)
            ->where('status', 'published')->whereNotNull('published_at')
            ->whereRaw("TRIM(COALESCE(published_html, '')) <> ''")
            ->update(['outlook_default' => true]);
    }

    public function down(): void
    {
        // Reverting would implicitly expose withdrawn system templates again
        // and couple the signature defaults. Require an explicit data review.
        throw new RuntimeException('Die getrennten Mail-Freigaben benötigen vor einem Rückbau eine ausdrückliche Prüfung der Zuordnungen.');
    }
};
