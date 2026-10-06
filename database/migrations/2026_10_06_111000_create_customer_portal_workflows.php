<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_portal_publications')) {
            Schema::create('customer_portal_publications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('location_id')->nullable()->constrained('customer_locations')->restrictOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('subject_type', 24);
                $table->unsignedBigInteger('subject_id')->default(0);
                $table->unsignedInteger('source_revision')->default(1);
                $table->unsignedInteger('revision')->default(1);
                $table->dateTime('service_starts_at')->nullable();
                $table->dateTime('service_ends_at')->nullable();
                $table->string('status', 24)->default('quarantined');
                $table->string('title', 180);
                $table->longText('payload');
                $table->string('file_path')->nullable();
                $table->char('file_hash', 64)->nullable();
                $table->string('file_name', 180)->nullable();
                $table->string('file_mime', 80)->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('withdrawn_at')->nullable();
                $table->timestamps();
                $table->unique(['customer_id', 'subject_type', 'subject_id', 'revision'], 'portal_publication_version_unique');
                $table->index(['customer_id', 'status', 'subject_type'], 'portal_publication_scope_index');
            });
        }
        if (! Schema::hasTable('customer_portal_requests')) {
            Schema::create('customer_portal_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('membership_id')->constrained('customer_portal_memberships')->restrictOnDelete();
                $table->foreignId('identity_id')->constrained('customer_portal_identities')->restrictOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
                $table->uuid('client_uuid');
                $table->char('request_hash', 64);
                $table->string('kind', 24);
                $table->string('title', 180);
                $table->string('status', 24)->default('submitted');
                $table->unsignedInteger('revision')->default(1);
                $table->longText('payload');
                $table->longText('response')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['identity_id', 'client_uuid'], 'portal_request_uuid_unique');
                $table->index(['customer_id', 'status'], 'portal_request_customer_status');
            });
        }
        if (! Schema::hasTable('customer_portal_messages')) {
            Schema::create('customer_portal_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('membership_id')->nullable()->constrained('customer_portal_memberships')->restrictOnDelete();
                $table->foreignId('identity_id')->nullable()->constrained('customer_portal_identities')->restrictOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->uuid('client_uuid');
                $table->char('request_hash', 64);
                $table->string('visibility', 24)->default('internal');
                $table->string('subject', 180);
                $table->longText('body');
                $table->timestamps();
                $table->unique(['customer_id', 'client_uuid'], 'portal_message_uuid_unique');
                $table->index(['customer_id', 'visibility'], 'portal_message_scope_index');
            });
        }
        if (! Schema::hasTable('customer_portal_attachments')) {
            Schema::create('customer_portal_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->foreignId('identity_id')->constrained('customer_portal_identities')->restrictOnDelete();
                $table->foreignId('membership_id')->constrained('customer_portal_memberships')->restrictOnDelete();
                $table->string('source_type', 24);
                $table->unsignedBigInteger('source_id');
                $table->uuid('client_uuid');
                $table->string('status', 24)->default('quarantined');
                $table->unsignedInteger('revision')->default(1);
                $table->string('file_path');
                $table->string('file_name', 180);
                $table->string('file_mime', 80);
                $table->unsignedBigInteger('file_size');
                $table->char('file_hash', 64);
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->longText('review_note')->nullable();
                $table->timestamps();
                $table->unique(['customer_id', 'client_uuid'], 'portal_attachment_uuid_unique');
                $table->index(['customer_id', 'source_type', 'source_id'], 'portal_attachment_source_index');
            });
        }
        foreach (['service_starts_at', 'service_ends_at'] as $column) {
            if (! Schema::hasColumn('customer_portal_publications', $column)) {
                Schema::table('customer_portal_publications', fn (Blueprint $table) => $table->dateTime($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['customer_portal_attachments', 'customer_portal_messages', 'customer_portal_requests', 'customer_portal_publications'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Portalhistorie erhalten; Rückbau benötigt einen gesonderten geprüften Plan.');
            }
        }
        foreach (['customer_portal_attachments', 'customer_portal_messages', 'customer_portal_requests', 'customer_portal_publications'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
