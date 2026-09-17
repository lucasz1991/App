<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persoenliches Dashboard-Layout je Nutzer. Eine Zeile pro Widget, das
     * jemand aktiv angeordnet hat - noch nie beruehrte, aber laut
     * WidgetRegistry standardmaessig sichtbare Widgets erscheinen ohne
     * eigene Zeile (siehe App\Support\Dashboard\DashboardLayout::forUser).
     * Entfernen loescht die Zeile nicht, sondern setzt hidden=true, damit
     * Position und Groesse beim erneuten Hinzufuegen erhalten bleiben.
     */
    public function up(): void
    {
        Schema::create('dashboard_widget_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('widget_key', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('size', 4)->default('sm');
            $table->boolean('hidden')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'widget_key']);
            $table->index(['user_id', 'hidden', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widget_placements');
    }
};
