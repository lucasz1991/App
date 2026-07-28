<?php

namespace App\Console\Commands;

use App\Support\Environment\EnvironmentFile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateLiveKitCredentials extends Command
{
    protected $signature = 'railtime:livekit-keys
        {--force : Replace an existing complete LiveKit credential set}
        {--host= : Public LiveKit host, e.g. livekit.rail-time.de}
        {--env-file= : Alternative .env file path}
        {--no-config-clear : Do not clear Laravel\'s cached configuration}';

    protected $description = 'Generate LiveKit API credentials, store them in .env and print the keys block for livekit.yaml';

    public function handle(EnvironmentFile $environment): int
    {
        $path = $this->environmentPath();

        try {
            $current = $environment->read($path);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $credentialKeys = ['LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET'];
        $complete = collect($credentialKeys)->every(
            fn (string $key): bool => trim($current[$key] ?? '') !== '',
        );

        $rotate = (bool) $this->option('force');

        if ($complete && ! $rotate) {
            $this->components->info('Existing LiveKit credentials were preserved. Use --force to rotate them.');
            $credentials = collect($credentialKeys)
                ->mapWithKeys(fn (string $key): array => [$key => $current[$key]])
                ->all();
        } else {
            $credentials = [
                'LIVEKIT_API_KEY' => 'RT'.Str::upper(Str::random(10)),
                'LIVEKIT_API_SECRET' => Str::random(48),
            ];
        }

        $host = $this->publicHost($current);

        $values = array_merge($credentials, [
            'LIVEKIT_URL' => 'https://'.$host,
            'LIVEKIT_WS_URL' => 'wss://'.$host,
        ]);

        try {
            $environment->update($path, $values);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('no-config-clear')) {
            $this->callSilent('config:clear');
        }

        $this->components->info(
            $complete && ! $rotate
                ? 'LiveKit configuration is complete.'
                : 'New LiveKit credentials were generated and stored.',
        );
        $this->line('Public host: '.$host);
        $this->newLine();
        $this->line('Diesen Block in die livekit.yaml der Media-VM uebernehmen (siehe SERVER_SETUP.md):');
        $this->newLine();
        $this->line('keys:');
        $this->line('  '.$values['LIVEKIT_API_KEY'].': "'.$values['LIVEKIT_API_SECRET'].'"');
        $this->newLine();
        $this->components->warn('Das Secret wird nur hier angezeigt – sicher uebertragen und dieses Terminal leeren.');

        return self::SUCCESS;
    }

    private function environmentPath(): string
    {
        $option = trim((string) $this->option('env-file'));

        return $option !== '' ? $option : base_path('.env');
    }

    /** @param array<string, string> $current */
    private function publicHost(array $current): string
    {
        $option = trim((string) $this->option('host'));

        if ($option !== '') {
            return $this->normalizeHost($option);
        }

        $existing = trim($current['LIVEKIT_URL'] ?? '');

        if ($existing !== '') {
            $parsed = parse_url($existing, PHP_URL_HOST);

            if (is_string($parsed) && $parsed !== '') {
                return $parsed;
            }
        }

        return 'livekit.rail-time.de';
    }

    private function normalizeHost(string $host): string
    {
        $parsed = parse_url(str_contains($host, '://') ? $host : 'https://'.$host, PHP_URL_HOST);

        if (! is_string($parsed) || $parsed === '') {
            throw new RuntimeException('The LiveKit host is invalid.');
        }

        return strtolower($parsed);
    }
}
