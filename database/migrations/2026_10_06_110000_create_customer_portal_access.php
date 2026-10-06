<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_portal_identities')) {
            Schema::create('customer_portal_identities', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 180);
                $table->string('email', 254)->unique();
                $table->string('password');
                $table->boolean('active')->default(true);
                $table->unsignedInteger('revision')->default(1);
                $table->timestamp('email_verified_at')->nullable();
                $table->text('two_factor_secret')->nullable();
                $table->timestamp('two_factor_confirmed_at')->nullable();
                $table->longText('two_factor_recovery_codes')->nullable();
                $table->unsignedBigInteger('two_factor_last_counter')->nullable();
                $table->text('two_factor_pending_secret')->nullable();
                $table->timestamp('two_factor_pending_expires_at')->nullable();
                $table->char('two_factor_pending_session_hash', 64)->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }
        foreach (['two_factor_recovery_codes', 'two_factor_last_counter', 'two_factor_pending_secret', 'two_factor_pending_expires_at', 'two_factor_pending_session_hash'] as $column) {
            if (! Schema::hasColumn('customer_portal_identities', $column)) {
                Schema::table('customer_portal_identities', function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'two_factor_recovery_codes' => $table->longText($column)->nullable(),
                        'two_factor_last_counter' => $table->unsignedBigInteger($column)->nullable(),
                        'two_factor_pending_expires_at' => $table->timestamp($column)->nullable(),
                        'two_factor_pending_session_hash' => $table->char($column, 64)->nullable(),
                        default => $table->text($column)->nullable(),
                    };
                });
            }
        }
        if (! Schema::hasTable('customer_portal_settings')) {
            Schema::create('customer_portal_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
                $table->boolean('enabled')->default(false);
                $table->unsignedInteger('revision')->default(1);
                $table->json('modules');
                $table->string('automation_mode', 24)->default('manual');
                $table->boolean('auto_reject')->default(false);
                $table->boolean('booking_authority')->default(false);
                $table->boolean('require_mfa')->default(false);
                $table->json('notifications');
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('customer_portal_memberships')) {
            Schema::create('customer_portal_memberships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('contact_id')->constrained('customer_contacts')->restrictOnDelete();
                $table->foreignId('identity_id')->nullable()->constrained('customer_portal_identities')->restrictOnDelete();
                $table->string('status', 24)->default('pending');
                $table->unsignedInteger('revision')->default(1);
                $table->string('role', 32)->default('reader');
                $table->json('capabilities');
                $table->json('location_ids');
                $table->date('history_from')->nullable();
                $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['customer_id', 'contact_id'], 'cp_membership_contact_unique');
                $table->unique(['customer_id', 'identity_id'], 'cp_membership_identity_unique');
                $table->index(['identity_id', 'status']);
            });
        }
        if (! Schema::hasTable('customer_portal_invitations')) {
            Schema::create('customer_portal_invitations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('membership_id')->constrained('customer_portal_memberships')->restrictOnDelete();
                $table->string('purpose', 16)->default('invite');
                $table->char('token_hash', 64)->unique();
                $table->longText('recipient_email');
                $table->unsignedInteger('setting_revision');
                $table->unsignedInteger('membership_revision');
                $table->unsignedInteger('contact_revision');
                $table->unsignedInteger('identity_revision')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamps();
                $table->index(['membership_id', 'purpose']);
            });
        }
        if (! Schema::hasTable('customer_portal_deliveries')) {
            Schema::create('customer_portal_deliveries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('membership_id')->constrained('customer_portal_memberships')->restrictOnDelete();
                $table->foreignId('invitation_id')->nullable()->constrained('customer_portal_invitations')->restrictOnDelete();
                $table->string('kind', 40);
                $table->char('dedup_key', 64)->unique();
                $table->string('status', 24)->default('pending');
                $table->longText('payload');
                $table->unsignedInteger('setting_revision');
                $table->unsignedInteger('membership_revision');
                $table->unsignedInteger('contact_revision');
                $table->unsignedInteger('attempts')->default(0);
                $table->string('failure_code', 40)->nullable();
                $table->timestamp('attempted_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('customer_portal_audits')) {
            Schema::create('customer_portal_audits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('membership_id')->nullable()->constrained('customer_portal_memberships')->restrictOnDelete();
                $table->foreignId('identity_id')->nullable()->constrained('customer_portal_identities')->restrictOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('action', 80);
                $table->unsignedInteger('revision')->nullable();
                $table->json('details')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('customer_portal_manager_grants')) {
            Schema::create('customer_portal_manager_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->boolean('active')->default(false);
                $table->json('abilities');
                $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at');
                $table->timestamps();
                $table->unique(['customer_id', 'user_id'], 'cp_manager_customer_unique');
            });
        }
        if (! Schema::hasTable('customer_portal_password_reset_tokens')) {
            Schema::create('customer_portal_password_reset_tokens', function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['customer_portal_audits', 'customer_portal_deliveries', 'customer_portal_invitations', 'customer_portal_memberships', 'customer_portal_settings', 'customer_portal_identities', 'customer_portal_manager_grants', 'customer_portal_password_reset_tokens'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Populated customer portal tables cannot be removed automatically.');
            }
        }
        foreach (['customer_portal_audits', 'customer_portal_deliveries', 'customer_portal_invitations', 'customer_portal_memberships', 'customer_portal_settings', 'customer_portal_identities', 'customer_portal_manager_grants', 'customer_portal_password_reset_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
