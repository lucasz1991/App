<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsMinimalRailTimeSchema
{
    protected function buildMinimalRailTimeSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->default('guest');
            $table->boolean('status')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('subscribable_type');
            $table->unsignedBigInteger('subscribable_id');
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->text('public_key')->nullable();
            $table->text('auth_token')->nullable();
            $table->string('content_encoding', 50)->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('platform', 20)->default('unknown');
            $table->string('browser', 60)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['subscribable_type', 'subscribable_id']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('category', 40);
            $table->boolean('web_push_enabled')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->json('rbac_permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            foreach ([
                'first_name', 'last_name', 'phone', 'mobile', 'street', 'postal_code', 'city', 'country',
                'birth_date', 'birth_place', 'birth_name', 'nationality', 'education', 'personnel_nr',
                'position', 'entry_date', 'multiple_employment', 'employment_type', 'weekly_working_hours',
                'additional_information', 'tax_identification_number', 'social_security_number', 'iban',
                'health_insurance', 'tax_class', 'children_count', 'religion', 'compensation_type',
                'compensation_amount',
            ] as $field) {
                $table->longText($field)->nullable();
            }
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->text('subject');
            $table->text('message');
            $table->text('action_url')->nullable();
            $table->string('action_label')->nullable();
            $table->unsignedBigInteger('from_user');
            $table->unsignedBigInteger('to_user');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('file_pools', function (Blueprint $table): void {
            $table->id();
            $table->string('filepoolable_type');
            $table->unsignedBigInteger('filepoolable_id');
            $table->string('title');
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('fileable');
            $table->unsignedBigInteger('filepool_id')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name')->nullable();
            $table->string('path')->nullable();
            $table->string('disk')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('type')->nullable();
            $table->json('shared_roles')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->date('visible_from')->nullable();
            $table->boolean('auto_delete')->default(false);
            $table->json('visible_teams')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Gehoert zum Minimalschema, weil die Topbar (livewire:tools.header-inbox)
        // auf JEDER authentifizierten Seite die letzten Chats laedt.
        Schema::create('chats', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->default('direct');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('chat_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->string('message_type', 24)->default('text');
            $table->boolean('view_once')->default(false);
            $table->unsignedSmallInteger('voice_duration_seconds')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_message_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_message_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('viewed_at');
            $table->timestamps();
            $table->unique(['chat_message_id', 'user_id']);
        });
    }
}
