<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routing_monthly_usage')) {
            Schema::create('routing_monthly_usage', function (Blueprint $table): void {
                $table->id();
                $table->string('month', 7)->unique();
                $table->unsignedBigInteger('routing_transactions')->default(0);
                $table->unsignedBigInteger('map_tiles')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_monthly_usage');
    }
};
