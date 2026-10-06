<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createMissing('operation_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32)->index();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title', 180);
            $table->string('status', 32)->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->longText('payload');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        $this->createMissing('operation_workflow_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_workflow_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 50);
            $table->longText('snapshot');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['operation_workflow_id', 'revision'], 'ops_workflow_revision_unique');
        });
        $this->createMissing('operations_rate_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('kind', 32)->index();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->json('configuration');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });
        $this->createMissing('operations_month_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('month', 7);
            $table->string('timezone', 64);
            $table->string('status', 32)->default('prepared');
            $table->unsignedInteger('revision')->default(1);
            $table->longText('snapshot');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'month'], 'ops_month_user_unique');
        });
        $this->createMissing('operations_closing_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operations_month_closing_id');
            $table->foreign('operations_month_closing_id', 'ops_closing_revision_fk')->references('id')->on('operations_month_closings')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 32);
            $table->longText('snapshot');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['operations_month_closing_id', 'revision'], 'ops_closing_revision_unique');
        });
        $this->createMissing('operations_cost_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->longText('hourly_cents');
            $table->char('currency', 3);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        $this->createMissing('operations_terminal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unique('user_id', 'ops_terminal_user_unique');
            $table->string('pin_hash');
            $table->uuid('terminal_id')->nullable();
            $table->boolean('location_consent')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_metres')->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        $this->createMissing('operations_terminal_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('operations_terminal_profile_id');
            $table->foreign('operations_terminal_profile_id', 'ops_terminal_profile_fk')->references('id')->on('operations_terminal_profiles')->restrictOnDelete();
            $table->uuid('terminal_id');
            $table->uuid('device_id');
            $table->char('token_hash', 64);
            $table->unique('token_hash', 'ops_terminal_token_unique');
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        foreach ([
            ['operation_workflow_revisions', ['operation_workflow_id', 'revision'], 'ops_workflow_revision_unique'],
            ['operations_month_closings', ['user_id', 'month'], 'ops_month_user_unique'],
            ['operations_closing_revisions', ['operations_month_closing_id', 'revision'], 'ops_closing_revision_unique'],
            ['operations_terminal_profiles', ['user_id'], 'ops_terminal_user_unique'],
            ['operations_terminal_sessions', ['token_hash'], 'ops_terminal_token_unique'],
        ] as [$table,$columns,$name]) {
            $has = collect(Schema::getIndexes($table))->contains(fn ($index) => $index['unique'] && $index['columns'] === $columns);
            if (! $has) {
                if (DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
                    throw new RuntimeException('Doppelte historische Operationsschlüssel prüfen; keine Daten werden gelöscht.');
                }
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (['operations_terminal_sessions', 'operations_terminal_profiles', 'operations_cost_rates', 'operations_closing_revisions', 'operations_month_closings', 'operations_rate_rules', 'operation_workflow_revisions', 'operation_workflows'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Historische Operationsdaten erhalten; Rückbau benötigt einen getrennten geprüften Migrationsplan.');
            }
        }
        foreach (['operations_terminal_sessions', 'operations_terminal_profiles', 'operations_cost_rates', 'operations_closing_revisions', 'operations_month_closings', 'operations_rate_rules', 'operation_workflow_revisions', 'operation_workflows'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createMissing(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }
};
