<?php

namespace App\Services\DeviceManagement\Desktop;

use App\Models\DeviceTrustCertificate;
use App\Models\ManagedDocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Herausgeber-Zertifikate für signierte Listen. Aktive Zertifikate gehen an
 * Firmengeräte, deren Clientrichtlinie „Office-Vertrauen“ erlaubt; die
 * RailTime-App trägt sie dort als vertrauenswürdigen Herausgeber ein.
 *
 * Ein Zertifikat aufzunehmen heißt: Makros, die damit signiert sind, laufen
 * auf diesen Geräten ohne Rückfrage. Deshalb nimmt nur ein Superadmin eines
 * auf, es muss ausschließlich für Codesignatur ausgestellt sein, und jede
 * Änderung steht im Protokoll. Gespeichert wird nur der öffentliche Teil.
 */
final class DeviceTrustService
{
    public const CAPABILITY = 'managed_trust';

    public const MAX_ACTIVE = 5;

    /** Größte DER-Länge, die auch der Client annimmt. */
    public const MAX_DER_BYTES = 4096;

    private const CODE_SIGNING = 'Code Signing';

    public function ready(): bool
    {
        try {
            return Schema::hasTable('device_trust_certificates') && Schema::hasTable('device_trust_certificate_version');
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertReady(): void
    {
        abort_unless($this->ready(), 503, 'Zertifikatsverwaltung vorübergehend nicht verfügbar. Die freigegebene Datenbankmigration muss durch die IT geprüft werden.');
    }

    public function canManage(?User $user): bool
    {
        return $user !== null && $user->isActive() && $user->isSuperAdmin() && $user->email_verified_at !== null
            && Gate::forUser($user)->allows('settings.manage');
    }

    /**
     * Liest eine .cer-Datei (DER oder Base64/PEM) und prüft, ob sie als
     * Herausgeber-Zertifikat taugt. Fehler landen unter dem Schlüssel `certificate`.
     *
     * @return array{name: string, subject: string, thumbprint_sha1: string, sha256: string, certificate: string, self_signed: bool, valid_from: Carbon, valid_until: Carbon}
     */
    public function inspect(string $bytes): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['certificate' => $message]);
        if ($bytes === '' || strlen($bytes) > 3 * self::MAX_DER_BYTES) {
            $fail('Die Datei ist leer oder zu groß für ein einzelnes Zertifikat.');
        }
        if (str_contains($bytes, 'PRIVATE KEY')) {
            $fail('Die Datei enthält einen privaten Schlüssel. Bitte nur den öffentlichen Teil (.cer) hochladen; der Schlüssel gehört nicht auf den Server.');
        }
        $trimmed = trim($bytes);
        $pem = str_starts_with($trimmed, '-----BEGIN CERTIFICATE-----')
            ? $trimmed
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($bytes), 64, "\n").'-----END CERTIFICATE-----';
        $x509 = @openssl_x509_read($pem);
        $exported = '';
        if ($x509 === false || ! openssl_x509_export($x509, $exported)) {
            $fail('Die Datei ist kein lesbares Zertifikat. Erwartet wird eine .cer-Datei (DER oder Base64).');
        }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $exported), true);
        $parsed = openssl_x509_parse($x509);
        if ($der === false || $parsed === false || strlen($der) > self::MAX_DER_BYTES) {
            $fail('Das Zertifikat ließ sich nicht auswerten oder ist ungewöhnlich groß.');
        }
        $extensions = $parsed['extensions'] ?? [];
        if (trim((string) ($extensions['extendedKeyUsage'] ?? '')) !== self::CODE_SIGNING) {
            $fail('Das Zertifikat muss ausschließlich für Codesignatur ausgestellt sein. Zertifikate für Webseiten, E-Mail oder mehrere Zwecke werden nicht angenommen.');
        }
        if (str_contains((string) ($extensions['basicConstraints'] ?? ''), 'CA:TRUE')) {
            $fail('Zertifikate einer Zertifizierungsstelle werden nicht angenommen, nur das Signaturzertifikat selbst.');
        }
        $key = openssl_pkey_get_details(openssl_pkey_get_public($x509) ?: null);
        if (($key['type'] ?? null) === OPENSSL_KEYTYPE_RSA && (int) ($key['bits'] ?? 0) < 2048) {
            $fail('Der Schlüssel des Zertifikats ist zu kurz (mindestens 2048 Bit).');
        }
        $from = Carbon::createFromTimestampUTC((int) $parsed['validFrom_time_t']);
        $until = Carbon::createFromTimestampUTC((int) $parsed['validTo_time_t']);
        if ($until->isPast()) {
            $fail('Das Zertifikat ist abgelaufen (gültig bis '.$until->format('d.m.Y').').');
        }
        if ($from->isAfter(now()->addDay())) {
            $fail('Das Zertifikat ist noch nicht gültig.');
        }
        $subject = $this->distinguishedName((array) ($parsed['subject'] ?? []));
        $name = trim((string) (is_array($parsed['subject']['CN'] ?? null) ? ($parsed['subject']['CN'][0] ?? '') : ($parsed['subject']['CN'] ?? '')));

        return [
            'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'Herausgeber', 0, 120),
            'subject' => mb_substr($subject, 0, 255),
            'thumbprint_sha1' => sha1($der),
            'sha256' => hash('sha256', $der),
            'certificate' => base64_encode($der),
            'self_signed' => ($parsed['subject'] ?? null) == ($parsed['issuer'] ?? false),
            'valid_from' => $from,
            'valid_until' => $until,
        ];
    }

    /** Nimmt ein Zertifikat auf oder liefert das vorhandene mit demselben Fingerabdruck. */
    public function store(string $bytes, User $actor): DeviceTrustCertificate
    {
        $this->authorize($actor);
        $this->assertReady();
        $facts = $this->inspect($bytes);

        return DB::transaction(function () use ($facts, $actor): DeviceTrustCertificate {
            $actor = $this->authorize($actor, lock: true);
            $existing = DeviceTrustCertificate::query()->where('sha256', $facts['sha256'])->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $this->assertRoom();
            $certificate = DeviceTrustCertificate::query()->create($facts + ['active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->audit($certificate, $actor, 'certificate-added', ['name' => $certificate->name, 'sha256' => $certificate->sha256]);

            return $certificate;
        }, 3);
    }

    public function setActive(DeviceTrustCertificate $certificate, bool $active, User $actor): void
    {
        $this->authorize($actor);
        $this->assertReady();
        DB::transaction(function () use ($certificate, $active, $actor): void {
            $actor = $this->authorize($actor, lock: true);
            $locked = DeviceTrustCertificate::query()->lockForUpdate()->findOrFail($certificate->id);
            if ($active && ! $locked->active) {
                if ($locked->valid_until->isPast()) {
                    throw ValidationException::withMessages(['certificate' => 'Ein abgelaufenes Zertifikat lässt sich nicht wieder aktivieren.']);
                }
                $this->assertRoom();
            }
            $locked->forceFill(['active' => $active, 'updated_by' => $actor->id])->save();
            $this->audit($locked, $actor, $active ? 'certificate-activated' : 'certificate-deactivated', ['sha256' => $locked->sha256]);
        }, 3);
    }

    /**
     * Verbindet eine Dateiversion mit dem Zertifikat, mit dem sie signiert ist.
     * Wählen darf, wer verbindliche Dateien pflegt; es entsteht dadurch kein neues Vertrauen.
     */
    public function couple(ManagedDocumentVersion $version, ?DeviceTrustCertificate $certificate, User $actor): void
    {
        abort_unless($actor->isActive(), 403);
        Gate::forUser($actor)->authorize('files.manage');
        $this->assertReady();
        if ($certificate && ! $certificate->active) {
            throw ValidationException::withMessages(['certificate' => 'Dieses Zertifikat ist deaktiviert.']);
        }
        $version->trustCertificates()->sync($certificate ? [$certificate->id] : []);
        if ($certificate) {
            $this->audit($certificate, $actor, 'certificate-coupled', ['sha256' => $certificate->sha256, 'version' => $version->id]);
        }
    }

    /**
     * Was ein Client eintragen soll: höchstens MAX_ACTIVE gültige, aktive Zertifikate.
     *
     * @return list<array{sha256: string, name: string, der: string}>
     */
    public function plan(): array
    {
        return DeviceTrustCertificate::query()->where('active', true)->where('valid_until', '>', now())
            ->orderBy('id')->limit(self::MAX_ACTIVE)->get()
            ->map(fn (DeviceTrustCertificate $certificate): array => [
                'sha256' => $certificate->sha256,
                'name' => $certificate->name,
                'der' => bin2hex((string) base64_decode($certificate->certificate, true)),
            ])
            // Ein Eintrag, der nicht mehr zu seinem Fingerabdruck passt, wird nie ausgeliefert.
            ->filter(fn (array $entry): bool => hash('sha256', (string) hex2bin($entry['der'])) === $entry['sha256'])
            ->values()->all();
    }

    private function assertRoom(): void
    {
        if (DeviceTrustCertificate::query()->where('active', true)->count() >= self::MAX_ACTIVE) {
            throw ValidationException::withMessages(['certificate' => 'Es sind bereits '.self::MAX_ACTIVE.' Zertifikate aktiv. Bitte zuerst ein nicht mehr benötigtes deaktivieren.']);
        }
    }

    private function distinguishedName(array $parts): string
    {
        $out = [];
        foreach ($parts as $key => $value) {
            foreach ((array) $value as $single) {
                $out[] = $key.'='.preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $single);
            }
        }

        return implode(', ', $out);
    }

    private function authorize(User $actor, bool $lock = false): User
    {
        $fresh = User::query()->whereKey($actor->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($this->canManage($fresh), 403);

        return $fresh;
    }

    private function audit(Model $subject, User $actor, string $event, array $properties): void
    {
        activity('device-management')->performedOn($subject)->causedBy($actor)->event('device-trust.'.$event)
            ->withProperties($properties)->log('Herausgeber-Zertifikat '.$event);
    }
}
