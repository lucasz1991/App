<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class OutlookUniformBrandAssetsTest extends TestCase
{
    /** Independent frozen pins: source SHA/bytes/MIME/geometry and selected PNG SHA. */
    private const NEW_PINS = [
        'contact-location-brand-v2.png' => ['ef78563be5b36fb14bbfb9aec2f08e8ab55493648b31aecac64418693ad4a52b', 1197, 'image/png', 44, 44, 'ef78563be5b36fb14bbfb9aec2f08e8ab55493648b31aecac64418693ad4a52b'],
        'contact-phone-brand-v2.png' => ['ad1ce232f6020548515acfb00b50bfd79cfc08ba9392553419a699a6d80a207b', 1116, 'image/png', 44, 44, 'ad1ce232f6020548515acfb00b50bfd79cfc08ba9392553419a699a6d80a207b'],
        'contact-mobile-brand-v2.png' => ['237a6ef4b921b7ec54a858137f54879b21c756b3a417be245bfa2d91e4d5a6d2', 547, 'image/png', 44, 44, '237a6ef4b921b7ec54a858137f54879b21c756b3a417be245bfa2d91e4d5a6d2'],
        'contact-email-brand-v2.png' => ['74e44dae98fa9e3115df12d89fa8c979ac5afadff1fe02899dc0e22614bafaee', 972, 'image/png', 44, 44, '74e44dae98fa9e3115df12d89fa8c979ac5afadff1fe02899dc0e22614bafaee'],
        'contact-web-brand-v2.png' => ['aee56057875f1695049f7aa018988742e37a15110b175b4926ffd828ce7c255e', 1606, 'image/png', 44, 44, 'aee56057875f1695049f7aa018988742e37a15110b175b4926ffd828ce7c255e'],
        'outlook-dispo-card-caption-alpha-v2.png' => ['a7626cc065e31143682998876b17647545238bc336786f3e10c62c0613a84d7f', 4750, 'image/png', 398, 76, 'a7626cc065e31143682998876b17647545238bc336786f3e10c62c0613a84d7f'],
        'outlook-dispo-card-caption-alpha-v3.png' => ['4a55285090810c491f92cbf1f8ffb928ec50daef77ddc2b36a1d3b4f587151ce', 6530, 'image/png', 430, 100, '4a55285090810c491f92cbf1f8ffb928ec50daef77ddc2b36a1d3b4f587151ce'],
        'outlook-hotline-mail-alpha-v2.png' => ['30b48b18c0c4c87b5126db3849653e924234e149a6084e1a1eba5235277477e7', 1644, 'image/png', 126, 102, '30b48b18c0c4c87b5126db3849653e924234e149a6084e1a1eba5235277477e7'],
        'outlook-hotline-phone-alpha-v2.png' => ['b1db855fc30a03e64095dca01b45e5c67315c136762a8356e07c5d805f66ca13', 2894, 'image/png', 126, 102, 'b1db855fc30a03e64095dca01b45e5c67315c136762a8356e07c5d805f66ca13'],
        'outlook-social-whatsapp-v1.png' => ['97a608a8f92464edf98f5374b503f0eb1943a73aaa2e7eb47cc78b85b824227d', 1625, 'image/png', 60, 60, '97a608a8f92464edf98f5374b503f0eb1943a73aaa2e7eb47cc78b85b824227d'],
        'outlook-social-instagram-v1.png' => ['3221c628235c1a0df2851f901fa10fd246d674d7f6a6f6e3345d86b171bc0d09', 3662, 'image/png', 60, 60, '3221c628235c1a0df2851f901fa10fd246d674d7f6a6f6e3345d86b171bc0d09'],
        'outlook-dispo-mail-kraeftig-v3.png' => ['19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1', 859, 'image/png', 72, 72, '19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b1'],
        'outlook-dispo-telefon-kraeftig-v3.png' => ['1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a', 1333, 'image/png', 72, 72, '1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a'],
        'outlook-dispo-whatsapp-weiss-v3.png' => ['f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9', 1515, 'image/png', 72, 72, 'f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9'],
        'zug-dampf-outlook-brand-v2-light.gif' => ['6aeef403af2bd639b8fee076312a37b6b3e27f22c148ae835ca8d5d43fcc3a2e', 182772, 'image/gif', 695, 151, '063984f410dce4d35f422c19aac565d8d5b59c95876f2db1462de487e33be387'],
        'zug-dampf-outlook-brand-v2-light.png' => ['063984f410dce4d35f422c19aac565d8d5b59c95876f2db1462de487e33be387', 7984, 'image/png', 695, 151, '063984f410dce4d35f422c19aac565d8d5b59c95876f2db1462de487e33be387'],
        'zug-dampf-outlook-brand-v2-dark.gif' => ['92913c81a7a44a28560e23f61907ae38b992b31dd10494ab4f3d9d13fc20ca24', 137334, 'image/gif', 695, 151, '19d3969cd41693aaa35c54503db2c7b412f2b2dca2ed65eb8609019af6840d61'],
        'zug-dampf-outlook-brand-v2-dark.png' => ['19d3969cd41693aaa35c54503db2c7b412f2b2dca2ed65eb8609019af6840d61', 4988, 'image/png', 695, 151, '19d3969cd41693aaa35c54503db2c7b412f2b2dca2ed65eb8609019af6840d61'],
        'zug-dampf-outlook-brand-v2-light-mirrored.gif' => ['11ded023b6a25d319c87a8fb25f105c98e8b58a5f9b0fcb711cb6ff3dc10bee6', 182778, 'image/gif', 695, 151, 'f1a2c9e0ba241c210ba9a1331dd406f7fce8d893e3b2b89528f33999853498da'],
        'zug-dampf-outlook-brand-v2-light-mirrored.png' => ['f1a2c9e0ba241c210ba9a1331dd406f7fce8d893e3b2b89528f33999853498da', 8056, 'image/png', 695, 151, 'f1a2c9e0ba241c210ba9a1331dd406f7fce8d893e3b2b89528f33999853498da'],
        'zug-dampf-outlook-brand-v2-dark-mirrored.gif' => ['c5ae93d54d0d2ed7a30ea3b8402a5598f57e25271d267b37719f899361308c5a', 136792, 'image/gif', 695, 151, 'e0cd25a52aa69159b10af004d407994cb2c6673a0c459d67856232d1e9a1ef63'],
        'zug-dampf-outlook-brand-v2-dark-mirrored.png' => ['e0cd25a52aa69159b10af004d407994cb2c6673a0c459d67856232d1e9a1ef63', 5058, 'image/png', 695, 151, 'e0cd25a52aa69159b10af004d407994cb2c6673a0c459d67856232d1e9a1ef63'],

    ];

    public function test_the_original_43_pins_are_unchanged_and_only_22_known_sources_are_appended(): void
    {
        $assets = $this->catalog();
        self::assertCount(65, $assets);
        self::assertSame('ca35d43932cbe01b355bc545bc7ed153a870c98eed21208688cac3ab8a72d8ef', hash('sha256', json_encode(array_slice($assets, 0, 43, true), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        $new = array_keys(array_slice($assets, 43, null, true));
        $expected = array_keys(self::NEW_PINS);
        sort($new);
        sort($expected);
        self::assertSame($expected, $new);
        $fingerprint = OutlookStaticLinkedTestDelivery::fingerprint();
        self::assertCount(114, $fingerprint);
        self::assertCount(65, array_filter(array_keys($fingerprint), static fn (string $key): bool => str_starts_with($key, 'map:')));
        self::assertCount(49, array_filter(array_keys($fingerprint), static fn (string $key): bool => str_starts_with($key, '/m/')));
    }

    #[DataProvider('newAssets')]
    public function test_each_new_source_resource_copy_and_public_route_has_the_exact_frozen_material(string $filename, array $pin): void
    {
        [$sha, $bytes, $mime, $width, $height, $pngSha] = $pin;
        $root = dirname(__DIR__, 2);
        $source = $root.'/public/mail-assets/'.$filename;
        $backup = $root.'/resources/mail-templates/assets/'.$filename;
        self::assertFalse(is_link($source));
        self::assertFalse(is_link($backup));
        $binary = file_get_contents($source);
        self::assertSame($bytes, strlen($binary));
        self::assertSame($sha, hash('sha256', $binary));
        self::assertSame($binary, file_get_contents($backup));
        $image = getimagesizefromstring($binary);
        self::assertSame([$width, $height], array_slice($image, 0, 2));
        self::assertSame($mime, $image['mime']);
        $url = '/m/'.substr($pngSha, 0, 10).'.png';
        self::assertSame([$sha, $bytes, $mime, $width, $height, $url, $pngSha], $this->catalog()[$filename]);
        $output = $root.'/public'.$url;
        self::assertFalse(is_link($output));
        self::assertSame($pngSha, hash_file('sha256', $output));
        $poster = preg_replace('/\.gif$/', '.png', $filename);
        self::assertSame(file_get_contents($root.'/public/mail-assets/'.$poster), file_get_contents($output));
        $selected = getimagesize($output);
        self::assertSame([$width, $height], array_slice($selected, 0, 2));
        self::assertSame('image/png', $selected['mime']);
    }

    #[DataProvider('foregroundAssets')]
    public function test_alpha_foregrounds_have_pure_brand_ink_even_at_antialiased_edges(string $filename, array $rgb): void
    {
        self::assertTrue(extension_loaded('gd'), 'GD is required to inspect actual decoded PNG pixels.');
        $image = imagecreatefrompng(dirname(__DIR__, 2).'/public/mail-assets/'.$filename);
        $transparent = $opaque = $antialiased = $wrongInk = 0;
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($pixel['alpha'] === 127) {
                    $transparent++;
                } else {
                    $wrongInk += [$pixel['red'], $pixel['green'], $pixel['blue']] !== $rgb ? 1 : 0;
                    if ($pixel['alpha'] === 0) {
                        $opaque++;
                    } else {
                        $antialiased++;
                    }
                }
            }
        }
        self::assertGreaterThan(0, $transparent, 'A red or white opaque matte must not be reintroduced.');
        self::assertGreaterThan(0, $opaque, 'The intended visible foreground must survive.');
        self::assertGreaterThan(0, $antialiased, 'Native antialiased outlines must survive.');
        self::assertSame(0, $wrongInk, 'Semitransparent edges must carry the foreground color, without a colored halo.');
    }

    #[DataProvider('contactAssets')]
    public function test_contact_recoloring_preserves_the_original_native_alpha_geometry(string $kind): void
    {
        $root = dirname(__DIR__, 2).'/public/mail-assets/';
        $before = imagecreatefrompng($root.'contact-'.$kind.'.png');
        $after = imagecreatefrompng($root.'contact-'.$kind.'-brand-v2.png');
        self::assertSame([44, 44], [imagesx($after), imagesy($after)]);
        $different = 0;
        for ($y = 0; $y < 44; $y++) {
            for ($x = 0; $x < 44; $x++) {
                $a = imagecolorsforindex($before, imagecolorat($before, $x, $y));
                $b = imagecolorsforindex($after, imagecolorat($after, $x, $y));
                $different += $a['alpha'] !== $b['alpha'] ? 1 : 0;
            }
        }
        self::assertSame(0, $different, 'Only foreground RGB changes; every alpha position stays intact.');
    }

    public static function newAssets(): iterable
    {
        foreach (self::NEW_PINS as $filename => $pin) {
            yield $filename => [$filename, $pin];
        }
    }

    public static function foregroundAssets(): iterable
    {
        foreach (['location', 'phone', 'mobile', 'email', 'web'] as $kind) {
            yield 'red '.$kind => ['contact-'.$kind.'-brand-v2.png', [233, 0, 50]];
        }
        foreach (['outlook-dispo-card-caption-alpha-v2.png', 'outlook-hotline-mail-alpha-v2.png', 'outlook-hotline-phone-alpha-v2.png', 'outlook-dispo-card-caption-alpha-v3.png', 'outlook-dispo-mail-kraeftig-v3.png', 'outlook-dispo-telefon-kraeftig-v3.png', 'outlook-dispo-whatsapp-weiss-v3.png'] as $filename) {
            yield 'white '.$filename => [$filename, [255, 255, 255]];
        }
    }

    public static function contactAssets(): iterable
    {
        foreach (['location', 'phone', 'mobile', 'email', 'web'] as $kind) {
            yield $kind => [$kind];
        }
    }

    private function catalog(): array
    {
        return (new ReflectionClass(OutlookStaticLinkedTestDelivery::class))->getConstant('ASSETS');
    }
}
