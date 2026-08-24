<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailBrandAnimationGifTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: int, 5: int, 6: int}>
     */
    public static function brandAnimations(): array
    {
        return [
            'signature wordmark light' => ['wortmarke-signature-light.gif', 504, 86, 52, 480, 6, 260 * 1024],
            'signature wordmark dark' => ['wortmarke-signature-dark.gif', 504, 86, 52, 480, 6, 260 * 1024],
            'mail wordmark dark' => ['wortmarke-mail-dark.gif', 618, 105, 52, 480, 6, 340 * 1024],
            'RT icon light' => ['icon-rt-light.gif', 132, 132, 44, 360, 5, 80 * 1024],
            'RT icon dark' => ['icon-rt-dark.gif', 132, 132, 44, 360, 5, 80 * 1024],
        ];
    }

    #[DataProvider('brandAnimations')]
    public function test_resource_and_public_brand_animations_are_byte_identical(
        string $filename,
        int $width,
        int $height,
        int $frameCount,
        int $durationCs,
        int $stepDelayCs,
        int $maximumBytes,
    ): void {
        $resourcePath = resource_path('mail-templates/assets/'.$filename);
        $publicPath = public_path('mail-assets/'.$filename);

        $this->assertFileExists($resourcePath);
        $this->assertFileExists($publicPath);

        $resource = file_get_contents($resourcePath);
        $public = file_get_contents($publicPath);

        $this->assertIsString($resource);
        $this->assertIsString($public);
        $this->assertSame(
            hash('sha256', $resource),
            hash('sha256', $public),
            "{$filename}: Mailquelle und oeffentlich ausgeliefertes Asset muessen bytegleich bleiben.",
        );
        $this->assertSame($resource, $public, "{$filename}: Die beiden GIF-Kopien sind nicht identisch.");
        $this->assertGreaterThan(1024, strlen($resource), "{$filename}: Das GIF sieht wie ein Platzhalter aus.");
        $this->assertLessThanOrEqual($maximumBytes, strlen($resource), "{$filename}: Das GIF ist fuer eine Mail zu gross.");
    }

    #[DataProvider('brandAnimations')]
    public function test_brand_animation_has_the_exact_single_run_timeline(
        string $filename,
        int $width,
        int $height,
        int $frameCount,
        int $durationCs,
        int $stepDelayCs,
        int $maximumBytes,
    ): void {
        $binary = file_get_contents(resource_path('mail-templates/assets/'.$filename));
        $this->assertIsString($binary);

        $gif = $this->parseGif($binary);

        $this->assertSame('GIF89a', $gif['signature'], "{$filename}: GIF89a erwartet.");
        $this->assertSame($width, $gif['width'], "{$filename}: falsche Breite.");
        $this->assertSame($height, $gif['height'], "{$filename}: falsche Hoehe.");
        $this->assertCount($frameCount, $gif['frames'], "{$filename}: falsche Anzahl Einzelbilder.");
        $this->assertStringNotContainsString('NETSCAPE2.0', $binary, "{$filename}: Die Animation darf nicht loopen.");
        $this->assertStringNotContainsString('ANIMEXTS1.0', $binary, "{$filename}: Die Animation darf keinen alternativen Loop-Block tragen.");

        $delays = array_column($gif['frames'], 'delayCs');
        $this->assertSame($durationCs, array_sum($delays), "{$filename}: falsche Gesamtlaufzeit.");
        $this->assertSame(
            array_fill(0, $frameCount - 1, $stepDelayCs),
            array_slice($delays, 0, -1),
            "{$filename}: Die Aufbauframes brauchen ein gleichmaessiges Tempo.",
        );
        $this->assertSame(
            $durationCs - (($frameCount - 1) * $stepDelayCs),
            $delays[$frameCount - 1],
            "{$filename}: Das fertige Logo muss im letzten Frame sichtbar gehalten werden.",
        );

        foreach ($gif['frames'] as $index => $frame) {
            $last = $index === $frameCount - 1;
            $this->assertTrue($frame['transparent'], "{$filename}: Frame {$index} muss Transparenz aktivieren.");
            $this->assertSame(
                $last ? 1 : 2,
                $frame['disposal'],
                "{$filename}: Frame {$index} hat die falsche Entsorgungsmethode.",
            );
            $this->assertSame(0, $frame['left'], "{$filename}: Frame {$index} beginnt nicht links am Canvas.");
            $this->assertSame(0, $frame['top'], "{$filename}: Frame {$index} beginnt nicht oben am Canvas.");
            $this->assertSame($width, $frame['width'], "{$filename}: Frame {$index} deckt die Breite nicht ab.");
            $this->assertSame($height, $frame['height'], "{$filename}: Frame {$index} deckt die Hoehe nicht ab.");
        }
    }

    #[DataProvider('brandAnimations')]
    public function test_brand_animation_builds_from_an_empty_canvas_to_a_complete_mark(
        string $filename,
        int $width,
        int $height,
        int $frameCount,
        int $durationCs,
        int $stepDelayCs,
        int $maximumBytes,
    ): void {
        $binary = file_get_contents(resource_path('mail-templates/assets/'.$filename));
        $this->assertIsString($binary);

        $frames = $this->parseGif($binary)['frames'];
        $first = $frames[0];
        $last = $frames[count($frames) - 1];
        $firstIndices = $this->decodeLzw($first['imageData'], $first['minimumCodeSize'], $width * $height);
        $lastIndices = $this->decodeLzw($last['imageData'], $last['minimumCodeSize'], $width * $height);

        $this->assertSame($width * $height, strlen($firstIndices), "{$filename}: erster Frame ist unvollstaendig.");
        $this->assertSame($width * $height, strlen($lastIndices), "{$filename}: letzter Frame ist unvollstaendig.");

        $firstInk = $this->countInkPixels($firstIndices, $first['transparentIndex']);
        $lastInk = $this->countInkPixels($lastIndices, $last['transparentIndex']);

        $this->assertSame(0, $firstInk, "{$filename}: Die Animation muss aus dem Nichts beginnen.");
        $this->assertGreaterThan(
            (int) floor($width * $height * 0.03),
            $lastInk,
            "{$filename}: Im letzten Frame fehlt ein wesentlicher Teil des Logos.",
        );
        $this->assertLessThan(
            $width * $height,
            $lastInk,
            "{$filename}: Der transparente Hintergrund des letzten Frames ging verloren.",
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
     */
    public static function v15BrandAnimations(): array
    {
        return [
            'V15 signature wordmark light' => [
                'wortmarke-signature-v15-light.gif',
                'wortmarke-signature-v15-light.png',
                150_454,
                24_237,
            ],
            'V15 mail wordmark dark' => [
                'wortmarke-mail-v15-dark.gif',
                'wortmarke-mail-v15-dark.png',
                147_044,
                22_484,
            ],
        ];
    }

    #[DataProvider('v15BrandAnimations')]
    public function test_v15_brand_assets_keep_the_optimized_single_run_contract(
        string $gifFilename,
        string $pngFilename,
        int $maximumGifBytes,
        int $maximumPngBytes,
    ): void {
        $gifResourcePath = resource_path('mail-templates/assets/'.$gifFilename);
        $gifPublicPath = public_path('mail-assets/'.$gifFilename);
        $gifResource = file_get_contents($gifResourcePath);
        $gifPublic = file_get_contents($gifPublicPath);

        $this->assertIsString($gifResource);
        $this->assertIsString($gifPublic);
        $this->assertSame($gifResource, $gifPublic, "{$gifFilename}: Ressourcen- und Public-Datei unterscheiden sich.");
        $this->assertLessThanOrEqual($maximumGifBytes, strlen($gifResource), "{$gifFilename}: V15-GIF ist groesser als die freigegebene Fassung.");
        $this->assertStringNotContainsString('NETSCAPE2.0', $gifResource, "{$gifFilename}: Die Wortmarke darf nicht loopen.");
        $this->assertStringNotContainsString('ANIMEXTS1.0', $gifResource, "{$gifFilename}: Alternativer Loop-Block gefunden.");

        $gif = $this->parseGif($gifResource);
        $this->assertSame('GIF89a', $gif['signature']);
        $this->assertSame(400, $gif['width']);
        $this->assertSame(68, $gif['height']);
        $this->assertCount(40, $gif['frames']);

        $delays = array_column($gif['frames'], 'delayCs');
        $delayCounts = array_count_values($delays);
        ksort($delayCounts);
        $this->assertSame(
            [6 => 36, 12 => 2, 18 => 1, 222 => 1],
            $delayCounts,
            "{$gifFilename}: Die optimierte V15-Timeline ist abgewichen.",
        );
        $this->assertSame(480, array_sum($delays), "{$gifFilename}: V15 muss exakt 4,8 s dauern.");
        $this->assertSame(222, $delays[39], "{$gifFilename}: Die fertige Wortmarke muss im letzten Frame sichtbar gehalten werden.");

        $firstInk = null;
        $lastInk = null;
        foreach ($gif['frames'] as $index => $frame) {
            $this->assertTrue($frame['transparent'], "{$gifFilename}: Frame {$index} muss transparent sein.");
            $this->assertSame(
                $index === 39 ? 1 : 2,
                $frame['disposal'],
                "{$gifFilename}: Frame {$index} hat die falsche Entsorgungsmethode.",
            );
            $this->assertGreaterThan(0, $frame['width'], "{$gifFilename}: Frame {$index} besitzt keine Breite.");
            $this->assertGreaterThan(0, $frame['height'], "{$gifFilename}: Frame {$index} besitzt keine Hoehe.");
            $this->assertLessThanOrEqual(
                $gif['width'],
                $frame['left'] + $frame['width'],
                "{$gifFilename}: Frame {$index} ragt rechts aus dem Canvas.",
            );
            $this->assertLessThanOrEqual(
                $gif['height'],
                $frame['top'] + $frame['height'],
                "{$gifFilename}: Frame {$index} ragt unten aus dem Canvas.",
            );

            $pixels = $this->decodeLzw(
                $frame['imageData'],
                $frame['minimumCodeSize'],
                $frame['width'] * $frame['height'],
            );
            $this->assertSame($frame['width'] * $frame['height'], strlen($pixels), "{$gifFilename}: Frame {$index} ist unvollstaendig.");
            $ink = $this->countInkPixels($pixels, $frame['transparentIndex']);
            if ($index === 0) {
                $firstInk = $ink;
            }
            if ($index === 39) {
                $lastInk = $ink;
            }
        }
        $this->assertSame(0, $firstInk, "{$gifFilename}: Die Animation muss aus einem leeren Canvas beginnen.");
        $this->assertIsInt($lastInk);
        $this->assertGreaterThan((int) floor($gif['width'] * $gif['height'] * 0.03), $lastInk, "{$gifFilename}: Im End-Hold fehlt die fertige Wortmarke.");

        $pngResourcePath = resource_path('mail-templates/assets/'.$pngFilename);
        $pngPublicPath = public_path('mail-assets/'.$pngFilename);
        $pngResource = file_get_contents($pngResourcePath);
        $pngPublic = file_get_contents($pngPublicPath);

        $this->assertIsString($pngResource);
        $this->assertIsString($pngPublic);
        $this->assertSame($pngResource, $pngPublic, "{$pngFilename}: Ressourcen- und Public-Datei unterscheiden sich.");
        $this->assertLessThanOrEqual($maximumPngBytes, strlen($pngResource), "{$pngFilename}: V15-PNG ist groesser als die freigegebene Fassung.");
        $pngSize = getimagesize($pngResourcePath);
        $this->assertIsArray($pngSize);
        $this->assertSame([400, 68], [$pngSize[0], $pngSize[1]]);

        $png = imagecreatefrompng($pngResourcePath);
        $this->assertInstanceOf(\GdImage::class, $png);
        $visiblePixels = 0;
        $transparentPixels = 0;
        for ($y = 0; $y < imagesy($png); $y++) {
            for ($x = 0; $x < imagesx($png); $x++) {
                if (((imagecolorat($png, $x, $y) >> 24) & 0x7F) < 127) {
                    $visiblePixels++;
                } else {
                    $transparentPixels++;
                }
            }
        }
        imagedestroy($png);
        $this->assertGreaterThan((int) floor(400 * 68 * 0.03), $visiblePixels, "{$pngFilename}: Das finale PNG enthaelt keine vollstaendige Wortmarke.");
        $this->assertGreaterThan(0, $transparentPixels, "{$pngFilename}: Der transparente Hintergrund des finalen PNG fehlt.");
    }

    /**
     * @return array{
     *     signature: string,
     *     width: int,
     *     height: int,
     *     frames: list<array{
     *         delayCs: int,
     *         disposal: int,
     *         transparent: bool,
     *         transparentIndex: int,
     *         left: int,
     *         top: int,
     *         width: int,
     *         height: int,
     *         minimumCodeSize: int,
     *         imageData: string
     *     }>
     * }
     */
    private function parseGif(string $binary): array
    {
        $this->assertGreaterThanOrEqual(13, strlen($binary), 'GIF-Datei ist abgeschnitten.');

        $signature = substr($binary, 0, 6);
        $width = $this->uint16($binary, 6);
        $height = $this->uint16($binary, 8);
        $packed = ord($binary[10]);
        $offset = 13;

        if (($packed & 0x80) !== 0) {
            $offset += 3 * (1 << (($packed & 0x07) + 1));
        }

        $frames = [];
        $control = null;
        $length = strlen($binary);

        while ($offset < $length) {
            $marker = ord($binary[$offset++]);

            if ($marker === 0x3B) {
                break;
            }

            if ($marker === 0x21) {
                $this->assertLessThan($length, $offset, 'GIF-Erweiterung ist abgeschnitten.');
                $label = ord($binary[$offset++]);

                if ($label === 0xF9) {
                    $this->assertSame(4, ord($binary[$offset++] ?? "\0"), 'Ungueltige Graphic Control Extension.');
                    $controlPacked = ord($binary[$offset++]);
                    $delayCs = $this->uint16($binary, $offset);
                    $offset += 2;
                    $transparentIndex = ord($binary[$offset++]);
                    $this->assertSame(0, ord($binary[$offset++] ?? "\1"), 'Graphic Control Extension ohne Abschluss.');
                    $control = [
                        'delayCs' => $delayCs,
                        'disposal' => ($controlPacked >> 2) & 0x07,
                        'transparent' => ($controlPacked & 0x01) !== 0,
                        'transparentIndex' => $transparentIndex,
                    ];
                } else {
                    $this->readSubBlocks($binary, $offset);
                }

                continue;
            }

            $this->assertSame(0x2C, $marker, sprintf('Unbekannter GIF-Block 0x%02x.', $marker));
            $this->assertNotNull($control, 'Bild ohne vorherige Graphic Control Extension.');

            $left = $this->uint16($binary, $offset);
            $top = $this->uint16($binary, $offset + 2);
            $frameWidth = $this->uint16($binary, $offset + 4);
            $frameHeight = $this->uint16($binary, $offset + 6);
            $imagePacked = ord($binary[$offset + 8]);
            $offset += 9;

            if (($imagePacked & 0x80) !== 0) {
                $offset += 3 * (1 << (($imagePacked & 0x07) + 1));
            }

            $minimumCodeSize = ord($binary[$offset++]);
            $imageData = $this->readSubBlocks($binary, $offset);
            $frames[] = $control + [
                'left' => $left,
                'top' => $top,
                'width' => $frameWidth,
                'height' => $frameHeight,
                'minimumCodeSize' => $minimumCodeSize,
                'imageData' => $imageData,
            ];
            $control = null;
        }

        return compact('signature', 'width', 'height', 'frames');
    }

    private function readSubBlocks(string $binary, int &$offset): string
    {
        $data = '';
        $length = strlen($binary);

        while ($offset < $length) {
            $blockLength = ord($binary[$offset++]);
            if ($blockLength === 0) {
                return $data;
            }

            $this->assertLessThanOrEqual($length, $offset + $blockLength, 'GIF-Unterblock ist abgeschnitten.');
            $data .= substr($binary, $offset, $blockLength);
            $offset += $blockLength;
        }

        $this->fail('GIF-Unterblock besitzt keinen Abschluss.');
    }

    private function uint16(string $binary, int $offset): int
    {
        $this->assertLessThan(strlen($binary) - 1, $offset, 'GIF-Wert ist abgeschnitten.');

        return ord($binary[$offset]) | (ord($binary[$offset + 1]) << 8);
    }

    private function decodeLzw(string $data, int $minimumCodeSize, int $expectedPixels): string
    {
        $clearCode = 1 << $minimumCodeSize;
        $endCode = $clearCode + 1;
        $bitOffset = 0;
        $bitLength = strlen($data) * 8;
        $output = '';
        $dictionary = [];
        $codeSize = 0;
        $nextCode = 0;
        $previous = null;

        $reset = static function () use (&$dictionary, &$codeSize, &$nextCode, &$previous, $clearCode, $endCode, $minimumCodeSize): void {
            $dictionary = [];
            for ($index = 0; $index < $clearCode; $index++) {
                $dictionary[$index] = chr($index);
            }
            $codeSize = $minimumCodeSize + 1;
            $nextCode = $endCode + 1;
            $previous = null;
        };
        $reset();

        while ($bitOffset + $codeSize <= $bitLength) {
            $code = 0;
            for ($bit = 0; $bit < $codeSize; $bit++) {
                $absoluteBit = $bitOffset + $bit;
                $code |= ((ord($data[intdiv($absoluteBit, 8)]) >> ($absoluteBit % 8)) & 1) << $bit;
            }
            $bitOffset += $codeSize;

            if ($code === $clearCode) {
                $reset();

                continue;
            }
            if ($code === $endCode) {
                break;
            }

            if (isset($dictionary[$code])) {
                $entry = $dictionary[$code];
            } elseif ($code === $nextCode && $previous !== null) {
                $entry = $previous.$previous[0];
            } else {
                $this->fail("Ungueltiger GIF-LZW-Code {$code}.");
            }

            $output .= $entry;
            if ($previous !== null && $nextCode < 4096) {
                $dictionary[$nextCode++] = $previous.$entry[0];
                if ($nextCode === (1 << $codeSize) && $codeSize < 12) {
                    $codeSize++;
                }
            }
            $previous = $entry;

            if (strlen($output) > $expectedPixels) {
                $this->fail('GIF-Frame enthaelt zu viele Pixel.');
            }
        }

        return $output;
    }

    private function countInkPixels(string $indices, int $transparentIndex): int
    {
        $ink = 0;
        $length = strlen($indices);

        for ($index = 0; $index < $length; $index++) {
            if (ord($indices[$index]) !== $transparentIndex) {
                $ink++;
            }
        }

        return $ink;
    }
}
