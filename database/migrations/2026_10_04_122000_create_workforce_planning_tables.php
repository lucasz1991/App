<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createMissingTable('workforce_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 24)->default('regular');
            $table->string('location_name', 160)->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        $this->createMissingTable('workforce_pool_user', function (Blueprint $table) {
            $table->foreignId('workforce_pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['workforce_pool_id', 'user_id']);
        });
        $this->createMissingTable('availability_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->date('from');
            $table->date('until');
            $table->string('timezone', 64);
            $table->dateTime('due_at');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        $this->createMissingTable('employee_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('availability_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->date('from');
            $table->date('until');
            $table->json('weekdays');
            $table->boolean('whole_day')->default(true);
            $table->string('start_time', 5)->nullable();
            $table->string('end_time', 5)->nullable();
            $table->string('timezone', 64);
            $table->foreignId('preferred_pool_id')->nullable()->constrained('workforce_pools')->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->string('late_reason', 1000)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'from', 'until']);
        });
        $this->createMissingTable('shift_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('plan_revision');
            $table->json('invited_user_ids');
            $table->dateTime('expires_at');
            $table->string('status', 24)->default('open');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['shift_id', 'status']);
        });
        $this->createMissingTable('shift_offer_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('interested');
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('responded_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['shift_offer_id', 'user_id']);
        });
        $this->createMissingTable('shift_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_assignment_id')->constrained('shift_assignments')->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_assignment_id')->nullable()->constrained('shift_assignments')->restrictOnDelete();
            $table->unsignedInteger('source_plan_revision');
            $table->unsignedInteger('target_plan_revision')->nullable();
            $table->string('status', 24)->default('awaiting_target');
            $table->string('note', 1000)->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        $this->createMissingTable('plan_variants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->date('from');
            $table->date('until');
            $table->string('timezone', 64);
            $table->json('entries');
            $table->json('baseline');
            $table->string('comment', 1000)->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();
        });
        $this->createMissingTable('staffing_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_assignment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('status', 24)->default('open');
            $table->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('due_at');
            $table->string('note', 1000)->nullable();
            $table->json('contacts')->nullable();
            $table->string('resolution', 1000)->nullable();
            $table->foreignId('replacement_assignment_id')->nullable()->constrained('shift_assignments')->restrictOnDelete();
            $table->dateTime('handed_over_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });
        // MariaDB DDL is not transactional. Repair each missing column separately,
        // even when earlier process tables or some demand columns already exist.
        foreach ([
            'staffing_mode' => fn (Blueprint $table) => $table->string('staffing_mode', 16)->default('minimum'),
            'maximum_staff' => fn (Blueprint $table) => $table->unsignedSmallInteger('maximum_staff')->nullable(),
            'qualification_ids' => fn (Blueprint $table) => $table->json('qualification_ids')->nullable(),
            'workforce_pool_id' => fn (Blueprint $table) => $table->foreignId('workforce_pool_id')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn('order_demands', $column)) {
                Schema::table('order_demands', $definition);
            }
        }
        // A crash can occur after ADD COLUMN but before its separate ADD CONSTRAINT.
        if (! $this->hasPoolForeignKey()) {
            Schema::table('order_demands', fn (Blueprint $table) => $table->foreign('workforce_pool_id')->references('id')->on('workforce_pools')->nullOnDelete());
        }
    }

    private function createMissingTable(string $name, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    private function hasPoolForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('order_demands'))->contains(fn ($key) => $key['columns'] === ['workforce_pool_id'] && $key['foreign_table'] === 'workforce_pools' && $key['foreign_columns'] === ['id']);
    }

    public function down(): void
    {
        Schema::table('order_demands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workforce_pool_id');
            $table->dropColumn(['staffing_mode', 'maximum_staff', 'qualification_ids']);
        });
        foreach (['staffing_cases', 'plan_variants', 'shift_transfer_requests', 'shift_offer_responses', 'shift_offers', 'employee_availabilities', 'availability_periods', 'workforce_pool_user', 'workforce_pools'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
