<?php

namespace App\Support\Reverb;

use InvalidArgumentException;

class SupervisorConfiguration
{
    public function render(
        string $serviceName,
        string $projectPath,
        string $phpBinary,
        string $user,
        string $homeDirectory,
        string $host,
        int $port,
    ): string {
        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $serviceName)) {
            throw new InvalidArgumentException('The Supervisor service name is invalid.');
        }

        if (! preg_match('/^[a-z_][a-z0-9_-]*[$]?$/i', $user)) {
            throw new InvalidArgumentException('The operating-system user name is invalid.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The Reverb server port is invalid.');
        }

        $projectPath = rtrim(str_replace('\\', '/', $projectPath), '/');
        $phpBinary = str_replace('\\', '/', $phpBinary);
        $artisan = $projectPath.'/artisan';
        $log = $projectPath.'/storage/logs/reverb.log';

        // Bewusst NUR Reverb: Die Standard-Queue betreibt der Plesk-eigene
        // Worker (siehe ReverbOperationsCommandTest::test_plesk_worker_owns_
        // the_default_queue_without_a_second_scheduled_worker). Ein zweites
        // queue:work-Programm hier waere ein konkurrierender Doppel-Worker.
        return implode("\n", [
            "[program:{$serviceName}]",
            'process_name=%(program_name)s',
            'directory='.$projectPath,
            'command='.$this->argument($phpBinary).' '.$this->argument($artisan)
                .' reverb:start --host='.$host.' --port='.$port,
            'user='.$user,
            'environment=HOME="'.$this->escape($homeDirectory).'",USER="'.$this->escape($user).'"',
            '',
            'autostart=true',
            'autorestart=true',
            'startsecs=5',
            'startretries=10',
            'stopsignal=TERM',
            'stopasgroup=true',
            'killasgroup=true',
            'stopwaitsecs=30',
            '',
            'redirect_stderr=true',
            'stdout_logfile='.$log,
            'stdout_logfile_maxbytes=10MB',
            'stdout_logfile_backups=5',
            '',
        ]);
    }

    private function argument(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\/.:-]+$/', $value)) {
            return $value;
        }

        return '"'.$this->escape($value).'"';
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
