<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_locations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('coordinates_confirmed_at')->nullable();
        });
        foreach (['orders', 'shifts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('customer_location_id')->nullable()->constrained('customer_locations')->nullOnDelete();
                $table->json('location_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['shifts', 'orders'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_location_id');
                $table->dropColumn('location_snapshot');
            });
        }
        Schema::table('customer_locations', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude', 'coordinates_confirmed_at']));
    }
};
