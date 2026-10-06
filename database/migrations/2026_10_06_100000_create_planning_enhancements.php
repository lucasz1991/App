<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->create('qualification_bundles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 36);
            $t->unsignedInteger('version')->default(1);
            $t->string('name', 120);
            $t->string('role_name', 160);
            $t->json('requirements');
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['code', 'version']);
        });
        $this->create('shift_bundle_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shift_id')->constrained()->restrictOnDelete();
            $t->foreignId('qualification_bundle_id')->constrained()->restrictOnDelete();
            $t->string('scope', 120)->default('Dienst');
            $t->json('requirements');
            $t->unsignedInteger('bundle_version');
            $t->unsignedInteger('shift_revision');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['shift_id', 'scope']);
        });
        $this->create('planning_teams', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->json('user_ids');
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        $this->create('rotation_cycles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->date('anchor');
            $t->unsignedSmallInteger('cycle_days');
            $t->string('timezone', 64);
            $t->json('slots');
            $t->json('exceptions');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        $this->create('shift_dependencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('predecessor_id')->constrained('shifts')->restrictOnDelete();
            $t->foreignId('successor_id')->constrained('shifts')->restrictOnDelete();
            $t->string('train_code', 120)->nullable();
            $t->string('vehicle_code', 120)->nullable();
            $t->string('handover_location', 160);
            $t->unsignedSmallInteger('transfer_minutes');
            $t->boolean('same_employee')->default(false);
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['predecessor_id', 'successor_id']);
        });
        $this->create('workforce_positions', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('role_name', 160);
            $t->string('location_name', 160)->nullable();
            $t->date('from');
            $t->date('until');
            $t->decimal('target_fte', 8, 3);
            $t->unsignedInteger('full_time_week_minutes')->nullable();
            $t->json('qualification_ids');
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
        });
    }

    private function create(string $name, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    public function down(): void
    {
        // Requirement snapshots and approvals are historical evidence, never silently discarded.
        throw new RuntimeException('Planungsnachweise bleiben erhalten. Rücknahme benötigt eine separate Datenfreigabe.');
    }
};
