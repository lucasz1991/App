<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('event_id', 128);
            $table->string('event_type', 64);
            $table->char('payload_hash', 64);
            $table->string('status', 16)->default('processing');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'dev_prov_evt_provider_id_uq');
            $table->index(['status', 'created_at'], 'dev_prov_evt_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_provider_events');
    }
};
