<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Enums\MarketingCreativeType;
use App\Http\Responses\PageBuilderPreviewResponse;
use App\Models\MailDocument;
use App\Models\User;
use App\Services\Marketing\MarketingStudioService;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTrainCarrier;
use App\Support\MailSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PageBuilderPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config()->set('marketing.disk', 'private');
    }

    public function test_preview_response_rejects_late_runtime_injection(): void
    {
        $html = '<!doctype html><html><head></head><body>Vorschau</body></html>';
        $response = new PageBuilderPreviewResponse($html);

        $response->setContent(str_replace('</body>', '<script src="/livewire/livewire.js"></script></body>', $html));

        $this->assertSame($html, $response->getContent());
    }

    public function test_shared_preview_card_renders_without_loading_its_frame(): void
    {
        $title = 'Vorschau `${globalThis.__rtPreviewPwned=1}`';
        $html = Blade::render(
            '<x-ui.page-builder.preview-card :title="$title" :sources="$sources" />',
            [
                'title' => $title,
                'sources' => ['post' => [
                    'label' => 'Post',
                    'url' => '/preview',
                    'width' => 1080,
                    'height' => 1080,
                ]],
            ],
        );

        $this->assertStringContainsString('data-page-builder-preview-card', $html);
        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringContainsString('x-bind:title="titlePrefix + (active?.label || \'Vorschau\')"', $html);
        $this->assertStringNotContainsString('x-bind:title="`', $html);
    }

    public function test_replayable_preview_card_defers_animated_frame_and_respects_reduced_motion(): void
    {
        $html = Blade::render(
            '<x-ui.page-builder.preview-card title="Mail" :sources="$sources" :replayable="true" />',
            ['sources' => ['light' => [
                'label' => 'Hell',
                'url' => '/preview?theme=light&animate=1',
                'width' => 1024,
                'height' => 820,
            ]]],
        );

        $this->assertStringContainsString('data-page-builder-preview-replay', $html);
        $this->assertStringContainsString('data-page-builder-preview-loading', $html);
        $this->assertStringContainsString('src="about:blank"', $html);
        $this->assertStringContainsString('x-bind:src="activeUrl"', $html);
        $this->assertStringContainsString('x-on:load="frameLoaded($event)"', $html);
        $this->assertStringContainsString(
            'x-bind:class="!frameReady ? &#039;opacity-0&#039; : &#039;opacity-100&#039;"',
            $html,
        );
        $this->assertStringNotContainsString('x-on:load="@js(', $html);
        $this->assertStringNotContainsString('x-bind:class="@js(', $html);
        $this->assertStringContainsString("url.searchParams.set('play', String(this.playbackId))", $html);
        $this->assertStringContainsString("url.searchParams.set('static', '1')", $html);
        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)')", $html);

        $mailHtml = Blade::render(
            '<x-ui.page-builder.preview-card title="Mail" :sources="$sources" :replayable="true" :loading-overlay="false" :navigate-edit="false" edit-url="/editor" />',
            ['sources' => ['light' => [
                'label' => 'Hell',
                'url' => '/preview?theme=light&animate=1',
                'width' => 1024,
                'height' => 820,
            ]]],
        );

        $this->assertStringNotContainsString('data-page-builder-preview-loading', $mailHtml);
        $this->assertStringNotContainsString('data-page-builder-preview-edit-link', $mailHtml);
        $this->assertStringContainsString('x-on:load="void 0"', $mailHtml);
        $this->assertStringContainsString('x-bind:class="&#039;opacity-100&#039;"', $mailHtml);
        $this->assertStringNotContainsString('@js(', $mailHtml);
        $this->assertStringNotContainsString('wire:navigate', $mailHtml);
    }

    public function test_deferred_preview_card_loads_only_near_the_viewport(): void
    {
        $html = Blade::render(
            '<x-ui.page-builder.preview-card title="Motiv" :sources="$sources" :deferred="true" />',
            ['sources' => ['story' => [
                'label' => 'Story',
                'url' => '/preview/story',
                'width' => 1080,
                'height' => 1920,
            ]]],
        );

        $this->assertStringContainsString('data-page-builder-preview-deferred="true"', $html);
        $this->assertStringContainsString('src="about:blank"', $html);
        $this->assertStringContainsString('shouldLoad: false', $html);
        $this->assertStringContainsString("typeof IntersectionObserver !== 'function'", $html);
        $this->assertStringContainsString("rootMargin: '360px 0px'", $html);
        $this->assertStringContainsString('Vorschau wird geladen', $html);
    }

    public function test_marketing_preview_is_admin_only_sandbox_ready_and_network_free(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );
        $hashes = $creative->variants()->pluck('content_hash', 'format')->all();
        $url = route('admin.marketing.creatives.preview', [$creative, 'story']);

        $this->actingAs($staff)->get($url)->assertForbidden();

        $response = $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-PageBuilder-Preview-Width', '1080')
            ->assertHeader('X-PageBuilder-Preview-Height', '1920');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $html = (string) $response->getContent();

        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("script-src 'none'", $csp);
        $this->assertStringContainsString('connect-src', $csp);
        $this->assertStringContainsString('sandbox', $csp);
        $this->assertStringContainsString('data-preview-width="1080"', $html);
        $this->assertStringContainsString('data-preview-height="1920"', $html);
        $this->assertStringContainsString('data:image/', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
        foreach (['job-tasks.svg', 'job-profile.svg', 'job-benefits.svg'] as $icon) {
            $contents = file_get_contents(public_path('rt-brand/icons/'.$icon));
            $this->assertIsString($contents);
            $this->assertStringContainsString(
                'data:image/svg+xml;base64,'.base64_encode($contents),
                $html,
            );
        }
        $this->assertStringContainsString('class="rt-job-card ', $html);
        $this->assertStringNotContainsString('/rt-brand/icons/', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('http://', strtolower($html));
        $this->assertStringNotContainsString('https://', strtolower($html));
        $this->assertStringNotContainsString('{{', $html);
        $this->assertSame($hashes, $creative->fresh()->variants()->pluck('content_hash', 'format')->all());
    }

    public function test_mail_preview_uses_current_draft_with_the_system_sender_and_local_assets_without_mutating_publish_state(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'name' => 'Mara Vorschau',
            'email' => 'mara.vorschau@rail-time.test',
        ]);
        User::factory()->create(['role' => 'staff']);
        $this->createCanonicalMailDocuments();
        $document = MailDocument::query()->where('kind', MailDocumentKind::Template->value)->firstOrFail();
        // Zeitstempel als Zeichenkette vergleichen: published_at ist ein
        // Carbon-Objekt, und zwei Abfragen liefern zwei INSTANZEN. Ein
        // identischer Vergleich schluege daran fehl, obwohl der Wert gleich
        // ist — geprueft werden soll der Inhalt, nicht die Objektgleichheit.
        $zustand = static fn (MailDocument $d): array => [
            'html' => $d->html,
            'css' => $d->css,
            'published_html' => $d->published_html,
            'published_css' => $d->published_css,
            'published_at' => $d->published_at?->toIso8601String(),
            'version' => $d->version,
        ];
        $before = $zustand($document);
        $url = route('admin.mail-documents.preview', [$document, 'theme' => 'dark']);

        $this->actingAs(User::query()->where('role', 'staff')->firstOrFail())
            ->get($url)
            ->assertForbidden();

        $response = $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertHeader('X-PageBuilder-Preview-Width', '1920')
            ->assertHeader('X-PageBuilder-Preview-Height', '820');
        $html = (string) $response->getContent();

        $this->assertStringContainsString('Mara Vorschau', $html);
        $this->assertStringNotContainsString('mara.vorschau@rail-time.test', $html);
        $this->assertStringContainsString((string) __('app.mail_signature_company_role'), $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('data-preview-document="template"', $html);
        $this->assertStringContainsString('data-preview-theme="dark"', $html);
        $this->assertStringContainsString('data-preview-animation="static"', $html);
        SignatureTrainCarrier::assertRuntimeImages($html, expectedIdleSource: '');
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train-mso"'));
        $this->assertSame(1, substr_count($html, 'data-rt-train-mso="1"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-stage"'));
        $this->assertSame(
            1,
            preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $html, $staticCarrier),
        );
        $this->assertStringNotContainsString('rt-sign-train-background', $staticCarrier[0]);
        $this->assertStringNotContainsString('data-rt-train-background', $staticCarrier[0]);
        $this->assertStringNotContainsString('data:image/', $staticCarrier[0]);
        $this->assertStringContainsString('background-image:linear-gradient(', $staticCarrier[0]);
        $this->assertMatchesRegularExpression('/<img\b[^>]*\bdata-rt-train(?:\s|=|>)[^>]*src="data:image\/png;base64,[^"]+"/i', $html);
        $this->assertMatchesRegularExpression('/<div\b[^>]*class="[^"]*\brt-sign-train-layer\b[^"]*"[^>]*style="display:block;[^">]*margin-bottom:-[0-9.]+(?:px|%);[^">]*overflow:hidden;/s', $html);
        $this->assertMatchesRegularExpression('/<img\b[^>]*class="rt-sign-train-mso"[^>]*\bdata-rt-train-mso="1"[^>]*src="data:image\/png;base64,[^"]+"/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $html);
        $this->assertStringNotContainsString('<tr><td class="rt-sign-train-mso"', $html);
        $this->assertStringContainsString(
            'background-repeat:no-repeat;',
            $staticCarrier[0],
        );
        $this->assertStringContainsString('background-position:center center;', $staticCarrier[0]);
        $this->assertStringContainsString('background-size:100% 100%;', $staticCarrier[0]);
        $this->assertStringNotContainsString(',75% bottom;', $staticCarrier[0]);
        $this->assertStringNotContainsString('data-rt-train-idle', $html);
        $this->assertStringNotContainsString('{{APPLICATION_CONTENT}}', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('http://', strtolower($html));
        $this->assertStringNotContainsString('https://', strtolower($html));
        $this->assertSame($before, $zustand($document->fresh()));

        $animatedA = $this->actingAs($admin)->get($url.'&animate=1&play=1')->assertOk();
        $animatedB = $this->actingAs($admin)->get($url.'&animate=1&play=2')->assertOk();
        $animatedHtml = (string) $animatedA->getContent();

        $this->assertStringContainsString('data-preview-animation="animated"', $animatedHtml);
        SignatureTrainCarrier::assertRuntimeImages($animatedHtml);
        $this->assertSame(1, substr_count($animatedHtml, 'class="rt-sign-train"'));
        $this->assertSame(1, substr_count($animatedHtml, 'class="rt-sign-train-mso"'));
        $this->assertSame(1, substr_count($animatedHtml, 'data-rt-train-mso="1"'));
        $this->assertSame(1, substr_count($animatedHtml, 'class="rt-sign-stage"'));
        $this->assertSame(
            1,
            preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $animatedHtml, $animatedCarrier),
        );
        $this->assertStringNotContainsString('rt-sign-train-background', $animatedCarrier[0]);
        $this->assertStringNotContainsString('data-rt-train-background', $animatedCarrier[0]);
        $this->assertStringNotContainsString('data:image/', $animatedCarrier[0]);
        $this->assertStringContainsString('background-image:linear-gradient(', $animatedCarrier[0]);
        $this->assertMatchesRegularExpression('/<img\b[^>]*\bdata-rt-train(?:\s|=|>)[^>]*src="data:image\/gif;base64,[^"]+"/i', $animatedHtml);
        $this->assertMatchesRegularExpression('/<div\b[^>]*class="[^"]*\brt-sign-train-layer\b[^"]*"[^>]*style="display:block;[^">]*margin-bottom:-[0-9.]+(?:px|%);[^">]*overflow:hidden;/s', $animatedHtml);
        $this->assertMatchesRegularExpression('/<img\b[^>]*class="rt-sign-train-mso"[^>]*\bdata-rt-train-mso="1"[^>]*src="data:image\/png;base64,[^"]+"/i', $animatedHtml);
        $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $animatedHtml);
        $this->assertStringNotContainsString('<tr><td class="rt-sign-train-mso"', $animatedHtml);
        $this->assertStringContainsString(
            'background-repeat:no-repeat;',
            $animatedCarrier[0],
        );
        $this->assertStringContainsString('background-position:center center;', $animatedCarrier[0]);
        $this->assertStringContainsString('background-size:100% 100%;', $animatedCarrier[0]);
        $this->assertStringContainsString('data-rt-train-idle-overlay', $animatedHtml);
        $this->assertStringContainsString('data-rt-train-idle-image', $animatedHtml);
        $this->assertMatchesRegularExpression('/<img\b[^>]*\bdata-rt-train-idle-image(?:\s|=|>)[^>]*src="data:image\/gif;base64,[^"]+"/i', $animatedHtml);
        $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $animatedHtml);
        $this->assertNotSame($animatedHtml, (string) $animatedB->getContent());
        preg_match_all('/data:image\/gif;base64,([A-Za-z0-9+\/=]+)/', $animatedHtml, $gifMatches);
        $uniqueGifs = array_unique($gifMatches[1] ?? []);
        $this->assertGreaterThanOrEqual(3, count($uniqueGifs));
        $previewNonceGifs = array_filter(
            $uniqueGifs,
            static fn (string $encodedGif): bool => str_contains(
                base64_decode($encodedGif, true) ?: '',
                'RailTime-Preview:',
            ),
        );
        // Logo, RT-Zeichen und Hauptzug erhalten einen eigenen Replay-Nonce.
        // Das separate Idle-GIF bleibt ein regulaeres Medienasset und darf
        // deshalb nicht pauschal denselben Preview-Kommentar tragen.
        $this->assertCount(3, $previewNonceGifs);
        $this->assertSame($before, $zustand($document->fresh()));

        $signatureDocument = MailDocument::query()
            ->where('kind', MailDocumentKind::Signature->value)
            ->firstOrFail();
        $signatureHtml = (string) $this->actingAs($admin)
            ->get(route('admin.mail-documents.preview', [
                $signatureDocument,
                'theme' => 'dark',
                'animate' => 1,
                'play' => 'signature-parity',
            ]))
            ->assertOk()
            ->assertHeader('X-PageBuilder-Preview-Width', '1920')
            ->assertHeader('X-PageBuilder-Preview-Height', '360')
            ->getContent();

        $this->assertStringContainsString('data-preview-document="signature"', $signatureHtml);
        $this->assertStringContainsString('data-preview-animation="animated"', $signatureHtml);
        SignatureTrainCarrier::assertRuntimeImages($signatureHtml);
        $this->assertSame(1, substr_count($signatureHtml, 'class="rt-sign-train"'));
        $this->assertSame(1, substr_count($signatureHtml, 'class="rt-sign-train-mso"'));
        $this->assertSame(1, substr_count($signatureHtml, 'data-rt-train-mso="1"'));
        $this->assertSame(1, substr_count($signatureHtml, 'data-rt-train-idle-overlay'));
        $this->assertSame(1, substr_count($signatureHtml, 'data-rt-train-idle-image'));
        $this->assertStringNotContainsString('RT_COMPANY_PHONE_START', $signatureHtml);
        $this->assertStringNotContainsString('RT_COMPANY_PHONE_END', $signatureHtml);
        $this->assertStringNotContainsString('RT_COMPANY_EMAIL_START', $signatureHtml);
        $this->assertStringNotContainsString('RT_COMPANY_EMAIL_END', $signatureHtml);
        $this->assertStringNotContainsString('{{FIRMEN_TELEFON}}', $signatureHtml);
        $this->assertStringNotContainsString('{{FIRMEN_EMAIL}}', $signatureHtml);
        $this->assertStringNotContainsString('{{TRAIN_SRC}}', $signatureHtml);
        $this->assertStringContainsString('@media only screen and (max-width: 860px)', $signatureHtml);
        $this->assertStringContainsString('html,body{margin:0;min-width:0;width:100%;', $signatureHtml);
        $this->assertStringNotContainsString('min-width:1920px;width:1920px', $signatureHtml);
    }

    public function test_marketing_source_page_renders_file_library_cards_without_preview_iframes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk();
        $html = (string) $response->getContent();

        foreach ([
            'data-marketing-motive-list',
            'data-marketing-motive-card',
            'Dateien verwalten',
            'wire:target="search,type"',
            route('admin.marketing.creatives.files', $creative),
        ] as $needle) {
            $this->assertTrue(str_contains($html, $needle), 'Marketing-Seite enthält nicht: '.$needle);
        }

        foreach ([
            'data-page-builder-preview-card',
            'data-page-builder-preview-frame',
            '<iframe',
            'src="about:blank"',
            'Vorschau wird geladen',
            route('admin.marketing.creatives.preview', [$creative, 'story']),
            route('admin.marketing.creatives.preview', [$creative, 'post']),
            route('admin.marketing.creatives.preview', [$creative, 'web']),
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }
    }

    public function test_email_source_page_renders_two_admin_preview_cards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createCanonicalMailDocuments();

        $response = $this->actingAs($admin)
            ->get(route('email-templates.index'))
            ->assertOk();
        $html = (string) $response->getContent();

        foreach (['data-email-template-page-builder-previews', 'Nachrichtenschale', 'Signatur', 'sandbox=""'] as $needle) {
            $this->assertTrue(str_contains($html, $needle), 'Mail-Seite enthält nicht: '.$needle);
        }
    }

    public function test_legacy_marketing_editor_route_redirects_admin_to_the_file_library(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );
        $legacyEditorUrl = route('admin.marketing.creatives.editor', $creative);
        $filesUrl = route('admin.marketing.creatives.files', $creative);

        $this->actingAs($staff)
            ->get($legacyEditorUrl)
            ->assertForbidden();

        $this->actingAs($admin)
            ->get($legacyEditorUrl)
            ->assertRedirect($filesUrl);

        $this->actingAs($admin)
            ->get($filesUrl)
            ->assertOk()
            ->assertSee('data-marketing-motive-files', false)
            ->assertSee('Dateien hochladen und organisieren');
    }

    public function test_marketing_index_links_directly_to_files_without_fullscreen_editor_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );

        $sourceHtml = (string) $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.marketing.creatives.files', $creative), $sourceHtml);
        $this->assertStringNotContainsString(route('admin.marketing.creatives.editor', $creative), $sourceHtml);
        $this->assertStringNotContainsString('open=1', $sourceHtml);
        $this->assertStringNotContainsString('data-page-builder-fullscreen', $sourceHtml);
        $this->assertStringNotContainsString('data-page-builder-open', $sourceHtml);
        $this->assertStringNotContainsString('pageBuilderOpen:', $sourceHtml);
    }

    private function createCanonicalMailDocuments(): void
    {
        foreach (MailDocumentKind::cases() as $kind) {
            $html = $this->canonicalMailDocumentHtml($kind);
            $builderData = [
                'pages' => [[
                    'name' => $kind->label(),
                    'component' => $html,
                ]],
                'styles' => [],
                'railtime' => [
                    'document' => $kind->value,
                    'schema' => SignatureDocumentContract::SCHEMA,
                ],
            ];

            MailDocument::query()->create([
                'kind' => $kind,
                'status' => MailDocumentStatus::Published,
                'builder_data' => $builderData,
                'html' => $html,
                'css' => '',
                'published_html' => $html,
                'published_css' => '',
                'published_at' => now(),
                'content_hash' => MailDocument::contentHashFor($builderData, $html, ''),
                'version' => 1,
            ]);
        }
    }

    private function canonicalMailDocumentHtml(MailDocumentKind $kind): string
    {
        if ($kind === MailDocumentKind::Template) {
            $html = (string) file_get_contents(EmailTemplateBuilder::masterPath('email-master.html'));
        } else {
            $tokens = [];
            foreach (array_keys(MailSignature::forCompany()->values([], CompanyData::defaults())) as $key) {
                $tokens[$key] = '{{'.$key.'}}';
            }

            $html = view('emails.parts.signature', ['values' => $tokens])->render();
        }

        return trim(app(EmailHtmlSanitizer::class)->assertClean(trim($html))->html);
    }
}
