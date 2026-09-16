<?php

namespace Tests\Feature;

use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PortableMediaCatalog;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTableOverlap;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\Mail\TrustedEmailCss;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\MailSignature;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class V28V29MailDeliveryTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_08_09_000100_create_mail_documents_table.php', '2026_08_22_000200_create_mail_document_versions_table.php', '2026_08_27_000100_add_design_slots_to_mail_documents.php', '2026_09_08_120000_add_mail_document_signature_pairing.php'] as $migration) {
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

    private function source(string $version = 'v27'): string
    {
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27.html')));

        return $version === 'v27' ? $source : SignatureTableOverlap::mirroredFromV27($source, $version);
    }

    private function media(string $version): array
    {
        return array_map(static function (string $id): array {
            $binary = file_get_contents(public_path('mail-assets/'.$id));

            return ['id' => $id, 'name' => $id, 'source' => '/mail-assets/'.$id, 'mime_type' => str_ends_with($id, '.gif') ? 'image/gif' : 'image/png', 'bytes' => strlen($binary), 'sha256' => hash('sha256', $binary), 'data' => base64_encode($binary)];
        }, PortableMediaCatalog::requiredSystemAssetIds('signature', $version));
    }

    public function test_v27_ledger_survives_import_save_and_reimport_without_changing_a_release(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $report = app(EmailHtmlSanitizer::class)->sanitize($source);
        self::assertFalse($report->hasViolations());
        SignatureDocumentContract::assertValid($report->html);
        SignatureDocumentContract::assertValid($this->source());
        $bundle = ['format' => 'railtime-mail-document', 'version' => 2, 'kind' => 'signature', 'html' => $source, 'css' => '', 'media' => $this->media('v27')];
        $this->postJson(route('admin.mail-documents.import'), $bundle)->assertCreated();
        $document = MailDocument::query()->latest('id')->firstOrFail();
        $this->putJson(route('admin.mail-documents.update', $document), ['expected_hash' => $document->content_hash, 'builder_data' => $document->builder_data, 'html' => $document->html, 'css' => ''])->assertOk();
        $document->refresh();
        $saved = $document->html;
        $bundle['html'] = $saved;
        $this->postJson(route('admin.mail-documents.draft-import', $document), $bundle + ['expected_hash' => $document->content_hash])->assertOk();
        $document->refresh();
        self::assertSame($saved, $document->html);
        self::assertStringContainsString('rt-sign-layout rt-sign-ledger', $saved);
        self::assertNull($document->published_at);
        self::assertNotTrue($document->is_active);
    }

    public function test_v27_ledger_has_scoped_runtime_and_embeds_its_single_train_via_cid(): void
    {
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        foreach ([TrustedEmailCss::forDocument($source), TrustedOutlookSignatureCss::responsive($source)] as $css) {
            self::assertStringContainsString('.rt-sign-ledger img.rt-logo', $css);
            self::assertStringContainsString('max-width:520px', $css);
            self::assertStringContainsString('max-width:860px', $css);
        }
        self::assertStringNotContainsString('rt-sign-ledger', SignatureTableOverlap::css('v28'));
        self::assertStringNotContainsString('rt-sign-ledger', TrustedEmailCss::forDocument($this->source()));
        self::assertStringNotContainsString('.rt-sign-heading-logo', TrustedEmailCss::forDocument($source));
        $rows = MailSignature::forCompany('light', remoteAssets: true)->renderDocument($source);
        SignatureTableOverlap::assertRuntime($rows);
        $email = (new Email)->html(SystemMailInlineImageEmbedder::mark('<html><body><!-- RT_TEMPLATE_MARK_START --><!-- RT_TEMPLATE_MARK_END --><table>'.$rows.'</table></body></html>'));
        self::assertGreaterThan(0, app(SystemMailInlineImageEmbedder::class)->embed($email));
        self::assertCount(1, array_filter($email->getAttachments(), static fn ($part) => $part->getFilename() === 'zug-dampf-v27-light.gif'));
        self::assertStringContainsString('src="cid:', $email->getHtmlBody());
        self::assertStringNotContainsString('background-image:', $email->getHtmlBody());
    }

    public function test_v27_ledger_cannot_drop_or_swap_contact_groups(): void
    {
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $this->expectException(RuntimeException::class);
        SignatureDocumentContract::assertValid(str_replace('class="rt-ledger-direct"', 'class="rt-ledger-company"', $source));
    }

    public static function mirroredVersions(): array
    {
        return [['v28'], ['v29']];
    }

    #[DataProvider('mirroredVersions')]
    public function test_mirrored_drafts_survive_sanitizer_import_save_and_reimport_without_publication(string $version): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $source = $this->source($version);
        $report = app(EmailHtmlSanitizer::class)->sanitize($source);
        self::assertFalse($report->hasViolations());
        SignatureDocumentContract::assertValid($report->html);
        preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $this->source(), $originalTokens);
        preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $report->html, $mirroredTokens);
        self::assertSame($originalTokens[0], $mirroredTokens[0]);
        $bundle = ['format' => 'railtime-mail-document', 'version' => 2, 'kind' => 'signature', 'html' => $source, 'css' => '', 'media' => $this->media($version)];
        self::assertCount(17, $bundle['media']);
        $this->postJson(route('admin.mail-documents.import'), $bundle)->assertCreated();
        $document = MailDocument::query()->latest('id')->firstOrFail();
        $this->putJson(route('admin.mail-documents.update', $document), ['expected_hash' => $document->content_hash, 'builder_data' => $document->builder_data, 'html' => $document->html, 'css' => ''])->assertOk();
        $document->refresh();
        $bundle['html'] = $document->html;
        $this->postJson(route('admin.mail-documents.draft-import', $document), $bundle + ['expected_hash' => $document->content_hash])->assertOk();
        $document->refresh();
        SignatureDocumentContract::assertValid($document->html);
        self::assertSame($version, SignatureArtifactVersion::detect('signature', $document->html));
        self::assertNull($document->published_at);
        self::assertNotTrue($document->is_active);
    }

    public function test_personal_company_static_animated_and_cid_rendering_use_mirrored_assets(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel', 'email' => 'mara@example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Mara', 'last_name' => 'Beispiel', 'position' => 'Disposition', 'phone' => '+49 4171 555123', 'mobile' => '+49 151 55512345']);
        foreach (['v28', 'v29'] as $version) {
            foreach (['light', 'dark'] as $theme) {
                foreach ([MailSignature::forUser($user, $theme, animated: true, remoteAssets: true), MailSignature::forCompany($theme, remoteAssets: true)] as $signature) {
                    $rows = $signature->renderDocument($this->source($version));
                    SignatureTableOverlap::assertRuntime($rows);
                    self::assertStringContainsString('zug-dampf-v27-'.$theme.'-mirrored.gif', $rows);
                    self::assertStringNotContainsString('rt-sign-train-layer', $rows);
                    $email = (new Email)->html(SystemMailInlineImageEmbedder::mark('<html><body><!-- RT_TEMPLATE_MARK_START --><!-- RT_TEMPLATE_MARK_END --><table>'.$rows.'</table></body></html>'));
                    self::assertGreaterThan(0, app(SystemMailInlineImageEmbedder::class)->embed($email));
                    $trains = array_filter($email->getAttachments(), static fn ($part) => $part->getFilename() === 'zug-dampf-v27-'.$theme.'-mirrored.gif');
                    self::assertCount(1, $trains);
                    self::assertStringContainsString('src="cid:', $email->getHtmlBody());
                }
                $rows = MailSignature::forUser($user, $theme, animated: false, remoteAssets: true)->renderDocument($this->source($version));
                self::assertStringContainsString('zug-dampf-v27-'.$theme.'-mirrored.png', $rows);
            }
        }
    }

    public function test_v27_uses_light_assets_even_when_a_dark_preview_is_requested(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel', 'email' => 'mara@example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Mara', 'last_name' => 'Beispiel']);

        $rows = MailSignature::forUser($user, 'dark', animated: true, remoteAssets: true)
            ->renderDocument($this->source('v27'));

        self::assertStringContainsString('wortmarke-signature-v19-light.gif', $rows);
        self::assertStringContainsString('zug-dampf-v27-light.gif', $rows);
        self::assertStringNotContainsString('wortmarke-signature-v19-dark.gif', $rows);
        self::assertStringNotContainsString('zug-dampf-v27-dark.gif', $rows);
    }

    public function test_profile_exposes_a_normalized_emergency_phone_link_for_v27(): void
    {
        $values = (new EmailTemplateBuilder(User::factory()->create()))->profileValues();

        self::assertSame(
            EmailTemplateBuilder::telHref($values['NOTFALLNUMMER']),
            $values['NOTFALLNUMMER_TEL'],
        );
    }

    public function test_mirrored_geometry_is_isolated_and_bounded_at_every_breakpoint(): void
    {
        foreach (['v28', 'v29'] as $version) {
            $source = $this->source($version);
            $css = TrustedEmailCss::forDocument($source);
            self::assertStringContainsString('tr[data-rt-artifact-version="'.$version.'"]', $css);
            self::assertStringNotContainsString('height:200px', $css);
            self::assertStringNotContainsString('width:183.796856%', $css);
            self::assertStringContainsString('direction:rtl!important', $css);
            self::assertStringContainsString('direction:ltr!important', $css);
            foreach (SignatureTableOverlap::profiles($version) as $profile) {
                self::assertSame('100', $profile['image']);
            }
            $outlookCss = TrustedOutlookSignatureCss::responsive($source);
            self::assertStringContainsString('direction:rtl!important', $outlookCss);
            self::assertStringNotContainsString('height:200px', $outlookCss);
        }
        self::assertSame('183.796856', SignatureTableOverlap::profiles()['mobile']['image']);
        self::assertSame(SignatureTableOverlap::css(), SignatureTableOverlap::css('v27'));
        self::assertStringContainsString('zug-dampf-v19-light.gif', EmailTemplateBuilder::signatureTrainUrl('light', true, 'v26'));
        self::assertStringContainsString('zug-dampf-v27-light.gif', EmailTemplateBuilder::signatureTrainUrl('light', true, 'v27'));
    }

    public function test_catalog_includes_only_new_mirrored_train_ids_with_matching_asset_copies(): void
    {
        foreach (['v28', 'v29'] as $version) {
            $ids = PortableMediaCatalog::requiredSystemAssetIds('signature', $version);
            $trains = array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'zug-dampf-')));
            self::assertCount(4, $trains);
            foreach ($trains as $id) {
                self::assertStringContainsString('-mirrored.', $id);
                self::assertSame(hash_file('sha256', public_path('mail-assets/'.$id)), hash_file('sha256', resource_path('mail-templates/assets/'.$id)));
                self::assertSame([1216, 171], array_slice(getimagesize(public_path('mail-assets/'.$id)), 0, 2));
            }
        }
    }

    public function test_mirrored_contract_rejects_changed_direction_css_mirroring_and_web_only_layout(): void
    {
        foreach (['v28', 'v29'] as $version) {
            $source = $this->source($version);
            foreach ([str_replace('dir="ltr"', 'dir="rtl"', $source), str_replace('display:inline-block;', 'display:inline-block;transform:scaleX(-1);', $source), str_replace('display:inline-block;', 'display:flex;', $source), str_replace('display:inline-block;', 'display:grid;', $source)] as $unsafe) {
                try {
                    SignatureDocumentContract::assertValid($unsafe);
                    self::fail('Unsafe mirrored source was accepted.');
                } catch (RuntimeException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
        }
        self::assertTrue(app(EmailHtmlSanitizer::class)->sanitize('<table align="right"><tr><td>Generic table</td></tr></table>')->hasViolations());
        self::assertTrue(app(EmailHtmlSanitizer::class)->sanitize(str_replace('data-rt-artifact-version="v28"', 'data-rt-artifact-version="v27"', $this->source('v28')))->hasViolations());
    }
}
