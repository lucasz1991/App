<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Requests\Mail\ImportMailDocumentRequest;
use App\Livewire\Admin\MailDocumentEditor;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PortableMediaCatalog;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;
use ZipArchive;

/** Static brand roles only: the train keeps its GIF and real MSO PNG fallback. */
final class EmailStaticBrandAssetsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        Http::preventStrayRequests();
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_08_09_000100_create_mail_documents_table.php',
            '2026_08_22_000200_create_mail_document_versions_table.php',
            '2026_08_27_000100_add_design_slots_to_mail_documents.php',
            '2026_09_06_010000_add_outlook_library_to_mail_documents.php',
            '2026_09_07_190000_separate_mail_document_delivery_channels.php',
            '2026_09_08_120000_add_mail_document_signature_pairing.php',
        ] as $migration) {
            (include database_path('migrations/'.$migration))->up();
        }
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public static function brandVariants(): array
    {
        $variants = [];
        foreach ([null, 'v15', 'v19', 'v27', 'v28', 'v29', 'v30'] as $version) {
            foreach (['light', 'dark'] as $theme) {
                $modern = $version !== null && $version !== 'v15';
                $mark = 'icon-rt-'.($modern ? 'v19-' : '').$theme.'.png';
                $logo = $theme === 'dark' ? 'wortmarke-mail-' : 'wortmarke-signature-';
                $logo .= ($modern ? 'v19-' : ($version === 'v15' ? 'v15-' : '')).$theme.'.png';
                $variants[($version ?? 'legacy').'-'.$theme] = [$version, $theme, $mark, $logo];
            }
        }

        return $variants;
    }

    #[DataProvider('brandVariants')]
    public function test_mail_role_selection_uses_existing_matching_static_pngs(?string $version, string $theme, string $mark, string $logo): void
    {
        self::assertSame($mark, EmailTemplateBuilder::emailMarkAsset($theme, $version));
        self::assertSame($logo, EmailTemplateBuilder::signatureLogoAsset($theme, $version));
        foreach ([$mark, $logo] as $asset) {
            $png = file_get_contents(public_path('mail-assets/'.$asset));
            $gif = file_get_contents(public_path('mail-assets/'.str_replace('.png', '.gif', $asset)));
            $image = getimagesizefromstring($png);
            $animated = getimagesizefromstring($gif);
            self::assertSame('image/png', $image['mime']);
            self::assertSame([$animated[0], $animated[1]], [$image[0], $image[1]], 'The static role preserves the matching original canvas and aspect ratio.');
            self::assertSame($png, file_get_contents(EmailTemplateBuilder::masterPath('assets/'.$asset)));
            self::assertLessThan(strlen($gif), strlen($png));
        }
        self::assertStringContainsString('.gif', EmailTemplateBuilder::signatureTrainUrl($theme, true, $version));
        self::assertStringContainsString('.png', EmailTemplateBuilder::signatureTrainStillUrl($theme, $version));
    }

    public function test_generic_requested_gif_embedding_and_svg_rejection_are_unchanged(): void
    {
        $asset = 'icon-rt-v19-light.gif';
        self::assertSame('data:image/gif;base64,'.base64_encode(file_get_contents(public_path('mail-assets/'.$asset))), EmailTemplateBuilder::inlineImage($asset, 'image/gif'));
        self::assertStringNotContainsString('svg', (new ImportMailDocumentRequest)->rules()['media.*.mime_type'][2]);
        $sanitized = (new EmailHtmlSanitizer)->sanitize('<div><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z"/></svg><p>Safe text</p></div>');
        self::assertStringNotContainsString('<svg', $sanitized->html);
        self::assertStringContainsString('Safe text', $sanitized->html);
    }

    public static function themes(): array
    {
        return ['light' => ['light'], 'dark' => ['dark']];
    }

    #[DataProvider('themes')]
    public function test_html_signature_and_template_downloads_keep_real_train_images_with_png_brands(string $theme): void
    {
        $user = $this->publishedFixture();
        $builder = new EmailTemplateBuilder($user);
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        foreach ([$theme === 'dark' ? 'vorlage-dunkel-html' : 'vorlage-html', $theme === 'dark' ? 'signatur-dunkel' : 'signatur-hell'] as $kind) {
            $html = $builder->build($kind)['content'];
            $images = $this->imageSources($html);
            self::assertNotEmpty($images);
            self::assertStringNotContainsString('data:image/svg', $html);
            $trainCount = 0;
            foreach ($images as $attributes) {
                $source = $attributes['src'];
                if (str_starts_with($source, 'data:image/gif;base64,')) {
                    $binary = base64_decode(substr($source, strlen('data:image/gif;base64,')), true);
                    self::assertSame(hash_file('sha256', public_path('mail-assets/zug-dampf-v27-delivery-light.gif')), hash('sha256', $binary), 'Only the V27 train remains animated.');
                    $trainCount++;
                }
                if (str_contains($attributes['class'], 'rt-logo') || str_contains($attributes['class'], 'rt-template-mark')) {
                    self::assertStringStartsWith('data:image/png;base64,', $source);
                }
            }
            self::assertSame(1, $trainCount);
            self::assertStringContainsString('rt-delivery-train-mso', $html);
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    #[DataProvider('themes')]
    public function test_eml_reuses_each_png_brand_cid_and_preserves_train_gif_plus_mso_png(string $theme): void
    {
        $builder = new EmailTemplateBuilder($this->publishedFixture());
        $eml = $builder->build($theme === 'dark' ? 'vorlage-dunkel-eml' : 'vorlage-eml')['content'];
        preg_match('~Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--~s', $eml, $htmlPart);
        $html = base64_decode(preg_replace('/\s+/', '', $htmlPart[1]), true);
        self::assertIsString($html);
        preg_match_all('~Content-Type: (image/[a-z]+); name="([^"]+)"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <([^>]+)>\r\nContent-Disposition: inline; filename="[^"]+"\r\n\r\n(.*?)\r\n--~s', $eml, $parts, PREG_SET_ORDER);
        $media = [];
        foreach ($parts as $part) {
            self::assertArrayNotHasKey($part[3], $media);
            $media[$part[3]] = ['mime' => $part[1], 'name' => $part[2], 'binary' => base64_decode(preg_replace('/\s+/', '', $part[4]), true)];
        }
        foreach (['railtime-mark', 'railtime-logo'] as $role) {
            self::assertSame('image/png', $media[$role]['mime']);
            self::assertStringEndsWith('.png', $media[$role]['name']);
            self::assertSame('image/png', getimagesizefromstring($media[$role]['binary'])['mime']);
            self::assertArrayNotHasKey($role.'-still', $media, 'Normal and MSO branches share the same static brand attachment.');
            self::assertGreaterThanOrEqual(2, substr_count($html, 'cid:'.$role));
        }
        self::assertSame('image/gif', $media['railtime-train']['mime']);
        self::assertSame('image/png', $media['railtime-train-still']['mime']);
        self::assertSame(hash_file('sha256', public_path('mail-assets/zug-dampf-v27-delivery-'.$theme.'.gif')), hash('sha256', $media['railtime-train']['binary']));
        self::assertSame(hash_file('sha256', public_path('mail-assets/zug-dampf-v27-delivery-'.$theme.'.png')), hash('sha256', $media['railtime-train-still']['binary']));
        preg_match_all('~src="cid:([^"]+)"~', $html, $references);
        foreach (array_unique($references[1]) as $cid) {
            self::assertArrayHasKey($cid, $media);
        }
        self::assertCount(count(array_unique($references[1])), $media);
        Http::assertNothingSent();
    }

    #[DataProvider('themes')]
    public function test_outlook_package_names_png_bytes_correctly_in_normal_and_mso_branches(string $theme): void
    {
        $builder = new EmailTemplateBuilder($this->publishedFixture());
        $download = $builder->build($theme === 'dark' ? 'signatur-outlook-dunkel' : 'signatur-outlook-hell');
        $path = tempnam(sys_get_temp_dir(), 'rt-static-brand-test-');
        self::assertIsString($path);
        try {
            self::assertSame(strlen($download['content']), file_put_contents($path, $download['content']));
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path));
            try {
                $names = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $names[] = $zip->getNameIndex($i);
                }
                $logoPath = array_values(array_filter($names, static fn (string $name): bool => str_ends_with($name, '/logo.png')));
                self::assertCount(1, $logoPath);
                self::assertEmpty(array_filter($names, static fn (string $name): bool => str_ends_with($name, '/logo.gif')));
                self::assertSame(file_get_contents(public_path('mail-assets/'.EmailTemplateBuilder::signatureLogoAsset($theme, 'v27'))), $zip->getFromName($logoPath[0]));
                foreach ($names as $name) {
                    if (str_ends_with($name, '.htm')) {
                        $html = $zip->getFromName($name);
                        self::assertGreaterThanOrEqual(2, substr_count($html, $logoPath[0]));
                        self::assertStringContainsString('rt-delivery-train-mso', $html);
                        self::assertStringContainsString('/zug-dampf.gif', $html);
                        self::assertStringContainsString('/zug-dampf.png', $html);
                    }
                }
            } finally {
                $zip->close();
            }
        } finally {
            unlink($path);
        }
        Http::assertNothingSent();
    }

    public function test_actual_global_and_all_paired_template_payloads_use_static_brands_without_source_writes(): void
    {
        $user = $this->publishedFixture();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $payload = app(OutlookAddinPayloadService::class)->forUser($user);
        self::assertCount(2, $payload['templates']);
        $this->assertMediaRoles($payload['signature']['html'], $payload['signature']['media'], false);
        foreach ($payload['templates'] as $template) {
            $this->assertMediaRoles($template['html'], $template['media'], true);
            $this->assertMediaRoles($template['composeHtml'], $template['composeMedia'], true, false);
            $signature = $template['signature'] ?? $payload['signature'];
            $this->assertMediaRoles($signature['html'], $signature['media'], false);
            $this->assertMediaRoles($template['combinedComposeHtml'], $template['combinedComposeMedia'], true);
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_current_system_mail_uses_the_same_static_brand_roles_with_an_animated_train(): void
    {
        $this->publishedFixture();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $html = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Synthetic application content</p>'));
        self::assertStringContainsString('Synthetic application content', $html);
        foreach (['icon-rt-v19-light.png', 'wortmarke-signature-v19-light.png'] as $asset) {
            self::assertGreaterThanOrEqual(2, substr_count($html, $asset));
            self::assertStringNotContainsString(str_replace('.png', '.gif', $asset), $html);
        }
        self::assertStringContainsString('zug-dampf-v27-delivery-light.gif', $html);
        self::assertStringContainsString('zug-dampf-v27-delivery-light.png', $html);
        self::assertStringContainsString('rt-delivery-train-mso', $html);
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_real_editor_asset_metadata_matches_pngs_without_rewriting_portable_gif_inventory(): void
    {
        $this->publishedFixture();
        $documents = [];
        foreach ([MailDocumentKind::Template, MailDocumentKind::Signature] as $kind) {
            $documents[$kind->value] = MailDocument::query()->where('kind', $kind->value)->where('is_active', true)->firstOrFail();
        }
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $editor = new MailDocumentEditor;
        $editor->kind = MailDocumentKind::Template->value;
        $config = (new ReflectionMethod($editor, 'editorConfig'))->invoke($editor, $documents);
        self::assertCount(9, $config['mailAssets']);
        $brandAssets = [
            'wortmarke-signature-v19-light.png', 'wortmarke-mail-v19-dark.png',
            'icon-rt-v19-light.png', 'icon-rt-v19-dark.png',
        ];
        foreach ($brandAssets as $index => $filename) {
            $asset = $config['mailAssets'][$index];
            $dimensions = getimagesize(public_path('mail-assets/'.$filename));
            self::assertSame(EmailTemplateBuilder::mailAssetUrl($filename), $asset['src']);
            self::assertSame('image/png', $asset['mime_type']);
            self::assertFalse($asset['animated']);
            self::assertSame([$dimensions[0], $dimensions[1]], [$asset['width'], $asset['height']]);
            self::assertSame('RailTime Marke', $asset['category']);
        }
        foreach (['location', 'phone', 'mobile', 'email', 'web'] as $index => $name) {
            $asset = $config['mailAssets'][$index + 4];
            self::assertSame(EmailTemplateBuilder::mailAssetUrl('contact-'.$name.'.png'), $asset['src']);
            self::assertSame('image/png', $asset['mime_type']);
            self::assertFalse($asset['animated']);
            self::assertSame([44, 44], [$asset['width'], $asset['height']]);
            self::assertSame('Kontakt', $asset['category']);
        }
        foreach ([MailDocumentKind::Template, MailDocumentKind::Signature] as $kind) {
            self::assertSame(PortableMediaCatalog::requiredSystemAssetContracts($kind), $config['portableMediaRequirements'][$kind->value]);
        }
        foreach (['icon-rt-v19-light.gif', 'icon-rt-v19-dark.gif', 'wortmarke-signature-v19-light.gif', 'wortmarke-mail-v19-dark.gif'] as $historicalAsset) {
            self::assertContains($historicalAsset, array_column($config['portableMedia'], 'id'));
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    private function assertMediaRoles(string $html, array $media, bool $mark, bool $signature = true): void
    {
        $byHash = [];
        $byCid = [];
        foreach ($media as $attachment) {
            $binary = base64_decode($attachment['base64'], true);
            self::assertIsString($binary);
            self::assertSame($attachment['contentId'], $attachment['name']);
            self::assertStringEndsWith(getimagesizefromstring($binary)['mime'] === 'image/gif' ? '.gif' : '.png', $attachment['name']);
            $byHash[hash('sha256', $binary)] = $attachment;
            self::assertArrayNotHasKey($attachment['contentId'], $byCid);
            $byCid[$attachment['contentId']] = $attachment;
        }
        foreach (array_filter([$mark ? 'icon-rt-v19-light.png' : null, $signature ? 'wortmarke-signature-v19-light.png' : null]) as $asset) {
            $hash = hash_file('sha256', public_path('mail-assets/'.$asset));
            self::assertArrayHasKey($hash, $byHash);
            self::assertGreaterThanOrEqual(2, substr_count($html, 'cid:'.$byHash[$hash]['contentId']), 'Matching normal and MSO roles share one PNG attachment.');
            self::assertArrayNotHasKey(hash_file('sha256', public_path('mail-assets/'.str_replace('.png', '.gif', $asset))), $byHash);
        }
        if ($signature) {
            foreach (['gif', 'png'] as $extension) {
                self::assertArrayHasKey(hash_file('sha256', public_path('mail-assets/zug-dampf-v27-delivery-light.'.$extension)), $byHash);
            }
        }
        preg_match_all('~src="cid:([^"]+)"~', $html, $references);
        foreach (array_unique($references[1]) as $cid) {
            self::assertArrayHasKey($cid, $byCid);
        }
        self::assertCount(count(array_unique($references[1])), $media);
    }

    private function publishedFixture(): User
    {
        $signatureSource = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $global = $this->publish($signatureSource, MailDocumentKind::Signature, true);
        $global->update(['outlook_default' => true]);
        $paired = $this->publish($signatureSource, MailDocumentKind::Signature, false);
        $master = file_get_contents(EmailTemplateBuilder::masterPath('email-master.html'));
        preg_match('~<!-- RT_TEMPLATE_MARK_START -->.*?<!-- RT_TEMPLATE_MARK_END -->~s', $master, $mark);
        $head = substr($master, 0, strpos($master, '<body'));
        $source = $head.'<body data-rt-theme="{{THEME}}" bgcolor="{{PAGE_BG}}" style="margin:0;padding:0;background:{{PAGE_BG}};color:{{TEXT_PRIMARY}};font-family:Arial,Helvetica,sans-serif;">'
            .'<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;"><tbody><tr><td class="design-page-pad" style="padding:34px 0 38px;">'
            .'<div style="width:100%;box-sizing:border-box;border-left:6px solid #e90032;overflow:hidden;"><table class="rt-shell design-v27" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"><tbody>'
            .'<tr><td class="rt-pad" style="padding:29px 42px 23px 22px;"><table dir="rtl" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"><tbody><tr><td align="right"></td>'.$mark[0].'</tr></tbody></table></td></tr>'
            .'<!-- RT_APPLICATION_CONTENT_START -->{{APPLICATION_CONTENT}}<tr><td class="rt-pad" style="padding:0 42px 26px 40px;font-size:16px;line-height:26px;">'
            .'<p class="design-salutation" style="margin:0 0 20px;font-size:26px;line-height:34px;font-weight:bold;">Guten Tag,</p>'
            .'<p class="design-spacer" style="margin:0 0 20px;"><br><br></p><p class="design-greeting" style="margin:0;">Mit freundlichen Grüßen,</p>'
            .'</td></tr><!-- RT_APPLICATION_CONTENT_END -->{{SIGNATURE_BLOCK}}</tbody></table></div></td></tr></tbody></table></body></html>';
        $this->publish($source, MailDocumentKind::Template, true)->update(['outlook_released' => true, 'outlook_default' => true]);
        $this->publish($source, MailDocumentKind::Template, false)->update(['outlook_released' => true, 'published_signature_document_id' => $paired->id]);
        $user = User::factory()->create(['name' => 'Synthetic Static Brand', 'email' => 'static-brand@example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Synthetic', 'last_name' => 'Static Brand', 'position' => 'Disposition', 'phone' => '+49 4171 555123', 'mobile' => '+49 160 555123']);
        $this->app->forgetScopedInstances();

        return $user;
    }

    private function publish(string $source, MailDocumentKind $kind, bool $active): MailDocument
    {
        $builder = ['pages' => [['name' => 'Static brand fixture', 'component' => $source]], 'styles' => [], 'railtime' => ['document' => $kind->value, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::query()->create([
            'kind' => $kind, 'name' => 'Static brand fixture', 'status' => MailDocumentStatus::Published, 'is_active' => $active,
            'html' => $source, 'css' => '', 'builder_data' => $builder, 'published_html' => $source, 'published_css' => '',
            'published_at' => now(), 'content_hash' => MailDocument::contentHashFor($builder, $source, ''), 'version' => 1,
        ]);
    }

    private function imageSources(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $images = [];
        foreach ((new DOMXPath($dom))->query('//img') as $image) {
            $images[] = ['src' => $image->getAttribute('src'), 'class' => $image->getAttribute('class')];
        }

        return $images;
    }
}
