<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'photo_path')) {
            Schema::table('chats', function (Blueprint $table): void {
                // Gruppenbild (public disk). Direktchats zeigen weiterhin das
                // Profilfoto des Gegenuebers und lassen die Spalte leer.
                $table->string('photo_path')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chats', 'photo_path')) {
            Schema::table('chats', function (Blueprint $table): void {
                $table->dropColumn('photo_path');
            });
        }
    }
};
