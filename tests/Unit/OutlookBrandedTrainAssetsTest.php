<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutlookBrandedTrainAssetsTest extends TestCase
{
    public function test_historical_catalog_is_unchanged_and_new_assets_are_only_appended(): void
    {
        $assets = (new \ReflectionClass(OutlookStaticLinkedTestDelivery::class))->getConstant('ASSETS');
        self::assertCount(43, $assets);
        self::assertSame('ac9308c1dc9cd08f6aa196cd482a1a4adeeae70111e965f1952f316b1fa8f7d4', hash('sha256', json_encode(array_slice($assets, 0, 42, true), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        self::assertSame('9111e4eea3bf1463da22574cb2e1112e444afa6d73fb9ce4a6e9be94bb507b7a', hash('sha256', json_encode(array_slice($assets, 0, 33, true), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }

    #[DataProvider('newAssets')]
    public function test_new_immutable_media_copies_and_static_targets_match_exact_pins(string $filename, string $sha, int $width, int $height): void
    {
        $root = dirname(__DIR__, 2);
        $public = file_get_contents($root.'/public/mail-assets/'.$filename);
        self::assertSame($sha, hash('sha256', $public));
        self::assertSame($public, file_get_contents($root.'/resources/mail-templates/assets/'.$filename));
        self::assertSame([$width, $height], array_slice(getimagesizefromstring($public), 0, 2));

        $assets = (new \ReflectionClass(OutlookStaticLinkedTestDelivery::class))->getConstant('ASSETS');
        $pin = $assets[$filename];
        self::assertSame($sha, $pin[0]);
        self::assertSame(strlen($public), $pin[1]);
        self::assertSame($pin[6], hash_file('sha256', $root.'/public'.$pin[5]));
        self::assertSame('/m/'.substr($pin[6], 0, 10).'.png', $pin[5]);
        $pngFilename = preg_replace('/\.gif$/', '.png', $filename);
        self::assertSame(file_get_contents($root.'/public/mail-assets/'.$pngFilename), file_get_contents($root.'/public'.$pin[5]));
    }

    public function test_reproducible_copy_manifest_and_runtime_catalog_have_the_same_exact_inventory(): void
    {
        $builder = file_get_contents(dirname(__DIR__, 2).'/scripts/mail/build_static_linked_test_assets.py');
        self::assertSame(1, preg_match('/MANIFEST = json.loads\(r"""(.*?)"""\)/s', $builder, $match));
        $manifest = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $assets = (new \ReflectionClass(OutlookStaticLinkedTestDelivery::class))->getConstant('ASSETS');
        self::assertCount(43, $manifest['sources']);
        self::assertCount(31, $manifest['targets']);
        foreach ($manifest['sources'] as $source) {
            self::assertSame([$source['sha256'], $source['bytes'], $source['mime'], ...$source['dimensions'], $source['output'], $source['png_sha256']], $assets[$source['source']]);
        }
    }

    public static function newAssets(): iterable
    {
        yield 'light PNG' => ['zug-dampf-outlook-brand-v1-light.png', '0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258', 695, 151];
        yield 'light GIF' => ['zug-dampf-outlook-brand-v1-light.gif', '47007453902679fbc96eda87bb8701db7f701b2c340d83e9561fa68c4dbd503d', 695, 151];
        yield 'dark PNG' => ['zug-dampf-outlook-brand-v1-dark.png', 'd8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3', 695, 151];
        yield 'dark GIF' => ['zug-dampf-outlook-brand-v1-dark.gif', '243786c7e426e2f06165858c8a1883cfd5598b72df1247c7cc2b3ff6e41220a5', 695, 151];
        yield 'mirrored light PNG' => ['zug-dampf-outlook-brand-v1-light-mirrored.png', 'b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792', 695, 151];
        yield 'mirrored light GIF' => ['zug-dampf-outlook-brand-v1-light-mirrored.gif', '67d1600699cad2f1f2af69ea59a9d88a7085f26556966f083ab615401e430d39', 695, 151];
        yield 'mirrored dark PNG' => ['zug-dampf-outlook-brand-v1-dark-mirrored.png', '917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b', 695, 151];
        yield 'mirrored dark GIF' => ['zug-dampf-outlook-brand-v1-dark-mirrored.gif', 'ed21ba08d9d32ede363a30d13d182aa802b426330fd0110c4b0f50b11c960768', 695, 151];
        yield 'transparent caption crop' => ['outlook-hotline-label-inktop-v1.png', '37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a', 552, 77];
        yield 'opaque white dispatch caption' => ['outlook-dispo-card-caption-v1.png', '464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7', 398, 76];
    }
}
