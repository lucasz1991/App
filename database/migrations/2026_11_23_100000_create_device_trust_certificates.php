<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Herausgeber-Zertifikate für signierte Listen (Excel-Makros). Die RailTime-App
 * auf den Firmengeräten trägt aktive Zertifikate als vertrauenswürdigen
 * Herausgeber ein. Eine Kopplung an Versionen verbindlicher Dateien zeigt,
 * welche Liste mit welchem Zertifikat signiert ist.
 */
return new class extends Migration
{
    /** Öffentlicher Teil des selbst erstellten Zertifikats „RailTime GmbH“ vom 23.11.2026 (kein Geheimnis). */
    private const RAILTIME_SHA256 = '8bcaa022684c40564af1c37446f15c38b0a934d97fde23dc9d04912f8d08471f';

    private const RAILTIME_CERTIFICATE = 'MIIEADCCAmigAwIBAgIQOXFJTlZwPalEFNmLhtxPHTANBgkqhkiG9w0BAQsFADAYMRYwFAYDVQQDDA1SYWlsVGltZSBHbWJIMB4XDTI2MTEyMzA2NDQ1M1oXDTMxMTEyMzA2NTQ1M1owGDEWMBQGA1UEAwwNUmFpbFRpbWUgR21iSDCCAaIwDQYJKoZIhvcNAQEBBQADggGPADCCAYoCggGBAKb4quhsA8tlsf1tHYAlnSCoOlh4yWoeqs5cl80QN3uKtpMpvQTeNRjnWYq3/O27EpdKKBkizyDA09IRFDJP6AeQqePulcF56XMvUq164qp89YtEnyGnwZ7KK5pbUXsgQdgzhsnST5V0B4Fjr2Q2po1sgw0FGdVIam3k6h+e9QT/wstdBTS96LK5qwDMLZYSvleId8J+amX0O2unnhphEfMMMaal1l1fOphBE5xJPVxQRtRUD2OFUcHsJJ0aSAFe1T3sFd66jfEQECWTLX0jVws9A6TvNM3vUgVBIN+4sy9/AH/6dTkLmvM5waMBGJcDxjiBlqeFnlO/FfnGDzLCTg6OugMk8CoNQ0yjzs8jeX6wnT/ex8FjNU2ZY1XT43Rn3TkhjBpYAlD6UO/8d/C9ioGIM5VuW3rpTDKDTj0PqHXe2w0oYcWO9CtG9spr8Ee27T1bizE6n2TydekZIoQiyzySjOKUsxIqiWoM+9Oim3HWdRHFRrO9HtxuzS43fpzd5QIDAQABo0YwRDAOBgNVHQ8BAf8EBAMCB4AwEwYDVR0lBAwwCgYIKwYBBQUHAwMwHQYDVR0OBBYEFDZeYEgRAuoesAMkMpafLSWjxhzAMA0GCSqGSIb3DQEBCwUAA4IBgQCK4obextMx+ONUtgBBPbdOuNACFaK0Jn6Dy86ZOJr01NrXyjwLJx2MhnXooY3knhuyhYzt3rzzNNemGmwa+jMzKlx4FnBR93ryiz94nvtS1bjOfqP/S2lrKrbRPUhVep7hRPdI7+FvlZKG4wnWfaEi8959Rg4dKK5Ku2GM7Zsqg7nQvauQRPhAsQVKVqwz5IXYPa8tP2FEvMRcKGB2gwROHkRG4o6+xvMKTCfJeqYTBkMeeLRN4axXflj06BwAMt3Vygi64WopOmE+LQH7y2lOa8c8hG6G7f8pMuOTeV6l6wFt98DM3M9Az8VI7QC8Gh0kCtU3ow8pocGdz4I552ojxlHnWcSvI2sVeBqAu8kkDKdv1J1OgOpfhl81AI/knn0+3vYQtaRt4SLMWrKZZNRCAl6Ap3RX5izDxBzD0LewNnxjgGe9xf1rR9sEgV0hvtpB12yz+0GDnd0ToJwCdC1j1DWgchgTTvJmDu17odIVJ3GYAYZUDTjYq4JZUJFptuQ=';

    public function up(): void
    {
        if (! Schema::hasTable('device_trust_certificates')) {
            Schema::create('device_trust_certificates', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('name', 160);
                $table->string('subject', 255);
                $table->char('thumbprint_sha1', 40);
                $table->char('sha256', 64)->unique();
                // DER, Base64. Nur der öffentliche Teil; ein privater Schlüssel wird nie gespeichert.
                $table->text('certificate');
                $table->boolean('self_signed')->default(false);
                $table->dateTime('valid_from');
                $table->dateTime('valid_until');
                $table->boolean('active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('device_trust_certificate_version') && Schema::hasTable('managed_document_versions')) {
            Schema::create('device_trust_certificate_version', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('device_trust_certificate_id')->constrained('device_trust_certificates', 'id', 'dtcv_certificate_fk')->cascadeOnDelete();
                $table->foreignId('managed_document_version_id')->constrained('managed_document_versions', 'id', 'dtcv_version_fk')->cascadeOnDelete();
                $table->timestamps();
                // Eine Dateiversion ist mit höchstens einem Zertifikat signiert.
                $table->unique('managed_document_version_id', 'dtcv_version_unique');
            });
        }

        if (! DB::table('device_trust_certificates')->where('sha256', self::RAILTIME_SHA256)->exists()) {
            DB::table('device_trust_certificates')->insert([
                'public_id' => (string) Str::uuid(),
                'name' => 'RailTime GmbH',
                'subject' => 'CN=RailTime GmbH',
                'thumbprint_sha1' => '18d43c5ae28acf36ac30cd93a33cf10c21dea80a',
                'sha256' => self::RAILTIME_SHA256,
                'certificate' => self::RAILTIME_CERTIFICATE,
                'self_signed' => true,
                'valid_from' => '2026-11-23 06:44:53',
                'valid_until' => '2031-11-23 06:54:53',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_trust_certificate_version');
        Schema::dropIfExists('device_trust_certificates');
    }
};
