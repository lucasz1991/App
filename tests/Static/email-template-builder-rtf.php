<?php

declare(strict_types=1);

namespace Illuminate\Support {
    final class Str
    {
        public static function random(int $length): string
        {
            return str_repeat('a', $length);
        }
    }
}

namespace {
    use App\Support\EmailTemplateBuilder;
    use App\Support\Mail\SignatureTrainCarrier;

    function resource_path(string $path = ''): string
    {
        return dirname(__DIR__, 2).'/resources'.($path === '' ? '' : '/'.$path);
    }

    require dirname(__DIR__, 2).'/app/Support/Mail/CssSemantic.php';
    require dirname(__DIR__, 2).'/app/Support/Mail/SignatureTrainCarrier.php';
    require dirname(__DIR__, 2).'/app/Support/EmailTemplateBuilder.php';

    final class IsolatedEmailTemplateBuilder extends EmailTemplateBuilder
    {
        public function __construct() {}

        public function profileValues(): array
        {
            return [
                'VORNAME_NACHNAME' => 'Mara Beispiel',
                'E_MAIL' => 'mara@example.test',
                'FIRMENNAME' => 'RT Rail Time GmbH',
            ];
        }

        protected function buildPlainBody(): string
        {
            return 'RailTime plain body';
        }

        protected function buildEmailHtml(
            bool $inlineImages,
            string $theme = 'light',
            bool $animatedSignature = false,
            ?string $playbackNonce = null,
            bool $staticAnimations = false,
            bool $cidOutlookImages = false,
        ): string {
            if ($inlineImages || ! $cidOutlookImages) {
                throw new RuntimeException('EML did not select the CID Outlook image path.');
            }

            return '<img src="cid:railtime-logo"><img src="cid:railtime-logo-still">'
                .'<img src="cid:railtime-train"><img src="cid:railtime-train-still">';
        }

        public function eml(string $theme): string
        {
            return $this->buildEml($theme);
        }
    }

    $reflection = new ReflectionClass(EmailTemplateBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('buildOutlookRtf');
    $method->setAccessible(true);

    $rtf = $method->invoke($builder, 'BMP é · Astral 😀 𝄞');
    $expected = [
        '\\u233?',       // é
        '\\u183?',       // ·
        '\\u-10179?',    // U+1F600 high surrogate D83D
        '\\u-8704?',     // U+1F600 low surrogate DE00
        '\\u-10188?',    // U+1D11E high surrogate D834
        '\\u-8930?',     // U+1D11E low surrogate DD1E
    ];

    foreach ($expected as $escape) {
        if (! str_contains($rtf, $escape)) {
            fwrite(STDERR, "Missing RTF escape: {$escape}\n");
            exit(1);
        }
    }

    if (str_contains($rtf, '\\u62976?') || str_contains($rtf, '\\u53534?')) {
        fwrite(STDERR, "Found invalid non-BMP codepoint escape.\n");
        exit(1);
    }

    $plainFormatter = $reflection->getMethod('formatPlainSignature');
    $plainFormatter->setAccessible(true);
    $plainSignature = $plainFormatter->invoke(null, [
        'VORNAME_NACHNAME' => 'Mara Beispiel',
        'POSITION' => 'Wagenmeisterin',
        'DURCHWAHL' => '04171 546803',
        'MOBIL' => '',
        'E_MAIL' => 'info@rail-time.de',
        'FIRMENNAME' => 'RT Rail Time GmbH',
        'FIRMENSTRASSE' => 'Borsteler Weg 29–31',
        'FIRMEN_PLZ_ORT' => '21423 Winsen (Luhe)',
        'FIRMENLAND' => 'Deutschland',
        'FIRMEN_TELEFON' => '04171 546803',
        'FIRMEN_EMAIL' => 'info@rail-time.de',
        'FIRMEN_WEBSITE' => 'https://www.rail-time.de',
        'GESCHAEFTSFUEHRUNG' => 'Beispiel Name',
        'REGISTERGERICHT' => 'Amtsgericht Tostedt',
        'HRB' => '204604',
        'UST_ID' => 'DE169651368',
        'STEUERNUMMER' => '',
    ]);

    if (substr_count($plainSignature, '04171 546803') !== 1) {
        fwrite(STDERR, "Plain signature duplicated the normalized company phone.\n");
        exit(1);
    }
    foreach (['W rail-time.de', 'Geschäftsführung:', 'Diese E-Mail kann vertrauliche Informationen enthalten.'] as $requiredText) {
        if (! str_contains($plainSignature, $requiredText)) {
            fwrite(STDERR, "Plain signature is incomplete: {$requiredText}\n");
            exit(1);
        }
    }

    $eml = (new IsolatedEmailTemplateBuilder)->eml('light');
    foreach (['railtime-logo', 'railtime-logo-still', 'railtime-train', 'railtime-train-still'] as $contentId) {
        if (substr_count($eml, "Content-ID: <{$contentId}>\r\n") !== 1) {
            fwrite(STDERR, "CID part is missing or duplicated: {$contentId}\n");
            exit(1);
        }
    }

    if (! str_contains($eml, 'Content-Type: image/gif; name="zug-dampf-light.gif"')) {
        fwrite(STDERR, "Train GIF MIME part is missing.\n");
        exit(1);
    }
    if (! str_contains($eml, 'Content-Type: image/png; name="zug-dampf-light.png"')) {
        fwrite(STDERR, "Train still MIME part is missing.\n");
        exit(1);
    }
    if (preg_match(
        '/Content-Type: text\/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--=_rt_rel_/s',
        $eml,
        $emlHtmlMatch,
    ) !== 1) {
        fwrite(STDERR, "EML HTML part is missing.\n");
        exit(1);
    }
    $emlHtml = base64_decode((string) preg_replace('/\s+/', '', $emlHtmlMatch[1]), true);
    if (! is_string($emlHtml)
        || substr_count($emlHtml, 'src="cid:railtime-train"') !== 1
        || substr_count($emlHtml, 'src="cid:railtime-train-still"') !== 1) {
        fwrite(STDERR, "EML HTML does not reference both train CID parts exactly once.\n");
        exit(1);
    }

    $browserTrainMethod = $reflection->getMethod('placeBrowserCopyTrainBehindContent');
    $browserTrainMethod->setAccessible(true);
    $browserTrainFixture = <<<'HTML'
<!doctype html><html><body><table role="presentation" style="border-top:5px solid #e4002b;">
<tr><td class="rt-sign-cell rt-sign-train-background" data-rt-train-background data-rt-train-align="left" data-rt-train-size="100" data-rt-train-mobile="train" style="overflow:hidden;background-image:url(https://app.rail-time.de/mail-assets/signatur-raster-light.png),url(https://app.rail-time.de/mail-assets/signatur-marke-light.png),linear-gradient(rgba(0,0,0,0),rgba(0,0,0,0)),url('https://app.rail-time.de/mail-assets/zug-dampf-light.gif');background-repeat:repeat,no-repeat,no-repeat,no-repeat;background-position:left top,right center,center center,left bottom;background-size:64px 64px,auto 100%,100% 100%,100% auto;"><div class="rt-sign-stage" style="position:relative;overflow:hidden;"><!--[if mso]><v:rect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" class="rt-sign-train-mso" fill="true" stroke="false" o:allowincell="true" style="position:absolute;left:0;bottom:0;width:720px;height:53px;z-index:0;"><v:fill type="frame" src="https://app.rail-time.de/mail-assets/zug-dampf-light.png" aspect="atmost" position="0,1" /></v:rect><![endif]--><table><tr><td class="rt-sign-identity"><img src="https://app.rail-time.de/mail-assets/contact-email.png"></td><td class="rt-sign-logo"><img src="https://app.rail-time.de/mail-assets/logo.gif"></td></tr></table><div class="rt-sign-train-layer" data-rt-layer-train style="position:absolute;left:0;bottom:0;mso-hide:all;"></div></div></td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td>Legal</td></tr>
</table></body></html>
HTML;
    $browserTrainSource = 'https://app.rail-time.de/mail-assets/zug-dampf-light.gif';
    $browserTrain = $browserTrainMethod->invoke(null, $browserTrainFixture, $browserTrainSource);
    if ($browserTrain !== $browserTrainFixture
        || substr_count($browserTrain, 'class="rt-sign-train"') !== 0
        || substr_count($browserTrain, 'class="rt-sign-train-mso"') !== 1
        || substr_count($browserTrain, 'data-rt-train-background') !== 1
        || substr_count($browserTrain, 'class="rt-sign-stage"') !== 1
        || substr_count($browserTrain, "url('{$browserTrainSource}')") !== 1
        || substr_count($browserTrain, 'data-rt-train ') !== 0
        || substr_count($browserTrain, 'src="https://app.rail-time.de/mail-assets/zug-dampf-light.png"') !== 1
        || preg_match('/<div class="rt-sign-stage"[^>]*>\s*<!--\[if mso\]><v:rect\b[^>]*class="rt-sign-train-mso"[^>]*>\s*<v:fill\b[^>]*zug-dampf-light\.png[^>]*>\s*<\/v:rect><!\[endif\]-->/', $browserTrain) !== 1
        || preg_match('/<!--\[if mso\]><tr><td\b[^>]*rt-sign-train-mso/', $browserTrain) === 1) {
        fwrite(STDERR, "Browser copy train did not preserve the four-layer background plus in-stage MSO contract.\n");
        exit(1);
    }

    // Schema 12 wird vor dem Seeder nur laufzeitlokal in die Stage gehoben.
    // Erlaubte data-* Decoys duerfen dabei weder das echte class/style/width-
    // Attribut treffen noch den alten background-Entferner umlenken.
    $legacyTrainFixture = <<<'HTML'
<tr><td data-class="rt-sign-cell" data-background="decoy" class="rt-sign-cell" background="{{TRAIN_STILL_SRC}}" style="padding:0;"><div data-class="rt-sign-train-layer" data-style="decoy">Decoy</div><img data-class="rt-sign-train" src="decoy.gif" alt=""><table><tr><td>Inhalt</td></tr></table><div data-style="decoy" class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;height:100%;margin:0;overflow:hidden;z-index:0;font-size:0;line-height:0;text-align:left;"><img data-width="decoy" data-style="decoy" class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="1815" alt="" style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto;margin:0;border:0;outline:none;text-decoration:none;"></div></td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td>Legal</td></tr>
HTML;
    $legacyTrain = SignatureTrainCarrier::projectAsImage(
        $legacyTrainFixture,
        'https://app.rail-time.de/mail-assets/zug-dampf-light.gif',
    );
    $legacyTrain = SignatureTrainCarrier::withoutLegacyBackgroundAttribute($legacyTrain);
    if (substr_count($legacyTrain, 'class="rt-sign-stage"') !== 1
        || substr_count($legacyTrain, 'mso-hide:all;') !== 2
        || substr_count($legacyTrain, 'width="720"') !== 1
        || ! str_contains($legacyTrain, 'data-style="decoy"')
        || ! str_contains($legacyTrain, 'data-width="decoy"')
        || ! str_contains($legacyTrain, 'data-background="decoy"')
        || str_contains($legacyTrain, ' background=')) {
        fwrite(STDERR, "Legacy train hardening changed a decoy instead of the real attribute.\n");
        exit(1);
    }

    // Der gespeicherte/editorische IMG-Vertrag wird erst fuer die tatsaechliche
    // Mail- und Preview-Ausgabe in die vierte, hoehenneutrale Background-Ebene
    // projiziert. Der Idle-Rauch bleibt dabei im absoluten, leeren Layer; der
    // Outlook-Fallback sitzt als VML in derselben Stage und nie in einer TR.
    $runtimeTrainFixture = <<<'HTML'
<tr><td class="rt-sign-cell" style="padding:0;overflow:hidden;background-image:url(https://app.rail-time.de/mail-assets/signatur-raster-light.png),url(https://app.rail-time.de/mail-assets/signatur-marke-light.png),linear-gradient(rgba(0,0,0,0),rgba(0,0,0,0));background-repeat:repeat,no-repeat,no-repeat;background-position:left top,right center,center center;background-size:64px 64px,auto 100%,100% 100%;"><div class="rt-sign-stage" style="position:relative;overflow:hidden;"><table><tr><td>Inhalt</td></tr></table><div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;margin:0;overflow:hidden;z-index:0;font-size:0;line-height:0;text-align:left;mso-hide:all;"><img class="rt-sign-train" data-rt-train src="https://app.rail-time.de/mail-assets/zug-dampf-light.gif" width="720" alt="" style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto;margin:0;border:0;outline:none;text-decoration:none;mso-hide:all;"></div></div></td></tr><!-- RT_SIGNATURE_MAIN_END -->
HTML;
    $runtimeBackground = SignatureTrainCarrier::withIdleOverlay(
        $runtimeTrainFixture,
        'https://app.rail-time.de/mail-assets/zug-dampf-idle-light.gif',
    );
    $runtimeBackground = SignatureTrainCarrier::projectAsRuntimeBackground($runtimeBackground);
    SignatureTrainCarrier::assertRuntimeBackground(
        $runtimeBackground,
        'https://app.rail-time.de/mail-assets/zug-dampf-light.gif',
    );
    $unsafeRelativeWasRejected = false;
    try {
        SignatureTrainCarrier::withMsoFallback($runtimeBackground, '../zug-dampf-light.png');
    } catch (RuntimeException) {
        $unsafeRelativeWasRejected = true;
    }
    if (! $unsafeRelativeWasRejected) {
        fwrite(STDERR, "Outlook VML accepted an unsafe relative asset path.\n");
        exit(1);
    }

    $runtimeTrain = SignatureTrainCarrier::withMsoFallback(
        $runtimeBackground,
        'RailTime_files/zug-dampf-light.png',
    );
    $runtimeChecks = [
        'carrier class' => substr_count($runtimeTrain, 'class="rt-sign-cell rt-sign-train-background"') === 1,
        'carrier marker' => substr_count($runtimeTrain, 'data-rt-train-background') === 1,
        'fourth image' => substr_count($runtimeTrain, 'url(&apos;https://app.rail-time.de/mail-assets/zug-dampf-light.gif&apos;)') === 1,
        'four repeats' => substr_count($runtimeTrain, 'background-repeat:repeat,no-repeat,no-repeat,no-repeat') === 1,
        'four positions' => substr_count($runtimeTrain, 'background-position:left top,right center,center center,left bottom') === 1,
        'four sizes' => substr_count($runtimeTrain, 'background-size:64px 64px,auto 100%,100% 100%,100% auto') === 1,
        'idle overlay' => substr_count($runtimeTrain, 'data-rt-train-idle-overlay') === 1,
        'no main image class' => substr_count($runtimeTrain, 'class="rt-sign-train"') === 0,
        'no main image marker' => substr_count($runtimeTrain, 'data-rt-train ') === 0,
        'one VML shape' => substr_count($runtimeTrain, 'class="rt-sign-train-mso"') === 1,
        'relative VML source' => substr_count($runtimeTrain, 'src="RailTime_files/zug-dampf-light.png"') === 1,
        'VML inside stage' => preg_match('/<div class="rt-sign-stage"[^>]*>\s*<!--\[if mso\]><v:rect\b/', $runtimeTrain) === 1,
        'no MSO table row' => preg_match('/<!--\[if mso\]><tr><td\b[^>]*rt-sign-train-mso/', $runtimeTrain) !== 1,
    ];
    foreach ($runtimeChecks as $contract => $passed) {
        if (! $passed) {
            fwrite(STDERR, "Runtime train projection failed: {$contract}.\n");
            exit(1);
        }
    }

    $readmeMethod = $reflection->getMethod('buildOutlookReadme');
    $readmeMethod->setAccessible(true);
    $readme = $readmeMethod->invoke(
        $builder,
        'RailTime-Signatur-hell-mara-beispiel',
        'RailTime-Signatur-hell-mara-beispiel_files',
        $browserTrain,
    );
    foreach ([
        'id="railtime-signature-copy-frame"',
        'id="railtime-copy-signature"',
        'id="railtime-select-signature"',
        "execCommand('copy')",
        'body > table[role="presentation"]',
        '.rt-copy-preview iframe { display:block; width:min(720px,100%); height:1px;',
        "frame.style.height = '1px';",
    ] as $requiredCopyContract) {
        if (! str_contains($readme, $requiredCopyContract)) {
            fwrite(STDERR, "Browser copy README is incomplete: {$requiredCopyContract}\n");
            exit(1);
        }
    }

    fwrite(STDOUT, "RTF, EML CID and Outlook browser-copy tests passed.\n");
}
