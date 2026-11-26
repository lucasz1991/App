<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        // Erst den Index, dann die Spalte: SQLite verweigert sonst das Loeschen
        // der Spalte, solange der Index sie noch verwendet.
        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
        });

        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
