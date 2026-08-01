<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->uuid('recording_storage_uuid')->nullable()->unique()->after('id');
        });

        DB::table('teams')
            ->whereNull('recording_storage_uuid')
            ->orderBy('id')
            ->chunkById(100, function ($teams): void {
                foreach ($teams as $team) {
                    DB::table('teams')->where('id', $team->id)->update([
                        'recording_storage_uuid' => (string) Str::uuid(),
                    ]);
                }
            });

        Schema::create('call_recording_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('policy_version', 100);
            $table->dateTime('accepted_at');
            $table->timestamps();

            $table->unique(['user_id', 'policy_version'], 'call_recording_ack_user_policy_unique');
        });

        Schema::table('room_participants', function (Blueprint $table): void {
            $table->foreignId('call_recording_acknowledgement_id')
                ->nullable()
                ->after('livekit_identity')
                ->constrained('call_recording_acknowledgements')
                ->nullOnDelete();
        });

        Schema::create('room_recordings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
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

            // Ein Raum besitzt genau eine verpflichtende Gesamtaufzeichnung.
            $table->unique('room_id');
            $table->index(['status', 'start_deadline_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('livekit_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type', 64);
            $table->dateTime('received_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livekit_webhook_receipts');
        Schema::dropIfExists('room_recordings');

        Schema::table('room_participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('call_recording_acknowledgement_id');
        });

        Schema::dropIfExists('call_recording_acknowledgements');

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropUnique(['recording_storage_uuid']);
            $table->dropColumn('recording_storage_uuid');
        });
    }
};
