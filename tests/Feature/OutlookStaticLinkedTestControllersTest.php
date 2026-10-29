<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Admin\MailDocumentController;
use App\Models\EmployeeIdentityAccount;
use App\Models\MailDocument;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\MailDocumentTestNotification;
use App\Support\CompanyData;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookStaticLinkedTestDelivery;
use App\Support\OutlookAddin\OutlookStaticLinkedTestProfiles;
use Firebase\JWT\JWT;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Mime\Email;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/** HTTP admission with real V27 sources, production compilers and signed synthetic Entra tokens. */
final class OutlookStaticLinkedTestControllersTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const ORIGIN = 'https://mail.example.test';

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const CLIENT = '22222222-2222-4222-8222-222222222222';

    private const OBJECT = '33333333-3333-4333-8333-333333333333';

    private User $actor;

    private MailDocument $system;

    private MailDocument $personal;

    private MailDocument $offered;

    private ?string $privateKey = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        Storage::fake('private');
        config(['app.url' => self::ORIGIN, 'outlook_addin.snapshots.auto_refresh' => false,
            'outlook_addin.enabled' => true, 'outlook_addin.deployed' => true, 'outlook_addin.addin_id' => self::CLIENT,
            'outlook_addin.base_url' => self::ORIGIN, 'outlook_addin.entra.tenant_id' => self::TENANT,
            'outlook_addin.entra.client_id' => self::CLIENT, 'outlook_addin.entra.audience' => self::CLIENT,
            'outlook_addin.entra.scope' => 'Signature.Read', 'outlook_addin.entra.scope_uri' => 'api://'.self::CLIENT.'/Signature.Read']);
        URL::forceRootUrl(self::ORIGIN);
        URL::forceScheme('https');
        foreach (['2026_08_09_000100_create_mail_documents_table.php', '2026_08_22_000200_create_mail_document_versions_table.php',
            '2026_08_27_000100_add_design_slots_to_mail_documents.php', '2026_09_06_010000_add_outlook_library_to_mail_documents.php',
            '2026_09_07_190000_separate_mail_document_delivery_channels.php', '2026_09_08_120000_add_mail_document_signature_pairing.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::create('employee_identity_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider');
            foreach (['external_id', 'tenant_id', 'principal', 'email', 'lifecycle_status'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        $this->actor = User::withoutEvents(static fn () => User::factory()->create([
            'name' => 'Synthetic Admin', 'email' => 'person@example.test', 'role' => 'admin',
            'status' => true, 'email_verified_at' => now(),
        ]));
        UserProfile::withoutEvents(fn () => UserProfile::create(['user_id' => $this->actor->id,
            'first_name' => 'Mara', 'last_name' => 'Muster', 'position' => 'Disposition',
            'phone' => '+49 30 5550101', 'mobile' => '+49 170 5550101']));
        EmployeeIdentityAccount::withoutEvents(fn () => EmployeeIdentityAccount::create([
            'user_id' => $this->actor->id, 'provider' => AccountProvider::Microsoft365, 'external_id' => self::OBJECT,
            'tenant_id' => self::TENANT, 'principal' => 'own@example.test', 'email' => 'own@example.test', 'lifecycle_status' => 'active',
        ]));
        Setting::setValue('company', 'profile', array_replace(CompanyData::defaults(), [
            'name' => 'Synthetic Rail GmbH', 'street' => 'Musterweg 12', 'postal_code' => '10115', 'city' => 'Berlin',
            'phone' => '+49 30 5550000', 'emergency_phone' => '+49 170 5550202', 'email' => 'office@example.test',
            'website' => 'https://example.test', 'managing_directors' => 'M. Muster', 'register_court' => 'Beispielgericht',
            'commercial_register_number' => '123456', 'vat_id' => 'DE123456789', 'tax_number' => '00/000/00000',
        ]));
        $family = new ReflectionClass(V27PersonalLayoutTest::class);
        $source = $family->getConstant('SOURCE');
        $this->system = $this->document(MailDocumentKind::Signature, $source, '', ['is_active' => true]);
        $this->personal = $this->document(MailDocumentKind::Signature, str_replace('design-train-logo', 'design-train-logo design-personal-columns', $source), '', ['outlook_default' => true]);
        $this->document(MailDocumentKind::Template, $family->getConstant('TEMPLATE'), $family->getConstant('TEMPLATE_CSS'), [
            'is_active' => true, 'outlook_released' => true, 'outlook_default' => true,
            'signature_document_id' => $this->personal->id, 'published_signature_document_id' => $this->personal->id,
        ]);
        $this->offered = $this->document(MailDocumentKind::Template, $family->getConstant('TEMPLATE'), $family->getConstant('TEMPLATE_CSS'), [
            'is_outlook_template' => true, 'outlook_released' => true,
            'signature_document_id' => $this->personal->id, 'published_signature_document_id' => $this->personal->id,
        ]);
        $fixture = new OutlookBrandedSignatureIntegrationTest('test_actual_global_paired_desktop_and_mobile_documents_keep_exact_media_and_budgets');
        (new ReflectionMethod($fixture, 'matchCanonicalTemplateFixtureFragments'))->invoke($fixture);
        $this->offered->refresh();
        $this->app->forgetScopedInstances();
    }

    public function test_system_preview_projects_only_the_exact_selected_published_signature_without_activating_it(): void
    {
        $selected = $this->markedSystemSignature();
        $compiled = $this->compiled($selected);
        $expected = OutlookStaticLinkedTestDelivery::renderedPreview($compiled, OutlookStaticLinkedTestDelivery::PROFILE, self::ORIGIN);
        $before = $this->rows();
        $response = $this->preview($selected)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame($expected, $response->json('preview.html'));
        self::assertSame(strlen($expected), $response->json('preview.html_bytes'));
        self::assertSame($selected->html, $response->json('document.html'));
        self::assertSame($selected->css, $response->json('document.css'));
        self::assertDoesNotMatchRegularExpression('/cid:|data:|\.gif(?:[?"\'])|data-rt-static/i', $expected);
        self::assertStringContainsString(self::ORIGIN.'/m/', $expected);
        self::assertSame($before, $this->rows());
        self::assertTrue($this->system->fresh()->is_active);
        self::assertNull($selected->fresh()->is_active);
        self::assertSame(MailDocumentStatus::Draft, $selected->fresh()->status, 'An inactive published snapshot need not become the active system signature.');
        Http::assertNothingSent();
    }

    public static function untouchedPreviewCases(): array
    {
        return [['unmarked'], ['unpublished'], ['edited-html'], ['edited-css']];
    }

    #[DataProvider('untouchedPreviewCases')]
    public function test_unmarked_and_draft_previews_keep_the_exact_existing_compiler_output(string $case): void
    {
        $selected = $case === 'unmarked' ? $this->system : $this->markedSystemSignature();
        if ($case === 'unpublished') {
            $selected->forceFill(['published_at' => null, 'published_html' => null, 'published_css' => null])->saveQuietly();
        }
        $html = $selected->html.($case === 'edited-html' ? '<!-- synthetic unsaved draft -->' : '');
        $css = $selected->css.($case === 'edited-css' ? '.rt-sign-name{letter-spacing:1px;}' : '');
        $expected = $this->compiled($selected, $html, $css);
        $before = $this->rows();
        $response = $this->preview($selected, $html, $css)->assertOk();
        self::assertSame($expected, $response->json('preview.html'));
        self::assertStringNotContainsString(self::ORIGIN.'/m/', $expected);
        self::assertSame($before, $this->rows());
    }

    public function test_unknown_published_marker_and_stale_hash_are_rejected_without_a_projected_response(): void
    {
        $selected = $this->markedSystemSignature();
        $bad = str_replace('data-rt-static="v1"', 'data-rt-static="v2"', $selected->html);
        $selected->forceFill(['html' => $bad, 'published_html' => $bad])->saveQuietly();
        $before = $this->rows();
        $this->preview($selected)->assertUnprocessable()->assertJsonValidationErrors('preview')->assertJsonMissingPath('preview');
        $this->preview($selected, hash: str_repeat('0', 64))->assertUnprocessable()->assertJsonValidationErrors('expected_hash');
        self::assertSame($before, $this->rows());
    }

    public function test_publication_change_between_fresh_preview_reads_rejects_the_result(): void
    {
        $selected = $this->markedSystemSignature();
        $changed = false;
        DB::listen(static function (QueryExecuted $query) use ($selected, &$changed): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'published_at') && str_contains($query->sql, 'published_html')
                && in_array($selected->id, $query->bindings, true) && in_array('signature', $query->bindings, true)) {
                $changed = true;
                DB::table('mail_documents')->where('id', $selected->id)->update(['published_css' => '.rt-sign-name{letter-spacing:1px;}']);
            }
        });
        $this->preview($selected)->assertUnprocessable()->assertJsonValidationErrors('expected_hash')->assertJsonMissingPath('preview');
        self::assertTrue($changed, 'The simulated publication change must occur after the first selected-source read.');
    }

    public function test_bootstrap_projects_a_valid_pair_before_etag_and_template_only_filtering(): void
    {
        $this->markOfferedPair();
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $canonical = $snapshots->currentForUser($this->actor);
        $cached = Storage::disk('private')->get($snapshots->pathForUser($this->actor));
        $wire = OutlookClientPresentation::payload($canonical);
        $expected = OutlookStaticLinkedTestProfiles::payload($wire, $this->actor);
        $before = $this->rows();
        $response = $this->getJson('/api/outlook-addin/bootstrap', $this->headers())->assertOk();
        foreach (['signature', 'template', 'templates', 'version'] as $field) {
            self::assertSame($expected[$field], $response->json($field));
        }
        $etag = '"'.hash('sha256', json_encode($response->json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'"';
        $response->assertHeader('ETag', $etag);
        $this->getJson('/api/outlook-addin/bootstrap', $this->headers() + ['If-None-Match' => $etag])->assertStatus(304);
        self::assertSame($before, $this->rows());
        $this->preferences(signature: false, template: true, selected: $this->offered->public_id);
        $before = $this->rows();
        $only = $this->getJson('/api/outlook-addin/bootstrap', $this->eventHeaders())->assertOk()
            ->assertJsonMissingPath('signature')->assertJsonMissingPath('template')
            ->assertJsonPath('automaticTemplateId', $this->offered->public_id)->assertJsonPath('templates.0.composeMedia', []);
        $projected = collect($expected['templates'])->firstWhere('id', $this->offered->public_id);
        self::assertSame($projected['composeHtml'], $only->json('templates.0.composeHtml'));
        self::assertSame($projected['version'], $only->json('templates.0.version'));
        self::assertStringNotContainsString('RT-SIGNATURE-', $only->json('templates.0.composeHtml'));
        self::assertSame($before, $this->rows());
        self::assertSame($cached, Storage::disk('private')->get($snapshots->pathForUser($this->actor)));
    }

    public function test_bootstrap_quarantines_a_half_marked_offer_preserves_signature_only_and_rejects_actual_selection(): void
    {
        $this->markOfferedPair(signature: false);
        $snapshots = app(OutlookAddinUserSnapshotStore::class);
        $canonical = $snapshots->currentForUser($this->actor);
        $cached = Storage::disk('private')->get($snapshots->pathForUser($this->actor));
        $wire = OutlookClientPresentation::payload($canonical);
        $expected = array_values(array_filter($wire['templates'], fn (array $entry): bool => $entry['id'] !== $this->offered->public_id));
        $before = $this->rows();
        $response = $this->getJson('/api/outlook-addin/bootstrap', $this->headers())->assertOk();
        self::assertSame($expected, $response->json('templates'));
        foreach (['signature', 'template', 'version'] as $field) {
            self::assertSame($wire[$field], $response->json($field));
        }
        self::assertSame($before, $this->rows());
        $this->preferences(signature: true, template: false);
        $before = $this->rows();
        $only = $this->getJson('/api/outlook-addin/bootstrap', $this->eventHeaders())->assertOk()
            ->assertJsonPath('templates', [])->assertJsonPath('automaticTemplateId', null)->assertJsonMissingPath('template');
        self::assertSame($wire['signature'], $only->json('signature'));
        self::assertSame(array_intersect_key($wire['version'], ['signature' => true, 'personal' => true]), $only->json('version'));
        self::assertSame($before, $this->rows());
        $this->preferences(signature: true, template: true, selected: $this->offered->public_id);
        $before = $this->rows();
        $this->getJson('/api/outlook-addin/bootstrap', $this->eventHeaders())->assertStatus(409)
            ->assertJsonPath('error', 'outlook_addin_static_profile_invalid')->assertJsonMissingPath('templates')->assertJsonMissingPath('signature');
        self::assertSame($before, $this->rows());
        self::assertSame($cached, Storage::disk('private')->get($snapshots->pathForUser($this->actor)));
    }

    public function test_selected_system_testmail_emits_static_https_png_mime_without_activation_or_callback_leak(): void
    {
        $selected = $this->markedSystemSignature();
        $transport = $this->arrayTransport();
        $snapshots = app(PublishedMailDocumentSnapshotStore::class);
        $snapshots->useSnapshot(MailDocumentKind::Signature, $selected->html, $selected->css);
        try {
            $mail = $this->notification($selected)->toMail(new \stdClass);
            self::assertSame([], $mail->callbacks);
            $compiled = (string) app(Markdown::class)->render($mail->markdown, $mail->data());
            $expected = OutlookStaticLinkedTestDelivery::renderedPreview($compiled, OutlookStaticLinkedTestDelivery::PROFILE, self::ORIGIN);
        } finally {
            $snapshots->forget(MailDocumentKind::Signature);
        }
        $before = $this->rows();
        $this->sendEditorTestMail($selected)->assertOk()->assertJsonPath('recipient', 'static-system-test@example.test');
        $email = $transport->messages()->sole()->getOriginalMessage();
        self::assertInstanceOf(Email::class, $email);
        $html = $email->getHtmlBody();
        self::assertSame($expected, $html);
        self::assertStringContainsString(self::ORIGIN.'/m/', $html);
        self::assertDoesNotMatchRegularExpression('/cid:|data:|\.gif(?:[?"\'])|data-rt-static/i', $html);
        self::assertSame([], $email->getAttachments());
        self::assertStringNotContainsString('Content-ID:', $email->toString());
        self::assertStringNotContainsString('image/gif', $email->toString());
        self::assertNotEmpty($email->getTextBody());
        self::assertSame('static-system-test@example.test', $email->getTo()[0]->getAddress());
        self::assertSame($before, $this->rows());
        self::assertTrue($this->system->fresh()->is_active);
        self::assertNull($selected->fresh()->is_active);

        $transport->flush();
        $this->sendEditorTestMail($this->system)->assertOk();
        $ordinary = $transport->messages()->sole()->getOriginalMessage();
        self::assertStringContainsString('src="cid:', $ordinary->getHtmlBody());
        self::assertStringNotContainsString(self::ORIGIN.'/m/', $ordinary->getHtmlBody());
        self::assertNotEmpty($ordinary->getAttachments());
        self::assertSame($before, $this->rows());
        Http::assertNothingSent();
    }

    public function test_unmarked_testmail_stays_byte_exact_to_the_existing_four_argument_notification(): void
    {
        $transport = $this->arrayTransport();
        $snapshots = app(PublishedMailDocumentSnapshotStore::class);
        $snapshots->useSnapshot(MailDocumentKind::Signature, $this->system->html, $this->system->css);
        try {
            Notification::route('mail', 'static-system-test@example.test')->notify($this->notification($this->system));
        } finally {
            $snapshots->forget(MailDocumentKind::Signature);
        }
        $baseline = $transport->messages()->sole()->getOriginalMessage();
        $transport->flush();
        $before = $this->rows();
        $this->actingAs($this->actor)->postJson(route('admin.mail-documents.test-mail', $this->system), [
            'expected_hash' => $this->system->content_hash, 'static_profile' => OutlookStaticLinkedTestDelivery::PROFILE,
        ])->assertOk();
        $actual = $transport->messages()->sole()->getOriginalMessage();
        self::assertSame($baseline->getHtmlBody(), $actual->getHtmlBody());
        self::assertSame($baseline->getTextBody(), $actual->getTextBody());
        $attachments = static fn (Email $email): array => array_map(static fn ($part): array => [
            $part->getFilename(), $part->getContentId(), $part->getMediaType().'/'.$part->getMediaSubtype(), $part->getBody(),
        ], $email->getAttachments());
        self::assertSame($attachments($baseline), $attachments($actual));
        self::assertSame($before, $this->rows());
        Http::assertNothingSent();
    }

    public static function deniedStaticTestMailCases(): array
    {
        return [['unpublished'], ['edited-html'], ['edited-css'], ['unknown-marker'], ['stale-hash']];
    }

    #[DataProvider('deniedStaticTestMailCases')]
    public function test_unpublished_changed_or_untrusted_static_testmail_never_reaches_transport(string $case): void
    {
        $selected = $this->markedSystemSignature();
        $transport = $this->arrayTransport();
        $changes = match ($case) {
            'unpublished' => ['published_at' => null, 'published_html' => null, 'published_css' => null],
            'edited-html' => ['html' => $selected->html.'<!-- synthetic changed draft -->'],
            'edited-css' => ['css' => '.rt-sign-name{letter-spacing:1px;}'],
            'unknown-marker' => ['html' => str_replace('data-rt-static="v1"', 'data-rt-static="v2"', $selected->html),
                'published_html' => str_replace('data-rt-static="v1"', 'data-rt-static="v2"', $selected->html)],
            default => [],
        };
        $selected->forceFill($changes)->saveQuietly();
        $before = $this->rows();
        $this->sendEditorTestMail($selected, $case === 'stale-hash' ? str_repeat('0', 64) : null)->assertUnprocessable();
        self::assertCount(0, $transport->messages());
        self::assertSame($before, $this->rows());
        Http::assertNothingSent();
    }

    public function test_static_testmail_rechecks_publication_after_render_before_transport(): void
    {
        $selected = $this->markedSystemSignature();
        $transport = $this->arrayTransport();
        $changed = false;
        DB::listen(static function (QueryExecuted $query) use ($selected, &$changed): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select')
                && str_contains($query->sql, 'published_at') && str_contains($query->sql, 'published_html')
                && in_array($selected->id, $query->bindings, true) && in_array('signature', $query->bindings, true)) {
                $changed = true;
                DB::table('mail_documents')->where('id', $selected->id)->update(['published_css' => '.rt-sign-name{letter-spacing:1px;}']);
            }
        });
        $this->sendEditorTestMail($selected)->assertUnprocessable()->assertJsonValidationErrors('expected_hash');
        self::assertTrue($changed);
        self::assertCount(0, $transport->messages());
        Http::assertNothingSent();
    }

    private function arrayTransport(): ArrayTransport
    {
        self::assertSame('array', config('mail.default'));
        $transport = Mail::mailer()->getSymfonyTransport();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        $transport->flush();
        Setting::setValue('mails', 'admin_email', 'static-system-test@example.test');

        return $transport;
    }

    private function sendEditorTestMail(MailDocument $document, ?string $hash = null): TestResponse
    {
        return $this->actingAs($this->actor)->postJson(route('admin.mail-documents.test-mail', $document), [
            'expected_hash' => $hash ?? $document->content_hash,
        ]);
    }

    private function notification(MailDocument $document): MailDocumentTestNotification
    {
        return new MailDocumentTestNotification($document->kind, (int) $document->version,
            SignatureArtifactVersion::detect($document->kind, $document->html), (string) $document->content_hash);
    }

    private function markedSystemSignature(): MailDocument
    {
        $source = preg_replace('~<tr\b(?=[^>]*\bdata-rt-artifact-version="v27")~', '$0 data-rt-static="v1"', $this->system->html, -1, $count);
        self::assertSame(1, $count);

        return $this->document(MailDocumentKind::Signature, $source, '', ['status' => MailDocumentStatus::Draft]);
    }

    private function preview(MailDocument $document, ?string $html = null, ?string $css = null, ?string $hash = null): TestResponse
    {
        return $this->actingAs($this->actor)->postJson(route('admin.mail-documents.delivery-preview', $document), [
            'html' => $html ?? $document->html, 'css' => $css ?? $document->css,
            'builder_data' => $document->builder_data, 'expected_hash' => $hash ?? $document->content_hash,
        ]);
    }

    private function compiled(MailDocument $document, ?string $html = null, ?string $css = null): string
    {
        return (new ReflectionMethod(MailDocumentController::class, 'compileFinalSystemMailCandidate'))->invoke(
            new MailDocumentController, app(PublishedMailDocumentSnapshotStore::class), $document,
            $html ?? $document->html, $css ?? $document->css, false,
        );
    }

    private function markOfferedPair(bool $signature = true): void
    {
        $source = preg_replace('~<table\b(?=[^>]*\bclass="[^"]*\brt-shell\b)(?=[^>]*\bclass="[^"]*\bdesign-v27\b)~', '$0 data-rt-static="v1"', $this->offered->html, -1, $count);
        self::assertSame(1, $count);
        $attributes = ['html' => $source, 'published_html' => $source];
        if ($signature) {
            $marked = preg_replace('~<tr\b(?=[^>]*\bdata-rt-artifact-version="v27")~', '$0 data-rt-static="v1"', $this->personal->html, -1, $signatureCount);
            self::assertSame(1, $signatureCount);
            $pair = $this->document(MailDocumentKind::Signature, $marked, '');
            $attributes += ['signature_document_id' => $pair->id, 'published_signature_document_id' => $pair->id];
        }
        $this->offered->forceFill($attributes)->saveQuietly();
        $this->app->forgetScopedInstances();
    }

    private function preferences(bool $signature, bool $template, ?string $selected = null): void
    {
        Setting::setValue('outlook_addin', 'automatic_insertion_user_'.$this->actor->id, ['schema' => 2,
            'newMessages' => ['signature' => $signature, 'template' => $template], 'replies' => ['signature' => true, 'template' => true]]);
        if ($selected !== null) {
            Setting::setValue('outlook_addin', 'template_choices_user_'.$this->actor->id, ['schema' => 1,
                'newMessages' => ['mode' => 'document', 'documentId' => $selected], 'replies' => ['mode' => 'default', 'documentId' => null]]);
        }
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['mail_documents', 'mail_document_versions', 'settings'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    private function document(MailDocumentKind $kind, string $html, string $css, array $attributes = []): MailDocument
    {
        $builder = ['pages' => [['component' => $html]], 'styles' => [], 'railtime' => ['document' => $kind->value, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::withoutEvents(fn () => MailDocument::create($attributes + [
            'kind' => $kind, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic '.Str::random(8),
            'status' => MailDocumentStatus::Published, 'is_active' => null, 'outlook_default' => null,
            'html' => $html, 'css' => $css, 'builder_data' => $builder, 'published_html' => $html, 'published_css' => $css,
            'published_at' => now(), 'content_hash' => MailDocument::contentHashFor($builder, $html, $css), 'version' => 1,
        ]));
    }

    private function eventHeaders(): array
    {
        return array_replace($this->headers(), ['X-RailTime-Outlook-Context' => 'event',
            'X-RailTime-Automatic-Policy' => '3', 'X-RailTime-Compose-Type' => 'newMail']);
    }

    private function headers(): array
    {
        if ($this->privateKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertTrue(openssl_pkey_export($key, $private));
            $this->privateKey = $private;
            $details = openssl_pkey_get_details($key);
            $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            Http::fake(['https://login.microsoftonline.com/'.self::TENANT.'/discovery/v2.0/keys' => Http::response(['keys' => [[
                'kty' => 'RSA', 'use' => 'sig', 'kid' => 'static-profile-http', 'alg' => 'RS256',
                'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e']),
            ]]])]);
        }
        $token = JWT::encode(['aud' => self::CLIENT, 'azp' => self::CLIENT, 'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'iat' => time() - 5, 'nbf' => time() - 5, 'exp' => time() + 300, 'tid' => self::TENANT, 'oid' => self::OBJECT,
            'scp' => 'Signature.Read', 'preferred_username' => 'own@example.test'], $this->privateKey, 'RS256', 'static-profile-http');

        return ['Authorization' => 'Bearer '.$token, 'X-RailTime-Outlook-Mailbox' => 'own@example.test',
            'X-RailTime-Outlook-Sender' => 'own@example.test', 'X-RailTime-Compose-Contract' => 'native-signature-v1',
            'X-RailTime-Outlook-Context' => 'taskpane'];
    }
}
