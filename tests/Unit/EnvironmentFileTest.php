<?php

namespace Tests\Unit;

use App\Support\Environment\EnvironmentFile;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class EnvironmentFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'railtime-env-');
        file_put_contents($this->path, "APP_NAME=RailTime\nREVERB_APP_KEY=old\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    public function test_it_updates_existing_values_and_appends_missing_values_without_duplicates(): void
    {
        $environment = new EnvironmentFile(new Filesystem);

        $environment->update($this->path, [
            'REVERB_APP_KEY' => 'new-key',
            'VITE_REVERB_APP_KEY' => '${REVERB_APP_KEY}',
        ]);

        $contents = file_get_contents($this->path);
        $values = $environment->read($this->path);

        $this->assertSame(1, substr_count($contents, 'REVERB_APP_KEY=new-key'));
        $this->assertSame('new-key', $values['REVERB_APP_KEY']);
        $this->assertSame('${REVERB_APP_KEY}', $values['VITE_REVERB_APP_KEY']);
    }
}
