<?php

namespace App\Services\SystemHealth;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/** Safe infrastructure probes; no repair commands or business-record writes. */
class InfrastructureChecks
{
    public function run(string $id): array
    {
        return match ($id) {
            'application' => $this->application(),
            'database' => $this->database(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'session' => $this->session(),
            'assets' => $this->assets(),
            default => throw new InvalidArgumentException('Unbekannte Infrastrukturprüfung.'),
        };
    }

    private function application(): array
    {
        $errors = [];
        $warnings = [];
        $details = [];
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $errors[] = 'Die Anwendung benötigt mindestens PHP 8.2.';
        }
        foreach (['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'session', 'tokenizer', 'xml'] as $extension) {
            if (! extension_loaded($extension)) {
                $errors[] = 'PHP-Erweiterung fehlt: '.$extension.'.';
            }
        }
        foreach (['gd', 'zip'] as $extension) {
            if (! extension_loaded($extension)) {
                $warnings[] = 'Für Bild- oder Dokumentverarbeitung fehlt: '.$extension.'.';
            }
        }
        $key = (string) config('app.key', '');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($decoded) || ! Encrypter::supported($decoded, (string) config('app.cipher', 'AES-256-CBC'))) {
            $errors[] = 'Der Anwendungsschlüssel fehlt oder passt nicht zum Verschlüsselungsverfahren.';
        }
        $production = config('app.env') === 'production';
        if (! $production) {
            $warnings[] = 'Dies ist keine als production konfigurierte Umgebung.';
        }
        if (config('app.debug')) {
            if ($production) {
                $errors[] = 'Der Debugmodus ist in der Produktionsumgebung aktiviert.';
            } else {
                $warnings[] = 'Der Debugmodus ist aktiviert.';
            }
        }
        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            $warnings[] = 'Die konfigurierte Anwendungsadresse verwendet kein HTTPS.';
        }
        if (app()->isDownForMaintenance()) {
            $warnings[] = 'Die Anwendung befindet sich im Wartungsmodus.';
        }
        $details[] = 'PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'; erforderliche Erweiterungen und Schlüsselformat geprüft.';
        $details[] = 'Konfigurationsprüfung; TLS-Zertifikat, Browserzugriff und Geschäftsabläufe sind damit nicht nachgewiesen.';

        return $this->findings($errors, $warnings, $details, 'configuration', 'Die grundlegenden Anwendungsvoraussetzungen sind erfüllt.');
    }

    private function database(): array
    {
        try {
            return app(BoundedInfrastructureConnections::class)->database(null, fn (Connection $connection): array => $this->inspectDatabase($connection))
                ?? $this->result('not_checked', 'configuration', 'Für diesen Datenbanktreiber ist keine sicher begrenzte Diagnoseverbindung verfügbar.', ['Kein unbegrenzter Ersatzabruf; MySQL benötigt eine veränderbare request-lokale mysqlnd-Lesezeitgrenze.']);
        } catch (Throwable) {
            return $this->result('error', 'connection', 'Datenbankverbindung oder Schemaprüfung fehlgeschlagen.', ['Keine Reparatur ausgeführt. Zugangsdaten und Datenbankantworten werden nicht angezeigt.']);
        }
    }

    private function inspectDatabase(Connection $connection): array
    {
        $connection->selectOne('SELECT 1 AS system_health_probe');
        $schema = $connection->getSchemaBuilder();
        $tables = $schema->getTableListing(schemaQualified: false);
        $prefix = $connection->getTablePrefix();
        $required = ['users', 'settings', 'teams', 'team_user', 'files', 'devices', 'device_assignments', 'employee_identity_accounts'];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! in_array($prefix.$table, $tables, true)));
        $errors = $missing === [] ? [] : ['Benötigte Kerntabellen fehlen: '.implode(', ', $missing).'.'];
        $warnings = [];
        $details = ['Datenbankverbindung mit SELECT 1 und Kerntabellen lesend geprüft; keine Geschäftsdatensätze gelesen oder geändert.'];
        $migrator = app('migrator');
        $migrationConfiguration = config('database.migrations', 'migrations');
        $migrationTable = is_array($migrationConfiguration) ? ($migrationConfiguration['table'] ?? 'migrations') : $migrationConfiguration;
        if (! $schema->hasTable($migrationTable)) {
            $errors[] = 'Die Migrationstabelle fehlt; der Schemazustand ist nicht nachvollziehbar.';
        } else {
            // Listing filenames does not require or execute migration PHP files.
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $ran = $connection->table($migrationTable)->orderBy('batch')->orderBy('migration')->pluck('migration')->all();
            $pending = array_diff(array_keys($files), $ran);
            $details[] = count($pending).' ausstehende Migrationen; es wurde keine Migration ausgeführt.';
            if ($pending !== []) {
                $warnings[] = 'Es stehen Datenbankmigrationen aus. Vor einem koordinierten Release fachlich prüfen.';
            }
        }

        return $this->findings($errors, $warnings, $details, 'connection', 'Datenbank und geprüftes Basisschema sind erreichbar und aktuell.');
    }

    private function cache(): array
    {
        try {
            $store = (string) config('cache.default');
            $driver = config('cache.stores.'.$store.'.driver');
            if (! is_string($driver) || $driver === '') {
                return $this->result('not_configured', 'configuration', 'Der Standard-Cache ist nicht eingerichtet.');
            }
            if (in_array($driver, ['array', 'null'], true)) {
                return $this->result('warning', 'configuration', 'Der Cache ist nicht dauerhaft über mehrere Requests verfügbar.', ['Array- und Null-Treiber liefern keinen Nachweis eines produktiven Cache-Speichers.']);
            }
            $ok = $driver === 'file'
                ? $this->probeCache(Cache::store($store))
                : app(BoundedInfrastructureConnections::class)->cache($store, fn (Repository $cache): bool => $this->probeCache($cache));
            if ($ok === null) {
                return $this->result('not_checked', 'configuration', 'Für diesen Cachetreiber ist keine sicher begrenzte Diagnoseverbindung verfügbar.', ['Kein automatischer Rückfall auf unbeschränkte Netzwerkverbindungen.']);
            }

            return $this->result($ok ? 'ok' : 'error', 'runtime', $ok
                ? 'Der Anwendungscache hat Schreiben, Lesen und Bereinigung bestätigt.'
                : 'Der isolierte Cache-Schreib-/Lese-/Löschtest ist fehlgeschlagen.', ['Nur ein zufälliger Diagnose-Schlüssel wurde verwendet; kein Cache-Flush und keine Änderung vorhandener Schlüssel.']);
        } catch (Throwable) {
            return $this->result('error', 'connection', 'Der Anwendungscache konnte nicht geprüft werden.', ['Keine Verbindungsdetails oder Zugangsdaten werden ausgegeben.']);
        }
    }

    private function probeCache(Repository $cache): bool
    {
        $key = 'railtime:system-health:probe:'.bin2hex(random_bytes(20));
        $value = bin2hex(random_bytes(16));
        $attempted = false;
        $ok = false;
        try {
            if ($cache->has($key)) {
                return false;
            }
            $attempted = true;
            $ok = $cache->put($key, $value, 120) && $cache->get($key) === $value;
        } catch (Throwable) {
            $ok = false;
        } finally {
            if ($attempted) {
                try {
                    $ok = $cache->forget($key) && ! $cache->has($key) && $ok;
                } catch (Throwable) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    private function storage(): array
    {
        $errors = [];
        $warnings = [];
        $details = [];
        foreach ($this->activeDisks() as $name) {
            $config = config('filesystems.disks.'.$name);
            $label = preg_match('/\A[a-zA-Z0-9_-]{1,50}\z/D', $name) ? $name : 'konfigurierter Speicher';
            if (! is_array($config) || empty($config['driver'])) {
                $errors[] = 'Aktiver Speicher ist nicht eingerichtet: '.$label.'.';

                continue;
            }
            $driver = $config['driver'];
            if (! in_array($driver, ['local', 's3', 'ftp', 'sftp'], true)) {
                $warnings[] = 'Speicher '.$label.': Dieser Treiber hat keinen sicheren Diagnoseadapter; nicht geprüft.';

                continue;
            }
            if ($driver === 'local') {
                $root = $config['root'] ?? null;
                if (! is_string($root) || ! is_dir($root) || ! is_readable($root) || ! is_writable($root)) {
                    $errors[] = 'Speicher '.$label.': Wurzelverzeichnis fehlt oder ist nicht les- und schreibbar.';

                    continue;
                }
                $free = disk_free_space($root);
                if ($free === false) {
                    $warnings[] = 'Speicher '.$label.': Freier Speicherplatz konnte nicht ermittelt werden.';
                } elseif ($free < 100 * 1024 * 1024) {
                    $errors[] = 'Speicher '.$label.': Weniger als 100 MiB frei.';
                } elseif ($free < 1024 * 1024 * 1024) {
                    $warnings[] = 'Speicher '.$label.': Weniger als 1 GiB frei.';
                }
            } else {
                // A throwaway adapter bounds probe connections without modifying a shared production disk.
                $config['timeout'] = 3;
                if ($driver === 's3') {
                    $config['http'] = array_replace((array) ($config['http'] ?? []), ['connect_timeout' => 2, 'timeout' => 3]);
                    $config['retries'] = 0;
                    if (empty($config['bucket']) || empty($config['key']) || empty($config['secret'])) {
                        $warnings[] = 'Speicher '.$label.': Bucket oder explizite Zugangsdaten fehlen; kein automatischer Cloud-Metadatenabruf.';

                        continue;
                    }
                }
            }
            try {
                $disk = $driver === 'local' ? Storage::disk($name) : Storage::build($config);
                if ($this->probeDisk($disk)) {
                    $details[] = 'Speicher '.$label.': Eigene Testdatei geschrieben, gelesen und gelöscht.';
                } else {
                    $errors[] = 'Speicher '.$label.': Schreib-/Lese-/Löschprobe fehlgeschlagen.';
                }
            } catch (Throwable) {
                $errors[] = 'Speicher '.$label.': Diagnoseverbindung fehlgeschlagen.';
            }
        }
        foreach ([storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $errors[] = 'Ein benötigtes Laufzeitverzeichnis für Views, Logs oder Bootstrap-Cache ist nicht schreibbar.';
                break;
            }
        }
        if (config('filesystems.disks.public.driver') === 'local') {
            $public = realpath(public_path('storage'));
            $expected = realpath((string) config('filesystems.disks.public.root'));
            if ($public === false || $expected === false || ! $this->samePath($public, $expected)) {
                $warnings[] = 'Die Public-Speicherverknüpfung fehlt oder zeigt nicht auf den konfigurierten öffentlichen Speicher.';
            } else {
                $details[] = 'Die Public-Speicherverknüpfung zeigt auf den konfigurierten lokalen Speicher.';
            }
        }
        $details[] = 'Nur aktiv verwendete Disks und eigene zufällige Diagnose-Dateien; kein Lesen oder Löschen von Nutzdateien.';

        return $this->findings($errors, $warnings, $details, 'runtime', 'Die geprüften Dateispeicher und Laufzeitverzeichnisse sind bereit.');
    }

    private function activeDisks(): array
    {
        $disks = [config('filesystems.default', 'local'), 'private', 'public', config('jetstream.profile_photo_disk', 'public'), config('marketing.disk', 'private'), config('outlook_addin.snapshots.disk', 'private'), config('device_management.artifact_disk', 'private')];
        if (config('livewire.temporary_file_upload.disk')) {
            $disks[] = config('livewire.temporary_file_upload.disk');
        }
        if (config('call_recording.enabled')) {
            $disks[] = config('call_recording.storage_disk', 'call_recordings');
        }

        return array_values(array_unique(array_filter($disks, fn ($disk) => is_string($disk) && $disk !== '')));
    }

    private function probeDisk(Filesystem $disk): bool
    {
        $path = 'system-health/probes/'.bin2hex(random_bytes(20)).'.txt';
        $value = 'RailTime isolated storage probe '.bin2hex(random_bytes(12));
        $attempted = false;
        $ok = false;
        try {
            if ($disk->exists($path)) {
                return false;
            }
            $attempted = true;
            $ok = $disk->put($path, $value) && $disk->get($path) === $value;
        } catch (Throwable) {
            $ok = false;
        } finally {
            if ($attempted) {
                try {
                    $ok = $disk->delete($path) && ! $disk->exists($path) && $ok;
                } catch (Throwable) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    private function session(): array
    {
        $errors = [];
        $warnings = [];
        $driver = config('session.driver');
        try {
            switch ($driver) {
                case 'database':
                    $ready = app(BoundedInfrastructureConnections::class)->database(config('session.connection'), function (Connection $connection): bool {
                        $connection->selectOne('SELECT 1 AS system_health_probe');

                        return $connection->getSchemaBuilder()->hasColumns((string) config('session.table', 'sessions'), ['id', 'payload', 'last_activity']);
                    });
                    if ($ready === null) {
                        return $this->result('not_checked', 'configuration', 'Für den Session-Datenbanktreiber ist keine sicher begrenzte Diagnoseverbindung verfügbar.');
                    }
                    if (! $ready) {
                        $errors[] = 'Der Datenbank-Session-Speicher oder benötigte Spalten fehlen.';
                    }
                    break;
                case 'file':
                    $path = config('session.files');
                    if (! is_string($path) || ! is_dir($path) || ! is_readable($path) || ! is_writable($path)) {
                        $errors[] = 'Das Session-Verzeichnis fehlt oder ist nicht les- und schreibbar.';
                    }
                    break;
                case 'cookie':
                    break;
                case 'apc':
                case 'memcached':
                case 'redis':
                case 'dynamodb':
                    $store = config('session.store') ?: $driver;
                    if (! config('cache.stores.'.$store.'.driver')) {
                        $errors[] = 'Der konfigurierte Session-Cache ist nicht eingerichtet.';
                    } else {
                        $warnings[] = 'Session-Cache-Konfiguration vorhanden; diese Prüfung belegt keine Session-Verarbeitung.';
                    }
                    break;
                case 'array':
                    $warnings[] = 'Array-Sessions sind nicht dauerhaft und nicht für produktive Anmeldungen geeignet.';
                    break;
                default:
                    return $this->result('not_configured', 'configuration', 'Der Session-Treiber fehlt oder wird von dieser Diagnose nicht unterstützt.');
            }
        } catch (Throwable) {
            $errors[] = 'Der konfigurierte Session-Speicher konnte nicht lesend geprüft werden.';
        }
        if (config('session.http_only') !== true) {
            $warnings[] = 'Session-Cookies sind nicht ausschließlich per HTTP zugänglich.';
        }
        if (config('app.env') === 'production' && config('session.secure') !== true) {
            $warnings[] = 'Für produktive Session-Cookies ist Secure nicht ausdrücklich aktiviert.';
        }
        if (! in_array(config('session.same_site'), ['lax', 'strict', 'none'], true)) {
            $warnings[] = 'Die SameSite-Einstellung ist nicht ausdrücklich festgelegt.';
        }
        if (config('session.same_site') === 'none' && config('session.secure') !== true) {
            $errors[] = 'SameSite=None benötigt sichere Session-Cookies.';
        }

        return $this->findings($errors, $warnings, ['Nur Voraussetzungen geprüft; keine Sessions geöffnet, verändert oder gelöscht und kein Benutzer-Login simuliert.'], 'configuration', 'Die geprüften Session-Voraussetzungen sind vorhanden.');
    }

    private function assets(): array
    {
        $root = realpath(public_path('build'));
        $manifestPath = public_path('build/manifest.json');
        if ($root === false || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return $this->result('error', 'configuration', 'Das Produktions-Build-Manifest fehlt oder ist nicht lesbar.');
        }
        try {
            $size = filesize($manifestPath);
            if ($size === false || $size > 2 * 1024 * 1024) {
                return $this->result('error', 'configuration', 'Das Build-Manifest überschreitet die sichere Diagnosegröße.');
            }
            $manifest = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ! isset($manifest['resources/js/app.js'], $manifest['resources/css/app.css'])) {
                return $this->result('error', 'configuration', 'Dem Build-Manifest fehlen die erforderlichen App-Einstiegspunkte.');
            }
            $files = [];
            foreach ($manifest as $entry) {
                if (! is_array($entry) || ! isset($entry['file']) || ! is_array($entry['css'] ?? []) || ! is_array($entry['assets'] ?? []) || ! is_array($entry['imports'] ?? []) || ! is_array($entry['dynamicImports'] ?? [])) {
                    return $this->result('error', 'configuration', 'Das Build-Manifest enthält ungültige Einträge.');
                }
                foreach (array_merge($entry['imports'] ?? [], $entry['dynamicImports'] ?? []) as $import) {
                    if (! is_string($import) || ! array_key_exists($import, $manifest)) {
                        return $this->result('error', 'configuration', 'Das Build-Manifest enthält eine nicht auflösbare Modulreferenz.');
                    }
                }
                foreach (array_merge([$entry['file']], $entry['css'] ?? [], $entry['assets'] ?? []) as $file) {
                    if (! is_string($file) || $file === '' || str_contains($file, '\\') || str_contains($file, ':') || str_starts_with($file, '/') || in_array('..', explode('/', $file), true)) {
                        return $this->result('error', 'configuration', 'Das Build-Manifest enthält einen unzulässigen Asset-Pfad.');
                    }
                    $resolved = realpath($root.DIRECTORY_SEPARATOR.$file);
                    if ($resolved === false || ! $this->insidePath($resolved, $root) || ! is_file($resolved) || ! is_readable($resolved)) {
                        return $this->result('error', 'configuration', 'Mindestens eine referenzierte Build-Datei fehlt oder ist nicht sicher lesbar.');
                    }
                    $files[$file] = true;
                }
            }
            $warnings = is_file(public_path('hot')) ? ['Ein Vite-Hot-Marker ist vorhanden; die ausgelieferte Seite kann statt des Produktionsbuilds den Entwicklungsserver verwenden.'] : [];

            return $this->findings([], $warnings, [count($files).' eindeutige Build-Dateien und alle Manifestreferenzen lesend geprüft.', 'Kein Nachweis von Browserdarstellung, CDN-Erreichbarkeit oder JavaScript-Ausführung.'], 'configuration', 'Build-Manifest und referenzierte Produktionsdateien sind vorhanden.');
        } catch (Throwable) {
            return $this->result('error', 'configuration', 'Das Build-Manifest konnte nicht sicher gelesen werden.');
        }
    }

    private function insidePath(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';

        return PHP_OS_FAMILY === 'Windows' ? str_starts_with(strtolower($path), strtolower($root)) : str_starts_with($path, $root);
    }

    private function samePath(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function findings(array $errors, array $warnings, array $details, string $evidence, string $success): array
    {
        return $this->result($errors !== [] ? 'error' : ($warnings !== [] ? 'warning' : 'ok'), $evidence, $errors[0] ?? $warnings[0] ?? $success, array_values(array_unique(array_merge($errors, $warnings, $details))));
    }

    private function result(string $status, string $evidence, string $message, array $details = []): array
    {
        return compact('status', 'evidence', 'message', 'details');
    }
}
