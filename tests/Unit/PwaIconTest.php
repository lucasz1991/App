<?php

namespace Tests\Unit;

use App\Support\Pwa\PwaIcon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PwaIconTest extends TestCase
{
    public function test_generated_deployment_fallback_is_a_real_192_pixel_png(): void
    {
        $png = PwaIcon::fallback('pwa-192.png');
        $image = getimagesizefromstring($png);

        $this->assertNotFalse($image);
        $this->assertSame([192, 192], [$image[0], $image[1]]);
        $this->assertSame('image/png', $image['mime']);
    }

    public function test_only_declared_pwa_assets_can_be_generated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PwaIcon::fallback('../private.png');
    }
}
