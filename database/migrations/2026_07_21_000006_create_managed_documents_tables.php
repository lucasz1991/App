<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_documents')) {
            Schema::create('managed_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('audience_type', 20)->default('all')->index();
                $table->boolean('notify_on_update')->default(true);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('content_updated_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('managed_document_team')) {
            Schema::create('managed_document_team', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('managed_document_id')->constrained()->cascadeOnDelete();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['managed_document_id', 'team_id']);
            });
        }

        if (! Schema::hasTable('managed_document_versions')) {
            Schema::create('managed_document_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('managed_document_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version_number');
                $table->boolean('is_current')->default(false)->index();
                $table->text('change_notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(
                    ['managed_document_id', 'version_number'],
                    'managed_doc_versions_doc_version_unique'
                );
            });
        } elseif (! Schema::hasIndex(
            'managed_document_versions',
            ['managed_document_id', 'version_number'],
            'unique'
        )) {
            // MySQL behält bei fehlgeschlagenen DDL-Migrationen bereits
            // angelegte Tabellen. So kann der korrigierte Lauf fortgesetzt
            // werden, ohne vorhandene Versionen zu löschen.
            Schema::table('managed_document_versions', function (Blueprint $table): void {
                $table->unique(
                    ['managed_document_id', 'version_number'],
                    'managed_doc_versions_doc_version_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_document_versions');
        Schema::dropIfExists('managed_document_team');
        Schema::dropIfExists('managed_documents');
    }
};
