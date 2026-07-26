<?php

namespace App\Console\Commands;

use App\Support\Environment\EnvironmentFile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateReverbCredentials extends Command
{
    protected $signature = 'railtime:reverb-keys
        {--force : Replace an existing complete Reverb credential set}
        {--host= : Public Reverb host; defaults to the host from APP_URL}
        {--env-file= : Alternative .env file path}
        {--no-config-clear : Do not clear Laravel\'s cached configuration}';

    protected $description = 'Generate Reverb credentials and store the complete Reverb configuration in .env';

    public function handle(EnvironmentFile $environment): int
    {
        $path = $this->environmentPath();

        try {
            $current = $environment->read($path);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $credentialKeys = ['REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET'];
        $complete = collect($credentialKeys)->every(
            fn (string $key): bool => trim($current[$key] ?? '') !== '',
        );

        $rotate = (bool) $this->option('force');

        if ($complete && ! $rotate) {
            $this->components->info('Existing Reverb credentials were preserved. Use --force to rotate them.');
            $credentials = collect($credentialKeys)
                ->mapWithKeys(fn (string $key): array => [$key => $current[$key]])
                ->all();
        } else {
            if (! $complete && collect($credentialKeys)->contains(
                fn (string $key): bool => trim($current[$key] ?? '') !== '',
            )) {
                $this->components->warn('The incomplete Reverb credential set will be replaced as one unit.');
            }

            $credentials = [
                'REVERB_APP_ID' => (string) random_int(100000, 999999),
                'REVERB_APP_KEY' => Str::random(24),
                'REVERB_APP_SECRET' => Str::random(48),
            ];
        }

        try {
            $host = $this->publicHost($current);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        $values = array_merge($credentials, [
            'BROADCAST_DRIVER' => 'reverb',
            'REVERB_HOST' => $host,
            'REVERB_PORT' => '443',
            'REVERB_SCHEME' => 'https',
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => '8080',
            'VITE_REVERB_APP_KEY' => '${REVERB_APP_KEY}',
            'VITE_REVERB_HOST' => '${REVERB_HOST}',
            'VITE_REVERB_PORT' => '${REVERB_PORT}',
            'VITE_REVERB_SCHEME' => '${REVERB_SCHEME}',
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
                ? 'Reverb configuration is complete.'
                : 'New Reverb credentials were generated and stored without printing the secrets.',
        );
        $this->line('Public host: '.$host);
        $this->components->warn('Run npm run build after changing Reverb credentials so the browser receives the new public key.');

        return self::SUCCESS;
    }

    private function environmentPath(): string
    {
        $option = trim((string) $this->option('env-file'));

        return $option !== '' ? $option : base_path('.env');
    }

    /**
     * @param  array<string, string>  $current
     */
    private function publicHost(array $current): string
    {
        $option = trim((string) $this->option('host'));

        if ($option !== '') {
            return $this->normalizeHost($option);
        }

        $existing = trim($current['REVERB_HOST'] ?? '');

        if ($existing !== '' && ! in_array($existing, ['localhost', '127.0.0.1'], true)) {
            return $this->normalizeHost($existing);
        }

        $appUrlHost = parse_url(trim($current['APP_URL'] ?? ''), PHP_URL_HOST);

        return is_string($appUrlHost) && $appUrlHost !== ''
            ? $this->normalizeHost($appUrlHost)
            : 'localhost';
    }

    private function normalizeHost(string $host): string
    {
        $parsed = parse_url(str_contains($host, '://') ? $host : 'https://'.$host, PHP_URL_HOST);

        if (! is_string($parsed) || $parsed === '') {
            throw new RuntimeException('The Reverb host is invalid.');
        }

        return strtolower($parsed);
    }
}
