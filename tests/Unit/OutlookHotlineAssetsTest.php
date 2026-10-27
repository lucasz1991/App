<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fixed portable artwork only: no Laravel boot, database or provider calls. */
final class OutlookHotlineAssetsTest extends TestCase
{
    public static function assets(): array
    {
        return [
            'fill' => ['fill', 1, 34, 75, '3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583', 0],
            'label' => ['label', 411, 102, 6485, 'c52d2cdb4861ec8714d6d33d45204a50dfd86de4b77c6d6c1f9f0d02185fd16d', 4581],
            'mail' => ['mail', 126, 102, 2415, '36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d', 758],
            'phone' => ['phone', 126, 102, 4360, 'a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5', 614],
        ];
    }

    #[DataProvider('assets')]
    public function test_fixed_opaque_pngs_have_exact_frame_red_edges_and_preserved_white_glyphs(string $kind, int $width, int $height, int $bytes, string $hash, int $whitePixels): void
    {
        $path = dirname(__DIR__, 2).'/public/mail-assets/outlook-hotline-'.$kind.'-v1.png';
        $binary = file_get_contents($path);
        self::assertSame($hash, hash('sha256', $binary));
        self::assertSame($bytes, strlen($binary));
        self::assertSame("\x89PNG\r\n\x1a\n", substr($binary, 0, 8));
        self::assertSame(8, ord($binary[24]), 'The PNG uses eight-bit RGB channels.');
        self::assertSame(2, ord($binary[25]), 'Color type 2 has no alpha/transparency channel.');
        self::assertStringNotContainsString('tRNS', $binary);
        $size = getimagesize($path);
        self::assertSame([$width, $height], [$size[0], $size[1]]);
        self::assertSame('image/png', $size['mime']);
        $image = imagecreatefrompng($path);
        try {
            self::assertTrue(imageistruecolor($image));
            $white = 0;
            $firstPaint = $width;
            $lastPaint = -1;
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixel = imagecolorat($image, $x, $y);
                    if (($x === 0 && $kind !== 'label') || $x === $width - 1 || $y === 0 || $y === $height - 1 || $kind === 'fill') {
                        self::assertSame(0xE90032, $pixel, 'No recolored edge or transparent seam can differ from the native left border.');
                    }
                    if ($pixel !== 0xE90032) {
                        $firstPaint = min($firstPaint, $x);
                        $lastPaint = max($lastPaint, $x);
                    }
                    $white += $pixel === 0xFFFFFF ? 1 : 0;
                }
            }
            self::assertSame($whitePixels, $white, 'The visible white label/outline pixels are bound to the reviewed artwork.');
            if ($kind === 'label') {
                self::assertSame(0, $firstPaint, 'Visible label ink starts at the shared text gutter, without a hidden image inset.');
                self::assertSame(371, $lastPaint, 'Every existing glyph pixel remains after translating the reviewed typography.');
                $oldRightGap = 152 - 415 / 3;
                $newRightGap = 137 - $lastPaint / 3;
                self::assertLessThanOrEqual(1, abs($oldRightGap - $newRightGap), 'The next mail icon retains its optical gap within one logical pixel.');
            }
        } finally {
            imagedestroy($image);
        }
    }
}
