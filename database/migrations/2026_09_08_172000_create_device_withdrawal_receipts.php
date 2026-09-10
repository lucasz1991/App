<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_withdrawal_receipts', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_withdrawal_receipts');
    }
};
