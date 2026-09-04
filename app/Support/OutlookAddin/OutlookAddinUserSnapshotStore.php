<?php

namespace App\Support\OutlookAddin;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Verschluesselter, vollstaendig ableitbarer Outlook-Abzug je Mitarbeiter.
 *
 * Die Datei ist kein fachlicher Datensatz. Vor jeder Verwendung wird ihr
 * Fingerabdruck gegen veroeffentlichte Dokumente, aktuelle Personen-/Firmen-
 * daten, Renderer und Medien geprueft. Veraltete Dateien werden nie geliefert.
 */
final class OutlookAddinUserSnapshotStore
{
    private const SNAPSHOT_SCHEMA = 1;

    public function __construct(
        private readonly OutlookAddinPayloadService $payloads,
    ) {}

    /** @return array<string, mixed> */
    public function currentForUser(User $user): array
    {
        return $this->underUserLock(
            (int) $user->getKey(),
            fn (): array => $this->currentLocked((int) $user->getKey()),
        );
    }

    /** @return array<string, mixed> */
    public function rebuildForUser(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;

        return $this->underUserLock($userId, fn (): array => $this->currentLocked($userId));
    }

    public function forgetForUser(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        if ($userId < 1) {
            return;
        }

        $this->underUserLock($userId, function () use ($userId): array {
            $this->deleteSnapshotFile($userId);

            return [];
        });
    }

    /**
     * @return array{processed: int, refreshed: int, failed: int}
     */
    public function rebuildAll(): array
    {
        $result = ['processed' => 0, 'refreshed' => 0, 'failed' => 0];

        User::query()
            ->where('status', true)
            ->whereIn('role', ['admin', 'staff'])
            ->orderBy('id')
            ->chunkById(25, function ($users) use (&$result): void {
                foreach ($users as $user) {
                    $result['processed']++;

                    try {
                        $this->rebuildForUser($user);
                        $result['refreshed']++;
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        Log::warning('Persoenlicher Outlook-Abzug konnte nicht aktualisiert werden.', [
                            'user_id' => (int) $user->getKey(),
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $result;
    }

    public function pathForUser(User|int $user): string
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        if ($userId < 1) {
            throw new RuntimeException('Der Outlook-Abzug benoetigt eine gueltige Mitarbeiter-ID.');
        }

        $directory = trim(str_replace('\\', '/', (string) config(
            'outlook_addin.snapshots.directory',
            'outlook-addin/users',
        )), '/');
        if ($directory === '' || preg_match('~(?:^|/)\.\.(?:/|$)~', $directory)) {
            throw new RuntimeException('Das Outlook-Snapshotverzeichnis ist ungueltig.');
        }

        $bucket = str_pad((string) ($userId % 100), 2, '0', STR_PAD_LEFT);

        return $directory.'/'.$bucket.'/'.$userId.'/current.json.enc';
    }

    /** @return array<string, mixed> */
    private function currentLocked(int $userId): array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $user = $this->freshActiveUser($userId);
            $sourceFingerprint = $this->payloads->sourceFingerprint($user);
            $stored = $this->readEnvelope($userId);

            if ($this->isCurrentEnvelope($stored, $userId, $sourceFingerprint)) {
                /** @var array<string, mixed> $payload */
                $payload = $stored['payload'];

                return $payload;
            }

            $payload = $this->payloads->forUser($user);
            $payload['version']['personal'] = substr($sourceFingerprint, 0, 16);

            // Eine Aenderung waehrend des Renderns darf keinen alten Job zum
            // letzten Schreiber machen. Ein zweiter Durchlauf liest alles neu.
            $confirmedFingerprint = $this->payloads->sourceFingerprint(
                $this->freshActiveUser($userId),
            );
            if (! hash_equals($sourceFingerprint, $confirmedFingerprint)) {
                if ($attempt < 2) {
                    continue;
                }

                throw new OutlookAddinException(
                    'Die Mitarbeiterdaten wurden waehrend der Outlook-Erzeugung erneut geaendert.',
                    409,
                    'outlook_addin_snapshot_changed',
                );
            }

            $this->writeEnvelope($userId, $sourceFingerprint, $payload);

            return $payload;
        }

        throw new OutlookAddinException(
            'Der persoenliche Outlook-Abzug konnte nicht stabil erzeugt werden.',
            409,
            'outlook_addin_snapshot_changed',
        );
    }

    private function freshActiveUser(int $userId): User
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User
            || ! $user->isActive()
            || ! in_array($user->role, ['admin', 'staff'], true)) {
            // currentLocked() haelt bereits die Benutzersperre. Deshalb hier
            // direkt entfernen, statt dieselbe Sperre rekursiv anzufordern.
            $this->deleteSnapshotFile($userId);

            throw new OutlookAddinException(
                'Das Konto ist kein aktiver Outlook-Mitarbeiter.',
                403,
                'outlook_addin_user_inactive',
            );
        }

        return $user;
    }

    /** @return array<string, mixed>|null */
    private function readEnvelope(int $userId): ?array
    {
        $disk = $this->disk();
        $path = $this->pathForUser($userId);

        try {
            if (! $disk->exists($path)) {
                return null;
            }

            $encrypted = $disk->get($path);
            if (! is_string($encrypted) || $encrypted === '' || strlen($encrypted) > $this->maximumFileBytes()) {
                throw new RuntimeException('Der gespeicherte Outlook-Abzug besitzt eine ungueltige Groesse.');
            }

            $compressed = base64_decode(Crypt::decryptString($encrypted), true);
            if (! is_string($compressed)) {
                throw new RuntimeException('Der gespeicherte Outlook-Abzug ist nicht lesbar.');
            }

            $json = gzdecode($compressed);
            if (! is_string($json)) {
                throw new RuntimeException('Der gespeicherte Outlook-Abzug ist nicht dekomprimierbar.');
            }

            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $exception) {
            $this->deleteSnapshotFile($userId);
            Log::notice('Beschaedigter persoenlicher Outlook-Abzug wird neu erzeugt.', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $envelope
     */
    private function isCurrentEnvelope(?array $envelope, int $userId, string $sourceFingerprint): bool
    {
        if (! is_array($envelope)
            || (int) ($envelope['snapshot_schema'] ?? 0) !== self::SNAPSHOT_SCHEMA
            || (int) ($envelope['user_id'] ?? 0) !== $userId
            || ! is_string($envelope['source_fingerprint'] ?? null)
            || ! hash_equals($sourceFingerprint, $envelope['source_fingerprint'])
            || ! is_array($envelope['payload'] ?? null)
            || ! is_string($envelope['payload_hash'] ?? null)
            || ! $this->validPayload($envelope['payload'])) {
            return false;
        }

        return hash_equals(
            $envelope['payload_hash'],
            hash('sha256', $this->encode($envelope['payload'])),
        );
    }

    /** @param array<string, mixed> $payload */
    private function validPayload(array $payload): bool
    {
        return (int) ($payload['schema'] ?? 0) === 1
            && is_string($payload['marker'] ?? null)
            && is_array($payload['signature'] ?? null)
            && is_string($payload['signature']['html'] ?? null)
            && is_array($payload['signature']['media'] ?? null)
            && is_array($payload['template'] ?? null)
            && is_string($payload['template']['html'] ?? null)
            && is_array($payload['template']['media'] ?? null)
            && is_array($payload['version'] ?? null)
            && is_string($payload['version']['personal'] ?? null);
    }

    /** @param array<string, mixed> $payload */
    private function writeEnvelope(int $userId, string $sourceFingerprint, array $payload): void
    {
        $envelope = [
            'snapshot_schema' => self::SNAPSHOT_SCHEMA,
            'user_id' => $userId,
            'source_fingerprint' => $sourceFingerprint,
            'payload_hash' => hash('sha256', $this->encode($payload)),
            'generated_at' => now()->utc()->toIso8601String(),
            'payload' => $payload,
        ];
        $compressed = gzencode($this->encode($envelope), 6);
        if (! is_string($compressed)) {
            throw new RuntimeException('Der persoenliche Outlook-Abzug konnte nicht komprimiert werden.');
        }

        $encrypted = Crypt::encryptString(base64_encode($compressed));
        if (strlen($encrypted) > $this->maximumFileBytes()) {
            throw new RuntimeException('Der persoenliche Outlook-Abzug ueberschreitet das sichere Dateilimit.');
        }

        if (! $this->disk()->put($this->pathForUser($userId), $encrypted)) {
            throw new RuntimeException('Der persoenliche Outlook-Abzug konnte nicht privat gespeichert werden.');
        }
    }

    private function deleteSnapshotFile(int $userId): void
    {
        $disk = $this->disk();
        $path = $this->pathForUser($userId);

        if ($disk->exists($path) && ! $disk->delete($path) && $disk->exists($path)) {
            throw new RuntimeException('Der persoenliche Outlook-Abzug konnte nicht entfernt werden.');
        }

        if ($disk->exists($path)) {
            throw new RuntimeException('Der persoenliche Outlook-Abzug ist nach dem Entfernen weiterhin vorhanden.');
        }
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Der persoenliche Outlook-Abzug enthaelt ungueltige Zeichen.', 0, $exception);
        }
    }

    private function disk(): Filesystem
    {
        $name = trim((string) config('outlook_addin.snapshots.disk', 'private'));
        $configuration = (array) config('filesystems.disks.'.$name, []);

        if (($configuration['driver'] ?? null) !== 'local'
            || ($configuration['visibility'] ?? null) !== 'private') {
            throw new RuntimeException('Persoenliche Outlook-Abzuege benoetigen einen lokalen privaten Datentraeger.');
        }

        return Storage::disk($name);
    }

    private function maximumFileBytes(): int
    {
        return max(1048576, (int) config(
            'outlook_addin.snapshots.maximum_file_bytes',
            12582912,
        ));
    }

    /** @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function underUserLock(int $userId, callable $callback): array
    {
        if ($userId < 1) {
            throw new OutlookAddinException(
                'Der Outlook-Abzug benoetigt eine gueltige Mitarbeiter-ID.',
                404,
                'outlook_addin_user_missing',
            );
        }

        try {
            return Cache::lock(
                'outlook-addin-user-snapshot:'.$userId,
                max(10, (int) config('outlook_addin.snapshots.lock_seconds', 45)),
            )->block(
                max(1, (int) config('outlook_addin.snapshots.wait_seconds', 12)),
                $callback,
            );
        } catch (LockTimeoutException $exception) {
            throw new OutlookAddinException(
                'Der persoenliche Outlook-Abzug wird gerade aktualisiert. Bitte erneut versuchen.',
                503,
                'outlook_addin_snapshot_busy',
                $exception,
            );
        }
    }
}
