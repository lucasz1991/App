<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['operation_inquiries' => ['created_by', 'updated_by'], 'orders' => ['created_by', 'updated_by'], 'operation_audits' => ['actor_id'], 'operation_workflow_revisions' => ['actor_id']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable()->change());
                }
            }
            $this->actorColumns($table);
        }
        if (Schema::hasTable('commercial_offer_revisions')) {
            $this->actorColumns('commercial_offer_revisions');
        }
        foreach (['operation_inquiries', 'orders'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'customer_portal_location_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->unsignedBigInteger('customer_portal_location_id')->nullable();
                    $t->foreign('customer_portal_location_id', 'cp_'.$table.'_loc')->references('id')->on('customer_locations')->restrictOnDelete();
                });
            }
        }
        $this->create('customer_portal_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('identity_id')->constrained('customer_portal_identities')->restrictOnDelete();
            $t->foreignId('membership_id')->constrained('customer_portal_memberships')->restrictOnDelete();
            $t->uuid('uuid');
            $t->char('payload_hash', 64);
            $t->longText('payload');
            $t->string('status', 32)->default('review');
            $t->unsignedInteger('revision')->default(1);
            $t->longText('decision')->nullable();
            $t->timestamps();
            $t->unique(['customer_id', 'uuid'], 'cp_intake_uuid');
        });
        $this->create('customer_portal_submission_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('submission_id')->constrained('customer_portal_submissions')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->nullable()->constrained('customer_locations')->restrictOnDelete();
            $t->foreignId('inquiry_id')->constrained('operation_inquiries')->restrictOnDelete();
            $t->unsignedSmallInteger('position');
            $t->longText('payload');
            $t->unique('inquiry_id', 'cp_intake_inquiry');
            $t->unique(['submission_id', 'position'], 'cp_intake_position');
        });
        $this->create('customer_portal_automation_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $t->string('name', 180);
            $t->string('mode', 20)->default('manual');
            $t->boolean('auto_reject')->default(false);
            $t->json('allowed_roles');
            $t->json('location_ids');
            $t->json('condition_ids');
            $t->unsignedInteger('minimum_lead_minutes')->nullable();
            $t->unsignedInteger('maximum_staff')->nullable();
            $t->unsignedBigInteger('maximum_total_cents')->nullable();
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        $this->create('customer_portal_profile_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('profile_id');
            $t->foreign('profile_id', 'cp_profile_history_fk')->references('id')->on('customer_portal_automation_profiles')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->string('action', 24);
            $t->longText('snapshot');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['profile_id', 'revision'], 'cp_profile_rev');
        });
        $this->create('customer_capacity_commitments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('customer_locations')->restrictOnDelete();
            $t->string('role_name', 160);
            $t->string('timezone', 64);
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->unsignedInteger('planned_break_minutes');
            $t->json('qualification_ids');
            $t->string('status', 24)->default('requested');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('consented_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'starts_at', 'ends_at'], 'cp_commit_staff_time');
        });
        $this->create('customer_capacity_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('commitment_id')->constrained('customer_capacity_commitments')->restrictOnDelete();
            $t->foreignId('submission_id')->constrained('customer_portal_submissions')->restrictOnDelete();
            $t->foreignId('inquiry_id')->constrained('operation_inquiries')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('commitment_revision');
            $t->string('role_name', 160);
            $t->string('location_name', 180);
            $t->unsignedSmallInteger('planned_break_minutes')->default(0);
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->string('status', 24)->default('held');
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
            $t->unique(['inquiry_id', 'user_id'], 'cp_reserve_inquiry_staff');
            $t->index(['user_id', 'status', 'starts_at', 'ends_at'], 'cp_reserve_time');
        });
        if (! Schema::hasColumn('customer_capacity_reservations', 'planned_break_minutes')) {
            Schema::table('customer_capacity_reservations', fn (Blueprint $t) => $t->unsignedSmallInteger('planned_break_minutes')->default(0));
        }
    }

    private function actorColumns(string $table): void
    {
        foreach (['customer_portal_identity_id' => 'customer_portal_identities', 'customer_portal_membership_id' => 'customer_portal_memberships'] as $column => $parent) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $t) use ($table, $column, $parent) {
                    $t->unsignedBigInteger($column)->nullable();
                    $t->foreign($column, 'cp_'.substr(hash('sha256', $table.$column), 0, 16))->references('id')->on($parent)->restrictOnDelete();
                });
            }
        }
    }

    private function create(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    public function down(): void
    {
        throw new LogicException('Portalhistorie und Akteurreferenzen duerfen nicht automatisch entfernt werden.');
    }
};
