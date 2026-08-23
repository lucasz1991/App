<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        // Existing installations created by the first device migration used
        // 128 characters here. Expand the compatibility mirror before the
        // normalized provider identifiers are backfilled into it.
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('primary_provider_device_id', 191)->nullable()->change();
        });

        Schema::create('device_provider_links', function (Blueprint $table) use ($sqlite): void {
            $table->id();
            if ($sqlite) {
                // Production MySQL receives the FK below. SQLite's migration
                // rebuild semantics would otherwise break the base migration's
                // intentional interrupted-install recovery test.
                $table->unsignedBigInteger('device_id')->index();
            } else {
                $table->foreignId('device_id')
                    ->constrained('devices', 'id', 'dev_provider_links_device_fk')
                    ->cascadeOnDelete();
            }
            $table->string('provider', 64);
            $table->string('external_device_id', 191)->nullable();
            $table->string('role', 24)->default('support')->index();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['device_id', 'provider'],
                'dev_provider_link_device_provider_uq',
            );
            $table->unique(
                ['provider', 'external_device_id'],
                'dev_provider_link_provider_external_uq',
            );
        });

        $now = now();
        DB::table('devices')
            ->select([
                'id',
                'primary_provider',
                'primary_provider_device_id',
                'last_seen_at',
                'last_synced_at',
            ])
            ->whereNotNull('primary_provider')
            ->where('primary_provider', '!=', '')
            ->orderBy('id')
            ->chunkById(250, function ($devices) use ($now): void {
                $rows = [];
                foreach ($devices as $device) {
                    $provider = strtolower(trim((string) $device->primary_provider));
                    if ($provider === '') {
                        continue;
                    }

                    $externalId = trim((string) ($device->primary_provider_device_id ?? ''));
                    $rows[] = [
                        'device_id' => $device->id,
                        'provider' => mb_substr($provider, 0, 64),
                        'external_device_id' => $externalId !== '' ? mb_substr($externalId, 0, 191) : null,
                        'role' => 'primary',
                        'status' => $externalId !== '' ? 'active' : 'pending',
                        'last_seen_at' => $device->last_seen_at,
                        'last_synced_at' => $device->last_synced_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('device_provider_links')->insert($rows);
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_provider_links');
    }
};
