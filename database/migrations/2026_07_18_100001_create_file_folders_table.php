<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_pool_id')->constrained('file_pools')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('file_folders')->cascadeOnDelete();
            $table->string('name');
            // Rechte je Rolle: { role: { view: bool, download: bool, delete: bool } }
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->index(['file_pool_id', 'parent_id']);
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreignId('folder_id')->nullable()->after('filepool_id')
                ->constrained('file_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });
        Schema::dropIfExists('file_folders');
    }
};
