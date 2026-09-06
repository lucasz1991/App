<?php

namespace Tests\Feature;

use App\Support\Mail\SignatureBackgroundContract;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class OutlookSignatureBackgroundStyleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://app.rail-time.de', 'outlook_addin.base_url' => 'https://app.rail-time.de']);
        URL::forceRootUrl('https://app.rail-time.de');
        URL::forceScheme('https');
        Http::preventStrayRequests();
    }

    public function test_verified_background_is_internal_scoped_css_and_one_real_gif_attachment(): void
    {
        $source = URL::asset('mail-assets/zug-dampf-v19-light.gif');
        foreach (['v22', 'v23'] as $version) {
            $rows = $this->rows($source, $version);
            $scope = TrustedOutlookSignatureCss::scopeClass($rows);
            $styles = TrustedOutlookSignatureCss::style($rows, scopeClass: $scope);
            $this->assertStringContainsString('<style data-rt-outlook-signature-background-css="1">.'.$scope.' .rt-sign-cell{background-image:url(\''.$source.'\');', $styles);
            $this->assertStringContainsString('background-size:110% auto;', $styles);
            $this->assertStringContainsString('background-size:175% auto!important', $styles);
            $this->assertStringNotContainsString('<img', $styles);
            $this->assertSame(1, substr_count($styles, 'background-image:'));

            $fragment = $styles.'<div class="'.$scope.'"><table><tbody>'.$rows.'</tbody></table></div>';
            $service = app(OutlookAddinPayloadService::class);
            [$localized, $media] = (new ReflectionMethod($service, 'localizeRemoteImages'))->invoke($service, $fragment);
            $this->assertCount(1, $media, 'Internal CSS and inline fallback reuse one attachment.');
            $binary = file_get_contents(public_path('mail-assets/zug-dampf-v19-light.gif'));
            $this->assertSame($binary, base64_decode($media[0]['base64'], true));
            $this->assertStringStartsWith('GIF', $binary);
            $this->assertSame('image/gif', getimagesizefromstring($binary)['mime']);
            $this->assertSame($media[0]['name'], $media[0]['contentId']);
            $cid = 'cid:'.$media[0]['contentId'];
            $this->assertSame(2, substr_count($localized, $cid));
            $this->assertStringContainsString('<style data-rt-outlook-signature-background-css="1">.'.$scope.' .rt-sign-cell{background-image:url('.$cid.');', $localized);
            $this->assertStringNotContainsString($source, $localized);
            $this->assertStringNotContainsString('rt-sign-train-layer', $localized);
            $this->assertStringContainsString('Kontakte bleiben normaler Text', $localized);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_and_non_background_versions_do_not_receive_a_background_rule(): void
    {
        $source = URL::asset('mail-assets/zug-dampf-v19-light.gif');
        $disabled = str_replace([htmlspecialchars("url('".$source."')", ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'data-rt-signature-background="1"'], ['none', 'data-rt-signature-background="0"'], $this->rows($source));
        SignatureBackgroundContract::assertRuntime($disabled);
        foreach ([$disabled, str_replace('data-rt-artifact-version="v23"', 'data-rt-artifact-version="v25"', $this->rows($source))] as $rows) {
            $styles = TrustedOutlookSignatureCss::style($rows);
            $this->assertStringNotContainsString('data-rt-outlook-signature-background-css', $styles);
            $this->assertStringNotContainsString('background-image:', $styles);
        }
    }

    #[DataProvider('unsafeSources')]
    public function test_background_style_rejects_unsafe_sources_without_loading_them(string $source): void
    {
        $error = null;
        try {
            TrustedOutlookSignatureCss::style($this->rows($source));
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $this->assertNotNull($error, 'Untrusted image URLs must not enter trusted internal CSS.');
        Http::assertNothingSent();
    }

    public static function unsafeSources(): array
    {
        return [
            'foreign host' => ['https://attacker.invalid/train.gif'],
            'javascript' => ['javascript:alert(1)'],
            'style close' => ['https://app.rail-time.de/mail-assets/x.gif</style><script>'],
            'CSS quote escape' => ["https://app.rail-time.de/mail-assets/x.gif');color:red;/*"],
            'CSS escaped scheme' => ['https://app.rail-time.de/mail-assets/x\\27.gif'],
        ];
    }

    public function test_background_style_rejects_duplicate_carriers_and_untrusted_scope(): void
    {
        $rows = $this->rows(URL::asset('mail-assets/zug-dampf-v19-light.gif'));
        foreach ([[$rows.'<tr><td class="rt-sign-cell"></td></tr>', 'rts1234567890'], [$rows, 'rts1234567890,body']] as [$html, $scope]) {
            $error = null;
            try {
                TrustedOutlookSignatureCss::style($html, scopeClass: $scope);
            } catch (RuntimeException $exception) {
                $error = $exception;
            }
            $this->assertInstanceOf(RuntimeException::class, $error);
        }
    }

    private function rows(string $source, string $version = 'v23'): string
    {
        $style = "width:100%;padding:0;background-color:#ffffff;background-image:url('".$source."');background-repeat:no-repeat;background-position:65% bottom;background-size:110% auto;";

        return '<tr data-rt-artifact-version="'.$version.'"><td class="rt-sign-cell" width="100%" '
            .'data-rt-signature-background="1" data-rt-bg-desktop="110" data-rt-bg-tablet="150" data-rt-bg-mobile="175" '
            .'style="'.htmlspecialchars($style, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">'
            .'<table class="rt-sign-content-frame"><tbody><tr><td>Kontakte bleiben normaler Text</td></tr></tbody></table>'
            .'</td></tr><tr><td>Rechtstext</td></tr>';
    }
}
