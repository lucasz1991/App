<?php

use App\Services\Ai\AssistantKnowledgeDefaultsImporter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(AssistantKnowledgeDefaultsImporter::class)->import();
    }

    public function down(): void
    {
        app(AssistantKnowledgeDefaultsImporter::class)->removePristineDefaults();
    }
};
