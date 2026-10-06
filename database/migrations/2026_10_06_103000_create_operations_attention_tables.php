<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operations_attention_locks')) {
            Schema::create('operations_attention_locks', function (Blueprint $table) {
                $table->string('key', 50)->primary();
            });
        }
        if (! Schema::hasTable('operations_reminder_preferences')) {
            Schema::create('operations_reminder_preferences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('kind', 32);
                $table->unsignedInteger('lead_minutes');
                $table->string('quiet_from', 5)->nullable();
                $table->string('quiet_until', 5)->nullable();
                $table->string('timezone', 64);
                $table->boolean('is_active')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'kind']);
            });
        }
        if (! Schema::hasTable('operations_attention_items')) {
            Schema::create('operations_attention_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('source_key', 64);
                $table->string('kind', 32);
                $table->string('headline', 180);
                $table->string('module', 50);
                $table->unsignedBigInteger('record_id')->nullable();
                $table->unsignedInteger('source_revision')->default(1);
                $table->dateTime('due_at')->nullable();
                $table->dateTime('read_at')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->timestamps();
                $table->unique(['recipient_user_id', 'source_key']);
                $table->index(['recipient_user_id', 'resolved_at', 'due_at'], 'attention_recipient_due');
            });
        }
        if (! Schema::hasTable('operations_monitor_profiles')) {
            Schema::create('operations_monitor_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->unsignedInteger('start_grace_minutes');
                $table->unsignedInteger('end_grace_minutes');
                $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
                $table->boolean('auto_cases')->default(false);
                $table->boolean('is_active')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Erinnerungs- und Leitstellenhistorie bleibt erhalten. Explizite Datenmigration erforderlich.');
    }
};
