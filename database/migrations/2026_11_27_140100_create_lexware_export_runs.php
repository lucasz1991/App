<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexware_export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('closing_id')->unique()->constrained('operations_month_closings')->restrictOnDelete();
            $table->unsignedInteger('closing_revision');
            $table->foreignId('profile_id')->constrained('lexware_profiles')->restrictOnDelete();
            $table->unsignedInteger('profile_version');
            $table->char('target_key', 64)->unique();
            $table->char('sha256', 64);
            $table->longText('payload');
            $table->text('positions');
            $table->text('imported_codes')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->foreignId('result_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('result_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexware_export_runs');
    }
};
