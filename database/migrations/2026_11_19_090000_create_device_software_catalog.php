<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programmverzeichnis der Geräteverwaltung: freigegebene WinGet-Pakete,
 * ihre Zuweisung und der vom Desktopclient gemeldete Stand. Rein additiv;
 * ohne Einträge im Verzeichnis ändert sich am Verhalten nichts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_software_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 120);
            $table->string('package_id', 128)->unique();
            $table->string('version', 40);
            $table->string('scope', 16)->default('user');
            $table->boolean('active')->default(true)->index();
            $table->foreignId('agreements_accepted_by')->nullable()->constrained('users', 'id', 'dsp_agreements_fk')->nullOnDelete();
            $table->timestamp('agreements_accepted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'dsp_creator_fk')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'dsp_editor_fk')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('device_software_assignments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('software_package_id')->constrained('device_software_packages', 'id', 'dsa_package_fk')->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->foreignId('device_id')->nullable()->constrained('devices', 'id', 'dsa_device_fk')->cascadeOnDelete();
            $table->string('profile_key', 64)->nullable();
            // "corporate", "profile:<key>" oder "device:<id>": macht ein Ziel je Paket eindeutig.
            $table->string('target_key', 80);
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'dsa_creator_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['software_package_id', 'target_key'], 'dsa_target_unique');
        });
        Schema::create('device_desktop_software_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('device_desktop_clients', 'id', 'ddss_client_fk')->cascadeOnDelete();
            $table->foreignId('software_package_id')->constrained('device_software_packages', 'id', 'ddss_package_fk')->cascadeOnDelete();
            $table->boolean('installed')->nullable();
            $table->string('reported_version', 64)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
            $table->unique(['client_id', 'software_package_id'], 'ddss_client_package_unique');
        });
        Schema::table('device_desktop_jobs', function (Blueprint $table): void {
            $table->string('origin', 16)->default('manual');
            $table->foreignId('software_package_id')->nullable()->constrained('device_software_packages', 'id', 'ddj_package_fk')->nullOnDelete();
            $table->unsignedTinyInteger('attempt')->default(1);
        });
        // Aufträge aus einer Zuweisung haben keinen anfordernden Benutzer.
        Schema::table('device_desktop_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Ohne anfordernden Benutzer lassen sich diese Aufträge im alten Schema nicht halten.
        DB::table('device_desktop_jobs')->where('origin', 'assignment')->delete();
        Schema::table('device_desktop_jobs', function (Blueprint $table): void {
            $table->dropForeign('ddj_package_fk');
        });
        Schema::table('device_desktop_jobs', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'software_package_id', 'attempt']);
        });
        Schema::table('device_desktop_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_by')->nullable(false)->change();
        });
        Schema::dropIfExists('device_desktop_software_states');
        Schema::dropIfExists('device_software_assignments');
        Schema::dropIfExists('device_software_packages');
    }
};
