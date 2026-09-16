<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PortableMediaCatalog;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTableOverlap;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\Mail\TrustedEmailCss;
use App\Support\MailSignature;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

final class V27MailDeliveryTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_08_09_000100_create_mail_documents_table.php', '2026_08_22_000200_create_mail_document_versions_table.php', '2026_08_27_000100_add_design_slots_to_mail_documents.php'] as $migration) {
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

    private function source(): string
    {
        return trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27.html')));
    }

    private function media(): array
    {
        return array_map(static function ($id) {
            $binary = file_get_contents(public_path('mail-assets/'.$id));

            return ['id' => $id, 'name' => $id, 'source' => '/mail-assets/'.$id, 'mime_type' => str_ends_with($id, '.gif') ? 'image/gif' : 'image/png', 'bytes' => strlen($binary), 'sha256' => hash('sha256', $binary), 'data' => base64_encode($binary)];
        }, PortableMediaCatalog::requiredSystemAssetIds('signature', 'v27'));
    }

    private function publishFixture(string $source, string $kind = 'signature'): void
    {
        $builder = ['pages' => [['name' => 'Synthetic V27', 'component' => $source]], 'styles' => [], 'railtime' => ['document' => $kind, 'schema' => SignatureDocumentContract::SCHEMA]];
        MailDocument::query()->create(['kind' => MailDocumentKind::from($kind), 'name' => 'Synthetic V27', 'status' => MailDocumentStatus::Published, 'is_active' => true, 'html' => $source, 'css' => '', 'builder_data' => $builder, 'published_html' => $source, 'published_css' => '', 'published_at' => now(), 'content_hash' => MailDocument::contentHashFor($builder, $source, ''), 'version' => 1]);
    }

    public function test_import_save_reimport_preserves_source_and_all_media(): void
    {
        Storage::fake('public');
        $source = $this->source();
        SignatureDocumentContract::assertValid($source);
        $report = app(EmailHtmlSanitizer::class)->sanitize($source);
        self::assertFalse($report->hasViolations());
        SignatureDocumentContract::assertValid($report->html);
        $bundle = ['format' => 'railtime-mail-document', 'version' => 2, 'kind' => 'signature', 'html' => $source, 'css' => '', 'media' => $this->media()];
        self::assertCount(17, $bundle['media']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('admin.mail-documents.import'), $bundle)->assertCreated();
        $document = MailDocument::query()->firstOrFail();
        $this->putJson(route('admin.mail-documents.update', $document), ['expected_hash' => $document->content_hash, 'builder_data' => $document->builder_data, 'html' => $document->html, 'css' => ''])->assertOk();
        $document->refresh();
        $exported = $bundle;
        $exported['html'] = $document->html;
        $this->postJson(route('admin.mail-documents.draft-import', $document), $exported + ['expected_hash' => $document->content_hash])->assertOk();
        SignatureTableOverlap::assertValid($document->fresh()->html);
        self::assertNull($document->fresh()->published_at);
    }

    public function test_full_personal_company_and_addin_cid_paths(): void
    {
        $source = $this->source();
        $this->publishFixture($source);
        $this->publishFixture(file_get_contents(EmailTemplateBuilder::masterPath('email-master.html')), 'template');
        $this->app->forgetScopedInstances();
        $user = User::factory()->create(['name' => 'Mara Beispiel', 'email' => 'mara@example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Mara', 'last_name' => 'Beispiel', 'position' => 'Disposition', 'phone' => '+49 4171 555123', 'mobile' => '+49 151 55512345']);
        foreach (['light', 'dark'] as $theme) {
            foreach ([MailSignature::forUser($user, $theme, animated: true, remoteAssets: true), MailSignature::forCompany($theme, remoteAssets: true)] as $signature) {
                $rows = $signature->renderDocument($source);
                SignatureTableOverlap::assertRuntime($rows);
                // V27 deliberately retains the light assets in both previews.
                self::assertStringContainsString('zug-dampf-v27-light.gif', $rows);
                self::assertStringNotContainsString('rt-sign-train-layer', $rows);
                $css = TrustedEmailCss::forDocument($rows);
                self::assertStringNotContainsString('height:200px', $css);
                self::assertStringContainsString('width:183.796856%', $css);
                if (getenv('V27_QA_OUTPUT') === '1') {
                    $full = '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.$css.'</style></head><body style="margin:0"><table width="100%" cellspacing="0" cellpadding="0">'.$rows.'</table></body></html>';
                    $full = (new CssToInlineStyles)->convert($full);
                    file_put_contents(base_path('.lmzdev/artifacts/temp/v26-table-carrier/full-'.$theme.'-'.(str_contains($rows, 'Mara') ? 'personal' : 'company').'.html'), $full);
                }
            }
        }
        $payload = app(OutlookAddinPayloadService::class)->forUser($user);
        self::assertStringContainsString('data-rt-artifact-version="v27"', $payload['signature']['html']);
        self::assertStringContainsString('src="cid:', $payload['signature']['html']);
        self::assertStringNotContainsString('rt-sign-train-layer', $payload['signature']['html']);
        self::assertLessThanOrEqual(30000, mb_strlen($payload['signature']['html']));
        $mail = (new MailMessage)->greeting('V27 synthetic test')->line('IMG table overlap');
        $compiled = (string) app(Markdown::class)->render($mail->markdown ?: 'notifications::email', $mail->data());
        $email = (new Email)->html(SystemMailInlineImageEmbedder::mark($compiled));
        self::assertGreaterThan(0, app(SystemMailInlineImageEmbedder::class)->embed($email));
        $trains = array_filter($email->getAttachments(), static fn ($part) => $part->getFilename() === 'zug-dampf-v27-light.gif');
        self::assertCount(1,$trains);
        self::assertStringNotContainsString('<div class="rt-sign-train-layer"',$email->getHtmlBody());
    }
}
