<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->unsignedInteger('revision')->default(1);
            $table->json('definition');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('shift_series', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_template_id')->constrained()->restrictOnDelete();
            $table->json('definition');
            $table->string('fingerprint', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('shift_series_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_series_id')->constrained()->restrictOnDelete();
            $table->date('service_date');
            $table->foreignId('shift_id')->unique()->constrained()->restrictOnDelete();
            $table->unique(['shift_series_id', 'service_date']);
        });
        Schema::create('order_demands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('role_name', 160);
            $table->unsignedInteger('required_staff');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 80);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('order_demand_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('shift_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('label', 180)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 80);
            $table->timestamps();
            $table->index(['shift_id', 'starts_at']);
        });
        Schema::create('duty_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('plan_revision');
            $table->unsignedInteger('revision')->default(1);
            $table->string('kind', 30);
            $table->unsignedInteger('delay_minutes')->nullable();
            $table->text('message');
            $table->string('status', 20)->default('open');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['shift_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_reports');
        Schema::dropIfExists('shift_sections');
        Schema::table('shifts', fn (Blueprint $table) => $table->dropConstrainedForeignId('order_demand_id'));
        Schema::dropIfExists('order_demands');
        Schema::dropIfExists('shift_series_occurrences');
        Schema::dropIfExists('shift_series');
        Schema::dropIfExists('shift_templates');
    }
};
