<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexware_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('employer_reference', 64)->unique();
            $table->string('company_name', 120)->nullable();
            $table->string('import_profile', 120)->nullable();
            $table->string('product_version', 80)->nullable();
            $table->json('mappings');
            $table->boolean('active')->default(false);
            $table->unsignedInteger('config_version');
            $table->unsignedInteger('revision');
            $table->unsignedInteger('tested_version')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->foreignId('tested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexware_profiles');
    }
};
