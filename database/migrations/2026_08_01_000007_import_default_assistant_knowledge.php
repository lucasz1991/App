<?php

use App\Services\Ai\AssistantKnowledgeDefaultsImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        app(AssistantKnowledgeDefaultsImporter::class)->import();
    }

    public function down(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        app(AssistantKnowledgeDefaultsImporter::class)->removePristineDefaults();
    }
};
