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

    function resource_path(string $path = ''): string
    {
        return dirname(__DIR__, 2).'/resources'.($path === '' ? '' : '/'.$path);
    }

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
                .'<img src="cid:railtime-train">';
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
    foreach (['railtime-logo', 'railtime-logo-still', 'railtime-train'] as $contentId) {
        if (substr_count($eml, "Content-ID: <{$contentId}>\r\n") !== 1) {
            fwrite(STDERR, "CID part is missing or duplicated: {$contentId}\n");
            exit(1);
        }
    }

    if (! str_contains($eml, 'Content-Type: image/gif; name="zug-dampf-light.gif"')) {
        fwrite(STDERR, "Train GIF MIME part is missing.\n");
        exit(1);
    }

    fwrite(STDOUT, "RTF Unicode and EML CID tests passed.\n");
}
