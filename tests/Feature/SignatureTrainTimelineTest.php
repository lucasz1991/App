<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignatureTrainTimelineTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: int, 4: bool}>
     */
    public static function trainAnimations(): array
    {
        return [
            'mail light' => ['zug-dampf-light.gif', 2160, 219, 620 * 1024, true],
            'mail dark' => ['zug-dampf-dark.gif', 2160, 219, 445 * 1024, true],
            'outlook light' => ['zug-dampf-outlook-light.gif', 720, 75, 200 * 1024, false],
            'outlook dark' => ['zug-dampf-outlook-dark.gif', 720, 75, 200 * 1024, false],
        ];
    }

    #[DataProvider('trainAnimations')]
    public function test_train_arrives_at_sixty_percent_before_idle_smoke_starts(
        string $filename,
        int $width,
        int $height,
        int $maximumBytes,
        bool $hasPublicCopy,
    ): void {
        $path = resource_path('mail-templates/assets/'.$filename);
        $binary = file_get_contents($path);

        $this->assertIsString($binary);
        $this->assertLessThanOrEqual($maximumBytes, strlen($binary), "{$filename}: Datei ist zu gross.");
        $this->assertStringNotContainsString('NETSCAPE2.0', $binary, "{$filename}: Die Zugfahrt darf nicht loopen.");
        $this->assertStringNotContainsString('ANIMEXTS1.0', $binary, "{$filename}: Alternativer Loop-Block gefunden.");

        if ($hasPublicCopy) {
            $public = file_get_contents(public_path('mail-assets/'.$filename));
            $this->assertIsString($public);
            $this->assertSame($binary, $public, "{$filename}: Ressourcen- und Public-Datei unterscheiden sich.");
        }

        $gif = $this->parseGif($binary);
        $this->assertSame('GIF89a', $gif['signature']);
        $this->assertSame($width, $gif['width']);
        $this->assertSame($height, $gif['height']);
        $this->assertCount(72, $gif['frames']);

        $delays = array_column($gif['frames'], 'delayCs');
        $this->assertSame(30, $delays[0], "{$filename}: 300-ms-Startbild fehlt.");
        $this->assertSame(1300, array_sum($delays), "{$filename}: 13,0-s-Einzelfolge erwartet.");

        $starts = [];
        $elapsed = 0;
        foreach ($delays as $delay) {
            $starts[] = $elapsed;
            $elapsed += $delay;
        }

        $arrivalFrame = array_search(735, $starts, true);
        $this->assertSame(52, $arrivalFrame, "{$filename}: Ankunft muss exakt bei 7,35 s beginnen.");
        $idleFrame = $arrivalFrame + 1;
        $this->assertSame(764, $starts[$idleFrame], "{$filename}: erster sichtbarer Idle-Schritt erwartet.");

        foreach ($gif['frames'] as $index => $frame) {
            $this->assertTrue($frame['transparent'], "{$filename}: Frame {$index} muss transparent sein.");
            $this->assertSame(
                $index === count($gif['frames']) - 1 ? 1 : 2,
                $frame['disposal'],
                "{$filename}: Frame {$index} hat die falsche Entsorgungsmethode.",
            );
            $this->assertSame($width, $frame['width']);
            $this->assertSame($height, $frame['height']);
        }

        $decoded = [];
        foreach ([0, 1, 2, 6, 11, 16, 21, 37, 51, $arrivalFrame, $idleFrame, 69, 70, 71] as $index) {
            $frame = $gif['frames'][$index];
            $decoded[$index] = $this->decodeLzw(
                $frame['imageData'],
                $frame['minimumCodeSize'],
                $width * $height,
            );
            $this->assertSame($width * $height, strlen($decoded[$index]), "{$filename}: Frame {$index} ist unvollstaendig.");
        }

        $this->assertSame(
            0,
            $this->countInk($decoded[0], $gif['frames'][0]['transparentIndex']),
            "{$filename}: Die Animation muss leer beginnen.",
        );
        $this->assertSame(
            0,
            $this->countInk($decoded[1], $gif['frames'][1]['transparentIndex']),
            "{$filename}: Der Zug darf im kurzen 300-ms-Vorlauf noch nicht im Bild stehen.",
        );
        $this->assertSame(44, $starts[2], "{$filename}: Der erste Einfahrtsframe muss bei 0,44 s beginnen.");

        // Die Lokfront beginnt links ausserhalb und wird direkt im ersten
        // Bewegungsframe sichtbar. Danach muss sie ueber mehrere klar
        // getrennte Positionen bis zum 60-Prozent-Endstand
        // fahren. So faellt eine erneute unsichtbare Vorfahrt auf, die in
        // skalierten Desktop-/Mobile-Carriern wie ein ploetzlicher Endstand
        // wirken wuerde.
        $previousRight = 0;
        foreach ([2, 6, 11, 16, 21, 37, $arrivalFrame] as $motionFrame) {
            $motionBounds = $this->inkBounds(
                $decoded[$motionFrame],
                $gif['frames'][$motionFrame]['transparentIndex'],
                $width,
                $height,
                (int) floor($height * 0.55),
            );
            $this->assertNotNull($motionBounds, "{$filename}: Frame {$motionFrame} enthaelt keine sichtbare Zugposition.");
            $this->assertGreaterThan(
                $previousRight,
                $motionBounds['right'],
                "{$filename}: Die sichtbare Einfahrt bewegt sich in Frame {$motionFrame} nicht weiter.",
            );
            $previousRight = $motionBounds['right'];
        }
        $firstMotionBounds = $this->inkBounds(
            $decoded[2],
            $gif['frames'][2]['transparentIndex'],
            $width,
            $height,
            (int) floor($height * 0.55),
        );
        $this->assertNotNull($firstMotionBounds);
        $this->assertLessThanOrEqual(
            (int) ceil($width * 0.03),
            $firstMotionBounds['right'],
            "{$filename}: Der Zug startet nicht mehr knapp ausserhalb der linken Kante.",
        );

        // Fahrrauch darf waehrend der sichtbaren Einfahrt durch die spaetere
        // Schornsteinzone ziehen. Unmittelbar vor und exakt bei der Ankunft
        // muss diese Zone jedoch leer sein; erst der Folgeschritt blendet den
        // ortsfesten Idle-Rauch ein. Der Quellvertrag weiter unten prueft
        // zusaetzlich die harte Zeitgrenze in idleWolkenBei().
        foreach ([51, $arrivalFrame] as $index) {
            $this->assertSame(
                0,
                $this->countInkInRegion(
                    $decoded[$index],
                    $gif['frames'][$index]['transparentIndex'],
                    $width,
                    (int) floor($width * 0.53),
                    0,
                    (int) ceil($width * 0.61),
                    (int) floor($height * 0.55),
                ),
                "{$filename}: Idle-Rauch ist in Frame {$index} vor der Ankunft sichtbar.",
            );
        }
        $this->assertGreaterThan(
            0,
            $this->countInkInRegion(
                $decoded[$idleFrame],
                $gif['frames'][$idleFrame]['transparentIndex'],
                $width,
                (int) floor($width * 0.53),
                0,
                (int) ceil($width * 0.61),
                (int) floor($height * 0.55),
            ),
            "{$filename}: Nach der Ankunft fehlt der Idle-Rauch.",
        );

        $arrivalBounds = $this->inkBounds(
            $decoded[$arrivalFrame],
            $gif['frames'][$arrivalFrame]['transparentIndex'],
            $width,
            $height,
            (int) floor($height * 0.55),
        );
        $this->assertNotNull($arrivalBounds);
        $targetRight = (int) round($width * 0.60);
        $this->assertEqualsWithDelta(
            $targetRight,
            $arrivalBounds['right'],
            max(3, (int) ceil($width * 0.005)),
            "{$filename}: Zug endet nicht bei 60 Prozent der Leinwand.",
        );
        $this->assertSame(
            $height,
            $arrivalBounds['bottom'],
            "{$filename}: Die Radauflage endet nicht exakt an der unteren Assetkante.",
        );
        $this->assertGreaterThan(
            0,
            $this->countInkInRegion(
                $decoded[$arrivalFrame],
                $gif['frames'][$arrivalFrame]['transparentIndex'],
                $width,
                0,
                $height - 1,
                $arrivalBounds['right'],
                $height,
            ),
            "{$filename}: Die letzte Assetzeile enthaelt keine Rad-/Fahrwerkpixel.",
        );

        $expectedBodyHeight = $width === 2160 ? 86 : 29;
        $this->assertEqualsWithDelta(
            $expectedBodyHeight,
            $arrivalBounds['bottom'] - $arrivalBounds['top'],
            2,
            "{$filename}: Die auf 90 Prozent verkleinerte Zughoehe ist abgewichen.",
        );

        // Die 30-Prozent-Fassung bleibt nach der GIF-Quantisierung bewusst
        // in den beiden hellsten Tonstufen. Der Boiler mit seinem
        // einfarbigen RT-Icon wird rechts davon in einer eigenen Region
        // geprueft und darf die Koerpermessung nicht verfaelschen.
        $expectedInkIndex = str_contains($filename, 'dark') ? 1 : 2;
        $bodyPixels = $this->countIndexAtLeastInRegion(
            $decoded[$arrivalFrame],
            1,
            $width,
            0,
            (int) floor($height * 0.55),
            (int) floor($width * 0.52),
            $height,
        );
        $this->assertGreaterThan(
            $width === 2160 ? 25_000 : 2_700,
            $bodyPixels,
            "{$filename}: Der Zugkoerper mit 30 Prozent Deckkraft fehlt.",
        );
        $this->assertSame(
            0,
            $this->countIndexAtLeastInRegion(
                $decoded[$arrivalFrame],
                3,
                $width,
                0,
                (int) floor($height * 0.55),
                (int) floor($width * 0.52),
                $height,
            ),
            "{$filename}: Der Zugkoerper ist staerker als die zugesicherten 30 Prozent.",
        );

        // Das kleine, aber lesbare offizielle RT-Monogramm sitzt auf dem
        // Boiler-Paneel zwischen rund 52 und 56 Prozent der Leinwand. Zug und
        // Marke muessen nach der Quantisierung exakt dieselbe staerkste
        // Palettenstufe besitzen: Ein zusaetzliches 30-Prozent-Overlay ueber
        // dem Zug ergäbe sonst optisch rund 51 Prozent.
        $brandPixels = $this->countIndexAtLeastInRegion(
            $decoded[$arrivalFrame],
            $expectedInkIndex,
            $width,
            (int) floor($width * 0.525),
            (int) floor($height * 0.67),
            (int) ceil($width * 0.555),
            (int) ceil($height * 0.93),
        );
        $this->assertGreaterThan(
            $width === 2160 ? 390 : 35,
            $brandPixels,
            "{$filename}: Die kleine RT-Aufschrift fehlt auf der Lokseitenflaeche.",
        );
        $bodyMaximum = $this->maximumIndexInRegion(
            $decoded[$arrivalFrame],
            $width,
            0,
            (int) floor($height * 0.55),
            (int) floor($width * 0.52),
            $height,
        );
        $brandMaximum = $this->maximumIndexInRegion(
            $decoded[$arrivalFrame],
            $width,
            (int) floor($width * 0.525),
            (int) floor($height * 0.67),
            (int) ceil($width * 0.555),
            (int) ceil($height * 0.93),
        );
        $this->assertSame($expectedInkIndex, $bodyMaximum, "{$filename}: Die 30-Prozent-Zugrampe ist abgewichen.");
        $this->assertLessThanOrEqual(
            $bodyMaximum,
            $brandMaximum,
            "{$filename}: Das RT-Icon ist staerker als der Zugkoerper.",
        );
        $this->assertSame(
            0,
            $this->countIndexAtLeastInRegion(
                $decoded[$arrivalFrame],
                3,
                $width,
                (int) floor($width * 0.525),
                (int) floor($height * 0.67),
                (int) ceil($width * 0.555),
                (int) ceil($height * 0.93),
            ),
            "{$filename}: Die RT-Marke enthaelt eine unzulaessig dunkle Palettenstufe.",
        );

        // Vier sichere Innenpunkte treffen R-Querbalken, T-Querbalken,
        // T-Stamm und R-Diagonale. Sie muessen dieselbe Rampenstufe wie die
        // Zugkontur besitzen. Dadurch wuerde auch ein optisches 30 % + 30 %
        // Overlay trotz identischer Binaermaske auffallen.
        $assetScale = $width / 2160;
        $markLeft = 1152.0 * $assetScale;
        $markTop = (165.0 * $assetScale) + ($width === 720 ? 2 : 0);
        $markSize = 36.0 * $assetScale;
        $isolatedMarkRaster = '';
        for ($markRasterY = 0; $markRasterY < 12; $markRasterY++) {
            for ($markRasterX = 0; $markRasterX < 12; $markRasterX++) {
                $pixelX = (int) round($markLeft + ($markSize * ($markRasterX / 12)));
                $pixelY = (int) round($markTop + ($markSize * ($markRasterY / 12)));
                $isolatedMarkRaster .= (string) ord($decoded[$arrivalFrame][($pixelY * $width) + $pixelX]);
            }
            $isolatedMarkRaster .= "\n";
        }
        $approvedMarkRasterHash = str_contains($filename, 'dark')
            ? '2e2e255bd1e628bb871aef36159b84b7616c7b668e6f92a971ba0b4ca36834dd'
            : 'cb9bcf36dada3628a3628c8b31e72b39c13affba72992350acda21ac9e171883';
        $this->assertSame(
            $approvedMarkRasterHash,
            hash('sha256', $isolatedMarkRaster),
            "{$filename}: Das isolierte 12-x-12-RT-Raster ist abgewichen.",
        );

        foreach ([[6, 1], [2, 3], [3, 8], [9, 10]] as [$markGridX, $markGridY]) {
            $sampleX = (int) round($markLeft + ($markSize * ($markGridX / 12)));
            $sampleY = (int) round($markTop + ($markSize * ($markGridY / 12)));
            $this->assertSame(
                $expectedInkIndex,
                ord($decoded[$arrivalFrame][($sampleY * $width) + $sampleX]),
                "{$filename}: Die RT-Marke besitzt im Raster {$markGridX}/{$markGridY} nicht exakt die Zug-Deckkraft.",
            );
        }
        $gapX = (int) round($markLeft + ($markSize * (8 / 12)));
        $gapY = (int) round($markTop + ($markSize * (3 / 12)));
        $this->assertSame(
            $gif['frames'][$arrivalFrame]['transparentIndex'],
            ord($decoded[$arrivalFrame][($gapY * $width) + $gapX]),
            "{$filename}: Die negative R/T-Trennfuge ist nicht mehr frei.",
        );

        // Die Schlussframes sind ein echtes End-Hold. Das sichtbare Bild
        // bleibt stehen, statt Zug oder Rauch erneut zu starten; der auf
        // 350 ms verkuerzte Vorlauf steckt nur in diesem unsichtbaren Hold.
        $this->assertSame($decoded[69], $decoded[70], "{$filename}: End-Hold springt in Frame 70.");
        $this->assertSame($decoded[70], $decoded[71], "{$filename}: End-Hold springt in Frame 71.");
    }

    public function test_classic_png_fallbacks_share_the_new_position_and_public_bytes(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $resource = resource_path("mail-templates/assets/zug-dampf-{$theme}.png");
            $public = public_path("mail-assets/zug-dampf-{$theme}.png");

            $this->assertSame(file_get_contents($resource), file_get_contents($public));
            $size = getimagesize($resource);
            $this->assertIsArray($size);
            $this->assertSame([2160, 219], [$size[0], $size[1]]);

            $image = imagecreatefrompng($resource);
            $this->assertInstanceOf(\GdImage::class, $image);
            $background = $theme === 'light' ? [255, 255, 255] : [12, 16, 23];
            $right = 0;
            $bottom = 0;

            for ($y = (int) floor(imagesy($image) * 0.55); $y < imagesy($image); $y++) {
                for ($x = 0; $x < imagesx($image); $x++) {
                    $colour = imagecolorat($image, $x, $y);
                    $rgb = [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF];
                    if ($rgb !== $background) {
                        $right = max($right, $x + 1);
                        $bottom = max($bottom, $y + 1);
                    }
                }
            }
            imagedestroy($image);

            $this->assertEqualsWithDelta(1296, $right, 12, "{$theme}: PNG-Zug endet nicht bei 60 Prozent.");
            $this->assertSame(219, $bottom, "{$theme}: PNG-Radauflage endet nicht an der unteren Assetkante.");
        }
    }

    public function test_outlook_arrival_is_the_exact_three_to_one_derivative_of_the_main_frame(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $main = $this->parseGif(file_get_contents(resource_path("mail-templates/assets/zug-dampf-{$theme}.gif")));
            $outlook = $this->parseGif(file_get_contents(resource_path("mail-templates/assets/zug-dampf-outlook-{$theme}.gif")));
            $mainFrame = $main['frames'][52];
            $outlookFrame = $outlook['frames'][52];
            $mainPixels = $this->decodeLzw(
                $mainFrame['imageData'],
                $mainFrame['minimumCodeSize'],
                $main['width'] * $main['height'],
            );
            $outlookPixels = $this->decodeLzw(
                $outlookFrame['imageData'],
                $outlookFrame['minimumCodeSize'],
                $outlook['width'] * $outlook['height'],
            );

            // 2160 x 219 wird mit Faktor 1/3 auf 720 x 73 skaliert und in
            // der 75-px-Outlook-Leinwand um zwei Pixel nach unten gesetzt.
            // Der Vergleich umfasst die komplette sichtbare Ableitung und
            // damit auch die monochrome RT-Aufschrift.
            $sampledMain = '';
            for ($y = 0; $y < 73; $y++) {
                for ($x = 0; $x < 720; $x++) {
                    $sampledMain .= $mainPixels[(($y * 3) * 2160) + ($x * 3)];
                }
            }

            $this->assertSame(
                $sampledMain,
                substr($outlookPixels, 2 * 720, 73 * 720),
                "{$theme}: Outlook ist nicht mehr die exakte 3:1-Ableitung des Hauptframes.",
            );
        }
    }

    public function test_idle_smoke_overlay_is_a_seamless_train_free_loop(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $filename = "zug-dampf-idle-{$theme}.gif";
            $resource = file_get_contents(resource_path('mail-templates/assets/'.$filename));
            $public = file_get_contents(public_path('mail-assets/'.$filename));

            $this->assertIsString($resource);
            $this->assertIsString($public);
            $this->assertSame($resource, $public, "{$filename}: Ressourcen- und Public-Datei unterscheiden sich.");
            $this->assertLessThanOrEqual(96 * 1024, strlen($resource), "{$filename}: Rauchschleife ist zu gross.");
            $this->assertStringContainsString('NETSCAPE2.0', $resource, "{$filename}: Endlosschleife fehlt.");

            $idle = $this->parseGif($resource);
            $this->assertSame(2160, $idle['width']);
            $this->assertSame(219, $idle['height']);
            $this->assertCount(20, $idle['frames']);
            $this->assertSame(200, array_sum(array_column($idle['frames'], 'delayCs')));

            foreach ($idle['frames'] as $index => $frame) {
                $this->assertSame(10, $frame['delayCs'], "{$filename}: Frame {$index} muss 100 ms dauern.");
                $this->assertTrue($frame['transparent'], "{$filename}: Frame {$index} muss transparent sein.");
                $this->assertSame(2, $frame['disposal'], "{$filename}: Frame {$index} muss vor dem Folgeframe geraeumt werden.");

                $pixels = $this->decodeLzw(
                    $frame['imageData'],
                    $frame['minimumCodeSize'],
                    $idle['width'] * $idle['height'],
                );
                $this->assertSame($idle['width'] * $idle['height'], strlen($pixels));
                $this->assertGreaterThan(0, $this->countInk($pixels, $frame['transparentIndex']));
                $this->assertSame(
                    0,
                    $this->countInkInRegion(
                        $pixels,
                        $frame['transparentIndex'],
                        $idle['width'],
                        0,
                        (int) floor($idle['height'] * 0.65),
                        $idle['width'],
                        $idle['height'],
                    ),
                    "{$filename}: Frame {$index} enthaelt Zugkoerper-Pixel statt nur Rauch.",
                );
            }

            // Das Overlay laeuft bereits unsichtbar. Bei seinem Reveal nach
            // 13,0 s steht der 2-s-Loop bei Frame 10. Dessen obere Bildhaelfte
            // muss den gehaltenen letzten Hauptframe pixelgenau fortsetzen.
            $main = $this->parseGif(file_get_contents(resource_path("mail-templates/assets/zug-dampf-{$theme}.gif")));
            $mainFrame = $main['frames'][array_key_last($main['frames'])];
            $idleFrame = $idle['frames'][10];
            $mainPixels = $this->decodeLzw(
                $mainFrame['imageData'],
                $mainFrame['minimumCodeSize'],
                $main['width'] * $main['height'],
            );
            $idlePixels = $this->decodeLzw(
                $idleFrame['imageData'],
                $idleFrame['minimumCodeSize'],
                $idle['width'] * $idle['height'],
            );

            $this->assertSame($mainFrame['transparentIndex'], $idleFrame['transparentIndex']);
            for ($y = 0; $y < intdiv($main['height'], 2); $y++) {
                $offset = $y * $main['width'];
                $this->assertSame(
                    substr($mainPixels, $offset, $main['width']),
                    substr($idlePixels, $offset, $idle['width']),
                    "{$filename}: Rauch-Handoff springt in Zeile {$y}.",
                );
            }
        }
    }

    public function test_generator_uses_the_official_rt_icon_as_a_single_thirty_percent_train_grey_layer(): void
    {
        $generator = file_get_contents(base_path('tools/render-zug-einfahrt.mjs'));

        $this->assertIsString($generator);
        $this->assertStringContainsString("const OFFIZIELLES_RT_ICON = 'public/rt-brand/rt-logo.svg';", $generator);
        $this->assertStringContainsString("const ZUG_GRAU = '#737d89';", $generator);
        $this->assertStringContainsString('readFileSync(OFFIZIELLES_RT_ICON', $generator);
        $this->assertStringContainsString("ladeSvg(nurGlyph('R_Red'), 'rtIconR')", $generator);
        $this->assertStringContainsString("ladeSvg(nurGlyph('T_Dark'), 'rtIconT')", $generator);
        $this->assertStringContainsString('const MARKEN_GROESSE_ANTEIL = 0.34;', $generator);
        $this->assertStringContainsString('const SKALA = Number(process.env.RT_SKALA || 1.5);', $generator);
        $this->assertStringContainsString('const MARKEN_RASTER_MAIN_PX = 3;', $generator);
        $this->assertStringContainsString('const MARKEN_KONTUR_CSS_PX = 2.0;', $generator);
        $this->assertStringContainsString('const MARKEN_TRENNFUGE_CSS_PX = 2.0;', $generator);
        $this->assertStringContainsString('markeGroesse: MARKEN_GROESSE,', $generator);
        $this->assertStringContainsString('const START_X = -ZUG_BREITE;', $generator);
        $this->assertStringNotContainsString('START_X = -ZUG_BREITE *', $generator);
        $this->assertStringContainsString('const ZIEL_RECHTS = Number(process.env.RT_ZIEL_RECHTS || 0.60);', $generator);
        $this->assertStringContainsString('const ZUG_Y = HOEHE - ZUG_HOEHE;', $generator);
        $this->assertStringContainsString('const oben = OUTLOOK_HOEHE - zielHoehe;', $generator);
        $this->assertStringContainsString('const WARTE_S = Number(process.env.RT_WARTE || 0.35);', $generator);
        $this->assertStringContainsString('const ENDE_HALT_S = Number(process.env.RT_ENDE_HALT || 1.65);', $generator);
        $this->assertStringContainsString('function idleWolkenBei(t) {', $generator);
        $this->assertStringContainsString('if (t <= FAHRT_ENDE_S) return [];', $generator);
        $this->assertStringContainsString('sichtbar.push(...idleWolkenBei(t));', $generator);
        $this->assertStringContainsString("zx.globalCompositeOperation = 'destination-out';", $generator);
        $this->assertStringContainsString('hx.fillStyle = a.markenFarbe;', $generator);
        $this->assertStringContainsString('markenFarbe: ZUG_GRAU,', $generator);
        $this->assertStringContainsString('x.globalAlpha = a.deckkraft;', $generator);
        $this->assertSame(2, substr_count($generator, 'deckkraft: 0.30'));
        $this->assertStringNotContainsString('deckkraft: 0.75', $generator);
        $this->assertStringNotContainsString('markeDeckkraft', $generator);
        $this->assertStringNotContainsString('markeR:', $generator);
        $this->assertStringNotContainsString('markeT:', $generator);
        $this->assertStringNotContainsString('const istR =', $generator);
        $this->assertStringNotContainsString('0.52', $generator);
        $this->assertFileExists(public_path('rt-brand/rt-logo.svg'));
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
        $this->assertGreaterThanOrEqual(13, strlen($binary));
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
                $label = ord($binary[$offset++]);
                if ($label === 0xF9) {
                    $this->assertSame(4, ord($binary[$offset++]));
                    $controlPacked = ord($binary[$offset++]);
                    $delayCs = $this->uint16($binary, $offset);
                    $offset += 2;
                    $transparentIndex = ord($binary[$offset++]);
                    $this->assertSame(0, ord($binary[$offset++]));
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

            $this->assertSame(0x2C, $marker);
            $this->assertNotNull($control);
            $left = $this->uint16($binary, $offset);
            $top = $this->uint16($binary, $offset + 2);
            $frameWidth = $this->uint16($binary, $offset + 4);
            $frameHeight = $this->uint16($binary, $offset + 6);
            $imagePacked = ord($binary[$offset + 8]);
            $this->assertSame(0, $imagePacked & 0x40, 'Interlaced GIF-Frames werden nicht erwartet.');
            $offset += 9;

            if (($imagePacked & 0x80) !== 0) {
                $offset += 3 * (1 << (($imagePacked & 0x07) + 1));
            }

            $minimumCodeSize = ord($binary[$offset++]);
            $imageData = $this->readSubBlocks($binary, $offset);
            $frames[] = $control + compact(
                'left',
                'top',
                'frameWidth',
                'frameHeight',
                'minimumCodeSize',
                'imageData',
            );
            $frames[array_key_last($frames)]['width'] = $frameWidth;
            $frames[array_key_last($frames)]['height'] = $frameHeight;
            unset($frames[array_key_last($frames)]['frameWidth'], $frames[array_key_last($frames)]['frameHeight']);
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
            $this->assertLessThanOrEqual($length, $offset + $blockLength);
            $data .= substr($binary, $offset, $blockLength);
            $offset += $blockLength;
        }

        $this->fail('GIF-Unterblock besitzt keinen Abschluss.');
    }

    private function uint16(string $binary, int $offset): int
    {
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

    private function countInk(string $indices, int $transparentIndex): int
    {
        return $this->countInkInRegion($indices, $transparentIndex, strlen($indices), 0, 0, strlen($indices), 1);
    }

    private function countInkInRegion(
        string $indices,
        int $transparentIndex,
        int $width,
        int $left,
        int $top,
        int $right,
        int $bottom,
    ): int {
        $ink = 0;
        for ($y = $top; $y < $bottom; $y++) {
            for ($x = $left; $x < $right; $x++) {
                if (ord($indices[($y * $width) + $x]) !== $transparentIndex) {
                    $ink++;
                }
            }
        }

        return $ink;
    }

    private function countIndexAtLeastInRegion(
        string $indices,
        int $minimumIndex,
        int $width,
        int $left,
        int $top,
        int $right,
        int $bottom,
    ): int {
        $matches = 0;
        for ($y = $top; $y < $bottom; $y++) {
            for ($x = $left; $x < $right; $x++) {
                if (ord($indices[($y * $width) + $x]) >= $minimumIndex) {
                    $matches++;
                }
            }
        }

        return $matches;
    }

    private function maximumIndexInRegion(
        string $indices,
        int $width,
        int $left,
        int $top,
        int $right,
        int $bottom,
    ): int {
        $maximum = 0;
        for ($y = $top; $y < $bottom; $y++) {
            for ($x = $left; $x < $right; $x++) {
                $maximum = max($maximum, ord($indices[($y * $width) + $x]));
            }
        }

        return $maximum;
    }

    /** @return array{left: int, top: int, right: int, bottom: int}|null */
    private function inkBounds(
        string $indices,
        int $transparentIndex,
        int $width,
        int $height,
        int $minimumY,
    ): ?array {
        $left = $width;
        $top = $height;
        $right = 0;
        $bottom = 0;

        for ($y = $minimumY; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (ord($indices[($y * $width) + $x]) === $transparentIndex) {
                    continue;
                }
                $left = min($left, $x);
                $top = min($top, $y);
                $right = max($right, $x + 1);
                $bottom = max($bottom, $y + 1);
            }
        }

        return $right === 0 ? null : compact('left', 'top', 'right', 'bottom');
    }
}
