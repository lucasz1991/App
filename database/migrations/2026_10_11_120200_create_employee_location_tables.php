<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const LOCATION_TABLES = [
        'employee_location_points',
        'employee_location_consent_events',
        'mobile_location_codes',
        'employee_location_consents',
        'mobile_location_installations',
    ];

    public function up(): void
    {
        $this->prepareSchema();

        Schema::create('mobile_location_installations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('revoked_at', 6)->nullable();
            $table->uuid('session_id')->nullable();
            $table->dateTime('session_started_at', 6)->nullable();
            $table->uuid('permit_id')->nullable();
            $table->dateTime('permit_expires_at', 6)->nullable();
            $table->unsignedBigInteger('consent_id')->nullable();
            $table->unsignedBigInteger('work_time_entry_id')->nullable();
            $table->unsignedInteger('work_revision')->nullable();
            $table->dateTime('last_sample_at', 6)->nullable();
            $table->dateTime('next_sample_at', 6)->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->dateTime('last_quality_at', 6)->nullable();
            $table->string('quality_status', 30)->nullable();
            $table->timestamps(6);
            $table->index(['user_id', 'revoked_at']);
        });
        Schema::create('employee_location_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('installation_id')->nullable()->constrained('mobile_location_installations')->nullOnDelete();
            $table->date('local_date');
            $table->string('purpose', 40)->default('work_routing');
            $table->unsignedInteger('explanation_version');
            $table->char('explanation_hash', 64);
            $table->string('decision', 10);
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('expires_at', 6);
            $table->dateTime('withdrawn_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['user_id', 'local_date', 'purpose']);
            $table->index(['user_id', 'expires_at']);
        });
        Schema::create('mobile_location_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installation_id')->nullable()->constrained('mobile_location_installations')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('purpose', 12);
            $table->dateTime('expires_at', 6);
            $table->dateTime('consumed_at', 6)->nullable();
            $table->timestamps(6);
        });
        Schema::create('employee_location_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consent_id')->constrained('employee_location_consents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('device_assignment_id')->nullable();
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->string('action', 20);
            $table->unsignedInteger('explanation_version');
            $table->char('explanation_hash', 64);
            $table->text('explanation_text');
            $table->dateTime('occurred_at', 6);
            $table->timestamps(6);
            $table->index(['user_id', 'occurred_at']);
        });
        Schema::create('employee_location_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installation_id')->constrained('mobile_location_installations')->cascadeOnDelete();
            $table->foreignId('device_assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consent_id')->constrained('employee_location_consents')->cascadeOnDelete();
            $table->foreignId('work_time_entry_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->text('position');
            $table->decimal('accuracy_m', 8, 2);
            $table->dateTime('measured_at', 6);
            $table->dateTime('received_at', 6);
            $table->timestamps(6);
            $table->unique(['installation_id', 'event_id']);
            $table->index(['user_id', 'measured_at']);
            $table->index('measured_at');
        });
    }

    public function down(): void
    {
        foreach (self::LOCATION_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function prepareSchema(): void
    {
        $missing = [];
        foreach (['users', 'devices', 'device_assignments', 'work_time_entries'] as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            } elseif (! Schema::hasColumn($table, 'id')) {
                $missing[] = $table.'.id';
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Die Mitarbeiter-Standortmigration benötigt zuerst die Tabellen und Schlüssel '.implode(', ', $missing).
                '. Bitte die Geräte-Basismigration 2026_08_17_070000_create_device_management_tables und die Arbeitszeiterfassungsmigration 2026_09_15_190000_create_operations_workflow_tables prüfen. Es wurden keine Standorttabellen verändert.'
            );
        }

        $existing = array_values(array_filter(self::LOCATION_TABLES, static fn (string $table): bool => Schema::hasTable($table)));
        $populated = array_values(array_filter($existing, static fn (string $table): bool => DB::table($table)->exists()));
        if ($populated !== []) {
            throw new RuntimeException(
                'Die vorhandenen Mitarbeiter-Standorttabellen enthalten bereits Daten ('.implode(', ', $populated).
                ') und werden nicht automatisch gelöscht. Bitte den Tabellenstand und die Migrationshistorie anhand einer Sicherung manuell prüfen.'
            );
        }

        // MySQL can leave CREATE TABLE behind after a later foreign-key failure.
        // Rebuild only our entirely empty tables; foreign-key checks stay enabled.
        foreach ($existing as $table) {
            Schema::dropIfExists($table);
        }
    }
};
