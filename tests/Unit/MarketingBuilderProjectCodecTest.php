<?php

namespace Tests\Unit;

use App\Services\Marketing\MarketingBuilderProjectCodec;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketingBuilderProjectCodecTest extends TestCase
{
    public function test_it_preserves_the_structured_v2_project_and_binds_the_canonical_html(): void
    {
        $project = $this->v2Project();
        $before = $project;
        $canonicalHtml = '<main id="hero-artboard" class="hero" data-rt-zone="hero"><h1>Gemeinsam sicher auf der Schiene.</h1><img src="/administrator/marketing/dateien/42?v=abc12345" alt="RailTime Team"></main>';

        $result = $this->codec()->decodeAndSynchronize($project, $canonicalHtml);

        $this->assertSame($before, $project, 'Der Eingabedatensatz darf nicht per Referenz verändert werden.');
        $this->assertSame($before['pages'], $result['pages']);
        $this->assertSame($before['styles'], $result['styles']);
        $this->assertSame($before['assets'], $result['assets']);
        $this->assertSame('page-story', data_get($result, 'pages.0.id'));
        $this->assertSame('frame-story', data_get($result, 'pages.0.frames.0.id'));
        $this->assertSame('wrapper-story', data_get($result, 'pages.0.frames.0.component.id'));
        $this->assertSame('hero-component', data_get($result, 'pages.0.frames.0.component.components.0.id'));
        $this->assertSame('rule-hero', data_get($result, 'styles.0.id'));
        $this->assertSame('selector-hero', data_get($result, 'styles.0.selectors.0.id'));
        $this->assertSame('marketing', data_get($result, 'railtime.mode'));
        $this->assertSame(2, data_get($result, 'railtime.codec_version'));
        $this->assertSame(hash('sha256', $canonicalHtml), data_get($result, 'railtime.source_html_sha256'));
        $this->assertSame('job_wagenmeister', data_get($result, 'railtime.template'));
        $this->assertSame('story', data_get($result, 'railtime.format'));
        $this->assertSame(4, data_get($result, 'railtime.schema'));
    }

    public function test_it_synchronizes_the_legacy_string_component_and_can_use_it_as_canonical_fallback(): void
    {
        $legacy = [
            'dataSources' => [],
            'assets' => [],
            'styles' => [[
                'selectors' => ['legacy-card'],
                'style' => ['color' => '#ffffff'],
            ]],
            'pages' => [[
                'id' => 'legacy-page',
                'name' => 'Altes Motiv',
                'component' => '<main class="legacy-card">Alt</main>',
            ]],
            'symbols' => [],
            'railtime' => [
                'template' => 'job_wagenmeister',
                'format' => 'post',
                'schema' => 4,
            ],
        ];
        $canonicalHtml = '<main class="legacy-card">Serverseitig kanonisch</main>';

        $synchronized = $this->codec()->decodeAndSynchronize($legacy, $canonicalHtml);
        $fallback = $this->codec()->decodeAndSynchronize($legacy);

        $this->assertSame($canonicalHtml, data_get($synchronized, 'pages.0.component'));
        $this->assertArrayNotHasKey('source_html_sha256', $synchronized['railtime']);
        $this->assertSame($legacy['railtime'], $synchronized['railtime']);
        $this->assertSame($legacy['pages'][0]['component'], data_get($fallback, 'pages.0.component'));
        $this->assertSame($legacy['railtime'], $fallback['railtime']);
    }

    public function test_it_preserves_safe_local_token_and_inline_image_assets(): void
    {
        $project = $this->v2Project();
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $project['assets'] = [
            [
                'id' => 'private-image',
                'type' => 'image',
                'src' => '/administrator/marketing/dateien/42?v=abc12345',
                'name' => 'Teamfoto',
                'width' => 1920,
                'height' => 1080,
                'mime_type' => 'image/jpeg',
                'animated' => false,
                'metadata' => [
                    'category' => 'Karriere',
                    'fallback_source' => '/rt-brand/img/logo-horizontal.png',
                ],
            ],
            [
                'type' => 'image',
                'src' => 'rtmedia://media-'.str_repeat('a', 64),
                'mime' => 'image/png',
            ],
            [
                'type' => 'image',
                'src' => $png,
                'width' => 1,
                'height' => 1,
                'mime_type' => 'image/png',
                'bytes' => strlen((string) base64_decode(substr($png, strpos($png, ',') + 1), true)),
            ],
        ];

        $result = $this->codec()->decodeAndSynchronize($project, '<main><p>Sicher</p></main>');

        $this->assertSame($project['assets'], $result['assets']);
    }

    public function test_it_rejects_multiple_pages_or_frames_with_the_exact_json_path(): void
    {
        $multiplePages = $this->v2Project();
        $multiplePages['pages'][] = $multiplePages['pages'][0];
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($multiplePages, '<main>Sicher</main>'),
            'builder_data.pages.1',
            '$.pages[1]',
        );

        $multipleFrames = $this->v2Project();
        $multipleFrames['pages'][0]['frames'][] = $multipleFrames['pages'][0]['frames'][0];
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($multipleFrames, '<main>Sicher</main>'),
            'builder_data.pages.0.frames.1',
            '$.pages[0].frames[1]',
        );
    }

    public function test_it_rejects_dynamic_collections_and_unknown_runtime_fields_fail_closed(): void
    {
        $dataSource = $this->v2Project();
        $dataSource['dataSources'] = [['id' => 'remote', 'provider' => 'https://evil.example']];
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($dataSource, '<main>Sicher</main>'),
            'builder_data.dataSources.0',
            '$.dataSources[0]',
        );

        $symbol = $this->v2Project();
        $symbol['symbols'] = [['id' => 'executable-symbol']];
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($symbol, '<main>Sicher</main>'),
            'builder_data.symbols.0',
            '$.symbols[0]',
        );

        $rootRuntime = $this->v2Project();
        $rootRuntime['storageManager'] = ['type' => 'remote'];
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($rootRuntime, '<main>Sicher</main>'),
            'builder_data.storageManager',
            '$.storageManager',
        );

        $componentRuntime = $this->v2Project();
        $componentRuntime['pages'][0]['frames'][0]['component']['components'][0]['script'] = 'fetch("https://evil.example")';
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($componentRuntime, '<main>Sicher</main>'),
            'builder_data.pages.0.frames.0.component.components.0.script',
            '$.pages[0].frames[0].component.components[0].script',
        );
    }

    public function test_it_rejects_unsafe_styles_assets_attributes_and_canonical_html(): void
    {
        $unsafeStyle = $this->v2Project();
        $unsafeStyle['styles'][0]['style']['background-image'] = 'url(javascript:alert(1))';
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($unsafeStyle, '<main>Sicher</main>'),
            'builder_data.styles.0.style.background-image',
            '$.styles[0].style["background-image"]',
        );

        $remoteAsset = $this->v2Project();
        $remoteAsset['assets'][0]['src'] = 'https://evil.example/tracker.png';
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($remoteAsset, '<main>Sicher</main>'),
            'builder_data.assets.0.src',
            '$.assets[0].src',
        );

        $eventAttribute = $this->v2Project();
        $eventAttribute['pages'][0]['frames'][0]['component']['components'][0]['attributes']['onclick'] = 'alert(1)';
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($eventAttribute, '<main>Sicher</main>'),
            'builder_data.pages.0.frames.0.component.components.0.attributes.onclick',
            '$.pages[0].frames[0].component.components[0].attributes.onclick',
        );

        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($this->v2Project(), '<main><script>alert(1)</script></main>'),
            'html',
            '$.html',
        );
    }

    public function test_it_rejects_unknown_railtime_metadata_and_cross_mode_projects(): void
    {
        $unknown = $this->v2Project();
        $unknown['railtime']['provider_injected'] = true;
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($unknown, '<main>Sicher</main>'),
            'builder_data.railtime.provider_injected',
            '$.railtime.provider_injected',
        );

        $wrongMode = $this->v2Project();
        $wrongMode['railtime']['mode'] = 'mail';
        $this->assertValidationPath(
            fn () => $this->codec()->decodeAndSynchronize($wrongMode, '<main>Sicher</main>'),
            'builder_data.railtime.mode',
            '$.railtime.mode',
        );
    }

    /** @return array<string, mixed> */
    private function v2Project(): array
    {
        return [
            'dataSources' => [],
            'assets' => [[
                'id' => 'asset-team',
                'type' => 'image',
                'src' => '/administrator/marketing/dateien/42?v=abc12345',
                'name' => 'RailTime Team',
                'width' => 1920,
                'height' => 1080,
                'category' => 'Karriere',
                'mime_type' => 'image/jpeg',
                'animated' => false,
            ]],
            'styles' => [
                [
                    'id' => 'rule-hero',
                    'selectors' => [[
                        'id' => 'selector-hero',
                        'name' => 'hero',
                        'label' => 'Hero',
                        'type' => 'class',
                    ]],
                    'style' => [
                        'display' => 'grid',
                        'background-image' => 'url("/rt-brand/img/logo-horizontal.png")',
                    ],
                ],
                [
                    'selectors' => [],
                    'selectorsAdd' => 'from',
                    'style' => ['opacity' => '0'],
                    'mediaText' => 'rt-enter',
                    'atRuleType' => 'keyframes',
                ],
            ],
            'pages' => [[
                'id' => 'page-story',
                'name' => 'Story',
                'type' => 'main',
                'frames' => [[
                    'id' => 'frame-story',
                    'component' => [
                        'type' => 'wrapper',
                        'id' => 'wrapper-story',
                        'stylable' => ['background', 'background-color', 'background-image'],
                        'head' => ['type' => 'head'],
                        'docEl' => ['tagName' => 'html'],
                        'components' => [[
                            'type' => 'default',
                            'id' => 'hero-component',
                            'tagName' => 'main',
                            'classes' => ['hero'],
                            'attributes' => [
                                'id' => 'hero-artboard',
                                'data-rt-zone' => 'hero',
                            ],
                            'style' => ['display' => 'grid'],
                            'components' => [
                                [
                                    'type' => 'text',
                                    'id' => 'headline-component',
                                    'content' => 'Gemeinsam sicher auf der Schiene.',
                                ],
                                [
                                    'type' => 'image',
                                    'id' => 'team-image-component',
                                    'tagName' => 'img',
                                    'attributes' => [
                                        'src' => '/administrator/marketing/dateien/42?v=abc12345',
                                        'alt' => 'RailTime Team',
                                    ],
                                    'void' => true,
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]],
            'symbols' => [],
            'railtime' => [
                'template' => 'job_wagenmeister',
                'format' => 'story',
                'schema' => 4,
                'design_preset' => 'railtime_modern',
            ],
        ];
    }

    private function codec(): MarketingBuilderProjectCodec
    {
        return new MarketingBuilderProjectCodec(
            maxAssetBytes: 8 * 1024 * 1024,
            maxAssetPixels: 40_000_000,
        );
    }

    private function assertValidationPath(callable $callback, string $path, string $jsonPath): void
    {
        try {
            $callback();
            $this->fail('Die ungültigen Builder-Daten wurden nicht abgelehnt: '.$path);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($path, $exception->errors());
            $this->assertStringContainsString(
                'JSON-Pfad: '.$jsonPath,
                implode(' ', $exception->errors()[$path]),
            );
        }
    }
}
