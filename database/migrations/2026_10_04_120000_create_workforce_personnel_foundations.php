<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->create('personnel_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('abilities');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['responsible_user_id', 'starts_on']);
        });
        $this->create('employee_work_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('timezone', 64);
            $table->unsignedInteger('weekly_target_minutes');
            $table->unsignedInteger('maximum_weekly_minutes')->nullable();
            $table->json('daily_minutes');
            $table->json('work_windows')->nullable();
            $table->json('valuation_rules')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'starts_on']);
        });
        $this->create('employee_rule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('operations_rule_profile_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'starts_on']);
        });
        $this->create('employee_vacation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('unit', 12);
            $table->string('holiday_region', 100)->nullable();
            $table->json('non_working_dates');
            $table->string('calendar_version', 120);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'starts_on']);
        });
        $this->create('workforce_account_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('account', 12);
            $table->string('kind', 30);
            $table->date('effective_on');
            $table->date('expires_on')->nullable();
            $table->unsignedSmallInteger('entitlement_year')->nullable();
            $table->integer('quantity');
            $table->string('unit', 12);
            $table->string('note', 1000);
            $table->foreignId('employee_vacation_policy_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('absence_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_entry_id')->nullable()->constrained('workforce_account_entries')->restrictOnDelete();
            $table->json('snapshot')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'account', 'effective_on']);
        });
        $this->create('vacation_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('absence_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('workforce_account_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_vacation_policy_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('reserved');
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['absence_request_id', 'workforce_account_entry_id'], 'vacation_absence_credit_unique');
        });
        $this->create('personnel_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('title', 180);
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('revision')->default(1);
            $table->string('note', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'due_on']);
        });
        $this->create('personnel_trainings', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->foreignId('qualification_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->unsignedInteger('capacity');
            $table->string('status', 20)->default('scheduled');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        $this->create('personnel_training_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personnel_training_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('confirmed');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->unique(['personnel_training_id', 'user_id'], 'training_participant_user_unique');
        });
        $this->create('personnel_plan_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_assignment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('plan_revision');
            $table->string('origin_type', 40);
            $table->unsignedBigInteger('origin_id');
            $table->unsignedInteger('origin_revision');
            $table->string('assessment_key', 64)->unique();
            $table->json('origin_snapshot');
            $table->json('initial_snapshot');
            $table->json('latest_snapshot');
            $table->string('status', 20);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        foreach (['vacation_reservations' => [['absence_request_id', 'workforce_account_entry_id'], 'vacation_absence_credit_unique'], 'personnel_training_participants' => [['personnel_training_id', 'user_id'], 'training_participant_user_unique']] as $table => [$columns, $name]) {
            if (! Schema::hasIndex($table, $columns, 'unique')) {
                Schema::table($table, fn (Blueprint $definition) => $definition->unique($columns, $name));
            }
        }
        if (Schema::hasTable('absence_requests') && ! Schema::hasColumn('absence_requests', 'vacation_fraction')) {
            Schema::table('absence_requests', fn (Blueprint $table) => $table->decimal('vacation_fraction', 3, 2)->nullable());
        }
        if (Schema::hasTable('employee_work_models') && ! Schema::hasColumn('employee_work_models', 'valuation_rules')) {
            Schema::table('employee_work_models', fn (Blueprint $table) => $table->json('valuation_rules')->nullable());
        }
    }

    private function create(string $name, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    public function down(): void
    {
        $tables = ['personnel_plan_reviews', 'personnel_training_participants', 'personnel_trainings', 'personnel_tasks', 'vacation_reservations', 'workforce_account_entries', 'employee_vacation_policies', 'employee_rule_assignments', 'employee_work_models', 'personnel_responsibilities'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Personalhistorie muss vor einem Rollback separat gesichert und freigegeben werden.');
            }
        }
        if (Schema::hasColumn('absence_requests', 'vacation_fraction')) {
            Schema::table('absence_requests', fn (Blueprint $table) => $table->dropColumn('vacation_fraction'));
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
