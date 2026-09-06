<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables owned by this base migration in reverse dependency order.
     *
     * @var list<string>
     */
    private const BASE_DEVICE_TABLES = [
        'device_commands',
        'device_artifacts',
        'device_readiness_checks',
        'device_enrollments',
        'device_account_assignments',
        'device_assignments',
        'device_provisioning_profiles',
        'employee_identity_accounts',
        'devices',
    ];

    /**
     * Tables owned by later migrations in reverse dependency order.
     *
     * These may only be removed while recovering a completely empty,
     * interrupted installation. A targeted rollback of this base migration
     * must never delete them.
     *
     * @var list<string>
     */
    private const LATER_DEVICE_TABLES = [
        'microsoft_device_runs',
        'microsoft_device_links',
        // Reserved for the provider webhook idempotency migration.
        'device_provider_events',
        // Added by the identity connector outbox migration. This child table
        // references devices, assignments and users and therefore has to be
        // removed before an intentionally empty interrupted base install can
        // be rebuilt.
        'device_identity_syncs',
        // Added by the normalized multi-provider migration. Keeping it in the
        // recovery order prevents its FK from blocking an intentionally empty
        // interrupted-install cleanup when this base migration is re-run.
        'device_provider_links',
    ];

    public function up(): void
    {
        $this->recoverInterruptedEmptyInstallation();

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('asset_tag', 64)->nullable()->unique();
            $table->string('serial_number', 128)->nullable()->unique();
            $table->string('hostname', 191)->nullable()->index();
            $table->string('display_name', 191)->nullable();
            $table->string('form_factor', 40)->default('unknown')->index();
            $table->string('platform', 32)->default('unknown')->index();
            $table->string('ownership', 32)->default('corporate')->index();
            $table->string('lifecycle_status', 32)->default('inventory')->index();
            $table->string('management_status', 32)->default('unmanaged')->index();
            $table->string('compliance_status', 32)->default('unknown')->index();
            $table->string('primary_provider', 64)->nullable()->index();
            // Provider identifiers use the connector contract's 191-character
            // ceiling. The compatibility mirror must not be narrower than the
            // normalized device_provider_links.external_device_id column.
            $table->string('primary_provider_device_id', 191)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 150)->nullable();
            $table->string('os_version', 100)->nullable();
            $table->string('declared_location', 191)->nullable()->index();
            // Praezise Positionen und erweiterte Inventardaten werden durch
            // Eloquent verschluesselt; deshalb bewusst kein JSON-Spaltentyp.
            $table->longText('location_data')->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['primary_provider', 'primary_provider_device_id'],
                'devices_provider_external_unique'
            );
            $table->index(
                ['lifecycle_status', 'management_status', 'compliance_status'],
                'devices_operational_status_index'
            );
        });

        Schema::create('employee_identity_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 40)->index();
            $table->string('external_id', 191)->nullable();
            $table->string('principal', 191);
            $table->string('email', 191)->nullable()->index();
            $table->string('lifecycle_status', 32)->default('active')->index();
            $table->string('provisioning_status', 32)->default('pending')->index();
            $table->string('license_status', 32)->default('unknown')->index();
            $table->longText('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id'], 'identity_provider_external_unique');
            $table->unique(['provider', 'principal'], 'identity_provider_principal_unique');
            $table->index(['user_id', 'provider'], 'identity_user_provider_index');
        });

        Schema::create('device_provisioning_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('provider', 64)->index();
            $table->string('type', 64)->index();
            $table->string('name', 120);
            $table->unsignedInteger('version')->default(1);
            $table->json('platforms');
            $table->longText('configuration');
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['provider', 'type', 'name', 'version'],
                'provisioning_profile_version_unique'
            );
        });

        Schema::create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('handover_at')->nullable();
            $table->text('handover_notes')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status'], 'device_assignment_device_status_index');
            $table->index(['user_id', 'status'], 'device_assignment_user_status_index');
        });

        Schema::create('device_account_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_identity_account_id')
                ->nullable()
                ->constrained('employee_identity_accounts', 'id', 'dev_acc_identity_fk')
                ->nullOnDelete();
            $table->foreignId('device_provisioning_profile_id')
                ->constrained('device_provisioning_profiles', 'id', 'dev_acc_profile_fk')
                ->restrictOnDelete();
            $table->string('desired_state', 32)->default('assigned')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('configured_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status'], 'device_account_device_status_index');
            $table->index(['user_id', 'status'], 'device_account_user_status_index');
            $table->unique(
                ['device_id', 'employee_identity_account_id', 'device_provisioning_profile_id'],
                'device_account_profile_unique'
            );
        });

        Schema::create('device_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64)->index();
            $table->string('mode', 40)->index();
            // Ausschliesslich der SHA-256-Hash; der Klartext-Token wird nie gespeichert.
            $table->char('token_hash', 64)->unique();
            $table->string('status', 24)->default('invited')->index();
            $table->timestamp('invited_at')->useCurrent();
            $table->timestamp('expires_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('metadata')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status'], 'device_enrollment_device_status_index');
            $table->index(['user_id', 'status'], 'device_enrollment_user_status_index');
            $table->index(['provider', 'status'], 'device_enrollment_provider_status_index');
        });

        Schema::create('device_readiness_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('check_key', 80);
            $table->string('label', 160);
            $table->string('status', 32)->default('unknown')->index();
            $table->string('source', 64)->nullable()->index();
            $table->longText('evidence')->nullable();
            $table->timestamp('checked_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['device_id', 'check_key'], 'device_readiness_check_unique');
        });

        Schema::create('device_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('device_provisioning_profile_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('kind', 64)->index();
            $table->string('platform', 32)->default('unknown')->index();
            $table->string('name', 191);
            $table->string('original_name', 191)->nullable();
            $table->string('disk', 40)->default('private');
            $table->string('storage_path', 500)->unique();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->index();
            $table->longText('metadata')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_assignment_id')
                ->nullable()
                ->constrained('device_assignments', 'id', 'dev_cmd_assignment_fk')
                ->restrictOnDelete();
            $table->string('provider', 64)->index();
            $table->string('type', 64)->index();
            $table->string('status', 32)->default('pending_approval')->index();
            $table->boolean('is_destructive')->default(false)->index();
            $table->text('justification');
            $table->longText('payload')->nullable();
            $table->longText('result')->nullable();
            $table->string('correlation_id', 128)->nullable()->unique();
            $table->string('provider_job_id', 191)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status'], 'device_command_device_status_index');
            $table->index(['device_assignment_id', 'status'], 'dev_cmd_assignment_status_idx');
            $table->index(['provider', 'provider_job_id'], 'device_command_provider_job_index');
        });
    }

    public function down(): void
    {
        $remainingLaterTables = array_values(array_filter(
            self::LATER_DEVICE_TABLES,
            static fn (string $table): bool => Schema::hasTable($table),
        ));

        if ($remainingLaterTables !== []) {
            throw new RuntimeException(sprintf(
                'Die Geräte-Basismigration kann nicht zurückgerollt werden, solange nachgelagerte Tabellen existieren (%s). Bitte zuerst deren Migrationen in umgekehrter Reihenfolge zurückrollen.',
                implode(', ', $remainingLaterTables),
            ));
        }

        $this->dropTables(self::BASE_DEVICE_TABLES);
    }

    private function recoverInterruptedEmptyInstallation(): void
    {
        $recoveryTables = [
            ...self::LATER_DEVICE_TABLES,
            ...self::BASE_DEVICE_TABLES,
        ];

        $existingTables = array_values(array_filter(
            $recoveryTables,
            static fn (string $table): bool => Schema::hasTable($table),
        ));

        if ($existingTables === []) {
            return;
        }

        $populatedTables = array_values(array_filter(
            $existingTables,
            static fn (string $table): bool => DB::table($table)->limit(1)->exists(),
        ));

        if ($populatedTables !== []) {
            throw new RuntimeException(sprintf(
                'Die unvollständige Geräte-Migration enthält bereits Daten (%s) und wird nicht automatisch gelöscht. Bitte Daten sichern und den Tabellenstand manuell prüfen.',
                implode(', ', $populatedTables),
            ));
        }

        $this->dropTables($recoveryTables);
    }

    /**
     * @param  list<string>  $tables
     */
    private function dropTables(array $tables): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach ($tables as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
};
