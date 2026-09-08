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
            // Null preserves the existing channel-specific default resolver.
            // Never derive a pairing from a current default during migration.
            $table->foreignId('signature_document_id')->nullable()
                ->constrained('mail_documents')->restrictOnDelete();
            $table->foreignId('published_signature_document_id')->nullable()
                ->constrained('mail_documents')->restrictOnDelete();
        });

        Schema::table('mail_document_versions', function (Blueprint $table): void {
            // History keeps the original identity even if an unused signature
            // is later deleted. A restore must resolve it again fail-closed.
            $table->unsignedBigInteger('signature_document_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('mail_documents')->whereNotNull('signature_document_id')
            ->orWhereNotNull('published_signature_document_id')->exists()
            || DB::table('mail_document_versions')->whereNotNull('signature_document_id')->exists()) {
            throw new RuntimeException('Signaturzuordnungen und ihre Historie müssen vor einem Rückbau ausdrücklich geprüft werden.');
        }

        Schema::table('mail_document_versions', function (Blueprint $table): void {
            $table->dropIndex(['signature_document_id']);
            $table->dropColumn('signature_document_id');
        });

        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signature_document_id');
            $table->dropConstrainedForeignId('published_signature_document_id');
        });
    }
};
