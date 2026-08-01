<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_knowledge_topics', function (Blueprint $table): void {
            $table->string('source_key', 160)->nullable()->unique('assistant_knowledge_topics_source_key_unique');
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
        });

        Schema::table('assistant_knowledge_entries', function (Blueprint $table): void {
            $table->string('source_key', 160)->nullable()->unique('assistant_knowledge_entries_source_key_unique');
            $table->text('source_url')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_knowledge_entries', function (Blueprint $table): void {
            $table->dropUnique('assistant_knowledge_entries_source_key_unique');
            $table->dropColumn(['source_key', 'source_url', 'source_hash', 'imported_at']);
        });

        Schema::table('assistant_knowledge_topics', function (Blueprint $table): void {
            $table->dropUnique('assistant_knowledge_topics_source_key_unique');
            $table->dropColumn(['source_key', 'source_hash', 'imported_at']);
        });
    }
};
