<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zweite Groessenachse neben "size" (Breite sm/lg): "rows" ist die Hoehe
     * in Zeileneinheiten (1 oder 2). Eigene Spalte statt eines kombinierten
     * Enums, damit Breite und Hoehe unabhaengig voneinander gesetzt werden
     * koennen (siehe App\Support\Dashboard\DashboardLayout::setRows).
     */
    public function up(): void
    {
        Schema::table('dashboard_widget_placements', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rows')->default(1)->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('dashboard_widget_placements', function (Blueprint $table): void {
            $table->dropColumn('rows');
        });
    }
};
