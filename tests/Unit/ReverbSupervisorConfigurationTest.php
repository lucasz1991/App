<?php

namespace Tests\Unit;

use App\Support\Reverb\SupervisorConfiguration;
use PHPUnit\Framework\TestCase;

class ReverbSupervisorConfigurationTest extends TestCase
{
    public function test_it_builds_a_self_restarting_non_root_reverb_service(): void
    {
        $configuration = (new SupervisorConfiguration)->render(
            serviceName: 'railtime-reverb',
            projectPath: '/var/www/vhosts/rail-time.de/httpdocs',
            phpBinary: '/opt/plesk/php/8.3/bin/php',
            user: 'railtime',
            homeDirectory: '/var/www/vhosts/rail-time.de',
            host: '127.0.0.1',
            port: 8080,
        );

        $this->assertStringContainsString('[program:railtime-reverb]', $configuration);
        $this->assertStringContainsString('user=railtime', $configuration);
        $this->assertStringContainsString('autostart=true', $configuration);
        $this->assertStringContainsString('autorestart=true', $configuration);
        $this->assertStringContainsString('reverb:start --host=127.0.0.1 --port=8080', $configuration);
        $this->assertStringContainsString('storage/logs/reverb.log', $configuration);
        $this->assertStringNotContainsString('user=root', $configuration);
    }
}
