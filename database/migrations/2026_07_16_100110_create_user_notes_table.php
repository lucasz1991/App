<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_notes', function (Blueprint $table) {
            $table->id();

            // Benutzer, dem die Bemerkung zugeordnet ist
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Verfasser der Bemerkung (Admin/Staff). Cascade, damit das
            // Loeschen eines Benutzerkontos nie an der FK-Constraint scheitert.
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();

            $table->text('body');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notes');
    }
};
