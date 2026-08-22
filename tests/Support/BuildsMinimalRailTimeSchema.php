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
            $table->string('locale', 10)->default('de');
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
            $table->uuid('recording_storage_uuid')->nullable()->unique();
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

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('customer_number', 32)->nullable()->unique();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->default('DE');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_name');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('order_number', 32)->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('service_type')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('requested')->index();
            $table->string('priority', 16)->default('normal')->index();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64)->default('Europe/Berlin');
            $table->string('location_name')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->char('country', 2)->nullable();
            $table->unsignedSmallInteger('required_staff')->default(1);
            $table->json('requirements')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('order_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('role_name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64)->default('Europe/Berlin');
            $table->string('location_name')->nullable();
            $table->unsignedSmallInteger('required_staff')->default(1);
            $table->string('status', 32)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('shift_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('confirmed');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['shift_id', 'user_id']);
            $table->index(['user_id', 'status']);
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

        Schema::create('assistant_knowledge_topics', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('assistant_knowledge_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('topic_id');
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->json('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('include_in_baseline')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Gehoert zum Minimalschema, weil x-ui.page auf JEDER Seite den ersten
        // Aufruf des Nutzers vermerkt (Intro-/Seiteninfo-Automatik).
        Schema::create('user_page_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('page_key', 120);
            $table->timestamp('first_seen_at');
            $table->unique(['user_id', 'page_key']);
        });

        // Gehoert zum Minimalschema, weil die Topbar (layouts/pwa-head) auf
        // JEDER authentifizierten Seite die persoenliche Ton-Zuordnung laedt.
        Schema::create('user_sound_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('event', 40);
            $table->string('signature', 40);
            $table->timestamps();
            $table->unique(['user_id', 'event']);
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
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('reply_to_message_id')->nullable();
            $table->text('body');
            $table->string('message_type', 24)->default('text');
            $table->boolean('view_once')->default(false);
            $table->unsignedSmallInteger('voice_duration_seconds')->nullable();
            $table->json('voice_waveform')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('chat_live_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('chat_message_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('duration_minutes');
            $table->longText('latest_position');
            $table->timestamp('last_position_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->index(['user_id', 'stopped_at', 'expires_at']);
        });

        Schema::create('chat_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_message_id');
            $table->unsignedBigInteger('user_id');
            $table->string('emoji', 16);
            $table->timestamps();
            $table->unique(['chat_message_id', 'user_id']);
        });

        Schema::create('chat_message_views', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_message_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('viewed_at');
            $table->timestamps();
            $table->unique(['chat_message_id', 'user_id']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type', 16)->default('direct');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('call_chat_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 64)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('call_recording_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('policy_version', 100);
            $table->dateTime('accepted_at');
            $table->timestamps();
            $table->unique(['user_id', 'policy_version']);
        });

        Schema::create('room_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('guest_name')->nullable();
            $table->string('role', 16)->default('speaker');
            $table->string('connection', 16)->default('invited');
            $table->string('livekit_identity')->nullable();
            $table->unsignedBigInteger('call_recording_acknowledgement_id')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'user_id']);
        });

        Schema::create('room_recordings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('room_id')->unique();
            $table->uuid('storage_scope_uuid');
            $table->string('livekit_egress_id')->nullable()->unique();
            $table->string('status', 24)->default('requested');
            $table->string('storage_disk', 64)->default('call_recordings');
            $table->string('storage_key')->nullable()->unique();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('start_deadline_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('livekit_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type', 64);
            $table->dateTime('received_at');
            $table->timestamps();
        });

        Schema::create('room_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 64);
            $table->string('external_id')->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('room_invitations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('inviter_id');
            $table->unsignedBigInteger('invitee_id');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('ringing_at')->nullable();
            $table->string('ringing_via', 20)->nullable();
            $table->timestamps();
        });
    }
}
