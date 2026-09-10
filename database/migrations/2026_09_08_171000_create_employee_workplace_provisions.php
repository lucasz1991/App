<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_workplace_provisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->uuid('tenant_id');
            $table->string('principal', 191);
            $table->char('usage_location', 2);
            $table->string('profile_key', 64);
            $table->uuid('sku_id')->nullable();
            $table->uuid('object_id')->nullable();
            $table->string('state', 40)->default('approved')->index();
            $table->string('account_state', 32)->default('pending');
            $table->string('license_state', 32)->default('pending');
            $table->string('mailbox_state', 32)->default('unknown');
            $table->string('error_code', 64)->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamp('signed_in_at')->nullable();
            $table->longText('initial_password')->nullable();
            $table->timestamp('password_expires_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'principal'], 'employee_provision_principal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_workplace_provisions');
    }
};
