<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staffing_automation_runs')) {
            Schema::create('staffing_automation_runs', function (Blueprint $table): void {
                $table->id();
                $table->uuid('run_uuid')->unique();
                $table->foreignId('shift_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('plan_revision');
                $table->unsignedInteger('settings_revision');
                $table->foreignId('supervising_user_id')->constrained('users')->restrictOnDelete();
                $table->string('state', 32)->default('waiting');
                $table->string('reason_code', 64)->nullable();
                $table->unsignedSmallInteger('wave_count')->default(0);
                $table->foreignId('offer_id')->nullable()->constrained('shift_offers')->restrictOnDelete();
                $table->foreignId('staffing_case_id')->nullable()->constrained('staffing_cases')->restrictOnDelete();
                $table->dateTime('next_run_at')->nullable();
                $table->json('basis')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->timestamps();
                $table->unique(['shift_id', 'plan_revision']);
                $table->index(['state', 'next_run_at']);
            });
        }
        // Staffing can be activated independently of the mailbox domain.
        foreach (['actor_kind' => fn (Blueprint $t) => $t->string('actor_kind', 20)->default('human'), 'supervising_user_id' => fn (Blueprint $t) => $t->unsignedBigInteger('supervising_user_id')->nullable()] as $column => $definition) {
            if (! Schema::hasColumn('operation_audits', $column)) {
                Schema::table('operation_audits', $definition);
            }
        }
        $actor = collect(Schema::getColumns('operation_audits'))->firstWhere('name', 'actor_id');
        if ($actor && ! $actor['nullable']) {
            Schema::table('operation_audits', fn (Blueprint $table) => $table->unsignedBigInteger('actor_id')->nullable()->change());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('staffing_automation_runs') && Schema::getConnection()->table('staffing_automation_runs')->exists()) {
            throw new RuntimeException('Personalanfragen enthalten Belege; Rücknahme würde Historie löschen.');
        }
        Schema::dropIfExists('staffing_automation_runs');
        // Additive authorship compatibility is retained for existing audit history.
    }
};
