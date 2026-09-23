<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropbox_connections', function (Blueprint $t) {
            $t->id();
            $t->string('mode')->default('off');
            $t->unsignedInteger('generation')->default(1);
            $t->string('app_key')->nullable();
            $t->text('app_secret')->nullable();
            $t->text('access_token')->nullable();
            $t->text('refresh_token')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('account_id')->nullable();
            $t->string('account_label')->nullable();
            $t->string('namespace_id')->nullable();
            $t->json('scopes')->nullable();
            $t->json('settings')->nullable();
            $t->timestamp('webhook_at')->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->timestamp('preview_at')->nullable();
            $t->unsignedInteger('preview_generation')->nullable();
            $t->timestamp('imported_at')->nullable();
            $t->timestamp('exported_at')->nullable();
            $t->string('error_code')->nullable();
            $t->boolean('closing')->default(false);
            $t->timestamp('closing_started_at')->nullable();
            $t->string('archive_path', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('dropbox_folders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->string('path', 500);
            $t->text('cursor')->nullable();
            $t->unsignedInteger('generation');
            $t->boolean('preview')->default(false);
            $t->timestamps();
        });
        Schema::create('dropbox_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->string('file_id');
            $t->string('path', 500);
            $t->string('name');
            $t->string('profile');
            $t->string('rev')->nullable();
            $t->string('own_rev')->nullable();
            $t->string('processed_rev')->nullable();
            $t->json('weeks')->nullable();
            $t->json('progress')->nullable();
            $t->string('state')->default('new');
            $t->timestamp('first_seen_at');
            $t->timestamp('server_modified')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'file_id']);
        });
        Schema::create('dropbox_identities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->string('alias');
            $t->string('kind')->default('unresolved');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->json('details')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['connection_id', 'alias']);
        });
        Schema::create('employee_competency_facts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('identity_id')->constrained('dropbox_identities');
            $t->string('kind');
            $t->string('name');
            $t->string('scope')->nullable();
            $t->json('value');
            $t->unsignedInteger('revision')->default(1);
            $t->foreignId('qualification_type_id')->nullable()->constrained('qualification_types');
            $t->string('qualification_field')->nullable();
            $t->timestamps();
        });
        Schema::create('dropbox_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->string('domain');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('assignment_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['model_type', 'model_id']);
        });
        Schema::create('dropbox_appearances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_id')->constrained('dropbox_sources');
            $t->foreignId('record_id')->constrained('dropbox_records');
            $t->string('sheet');
            $t->string('slot');
            $t->string('fingerprint', 64);
            $t->string('identity_hash', 64)->nullable()->index();
            $t->json('locator');
            $t->json('baseline');
            $t->json('last_excel');
            $t->string('seen_rev')->nullable();
            $t->timestamps();
            $t->unique(['source_id', 'sheet', 'slot']);
        });
        Schema::create('dropbox_work_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->unsignedInteger('generation');
            $t->string('kind');
            $t->string('resource');
            $t->json('payload')->nullable();
            $t->unsignedBigInteger('requested')->default(1);
            $t->unsignedBigInteger('completed')->default(0);
            $t->timestamp('available_at');
            $t->timestamp('queued_until')->nullable();
            $t->timestamp('running_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('error_code')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'generation', 'resource'], 'dropbox_work_resource');
        });
        Schema::create('dropbox_conflicts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->foreignId('source_id')->nullable()->constrained('dropbox_sources');
            $t->foreignId('record_id')->nullable()->constrained('dropbox_records');
            $t->string('key', 64)->unique();
            $t->string('reason');
            $t->json('snapshot');
            $t->json('decision')->nullable();
            $t->string('state')->default('open');
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('dropbox_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->string('kind');
            $t->string('status');
            $t->json('summary')->nullable();
            $t->timestamps();
        });
        Schema::create('dropbox_uploads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->unsignedInteger('generation');
            $t->string('path', 500);
            $t->string('expected_rev')->nullable();
            $t->string('content_hash', 64);
            $t->json('manifest')->nullable();
            $t->string('status')->default('prepared');
            $t->string('result_rev')->nullable();
            $t->timestamps();
        });
        Schema::create('dropbox_archives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained('dropbox_connections');
            $t->foreignId('source_id')->constrained('dropbox_sources');
            $t->unsignedInteger('generation');
            $t->string('source_rev');
            $t->string('archive_path', 500);
            $t->string('archive_rev');
            $t->timestamps();
            $t->unique(['source_id', 'generation', 'source_rev'], 'dropbox_archive_revision');
        });
        Schema::table('shifts', fn (Blueprint $t) => $t->json('disposition_details')->nullable());
    }

    public function down(): void
    {
        Schema::table('shifts', fn (Blueprint $t) => $t->dropColumn('disposition_details'));
        foreach (['dropbox_archives', 'dropbox_uploads', 'dropbox_runs', 'dropbox_conflicts', 'dropbox_work_items', 'dropbox_appearances', 'dropbox_records', 'employee_competency_facts', 'dropbox_identities', 'dropbox_sources', 'dropbox_folders', 'dropbox_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
