<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('mail_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 24);
            $table->json('builder_data');
            $table->longText('html');
            $table->longText('css')->nullable();
            $table->char('content_hash', 64);
            $table->boolean('was_published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mail_document_id', 'revision'], 'mail_doc_versions_revision_unique');
        });

        // Bestehende Installationen starten nicht mit einer leeren History:
        // der beim Deploy aktive Arbeitsstand wird Revision 1.
        if (Schema::hasTable('mail_documents')) {
            DB::table('mail_documents')->orderBy('id')->get()->each(function (object $document): void {
                DB::table('mail_document_versions')->insert([
                    'public_id' => (string) Str::uuid(),
                    'mail_document_id' => $document->id,
                    'revision' => 1,
                    'action' => $document->status === 'published' ? 'published' : 'saved',
                    'builder_data' => $document->builder_data,
                    'html' => $document->html,
                    'css' => $document->css,
                    'content_hash' => $document->content_hash,
                    'was_published' => $document->status === 'published'
                        && trim((string) $document->published_html) === trim((string) $document->html)
                        && trim((string) $document->published_css) === trim((string) $document->css),
                    'created_by' => $document->updated_by ?? $document->created_by,
                    'created_at' => $document->updated_at ?? now(),
                    'updated_at' => $document->updated_at ?? now(),
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_document_versions');
    }
};
