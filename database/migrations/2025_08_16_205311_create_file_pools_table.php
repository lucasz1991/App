<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('file_pools', function (Blueprint $table) {
            $table->id();

            // morphable Beziehung zum jeweiligen Owner-Modell
            $table->morphs('filepoolable');

            $table->string('title');
            $table->string('type')->nullable();
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_pools');
    }
};
