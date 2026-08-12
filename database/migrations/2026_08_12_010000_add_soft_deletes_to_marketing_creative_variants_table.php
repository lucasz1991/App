<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_creative_variants')
            || Schema::hasColumn('marketing_creative_variants', 'deleted_at')) {
            return;
        }

        Schema::table('marketing_creative_variants', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketing_creative_variants')
            || ! Schema::hasColumn('marketing_creative_variants', 'deleted_at')) {
            return;
        }

        Schema::table('marketing_creative_variants', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
