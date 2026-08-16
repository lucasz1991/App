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
    function resource_path(string $path = ''): string
    {
        return dirname(__DIR__, 2).'/resources'.($path === '' ? '' : '/'.$path);
    }

    require dirname(__DIR__, 2).'/app/Support/EmailTemplateBuilder.php';

    final class IsolatedEmailTemplateBuilder extends App\Support\EmailTemplateBuilder
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

    $reflection = new ReflectionClass(App\Support\EmailTemplateBuilder::class);
    $builder = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('buildOutlookRtf');
    $method->setAccessible(true);

    $rtf = $method->invoke($builder, "BMP é · Astral 😀 𝄞");
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

    $eml = (new IsolatedEmailTemplateBuilder)->eml('light');
    foreach (['railtime-logo', 'railtime-logo-still', 'railtime-train'] as $contentId) {
        if (substr_count($eml, "Content-ID: <{$contentId}>\r\n") !== 1) {
            fwrite(STDERR, "CID part is missing or duplicated: {$contentId}\n");
            exit(1);
        }
    }

    if (! str_contains($eml, "Content-Type: image/gif; name=\"zug-dampf-light.gif\"")) {
        fwrite(STDERR, "Train GIF MIME part is missing.\n");
        exit(1);
    }

    fwrite(STDOUT, "RTF Unicode and EML CID tests passed.\n");
}
