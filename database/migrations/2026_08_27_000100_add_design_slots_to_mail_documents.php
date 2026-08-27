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
            $table->string('name', 80)->default('Standarddesign')->after('kind');
            // Inaktive Slots tragen bewusst NULL statt false. Der eindeutige
            // Index erlaubt dadurch beliebig viele Entwuerfe, aber hoechstens
            // genau einen aktiven Slot je Dokumentart.
            $table->boolean('is_active')->nullable()->after('status');
        });

        DB::table('mail_documents')
            ->where('kind', 'template')
            ->update(['name' => 'Standardvorlage']);
        DB::table('mail_documents')
            ->where('kind', 'signature')
            ->update(['name' => 'Standardsignatur']);
        DB::table('mail_documents')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->whereNotNull('published_html')
            ->where('published_html', '!=', '')
            ->update(['is_active' => true]);

        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->dropUnique('mail_documents_kind_unique');
            $table->index('kind', 'mail_documents_kind_index');
            $table->unique(['kind', 'is_active'], 'mail_documents_kind_active_unique');
        });
    }

    public function down(): void
    {
        $hasDuplicateKinds = DB::table('mail_documents')
            ->select('kind')
            ->groupBy('kind')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateKinds) {
            throw new RuntimeException(
                'Die Design-Slot-Migration kann nicht zurueckgerollt werden, solange mehrere Slots derselben Dokumentart existieren.'
            );
        }

        Schema::table('mail_documents', function (Blueprint $table): void {
            $table->dropUnique('mail_documents_kind_active_unique');
            $table->dropIndex('mail_documents_kind_index');
            $table->dropColumn(['name', 'is_active']);
            $table->unique('kind');
        });
    }
};
