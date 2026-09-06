<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_identity_accounts', 'tenant_id')) {
            Schema::table('employee_identity_accounts', function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable()->index('identity_tenant_idx');
            });
        }

        if (Schema::hasTable('microsoft_device_links')) {
            return;
        }

        Schema::create('microsoft_device_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('devices')->cascadeOnDelete();
            $table->uuid('tenant_id');
            $table->uuid('directory_object_id');
            $table->uuid('entra_device_id');
            $table->uuid('intune_device_id')->nullable();
            $table->string('join_type', 32)->nullable();
            $table->string('directory_status', 24)->default('present');
            $table->string('assignment_status', 40)->default('unmatched');
            $table->string('assignment_source', 32)->nullable();
            $table->foreignId('suggested_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('owner_ids')->nullable();
            $table->boolean('entra_managed')->nullable();
            $table->boolean('entra_compliant')->nullable();
            $table->string('intune_compliance', 40)->nullable();
            $table->timestamp('directory_activity_at')->nullable();
            $table->timestamp('intune_synced_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->uuid('sync_run_id')->nullable()->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'directory_object_id'], 'ms_device_object_unique');
            $table->unique(['tenant_id', 'entra_device_id'], 'ms_device_entra_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_device_links');
        if (Schema::hasColumn('employee_identity_accounts', 'tenant_id')) {
            if (Schema::hasIndex('employee_identity_accounts', 'identity_tenant_idx')) {
                Schema::table('employee_identity_accounts', fn (Blueprint $table) => $table->dropIndex('identity_tenant_idx'));
            }
            Schema::table('employee_identity_accounts', fn (Blueprint $table) => $table->dropColumn('tenant_id'));
        }
    }
};
