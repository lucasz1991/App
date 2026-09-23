<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_excel_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->unique()->constrained('dropbox_sources');
            $table->foreignId('created_by')->constrained('users');
            $table->string('file_hash', 64);
            $table->string('disk_path');
            $table->string('parsed_path');
            $table->string('parsed_hash', 64);
            $table->string('status')->default('preview');
            $table->unsignedInteger('processed')->default(0);
            $table->json('summary');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_excel_imports');
    }
};
