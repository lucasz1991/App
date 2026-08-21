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
                .'<img src="cid:railtime-train"><img src="cid:railtime-train-idle">';
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
    foreach (['railtime-logo', 'railtime-logo-still', 'railtime-train', 'railtime-train-idle', 'railtime-train-still'] as $contentId) {
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
        || substr_count($emlHtml, 'src="cid:railtime-train-idle"') !== 1
        || substr_count($emlHtml, 'src="cid:railtime-train-still"') !== 0) {
        fwrite(STDERR, "EML HTML does not preserve the IMG-only train contract.\n");
        exit(1);
    }

    $browserTrainMethod = $reflection->getMethod('validateBrowserCopyTrainImages');
    $browserTrainMethod->setAccessible(true);
    $browserTrainFixture = <<<'HTML'
    <!doctype html><html><body><table role="presentation" style="border-top:5px solid #e4002b;">
    <tr><td class="rt-sign-cell" style="padding:0;overflow:hidden;background-image:linear-gradient(rgba(0,0,0,0),rgba(0,0,0,0));background-repeat:no-repeat;background-position:center center;background-size:100% 100%;"><div class="rt-sign-stage" style="position:relative;overflow:hidden;"><table style="position:relative;z-index:1;"><tr><td class="rt-sign-identity"><img src="https://app.rail-time.de/mail-assets/contact-email.png"></td><td class="rt-sign-logo"><img src="https://app.rail-time.de/mail-assets/logo.gif"></td></tr></table><div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;margin:0 auto 0 0;overflow:hidden;z-index:0;font-size:0;line-height:0;text-align:left;"><span class="rt-train-idle-overlay" data-rt-train-idle-overlay style="position:absolute;left:0;right:auto;top:auto;bottom:0;display:block;width:100%;max-width:none;height:0;max-height:0;margin:0;overflow:hidden;z-index:1;font-size:0;line-height:0;text-align:left;opacity:0;visibility:hidden;animation:rt-train-idle-reveal 1ms step-start 13s forwards;mso-hide:all;"><img class="rt-train-idle-image" data-rt-train-idle-image src="https://app.rail-time.de/mail-assets/zug-dampf-idle-light.gif" width="720" alt="" style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;z-index:1;mso-hide:all;"></span><img class="rt-sign-train" data-rt-train src="https://app.rail-time.de/mail-assets/zug-dampf-light.gif" width="720" alt="" style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;"></div></div></td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td>Legal</td></tr>
    </table></body></html>
    HTML;
    $browserTrainSource = 'https://app.rail-time.de/mail-assets/zug-dampf-light.gif';
    $browserIdleSource = 'https://app.rail-time.de/mail-assets/zug-dampf-idle-light.gif';
    $browserTrain = $browserTrainMethod->invoke(
        null,
        $browserTrainFixture,
        $browserTrainSource,
        $browserIdleSource,
    );
    if ($browserTrain !== $browserTrainFixture
        || substr_count($browserTrain, 'class="rt-sign-train"') !== 1
        || substr_count($browserTrain, 'class="rt-sign-train-mso"') !== 0
        || substr_count($browserTrain, 'data-rt-train-background') !== 0
        || substr_count($browserTrain, 'class="rt-sign-stage"') !== 1
        || substr_count($browserTrain, 'src="'.$browserTrainSource.'"') !== 1
        || substr_count($browserTrain, 'src="'.$browserIdleSource.'"') !== 1
        || substr_count($browserTrain, 'data-rt-train ') !== 1
        || substr_count($browserTrain, 'data-rt-train-idle-image') !== 1
        || ! str_contains($browserTrain, 'style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;')
        || ! str_contains($browserTrain, 'style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:none;')
        || preg_match('/<v:(?:rect|fill)\b/i', $browserTrain) === 1
        || preg_match('/<!--\[if mso\]><tr><td\b[^>]*rt-sign-train-mso/', $browserTrain) === 1) {
        fwrite(STDERR, "Browser copy train did not preserve the height-neutral IMG contract.\n");
        exit(1);
    }

    // Schema 12 wird vor dem Seeder nur laufzeitlokal in die Stage gehoben.
    // Die geschuetzten Layer-/IMG-Attribute bleiben dabei exakt. Decoys am
    // aeusseren Carrier duerfen den background-Entferner nicht umlenken.
    $legacyTrainFixture = <<<'HTML'
<tr><td data-class="rt-sign-cell" data-background="decoy" class="rt-sign-cell" background="{{TRAIN_STILL_SRC}}" style="padding:0;"><table style="position:relative;z-index:1;"><tr><td>Inhalt</td></tr></table><div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;height:100%;margin:0;overflow:hidden;z-index:0;font-size:0;line-height:0;text-align:left;"><img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="1815" alt="" style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto;margin:0;border:0;outline:none;text-decoration:none;"></div></td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td>Legal</td></tr>
HTML;
    $legacyTrain = SignatureTrainCarrier::projectAsImage(
        $legacyTrainFixture,
        'https://app.rail-time.de/mail-assets/zug-dampf-light.gif',
    );
    $legacyTrain = SignatureTrainCarrier::withoutLegacyBackgroundAttribute($legacyTrain);
    if (substr_count($legacyTrain, 'class="rt-sign-stage"') !== 1
        || substr_count($legacyTrain, 'mso-hide:all;') !== 1
        || substr_count($legacyTrain, 'width="720"') !== 1
        || ! str_contains($legacyTrain, 'data-background="decoy"')
        || str_contains($legacyTrain, ' background=')) {
        fwrite(STDERR, "Legacy train hardening changed a decoy instead of the real attribute.\n");
        exit(1);
    }

    // Hauptzug und Idle-Rauch bleiben fuer Mail und Preview echte IMG in einem
    // absolut positionierten, hoehenneutralen Layer hinter der Inhaltstabelle.
    // Ein MSO-Flow-Fallback, GIF-Background oder VML ist nicht erlaubt.
    $runtimeTrainFixture = <<<'HTML'
<tr><td class="rt-sign-cell" style="padding:0;overflow:hidden;background-image:linear-gradient(rgba(0,0,0,0),rgba(0,0,0,0));background-repeat:no-repeat;background-position:center center;background-size:100% 100%;"><div class="rt-sign-stage" style="position:relative;overflow:hidden;"><table style="position:relative;z-index:1;"><tr><td>Inhalt</td></tr></table><div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;margin:0 auto 0 0;overflow:hidden;z-index:0;font-size:0;line-height:0;text-align:left;"><img class="rt-sign-train" data-rt-train src="https://app.rail-time.de/mail-assets/zug-dampf-light.gif" width="720" alt="" style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;"></div></div></td></tr><!-- RT_SIGNATURE_MAIN_END -->
HTML;
    $runtimeImages = SignatureTrainCarrier::withIdleOverlay(
        $runtimeTrainFixture,
        'https://app.rail-time.de/mail-assets/zug-dampf-idle-light.gif',
    );
    SignatureTrainCarrier::assertRuntimeImages(
        $runtimeImages,
        'https://app.rail-time.de/mail-assets/zug-dampf-light.gif',
        'https://app.rail-time.de/mail-assets/zug-dampf-idle-light.gif',
        '',
    );
    $unsafeRelativeWasRejected = false;
    try {
        SignatureTrainCarrier::withMsoFallback($runtimeImages, '../zug-dampf-light.png');
    } catch (RuntimeException) {
        $unsafeRelativeWasRejected = true;
    }
    if (! $unsafeRelativeWasRejected) {
        fwrite(STDERR, "Outlook IMG accepted an unsafe relative asset path.\n");
        exit(1);
    }

    $runtimeTrain = SignatureTrainCarrier::withMsoFallback(
        $runtimeImages,
        'RailTime_files/zug-dampf-light.png',
    );
    $runtimeChecks = [
        'plain carrier class' => substr_count($runtimeTrain, 'class="rt-sign-cell"') === 1,
        'no runtime background marker' => substr_count($runtimeTrain, 'data-rt-train-background') === 0,
        'one wash repeat' => substr_count($runtimeTrain, 'background-repeat:no-repeat') === 1,
        'one wash position' => substr_count($runtimeTrain, 'background-position:center center') === 1,
        'one wash size' => substr_count($runtimeTrain, 'background-size:100% 100%') === 1,
        'no decorative signature images' => ! str_contains($runtimeTrain, 'signatur-raster-')
            && ! str_contains($runtimeTrain, 'signatur-marke-'),
        'idle overlay' => substr_count($runtimeTrain, 'data-rt-train-idle-overlay') === 1,
        'idle image marker' => substr_count($runtimeTrain, 'data-rt-train-idle-image') === 1,
        'zero-height idle holder before main' => preg_match(
            '/<span class="rt-train-idle-overlay"[^>]*height:0;max-height:0;[^>]*overflow:hidden;[^>]*>'
                .'<img class="rt-train-idle-image"[^>]*><\/span>'
                .'<img class="rt-sign-train"/i',
            $runtimeTrain,
        ) === 1,
        'idle image is sole holder child' => preg_match(
            '/<span\b[^>]*\bdata-rt-train-idle-overlay(?:\s|=|>)[^>]*>\s*'
                .'<img\b[^>]*\bdata-rt-train-idle-image(?:\s|=|>)[^>]*>\s*<\/span>/i',
            $runtimeTrain,
        ) === 1,
        'main image class' => substr_count($runtimeTrain, 'class="rt-sign-train"') === 1,
        'main image marker' => substr_count($runtimeTrain, 'data-rt-train ') === 1,
        'no MSO image' => substr_count($runtimeTrain, 'class="rt-sign-train-mso"') === 0,
        'no MSO image marker' => substr_count($runtimeTrain, 'data-rt-train-mso="1"') === 0,
        'absolute layer' => str_contains($runtimeTrain, 'style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;margin:0 auto 0 0;'),
        'absolute main image' => str_contains($runtimeTrain, 'style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:none;'),
        'bottom idle holder' => str_contains($runtimeTrain, 'style="position:absolute;left:0;right:auto;top:auto;bottom:0;display:block;width:100%;max-width:none;height:0;'),
        'absolute idle image' => str_contains($runtimeTrain, 'style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:none;'),
        'no VML' => preg_match('/<v:(?:rect|fill)\b/i', $runtimeTrain) !== 1,
        'no GIF CSS background' => preg_match('/background-image:[^;]*(?:data:image\/gif|\.gif)/i', $runtimeTrain) !== 1,
        'no MSO table row' => preg_match('/<!--\[if mso\]><tr><td\b[^>]*rt-sign-train-mso/', $runtimeTrain) !== 1,
    ];
    foreach ($runtimeChecks as $contract => $passed) {
        if (! $passed) {
            fwrite(STDERR, "Runtime train projection failed: {$contract}.\n");
            exit(1);
        }
    }

    $size125Train = str_replace(
        [
            'data-rt-layer-size="100"',
            'data-rt-layer-align="left"',
            'position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;margin:0 auto 0 0;',
            'position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:none;height:auto;margin:0;',
        ],
        [
            'data-rt-layer-size="125"',
            'data-rt-layer-align="right"',
            'position:absolute;left:auto;right:0;top:0;bottom:0;width:100%;max-width:1815px;margin:0 0 0 auto;',
            'position:absolute;left:0;right:auto;bottom:0;display:block;width:125%;max-width:none;height:auto;margin:0 0 0 -25%;',
        ],
        $runtimeTrainFixture,
    );
    $size125Train = SignatureTrainCarrier::withMsoFallback(
        $size125Train,
        'RailTime_files/zug-dampf-light.png',
    );
    try {
        SignatureTrainCarrier::assertRuntimeImages(
            $size125Train,
            'https://app.rail-time.de/mail-assets/zug-dampf-light.gif',
            expectedMsoSource: '',
        );
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "Right-aligned 125-percent IMG contract failed: {$exception->getMessage()}\n");
        exit(1);
    }

    if (str_contains($size125Train, 'rt-sign-train-mso')
        || ! str_contains($size125Train, 'position:absolute;left:auto;right:0;top:0;bottom:0;')
        || ! str_contains($size125Train, 'position:absolute;left:0;right:auto;bottom:0;display:block;width:125%;')) {
        fwrite(STDERR, "Right-aligned 125-percent IMG lost its height-neutral geometry.\n");
        exit(1);
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
