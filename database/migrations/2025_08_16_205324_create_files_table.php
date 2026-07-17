<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            // Morphable relation
            $table->morphs('fileable'); // fileable_id + fileable_type

            // Optional grouping ID
            $table->unsignedBigInteger('filepool_id')->nullable()->index();

            // Optional creator or target user
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // File metadata
            $table->string('name');
            $table->string('path');
            $table->string('disk', 50)->default('private');
            $table->string('mime_type')->nullable();
            $table->string('type', 50)->default('default')->index();
            $table->unsignedBigInteger('size')->nullable();

            $table->dateTime('expires_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
