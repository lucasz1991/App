<?php

use App\Support\EmployeeLocation\EmployeeLocationSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_permission_grants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64)->index();
            $table->text('session_identifier')->nullable();
            $table->dateTime('last_activity_at', 6);
            $table->dateTime('expires_at', 6)->index();
            $table->dateTime('ended_at', 6)->nullable();
            foreach (['push', 'location'] as $purpose) {
                $table->string($purpose.'_decision', 10)->nullable();
                $table->unsignedInteger($purpose.'_version')->nullable();
                $table->dateTime($purpose.'_decided_at', 6)->nullable();
            }
            $table->timestamps(6);
            $table->index(['user_id', 'ended_at']);
        });
        if (Schema::hasTable('employee_location_consents')) {
            Schema::table('employee_location_consents', function (Blueprint $table): void {
                $table->dropUnique(['user_id', 'local_date', 'purpose']);
                $table->foreignId('session_permission_grant_id')->nullable()->constrained('session_permission_grants')->nullOnDelete();
                $table->unique(['session_permission_grant_id', 'purpose'], 'location_consent_session_purpose_unique');
            });
            Schema::table('mobile_location_codes', function (Blueprint $table): void {
                $table->foreignId('consent_id')->nullable()->constrained('employee_location_consents')->nullOnDelete();
            });
            // Keep prior daily decisions as evidence, but never grant new session rights from them.
            DB::table('mobile_location_installations')->update(['session_id' => null, 'session_started_at' => null, 'permit_id' => null, 'permit_expires_at' => null]);
            DB::table('mobile_location_codes')->where('purpose', 'start')->whereNull('consumed_at')->update(['consumed_at' => now()->utc()]);
        }
        if (Schema::hasTable('push_subscriptions')) {
            Schema::table('push_subscriptions', function (Blueprint $table): void {
                $table->foreignId('session_permission_grant_id')->nullable()->constrained('session_permission_grants')->nullOnDelete();
            });
        }
        if (Schema::hasTable('settings')) {
            $setting = DB::table('settings')->where('type', EmployeeLocationSettings::GROUP)->where('key', EmployeeLocationSettings::KEY)->first();
            if ($setting) {
                $values = json_decode($setting->value, true) ?: [];
                if (! isset($values['explanation_text']) || $values['explanation_text'] === EmployeeLocationSettings::LEGACY_EXPLANATION) {
                    $values['explanation_text'] = EmployeeLocationSettings::DEFAULTS['explanation_text'];
                } else {
                    // Preserve the operator's wording and append the explicitly changed session lifetime.
                    $values['explanation_text'] .= "\n\nDie Zustimmung gilt ab dieser Version für die aktive Anmeldesitzung. Tatsächliche App-Nutzung verlängert die Sitzung und Freigabe. Abmeldung, Sitzungsablauf und Widerruf beenden sie; ein Tageswechsel beendet sie nicht. Pause und Arbeitsende stoppen weiterhin die Erfassung. In deinem Profil kannst du Push und Standort unabhängig ändern oder widerrufen.";
                }
                $values['explanation_version'] = max(2, (int) ($values['explanation_version'] ?? 1) + 1);
                DB::table('settings')->where('id', $setting->id)->update(['value' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('session_permission_grants')->exists()) {
            throw new RuntimeException('Sitzungsfreigaben enthalten Nachweise. Vor einem Rückbau bitte gesichert und gezielt prüfen; sie werden nicht automatisch gelöscht.');
        }
        if (Schema::hasColumn('push_subscriptions', 'session_permission_grant_id')) {
            Schema::table('push_subscriptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('session_permission_grant_id'));
        }
        if (Schema::hasColumn('mobile_location_codes', 'consent_id')) {
            Schema::table('mobile_location_codes', fn (Blueprint $table) => $table->dropConstrainedForeignId('consent_id'));
        }
        if (Schema::hasColumn('employee_location_consents', 'session_permission_grant_id')) {
            Schema::table('employee_location_consents', function (Blueprint $table): void {
                $table->dropUnique('location_consent_session_purpose_unique');
                $table->dropConstrainedForeignId('session_permission_grant_id');
                $table->unique(['user_id', 'local_date', 'purpose']);
            });
        }
        Schema::dropIfExists('session_permission_grants');
    }
};
