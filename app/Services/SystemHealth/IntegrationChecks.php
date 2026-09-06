<?php

namespace App\Services\SystemHealth;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use App\Services\SystemHealth\Transport\BoundedSocket;
use App\Services\SystemHealth\Transport\SmtpProbe;
use App\Services\SystemHealth\Transport\SpeechStatusProbe;
use App\Services\SystemHealth\Transport\WebSocketProbe;
use App\Support\Ai\AssistantSettings;
use App\Support\Ai\AssistantSpeechSettings;
use App\Support\Ai\OpenRouterSettings;
use App\Support\OutlookAddin\OutlookAddinManifest;
use App\Support\Push\WebPushConfiguration;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

/** Safe, isolated diagnostics. No mail delivery, inference, broadcast or device commands. */
class IntegrationChecks
{
    public function __construct(
        private readonly SmtpProbe $smtp,
        private readonly WebSocketProbe $websocket,
        private readonly SpeechStatusProbe $speech,
    ) {}

    /** @return array{status:string,evidence:string,message:string,details:list<string>} */
    public function run(string $id): array
    {
        try {
            return match ($id) {
                'mail' => $this->mail(),
                'realtime' => $this->realtime(),
                'livekit' => $this->livekit(),
                'push' => $this->push(),
                'speech' => $this->speech(),
                'ai' => $this->ai(),
                'outlook' => $this->outlook(),
                'recordings' => $this->recordings(),
                'marketing' => $this->marketing(),
                default => $this->result('not_checked', 'configuration', 'Diese Integrationsprüfung ist nicht registriert.'),
            };
        } catch (Throwable) {
            // Third-party exceptions can contain credentials, internal URLs and response bodies.
            return $this->result('error', 'configuration', 'Die Integrationsprüfung konnte nicht abgeschlossen werden.', [
                'Bitte Konfiguration und Verfügbarkeit des Dienstes prüfen. Zugangsdaten und Rohantworten werden nicht angezeigt.',
            ]);
        }
    }

    private function mail(): array
    {
        $name = (string) config('mail.default', '');
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) config('mail.mailers.'.$name, []));
        $transport = (string) ($config['transport'] ?? $config['driver'] ?? '');
        if (in_array($transport, ['array', 'log'], true)) {
            return $this->result('warning', 'configuration', 'E-Mail-Versand läuft im Simulationsmodus.', ['Es wird keine echte Zustellung ausgeführt.']);
        }
        if ($transport !== 'smtp') {
            return $this->result('not_checked', 'configuration', 'Für diesen Mailtransport ist kein versandfreier Verbindungstest vorhanden.', ['Es wurde weder ein Fallback ausgelöst noch eine E-Mail gesendet.']);
        }
        if (isset($config['driver']) && in_array($config['driver'], ['smtp', 'smtps'], true)) {
            $config['scheme'] = $config['driver'];
        }
        if (trim((string) ($config['host'] ?? '')) === '') {
            return $this->result('not_configured', 'configuration', 'Der SMTP-Server ist noch nicht eingerichtet.');
        }
        if (! BoundedSocket::validHost((string) $config['host'])) {
            return $this->result('error', 'configuration', 'Die SMTP-Serveradresse ist ungültig.');
        }
        if (((string) ($config['username'] ?? '') !== '') !== ((string) ($config['password'] ?? '') !== '')) {
            return $this->result('not_configured', 'configuration', 'Die SMTP-Anmeldedaten sind unvollständig.');
        }
        try {
            $proof = $this->smtp->check($config);
        } catch (Throwable) {
            return $this->result('error', 'connection', 'SMTP-Verbindung, TLS oder Anmeldung fehlgeschlagen.', ['Keine E-Mail versendet; Zertifikatsprüfung und Verschlüsselung bleiben verpflichtend.']);
        }
        if (! ($proof['tls'] ?? false)) {
            return $this->result('error', 'connection', 'Für SMTP wurde keine verschlüsselte Verbindung bestätigt.');
        }
        if (! ($proof['authenticated'] ?? false)) {
            return $this->result('warning', 'connection', 'SMTP ist per TLS erreichbar; eine Anmeldung ist nicht nachgewiesen.', ['Ein Relay ohne Anmeldung kann vorgesehen sein. Zustellung und Absenderberechtigung wurden nicht getestet.']);
        }

        return $this->result('ok', 'connection', 'SMTP-Verbindung, TLS und Anmeldung bestätigt.', ['Es wurden keine Empfänger übergeben und keine Nachricht versendet. Zustellung und Posteingang bleiben ungeprüft.']);
    }

    private function realtime(): array
    {
        $name = (string) config('broadcasting.default', 'null');
        $config = (array) config('broadcasting.connections.'.$name, []);
        $driver = (string) ($config['driver'] ?? $name);
        if ($driver === 'null') {
            return $this->result('disabled', 'configuration', 'Echtzeitübertragung ist deaktiviert.');
        }
        if ($driver === 'log') {
            return $this->result('warning', 'configuration', 'Echtzeitübertragung ist nur als Protokollsimulation eingerichtet.');
        }
        if (! in_array($driver, ['pusher', 'reverb'], true)) {
            return $this->result('not_checked', 'configuration', 'Für diesen Realtime-Treiber ist kein WebSocket-Test registriert.');
        }
        foreach (['key', 'secret', 'app_id'] as $required) {
            if (trim((string) ($config[$required] ?? '')) === '') {
                return $this->result('not_configured', 'configuration', 'Die Realtime-Anwendung ist nicht vollständig eingerichtet.');
            }
        }
        $options = (array) ($config['options'] ?? []);
        $host = (string) ($options['host'] ?? '');
        if ($driver === 'pusher') {
            $cluster = (string) ($options['cluster'] ?? 'mt1');
            if ($host === '' || $host === 'api-'.$cluster.'.pusher.com') {
                if (! preg_match('/\A[a-z0-9-]+\z/', $cluster)) {
                    return $this->result('error', 'configuration', 'Der Pusher-Cluster ist ungültig.');
                }
                $host = 'ws-'.$cluster.'.pusher.com';
            }
        }
        $tls = in_array($options['scheme'] ?? 'https', ['https', 'wss'], true);
        if (! BoundedSocket::validHost($host) || (! $tls && ! $this->loopback($host))) {
            return $this->result('error', 'configuration', 'Der Realtime-Endpunkt benötigt eine gültige Adresse und sichere Verbindung.');
        }
        $path = '/'.trim((string) ($options['path'] ?? ''), '/');
        $path = rtrim($path, '/').'/app/'.rawurlencode((string) $config['key']).'?protocol=7&client=railtime-health&version=1&flash=false';
        $appUrl = $this->safeBase((string) config('app.url'));
        if ($appUrl === null) {
            return $this->result('error', 'configuration', 'Die Anwendungsadresse ist für die Realtime-Origin-Prüfung ungültig.');
        }
        $appParts = parse_url($appUrl);
        $origin = $appParts['scheme'].'://'.$appParts['host'].(isset($appParts['port']) ? ':'.$appParts['port'] : '');
        try {
            $this->websocket->check($host, (int) ($options['port'] ?? ($tls ? 443 : 80)), $tls, $path, $origin);
        } catch (Throwable) {
            return $this->result('error', 'connection', 'Die Realtime-Anwendung hat den WebSocket-Aufbau nicht bestätigt.', ['Es wurden keine Kanäle abonniert und keine Ereignisse veröffentlicht.']);
        }

        return $this->result('ok', 'connection', 'WebSocket-Verbindung und Realtime-Anwendung bestätigt.', ['Browserempfang, private Kanalberechtigungen und Event-Zustellung sind damit noch nicht nachgewiesen.']);
    }

    private function livekit(): array
    {
        foreach (['url', 'api_key', 'api_secret'] as $key) {
            if (trim((string) config('livekit.'.$key)) === '') {
                return $this->result('not_configured', 'configuration', 'LiveKit-Server oder API-Zugang fehlen.');
            }
        }
        $url = $this->safeBase((string) config('livekit.url'));
        if ($url === null) {
            return $this->result('error', 'configuration', 'Die LiveKit-API benötigt eine gültige HTTPS-Adresse oder Loopback-Verbindung.');
        }
        try {
            $token = (new AccessToken((string) config('livekit.api_key'), (string) config('livekit.api_secret')))
                ->init((new AccessTokenOptions)->setTtl(30))
                ->setGrant((new VideoGrant)->setRoomList())
                ->toJwt();
            $response = Http::withToken($token)->acceptJson()->connectTimeout(2)->timeout(5)
                ->withOptions([
                    'allow_redirects' => false, 'verify' => true, 'proxy' => '',
                    'progress' => static function ($total, $received): void {
                        if ($total > 262144 || $received > 262144) {
                            throw new RuntimeException('Diagnostic response too large.');
                        }
                    },
                ])->withBody('{}', 'application/json')->post($url.'/twirp/livekit.RoomService/ListRooms');
            $payload = json_decode($response->body(), false, 32, JSON_THROW_ON_ERROR);
            if (! $response->successful() || ! is_object($payload) || (isset($payload->rooms) && ! is_array($payload->rooms)) || isset($payload->code)) {
                throw new RuntimeException('LiveKit read probe failed.');
            }
        } catch (Throwable) {
            return $this->result('error', 'connection', 'Die lesende LiveKit-API-Prüfung ist fehlgeschlagen.', ['Keine Raum- oder Teilnehmerdaten werden gespeichert; keine Räume erstellt oder verändert.']);
        }

        return $this->result('ok', 'connection', 'LiveKit-API und lesende Anmeldung bestätigt.', ['ListRooms wurde ohne Raumänderung ausgeführt. Medienübertragung, TURN und tatsächliche Anrufqualität sind ungeprüft.']);
    }

    private function push(): array
    {
        $report = WebPushConfiguration::inspect();
        if (! $report['enabled']) {
            return $this->result('disabled', 'configuration', 'Web Push ist deaktiviert.');
        }
        if (! $report['configured']) {
            return $this->result('not_configured', 'configuration', 'Web-Push-Konfiguration oder vorhandene VAPID-Schlüssel sind unvollständig oder ungültig.', ['Es werden keine Schlüssel erzeugt, ersetzt oder gespeichert.']);
        }

        return $this->result('ok', 'configuration', 'Vorhandene Web-Push-Konfiguration ist formal gültig.', ['Kein Push versendet. Browserberechtigung, Abonnement und Empfang wurden nicht getestet.']);
    }

    private function speech(): array
    {
        if (! (bool) config('assistant.speech.enabled') || AssistantSpeechSettings::mode(true) === AssistantSpeechSettings::EXTERNAL_ONLY) {
            return $this->result('disabled', 'configuration', 'Der lokale Sprachdienst wird im gewählten Betriebsmodus nicht verwendet.');
        }
        if (! $this->speech->configured()) {
            return $this->result('not_configured', 'configuration', 'Der lokale Sprachdienst ist nicht vollständig oder sicher eingerichtet.');
        }
        try {
            $payload = $this->speech->check();
            $engines = is_array($payload['engines'] ?? null) ? $payload['engines'] : [];
            $ready = static fn ($state): bool => $state === true || $state === 'ready';
            $stt = $ready($engines['ffmpeg'] ?? null) && $ready($engines['whisper'] ?? null);
            $tts = $ready($engines['piper'] ?? null);
        } catch (Throwable) {
            return $this->result('error', 'connection', 'Der lokale Sprachdienst hat die Statusabfrage nicht bestätigt.', ['Keine Audioverarbeitung und kein kostenpflichtiger externer Fallback ausgelöst.']);
        }

        return $this->result($stt && $tts ? 'ok' : 'warning', 'connection', $stt && $tts ? 'Der Sprachdienst meldet seine lokalen Engines bereit.' : 'Der Sprachdienst antwortet, meldet aber nicht alle lokalen Engines bereit.', [
            $stt ? 'Spracherkennung: Bereitschaft gemeldet.' : 'Spracherkennung: Bereitschaft nicht vollständig gemeldet.',
            $tts ? 'Sprachausgabe: Bereitschaft gemeldet.' : 'Sprachausgabe: Bereitschaft nicht gemeldet.',
            'Nur Status-GET; Sprachqualität und tatsächliche Verarbeitung bleiben ungeprüft.',
        ]);
    }

    private function ai(): array
    {
        if (! AssistantSettings::enabled(true)) {
            return $this->result('disabled', 'configuration', 'Der KI-Assistent ist deaktiviert oder nicht freigegeben.');
        }
        $settings = OpenRouterSettings::all();
        $parts = parse_url((string) $settings['api_url']);
        $allowed = in_array(strtolower((string) ($parts['host'] ?? '')), (array) config('assistant.openrouter.allowed_hosts', ['openrouter.ai']), true);
        if ($settings['api_key'] === '' || $settings['text_model'] === '') {
            return $this->result('not_configured', 'configuration', 'Für den KI-Assistenten fehlen API-Zugang oder Textmodell.');
        }
        if (! $allowed || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return $this->result('error', 'configuration', 'Der KI-Endpunkt entspricht nicht der erlaubten HTTPS-Konfiguration.');
        }

        return $this->result('ok', 'configuration', 'API-Zugang, erlaubter Endpunkt und Textmodell sind hinterlegt.', ['Es erfolgt keine externe Anfrage. Gültigkeit des Zugangs, Guthaben, Modellverfügbarkeit und Antwortqualität sind nicht nachgewiesen.']);
    }

    private function outlook(): array
    {
        $manifest = new OutlookAddinManifest;
        if (! $manifest->enabled()) {
            return $this->result('disabled', 'configuration', 'Das Outlook-Add-in ist deaktiviert.');
        }
        if (! $manifest->ready()) {
            return $this->result('not_configured', 'configuration', 'Die Outlook-Manifestkonfiguration ist unvollständig oder ungültig.', $manifest->issues());
        }
        if (! Route::has('outlook-addin.runtime') || ! Route::has('admin.outlook-addin.manifest') || ! str_contains($manifest->render(), '<OfficeApp')) {
            return $this->result('error', 'configuration', 'Outlook-Routen oder Manifest sind nicht verfügbar.');
        }
        foreach (['outlook-addin/runtime.js', 'outlook-addin/taskpane.js'] as $file) {
            if (! is_file(public_path($file)) || ! is_readable(public_path($file)) || filesize(public_path($file)) === 0) {
                return $this->result('error', 'configuration', 'Mindestens ein benötigtes Outlook-Build-Asset fehlt.');
            }
        }
        $deployed = (bool) config('outlook_addin.deployed');

        return $this->result($deployed ? 'ok' : 'warning', 'configuration', $deployed ? 'Outlook-Manifest, Routen und Build-Assets sind vorhanden.' : 'Outlook ist vorbereitet; die zentrale Bereitstellung ist noch nicht bestätigt.', ['Anmeldung im Office-Client, Tenant-Bereitstellung und Signaturdarstellung wurden nicht live geprüft. Keine Postfachdaten oder Entwürfe verändert.']);
    }

    private function recordings(): array
    {
        if (! (bool) config('call_recording.enabled')) {
            return $this->result('disabled', 'configuration', 'Anrufaufzeichnung ist deaktiviert.');
        }
        foreach (['livekit.url', 'livekit.api_key', 'livekit.api_secret', 'call_recording.s3.key', 'call_recording.s3.secret', 'call_recording.s3.region', 'call_recording.s3.bucket'] as $key) {
            if (trim((string) config($key)) === '') {
                return $this->result('not_configured', 'configuration', 'Für Aufzeichnungen fehlen LiveKit- oder Egress-Speicherangaben.');
            }
        }
        $disk = (array) config('filesystems.disks.'.config('call_recording.storage_disk', 'call_recordings'), []);
        if (($disk['driver'] ?? '') !== 's3' || ($disk['visibility'] ?? '') !== 'private') {
            return $this->result('error', 'configuration', 'Aufzeichnungen benötigen den vorgesehenen privaten S3-Speicher.');
        }
        foreach (['region', 'bucket', 'endpoint', 'use_path_style_endpoint'] as $key) {
            if ((string) ($disk[$key] ?? '') !== (string) config('call_recording.s3.'.$key, '')) {
                return $this->result('error', 'configuration', 'Egress-Ziel und Anwendungsspeicher für Aufzeichnungen stimmen nicht überein.');
            }
        }
        if ($this->safeBase((string) config('livekit.url')) === null || trim((string) config('call_recording.policy_version')) === '' || (int) config('call_recording.retention_days') < 1) {
            return $this->result('error', 'configuration', 'API-Adresse oder Aufbewahrungs- und Richtlinienangaben sind ungültig.');
        }

        return $this->result('ok', 'configuration', 'Aufzeichnungs- und private Speicherkonfiguration sind vorhanden.', ['Kein Aufnahmeauftrag ausgelöst. Egress-Worker, Einwilligung, echter Upload und Wiedergabe sind damit nicht nachgewiesen.']);
    }

    private function marketing(): array
    {
        $script = (string) config('marketing.renders.script');
        $node = $this->executableExists((string) config('marketing.renders.node_binary', 'node'));
        $chrome = trim((string) config('marketing.renders.chrome_path', ''));
        $candidates = $chrome !== '' ? [$chrome] : match (PHP_OS_FAMILY) {
            'Windows' => ['C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'],
            'Darwin' => ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge'],
            default => ['/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'],
        };
        $browser = false;
        foreach ($candidates as $candidate) {
            $browser = $browser || (is_file($candidate) && is_executable($candidate));
        }
        $disk = (array) config('filesystems.disks.'.config('marketing.disk', 'private'), []);
        if (($disk['visibility'] ?? '') !== 'private') {
            return $this->result('error', 'configuration', 'Marketing-Exporte benötigen einen privaten Zielspeicher.');
        }
        $missing = [];
        if (! function_exists('proc_open')) {
            $missing[] = 'Prozessstart durch PHP ist nicht verfügbar.';
        }
        if (! $node) {
            $missing[] = 'Node.js ist im PHP-Prozesskontext nicht auffindbar.';
        }
        if ($script === '' || ! is_readable($script) || ! is_readable(base_path('node_modules/puppeteer-core/package.json'))) {
            $missing[] = 'Renderer-Skript oder Puppeteer-Abhängigkeit fehlen.';
        }
        if (! $browser) {
            $missing[] = 'Ein ausführbarer Chromium-Browser wurde nicht gefunden.';
        }
        if ($missing !== []) {
            return $this->result('warning', 'configuration', 'Nicht alle Voraussetzungen für Marketing-Exporte sind vorhanden.', $missing);
        }

        return $this->result('ok', 'configuration', 'Renderer, Node.js, Chromium und privater Speicher sind konfiguriert.', ['Es wurden keine Prozesse gestartet oder Bilder gerendert. Worker-, Browserstart- und Ergebnisnachweis bleiben getrennte Tests.']);
    }

    private function safeBase(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! BoundedSocket::validHost((string) ($parts['host'] ?? ''))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)
            || ! (($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && $this->loopback($parts['host'])))) {
            return null;
        }

        return $url;
    }

    private function executableExists(string $name): bool
    {
        if (is_file($name) && is_executable($name)) {
            return true;
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || $name === '') {
            return false;
        }
        $suffixes = PHP_OS_FAMILY === 'Windows' ? ['', '.exe', '.cmd', '.bat'] : [''];
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: getenv('Path') ?: '') as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name.$suffix;
                if (is_file($candidate) && is_executable($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function loopback(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function result(string $status, string $evidence, string $message, array $details = []): array
    {
        return compact('status', 'evidence', 'message', 'details');
    }
}
