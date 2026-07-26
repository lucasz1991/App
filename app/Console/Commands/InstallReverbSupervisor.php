<?php

namespace App\Console\Commands;

use App\Support\Environment\EnvironmentFile;
use App\Support\Reverb\SupervisorConfiguration;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class InstallReverbSupervisor extends Command
{
    protected $signature = 'railtime:install-reverb-service
        {--dry-run : Show the detected configuration without changing the server}
        {--service-name=railtime-reverb : Supervisor service name}
        {--user= : Operating-system user that owns the RailTime project}
        {--php= : Absolute PHP binary path; defaults to the current PHP binary}
        {--host=127.0.0.1 : Local interface on which Reverb listens}
        {--port=8080 : Local Reverb port}
        {--env-file= : Alternative .env file path}
        {--skip-package-install : Fail instead of installing Supervisor when it is missing}
        {--skip-build : Do not rebuild the Vite production assets}';

    protected $description = 'Install and supervise RailTime Reverb on a Linux server';

    private string $projectPath;

    private string $projectUser;

    private string $phpBinary;

    public function handle(
        Filesystem $files,
        EnvironmentFile $environment,
        SupervisorConfiguration $configuration,
    ): int {
        $this->projectPath = realpath(base_path()) ?: base_path();
        $this->phpBinary = $this->resolvePhpBinary();
        $this->projectUser = $this->resolveProjectUser();

        $serviceName = (string) $this->option('service-name');
        $host = trim((string) $this->option('host'));
        $port = (int) $this->option('port');
        $home = $this->homeDirectory($this->projectUser);

        try {
            $supervisorConfig = $configuration->render(
                $serviceName,
                $this->projectPath,
                $this->phpBinary,
                $this->projectUser,
                $home,
                $host,
                $port,
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Setting', 'Detected value'],
            [
                ['Project', $this->projectPath],
                ['PHP', $this->phpBinary],
                ['Service user', $this->projectUser],
                ['Local Reverb address', "{$host}:{$port}"],
                ['Supervisor service', $serviceName],
            ],
        );

        if ($this->option('dry-run')) {
            $this->components->info('Dry run only. No files, packages, processes, or credentials were changed.');
            $this->newLine();
            $this->line($supervisorConfig);

            return self::SUCCESS;
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->components->error('The automatic installer supports Linux servers only.');

            return self::FAILURE;
        }

        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->components->error('Root permission is required for the one-time Supervisor installation.');
            $this->line('Run: sudo '.escapeshellarg($this->phpBinary).' artisan railtime:install-reverb-service');

            return self::FAILURE;
        }

        if ($this->projectUser === 'root') {
            $this->components->error('Refusing to run Reverb as root. Use --user=<plesk-system-user>.');

            return self::FAILURE;
        }

        $keyArguments = [];
        $environmentPath = trim((string) $this->option('env-file'));

        if ($environmentPath !== '') {
            $keyArguments['--env-file'] = $environmentPath;
        }

        if ($this->call('railtime:reverb-keys', $keyArguments) !== self::SUCCESS) {
            return self::FAILURE;
        }

        try {
            $environment->update(
                $environmentPath !== '' ? $environmentPath : base_path('.env'),
                [
                    'QUEUE_CONNECTION' => 'database',
                    'WEBPUSH_QUEUE' => 'default',
                ],
            );

            $supervisorctl = $this->ensureSupervisor();
            $configurationPath = $this->supervisorConfigurationPath($serviceName);
            $this->writeConfiguration($files, $configurationPath, $supervisorConfig);
            $this->ensureSupervisorDaemon();
            $this->runProcess([$supervisorctl, 'reread']);
            $this->runProcess([$supervisorctl, 'update']);

            $status = new Process([$supervisorctl, 'status', $serviceName]);
            $status->run();

            $this->runProcess([
                $supervisorctl,
                $status->isSuccessful() ? 'restart' : 'start',
                $serviceName,
            ]);

            if (! $this->option('skip-build')) {
                $this->buildFrontendAssets();
            }

            $this->waitForPort($host, $port);
            $this->runProcess([$supervisorctl, 'status', $serviceName]);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Supervisor and RailTime Reverb are installed and running.');
        $this->line('Future deployments only need: php artisan reverb:restart');

        return self::SUCCESS;
    }

    private function resolvePhpBinary(): string
    {
        $option = trim((string) $this->option('php'));
        $binary = $option !== '' ? $option : PHP_BINARY;
        $resolved = realpath($binary) ?: $binary;

        if (! is_file($resolved) || ! is_executable($resolved)) {
            throw new RuntimeException("PHP binary is not executable: {$resolved}");
        }

        return $resolved;
    }

    private function resolveProjectUser(): string
    {
        $option = trim((string) $this->option('user'));

        if ($option !== '') {
            return $option;
        }

        $owner = @fileowner(base_path('artisan'));

        if (is_int($owner) && function_exists('posix_getpwuid')) {
            $account = posix_getpwuid($owner);

            if (is_array($account) && isset($account['name'])) {
                return (string) $account['name'];
            }
        }

        $current = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? posix_getpwuid(posix_geteuid())
            : false;

        return is_array($current) && isset($current['name'])
            ? (string) $current['name']
            : get_current_user();
    }

    private function homeDirectory(string $user): string
    {
        if (function_exists('posix_getpwnam')) {
            $account = posix_getpwnam($user);

            if (is_array($account) && isset($account['dir'])) {
                return (string) $account['dir'];
            }
        }

        return dirname($this->projectPath);
    }

    private function ensureSupervisor(): string
    {
        $supervisorctl = $this->findExecutable('supervisorctl');

        if ($supervisorctl !== null) {
            return $supervisorctl;
        }

        if ($this->option('skip-package-install')) {
            throw new RuntimeException('Supervisor is not installed and package installation was disabled.');
        }

        $apt = $this->findExecutable('apt-get');
        $dnf = $this->findExecutable('dnf');
        $yum = $this->findExecutable('yum');

        if ($apt !== null) {
            $this->runProcess([$apt, 'update'], 600);
            $this->runProcess([$apt, 'install', '-y', 'supervisor'], 600);
        } elseif ($dnf !== null) {
            try {
                $this->runProcess([$dnf, 'install', '-y', 'supervisor'], 600);
            } catch (RuntimeException) {
                $this->runProcess([$dnf, 'install', '-y', 'epel-release'], 600);
                $this->runProcess([$dnf, 'install', '-y', 'supervisor'], 600);
            }
        } elseif ($yum !== null) {
            try {
                $this->runProcess([$yum, 'install', '-y', 'supervisor'], 600);
            } catch (RuntimeException) {
                $this->runProcess([$yum, 'install', '-y', 'epel-release'], 600);
                $this->runProcess([$yum, 'install', '-y', 'supervisor'], 600);
            }
        } else {
            throw new RuntimeException('No supported package manager (apt-get, dnf, yum) was found.');
        }

        return $this->findExecutable('supervisorctl')
            ?? throw new RuntimeException('Supervisor was installed, but supervisorctl could not be found.');
    }

    private function supervisorConfigurationPath(string $serviceName): string
    {
        foreach (['/etc/supervisor/conf.d', '/etc/supervisord.d'] as $directory) {
            if (is_dir($directory)) {
                $extension = str_ends_with($directory, 'supervisord.d') ? '.ini' : '.conf';

                return $directory.DIRECTORY_SEPARATOR.$serviceName.$extension;
            }
        }

        throw new RuntimeException('No Supervisor configuration directory was found.');
    }

    private function writeConfiguration(Filesystem $files, string $path, string $contents): void
    {
        if ($files->isFile($path) && $files->get($path) === $contents) {
            $this->components->info('Supervisor configuration is already current.');

            return;
        }

        $files->replace($path, $contents);
        @chmod($path, 0644);
        $this->components->info("Supervisor configuration written: {$path}");
    }

    private function ensureSupervisorDaemon(): void
    {
        $systemctl = $this->findExecutable('systemctl');

        if ($systemctl !== null) {
            foreach (['supervisor', 'supervisord'] as $unit) {
                $process = new Process([$systemctl, 'enable', '--now', $unit]);
                $process->setTimeout(120);
                $process->run();

                if ($process->isSuccessful()) {
                    return;
                }
            }
        }

        $service = $this->findExecutable('service');

        if ($service !== null) {
            foreach (['supervisor', 'supervisord'] as $unit) {
                $process = new Process([$service, $unit, 'start']);
                $process->setTimeout(120);
                $process->run();

                if ($process->isSuccessful()) {
                    return;
                }
            }
        }

        throw new RuntimeException('Supervisor is installed but its daemon could not be started.');
    }

    private function buildFrontendAssets(): void
    {
        if (! is_file($this->projectPath.DIRECTORY_SEPARATOR.'package.json')) {
            return;
        }

        $npm = $this->findExecutable('npm') ?? $this->findPleskNpm();

        if ($npm === null) {
            $this->components->warn('npm was not found. Run npm run build as the project user before testing Reverb in the browser.');

            return;
        }

        $command = [$npm, 'run', 'build'];
        $runuser = $this->findExecutable('runuser');

        if ($runuser === null) {
            $this->components->warn('runuser was not found. The asset build was skipped to avoid root-owned deployment files.');

            return;
        }

        $command = [$runuser, '-u', $this->projectUser, '--', ...$command];

        $this->runProcess($command, 900, [
            'HOME' => $this->homeDirectory($this->projectUser),
            'PATH' => dirname($npm).PATH_SEPARATOR.(getenv('PATH') ?: ''),
            'USER' => $this->projectUser,
        ]);
    }

    private function findPleskNpm(): ?string
    {
        $matches = glob('/opt/plesk/node/*/bin/npm') ?: [];
        natsort($matches);

        $matches = array_values(array_filter($matches, 'is_executable'));

        return $matches === [] ? null : end($matches);
    }

    private function waitForPort(string $host, int $port): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.5);

            if (is_resource($socket)) {
                fclose($socket);
                $this->components->info("Reverb accepts local connections on {$host}:{$port}.");

                return;
            }

            usleep(500_000);
        }

        throw new RuntimeException("Reverb did not open {$host}:{$port} after Supervisor started it.");
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $environment
     */
    private function runProcess(array $command, int $timeout = 180, array $environment = []): void
    {
        $process = new Process($command, $this->projectPath, $environment ?: null);
        $process->setTimeout($timeout);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Command failed: '.implode(' ', array_map('escapeshellarg', $command))
                ."\n".trim($process->getErrorOutput()),
            );
        }
    }

    private function findExecutable(string $name): ?string
    {
        $path = getenv('PATH') ?: '';
        $directories = array_filter(explode(PATH_SEPARATOR, $path));
        $directories = array_merge($directories, ['/usr/bin', '/usr/sbin', '/bin', '/sbin']);

        foreach (array_unique($directories) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
