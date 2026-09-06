<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Http\Controllers\Admin\MailDocumentController;
use App\Livewire\Admin\MailDocumentEditor;
use App\Livewire\Admin\MailDocumentLibrary;
use App\Models\MailDocument;
use App\Models\MailDocumentVersion;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserProfile;
use App\Notifications\MailDocumentTestNotification;
use App\Services\PageBuilder\PageBuilderPreviewService;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\CssSemantic;
use App\Support\Mail\EmailCompatibilityAuditor;
use App\Support\Mail\EmailCompatibilityCatalog;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\PortableMediaCatalog;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureArtifactVersion;
use App\Support\Mail\SignatureBackgroundContract;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTrainCarrier;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\Mail\TrustedEmailCss;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\MailSignature;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookTemplateLibrary;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Der Editor der beiden Maildokumente: Berechtigung, optimistische Sperre,
 * Haertung, Veroeffentlichen — und der Einhaengepunkt im Renderpfad.
 *
 * Die Messlatte steht in test_ohne_veroeffentlichte_fassung_bleibt_alles_wie_bisher:
 * ohne veroeffentlichtes Dokument darf sich am Ergebnis nichts aendern.
 */
class MailDocumentEditorTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        // Die Klasse prueft viele absichtlich abgelehnte Save-/Publish-Faelle
        // in einem Prozess. Das fachliche Ergebnis darf dabei nicht vom
        // globalen HTTP-Rate-Limit des vorigen Testfalls abhaengen.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->buildMinimalRailTimeSchema();
        // Die Tabelle gehoert nicht zum Minimalschema — hier kommt sie aus
        // der echten Migration, damit Spalten und Test nicht auseinanderlaufen.
        (include database_path('migrations/2026_08_09_000100_create_mail_documents_table.php'))->up();
        (include database_path('migrations/2026_08_22_000200_create_mail_document_versions_table.php'))->up();
        (include database_path('migrations/2026_08_27_000100_add_design_slots_to_mail_documents.php'))->up();

        // LogActivity haengt an jeder schreibenden Anfrage.
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Admin Beispiel']);
    }

    public function test_outlook_library_releases_and_defaults_are_isolated_from_system_mail(): void
    {
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        (include database_path('migrations/2026_09_06_010000_add_outlook_library_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $admin = $this->admin();
        $system = $this->document(MailDocumentKind::Template);
        $systemPublished = $system->published_html;
        $library = app(OutlookTemplateLibrary::class);
        $draft = $library->createDraft($admin, 'Mitarbeiter-Angebot');

        $this->assertTrue($draft->isOutlookTemplate());
        $this->assertFalse($draft->isActive());
        $this->assertFalse($draft->isPublished());
        $this->assertNull($draft->published_html);
        $this->assertCount(1, $draft->versions);
        $this->assertNotContains($draft->public_id, array_column(app(PublishedMailDocumentSnapshotStore::class)->freshTemplateSnapshots(), 'id'));

        // The normal editor's HTTP publish route must never activate a
        // library document as the system-message shell.
        $this->actingAs($admin)->postJson(route('admin.mail-documents.publish', $draft), [
            'expected_hash' => $draft->content_hash,
        ])->assertOk()->assertJsonPath('document.is_active', false)
            ->assertJsonPath('document.outlook_released', true);
        $draft->refresh();
        $this->assertTrue($draft->isPublished());
        $library->setDefault($admin, $draft, $draft->content_hash);
        $this->assertTrue($draft->fresh()->outlook_default);
        $library->setDefault($admin, $draft->fresh(), $draft->content_hash);
        $this->assertTrue($draft->fresh()->outlook_default, 'Selecting the same default must be idempotent.');
        $this->assertTrue($system->fresh()->isActive());
        $this->assertSame($systemPublished, $system->fresh()->published_html);
        $snapshots = app(PublishedMailDocumentSnapshotStore::class)->freshTemplateSnapshots();
        $this->assertCount(2, $snapshots);
        $this->assertSame($system->public_id, collect($snapshots)->firstWhere('active', true)['id']);
        $this->assertSame($draft->public_id, collect($snapshots)->firstWhere('isDefault', true)['id']);
        $this->assertSame(trim($systemPublished), app(PublishedMailDocumentSnapshotStore::class)->snapshot(MailDocumentKind::Template)['html']);

        $library->withdraw($admin, $draft, $draft->content_hash);
        $this->assertFalse($draft->fresh()->isPublished());
        $this->assertNull($draft->fresh()->outlook_default);
        $this->assertNotNull($draft->fresh()->published_html);
        $this->assertNotContains($draft->public_id, array_column(app(PublishedMailDocumentSnapshotStore::class)->templateSnapshots(), 'id'));
        $this->assertTrue($system->fresh()->isActive());

        $copyResponse = $this->actingAs($admin)->postJson(route('admin.mail-documents.slots.store', $draft), [
            'name' => 'Kopie im Outlook-Ordner', 'expected_hash' => $draft->content_hash,
        ]);
        $copyResponse->assertCreated()->assertJsonPath('document.is_outlook_template', true)
            ->assertJsonPath('document.outlook_released', false)->assertJsonPath('document.outlook_default', false);
        $copy = MailDocument::query()->where('public_id', $copyResponse->json('document.id'))->firstOrFail();
        $this->assertNull($copy->published_html);
        $this->assertFalse($copy->isActive());
    }

    public function test_outlook_library_rejects_drafts_stale_hashes_and_unsafe_release(): void
    {
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        (include database_path('migrations/2026_09_06_010000_add_outlook_library_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $admin = $this->admin();
        $library = app(OutlookTemplateLibrary::class);
        $draft = $library->createDraft($admin, 'Entwurf');
        foreach (['default-draft', 'stale-release', 'unsafe-release'] as $failure) {
            try {
                if ($failure === 'default-draft') {
                    $library->setDefault($admin, $draft, $draft->content_hash);
                } elseif ($failure === 'stale-release') {
                    $library->publish($admin, $draft, str_repeat('0', 64));
                } else {
                    $draft->forceFill(['html' => $draft->html.'<script>alert(1)</script>'])->save();
                    $library->publish($admin, $draft, $draft->content_hash);
                }
                $this->fail('Expected validation rejection: '.$failure);
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertFalse($draft->fresh()->isPublished());
        $this->assertFalse($draft->fresh()->isActive());
        $this->assertNull($draft->fresh()->outlook_default);
    }

    public function test_outlook_library_restore_only_changes_draft_and_keeps_release(): void
    {
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        (include database_path('migrations/2026_09_06_010000_add_outlook_library_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $admin = $this->admin();
        $library = app(OutlookTemplateLibrary::class);
        $draft = $library->createDraft($admin, 'Versionierte Vorlage');
        $original = $draft->versions()->firstOrFail();
        $draft->forceFill(['html' => str_replace('Sicher abgestimmt.', 'Neue Fassung.', $draft->html)])->save();
        $draft = $library->publish($admin, $draft, $draft->content_hash);
        $draft = $library->setDefault($admin, $draft, $draft->content_hash);
        $publishedHtml = $draft->published_html;
        $draft = $library->restoreDraft($admin, $draft, $original, $draft->content_hash);

        $this->assertSame($original->html, $draft->html);
        $this->assertSame($publishedHtml, $draft->published_html);
        $this->assertTrue($draft->isPublished());
        $this->assertTrue($draft->outlook_default);
        $this->assertTrue($draft->hasUnpublishedChanges());
        $this->assertSame('restored', $draft->versions()->first()->action);
    }

    public function test_mail_document_library_livewire_is_admin_only_lightweight_and_creates_only_drafts(): void
    {
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        (include database_path('migrations/2026_09_06_010000_add_outlook_library_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $staff = User::factory()->create(['role' => 'staff']);
        Livewire::actingAs($staff)->test(MailDocumentLibrary::class)->assertForbidden();
        $admin = $this->admin();
        $before = MailDocument::query()->pluck('content_hash', 'public_id')->all();
        $component = Livewire::actingAs($admin)->test(MailDocumentLibrary::class)
            ->assertSee('Vorlagen')->assertSee('Standardvorlage')
            ->assertDontSee('data-page-builder-workspace')
            ->assertDontSee('{{NACHRICHT}}');
        $this->assertSame($before, MailDocument::query()->pluck('content_hash', 'public_id')->all());
        $component->call('openCreate')->set('name', 'Angebot aus Übersicht')->call('createDraft')
            ->assertHasNoErrors()->assertSet('createOpen', false)->assertSee('Angebot aus Übersicht');
        $created = MailDocument::query()->where('name', 'Angebot aus Übersicht')->firstOrFail();
        $this->assertTrue($created->isOutlookTemplate());
        $this->assertNull($created->published_html);
        $this->assertFalse($created->isActive());
        $this->assertFalse($created->isPublished());
        $this->assertSame($before, MailDocument::query()->where('is_outlook_template', false)->pluck('content_hash', 'public_id')->all());

        $component->call('toggleHistory', $created->public_id)->assertSet('historyId', $created->public_id);
        $signature = $this->document(MailDocumentKind::Signature);
        $component->call('selectKind', 'signature')->call('openCreate', $signature->public_id, $signature->content_hash)
            ->set('name', 'Signaturkopie')->call('createDraft')->assertHasNoErrors();
        $copy = MailDocument::query()->where('name', 'Signaturkopie')->firstOrFail();
        $this->assertSame(MailDocumentKind::Signature, $copy->kind);
        $this->assertFalse($copy->isOutlookTemplate());
        $this->assertFalse($copy->isActive());
    }

    public function test_mail_document_library_livewire_confirms_publication_default_withdrawal_and_rejects_stale_confirmation(): void
    {
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        (include database_path('migrations/2026_09_06_010000_add_outlook_library_to_mail_documents.php'))->up();
        $this->createCanonicalMailDocuments();
        $admin = $this->admin();
        $draft = app(OutlookTemplateLibrary::class)->createDraft($admin, 'Angebot');
        $component = Livewire::actingAs($admin)->test(MailDocumentLibrary::class)
            ->call('prepareAction', 'publish', $draft->public_id, $draft->content_hash)
            ->assertSet('confirmOpen', true);
        $this->assertFalse($draft->fresh()->isPublished());
        $component->call('confirmAction')->assertHasNoErrors()->assertSet('confirmOpen', false);
        $draft->refresh();
        $component->call('prepareAction', 'default', $draft->public_id, $draft->content_hash)
            ->call('confirmAction')->assertHasNoErrors();
        $this->assertTrue($draft->fresh()->outlook_default);
        $this->assertTrue($this->document(MailDocumentKind::Template)->isActive());
        $component->call('prepareAction', 'withdraw', $draft->public_id, $draft->content_hash)
            ->call('confirmAction')->assertHasNoErrors();
        $this->assertFalse($draft->fresh()->isPublished());
        $this->assertNull($draft->fresh()->outlook_default);

        $component->call('prepareAction', 'publish', $draft->public_id, $draft->content_hash);
        $draft->forceFill(['content_hash' => str_repeat('a', 64)])->save();
        $component->call('confirmAction')->assertHasErrors(['operation']);
        $this->assertFalse($draft->fresh()->isPublished());
    }

    /**
     * Kanonische Dokumente ALS ENTWURF fuer die Editorfaelle.
     */
    private function seedDocuments(): void
    {
        $this->createCanonicalMailDocuments(published: false);

        app()->forgetScopedInstances();
    }

    private function createCanonicalMailDocuments(bool $published = true): void
    {
        foreach (MailDocumentKind::cases() as $kind) {
            $this->createCanonicalMailDocument($kind, $published);
        }
    }

    private function createCanonicalMailDocument(
        MailDocumentKind $kind,
        bool $published = true,
        ?string $name = null,
        ?bool $isActive = null,
    ): MailDocument {
        $html = $this->canonicalMailDocumentHtml($kind);
        $css = '';
        $active = $isActive ?? $published;
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

        return MailDocument::query()->create([
            'kind' => $kind,
            'name' => $name ?? match ($kind) {
                MailDocumentKind::Template => 'Standardvorlage',
                MailDocumentKind::Signature => 'Standardsignatur',
            },
            'status' => $published ? MailDocumentStatus::Published : MailDocumentStatus::Draft,
            'is_active' => $active ? true : null,
            'builder_data' => $builderData,
            'html' => $html,
            'css' => $css,
            'published_html' => $published ? $html : null,
            'published_css' => $published ? $css : null,
            'published_at' => $published ? now() : null,
            'content_hash' => MailDocument::contentHashFor($builderData, $html, $css),
            'version' => 1,
        ]);
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

    /** Baut den echten Schema-24-IMG-Stand ohne Layer-position nach. */
    private function legacySchema24Signature(string $canonical, string $overlap = '-7.3611%'): string
    {
        $canonicalLayerStyle = 'style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;"';
        $legacy = str_replace(
            '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">',
            '<div class="rt-sign-stage" style="position:relative;overflow:hidden;">',
            $canonical,
            $stageCount,
        );
        $legacy = str_replace(
            $canonicalLayerStyle,
            'style="display:block;width:100%;max-width:1815px;margin:0 auto;margin-bottom:'.$overlap.';overflow:hidden;font-size:0;line-height:0;text-align:left;"',
            $legacy,
            $layerCount,
        );
        $legacy = preg_replace(
            '~<table class="rt-sign-train-frame"[^>]*>\s*<tr>\s*<td class="rt-sign-train-slot"[^>]*>\s*~i',
            '',
            $legacy,
            1,
            $frameOpenCount,
        );
        $legacy = preg_replace(
            '~\s*</td>\s*</tr>\s*</table>\s*(?=</div>)~i',
            '',
            $legacy,
            1,
            $frameCloseCount,
        );
        $legacy = str_replace(
            '<table class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">',
            '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">',
            (string) $legacy,
            $contentFrameCount,
        );
        $legacy = str_replace(
            'text-decoration:none;vertical-align:bottom;mso-hide:all;',
            'text-decoration:none;vertical-align:top;mso-hide:all;',
            $legacy,
            $imageAlignmentCount,
        );

        $this->assertSame([1, 1, 1, 1, 1, 1], [
            $stageCount,
            $layerCount,
            $frameOpenCount,
            $frameCloseCount,
            $contentFrameCount,
            $imageAlignmentCount,
        ]);

        return $legacy;
    }

    /** Baut die bis V17 gespeicherte RTL-/Rowspan-Kontaktstruktur nach. */
    private function legacyV17ContactLayout(string $html): string
    {
        $html = str_replace(
            'class="rt-sign-layout" role="presentation" width="100%"',
            'class="rt-sign-layout" role="presentation" dir="rtl" width="100%"',
            $html,
            $layoutCount,
        );
        $html = str_replace(
            'style="width:100%;table-layout:fixed;border-collapse:collapse;position:relative;z-index:1;"',
            'style="direction:rtl;width:100%;border-collapse:collapse;position:relative;z-index:1;"',
            $html,
            $layoutStyleCount,
        );
        $html = preg_replace(
            '~<tr>\s*<td class="rt-sign-logo" colspan="2" width="100%"~',
            '<tr class="rt-stack rt-sign-top-row"><td class="rt-sign-logo" dir="ltr" width="50%"',
            $html,
            1,
            $logoRowCount,
        );
        $html = preg_replace(
            '~</td>\s*</tr>\s*<tr class="rt-stack rt-sign-top-row">\s*<td class="rt-sign-identity" dir="ltr" width="50%"~',
            '</td><td class="rt-sign-identity" dir="ltr" rowspan="2" width="50%"',
            (string) $html,
            1,
            $identityBridgeCount,
        );
        $html = preg_replace(
            '~</td>\s*<td class="rt-sign-company" dir="ltr" width="50%"~',
            '</td></tr><tr class="rt-sign-company-row"><td class="rt-sign-company" dir="ltr" width="50%"',
            (string) $html,
            1,
            $companyRowCount,
        );
        $this->assertIsString($html);

        $this->assertSame([1, 1, 1, 1, 1], [
            $layoutCount,
            $layoutStyleCount,
            $logoRowCount,
            $identityBridgeCount,
            $companyRowCount,
        ]);

        return $html;
    }

    /** @return list<array<string, int|string>> */
    private function portableSystemMedia(
        MailDocumentKind $kind,
        ?string $artifactVersion = null,
    ): array {
        return array_map(static function (string $id): array {
            $path = public_path('mail-assets/'.$id);
            $binary = (string) file_get_contents($path);
            $extension = strtolower((string) pathinfo($id, PATHINFO_EXTENSION));

            return [
                'id' => $id,
                'name' => $id,
                'source' => 'https://export.example/mail-assets/'.$id,
                'mime_type' => $extension === 'gif' ? 'image/gif' : 'image/png',
                'bytes' => strlen($binary),
                'sha256' => hash('sha256', $binary),
                'data' => base64_encode($binary),
            ];
        }, PortableMediaCatalog::requiredSystemAssetIds($kind, $artifactVersion));
    }

    /**
     * Baut ausschliesslich fuer die Runtime-Kompatibilitaetstests den vor
     * Schema 9 veroeffentlichten Background-Vertrag aus dem heutigen,
     * editierbaren IMG-Vertrag nach. Neue Editorstaende duerfen diese
     * Form nicht mehr speichern.
     */
    private function legacyBackgroundSignature(string $canonicalHtml, bool $withIdle = false): string
    {
        $canonicalImage = 'background-image:linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}});';
        $canonicalRepeat = 'background-repeat:no-repeat;';
        $canonicalPosition = 'background-position:center center;';
        $canonicalSize = 'background-size:100% 100%;';

        $legacyImage = 'background-image:url({{GRUND_RASTER_SRC}}),url({{GRUND_MARKE_SRC}}),'
            .'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}});';
        $legacyRepeat = 'background-repeat:repeat,no-repeat,no-repeat;';
        $legacyPosition = 'background-position:left top,right center,center center;';
        $legacySize = 'background-size:64px 64px,auto 100%,100% 100%;';

        $trainImages = $withIdle
            ? 'url({{TRAIN_IDLE_SRC}}),url({{TRAIN_SRC}})'
            : 'url({{TRAIN_SRC}})';
        $trainRepeats = $withIdle ? 'no-repeat,no-repeat' : 'no-repeat';
        $trainPositions = $withIdle ? 'right bottom,right bottom' : '75% bottom';
        $trainSizes = $withIdle ? 'auto 100%,auto 100%' : 'auto 100%';

        $html = str_replace(
            $canonicalImage.$canonicalRepeat.$canonicalPosition.$canonicalSize,
            rtrim($legacyImage, ';').','.$trainImages.';'
                .rtrim($legacyRepeat, ';').','.$trainRepeats.';'
                .rtrim($legacyPosition, ';').','.$trainPositions.';'
                .rtrim($legacySize, ';').','.$trainSizes.';',
            $canonicalHtml,
            $backgroundCount,
        );
        $this->assertSame(1, $backgroundCount);

        $html = preg_replace(
            '/<div\b(?=[^>]*class="rt-sign-train-layer")(?=[^>]*\bdata-rt-layer-train\b)[^>]*>[\s\S]*?<\/div>/i',
            '',
            $html,
            1,
            $imageLayerCount,
        );
        $this->assertIsString($html);
        $this->assertSame(1, $imageLayerCount);

        // Der alte Background-Vertrag besass noch keine sichere Stage. Sie
        // wird beim Runtime-Upgrade gemeinsam mit dem neuen, leeren Zug-Layer
        // genau einmal durch projectAsImage() erzeugt.
        $html = str_replace(
            '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">',
            '',
            $html,
            $stageOpenCount,
        );
        $html = str_replace(
            '<table class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">',
            '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">',
            $html,
            $contentFrameCount,
        );
        $html = preg_replace(
            '/<\/div>(\s*<\/td>\s*<\/tr>\s*<!-- RT_SIGNATURE_MAIN_END -->)/i',
            '$1',
            $html,
            1,
            $stageCloseCount,
        );
        $this->assertIsString($html);
        $this->assertSame([1, 1, 1], [$stageOpenCount, $stageCloseCount, $contentFrameCount]);

        return $html;
    }

    public function test_erstimport_legt_ein_fehlendes_maildokument_als_entwurf_an(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $html = $this->canonicalMailDocumentHtml(MailDocumentKind::Signature);

        $response = $this->actingAs($admin)->postJson(route('admin.mail-documents.import'), [
            'format' => 'railtime-mail-document',
            'version' => 2,
            'kind' => MailDocumentKind::Signature->value,
            'html' => $html,
            'css' => '',
            'media' => $this->portableSystemMedia(MailDocumentKind::Signature),
        ]);

        $response->assertCreated()
            ->assertJsonPath('document.kind', MailDocumentKind::Signature->value)
            ->assertJsonPath('document.status', MailDocumentStatus::Draft->value)
            ->assertJsonPath('compatibility.catalog_version', '1.0.1')
            ->assertJsonPath('redirect', route('admin.mail-documents.editor', [
                'dokument' => MailDocumentKind::Signature->value,
                'slot' => $response->json('document.id'),
                'open' => 1,
            ]));

        $document = $this->document(MailDocumentKind::Signature);
        $this->assertSame(MailDocumentStatus::Draft, $document->status);
        $this->assertNull($document->published_html);
        $this->assertNull($document->published_at);
        $this->assertSame($admin->getKey(), $document->created_by);
        $this->assertSame($html, (string) $document->html);
        $this->assertSame((string) data_get($document->builder_data, 'pages.0.component'), (string) $document->html);
        $this->assertSame(
            MailDocument::contentHashFor($document->builder_data ?: [], (string) $document->html, ''),
            $document->content_hash,
        );
    }

    public function test_erstimport_ueberschreibt_keinen_bestehenden_editorstand(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $existing = $this->createCanonicalMailDocument(MailDocumentKind::Signature, published: false);
        $before = [
            ...$existing->only(['html', 'content_hash', 'version']),
            'updated_at' => $existing->updated_at?->toIso8601String(),
        ];

        $this->actingAs($admin)->postJson(route('admin.mail-documents.import'), [
            'format' => 'railtime-mail-document',
            'version' => 2,
            'kind' => MailDocumentKind::Signature->value,
            'html' => $this->canonicalMailDocumentHtml(MailDocumentKind::Signature),
            'css' => '',
            'media' => $this->portableSystemMedia(MailDocumentKind::Signature),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $fresh = $existing->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($before, [
            ...$fresh->only(['html', 'content_hash', 'version']),
            'updated_at' => $fresh->updated_at?->toIso8601String(),
        ]);
        $this->assertSame(1, MailDocument::query()->count());
    }

    public function test_externe_importseite_bleibt_ohne_builder_erreichbar_und_listet_design_slots(): void
    {
        $template = $this->createCanonicalMailDocument(
            MailDocumentKind::Template,
            published: true,
            name: 'Aktive Vorlage',
        );
        $signature = $this->createCanonicalMailDocument(
            MailDocumentKind::Signature,
            published: false,
            name: 'Signatur Entwurf',
        );
        $admin = $this->admin();

        $this->get(route('admin.mail-documents.import-page'))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.mail-documents.import-page'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.mail-documents.import-page'))
            ->assertOk()
            ->assertSee('Builder-freier Rettungsimport', escape: false)
            ->assertSee('data-mail-draft-import', escape: false)
            ->assertSee($template->public_id, escape: false)
            ->assertSee($signature->public_id, escape: false)
            ->assertSee(route('admin.mail-documents.draft-import', $template), escape: false)
            ->assertDontSee('data-mail-document-config', escape: false)
            ->assertDontSee('data-mail-document-root', escape: false)
            ->assertDontSee('data-page-builder-workspace', escape: false)
            ->assertDontSee('/vendor/lmz-builder/', escape: false);
    }

    public function test_externer_bundle_import_ersetzt_nur_den_entwurf_und_bewahrt_die_freigabe(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $document = $this->createCanonicalMailDocument(
            MailDocumentKind::Signature,
            published: true,
        );
        $publishedBefore = [
            'published_html' => (string) $document->published_html,
            'published_css' => (string) $document->published_css,
            'published_at' => $document->published_at?->toIso8601String(),
            'status' => $document->status->value,
            'is_active' => $document->is_active,
        ];

        $response = $this->actingAs($admin)->postJson(
            route('admin.mail-documents.draft-import', $document),
            [
                'format' => 'railtime-mail-document',
                'version' => 2,
                'kind' => MailDocumentKind::Signature->value,
                'html' => $this->canonicalMailDocumentHtml(MailDocumentKind::Signature),
                'css' => 'td { color:#111820; }',
                'media' => $this->portableSystemMedia(MailDocumentKind::Signature),
                'expected_hash' => $document->content_hash,
            ],
        );

        $response->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('document.version', 2)
            ->assertJsonPath('document.status', MailDocumentStatus::Published->value)
            ->assertJsonPath('document.is_active', true)
            ->assertJsonPath('document.has_unpublished_changes', true);

        $fresh = $document->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($publishedBefore, [
            'published_html' => (string) $fresh->published_html,
            'published_css' => (string) $fresh->published_css,
            'published_at' => $fresh->published_at?->toIso8601String(),
            'status' => $fresh->status->value,
            'is_active' => $fresh->is_active,
        ]);
        $this->assertSame('td { color:#111820; }', (string) $fresh->css);
        $this->assertSame((string) $fresh->html, (string) data_get($fresh->builder_data, 'pages.0.component'));
        $this->assertSame($admin->getKey(), $fresh->updated_by);
        $this->assertDatabaseHas('mail_document_versions', [
            'mail_document_id' => $fresh->getKey(),
            'revision' => 1,
            'action' => 'imported',
        ]);
    }

    public function test_externer_bundle_import_lehnt_falsche_art_und_veralteten_hash_ohne_aenderung_ab(): void
    {
        Storage::fake('public');
        $admin = $this->admin();
        $document = $this->createCanonicalMailDocument(
            MailDocumentKind::Signature,
            published: false,
        );
        $before = $document->only(['html', 'css', 'content_hash', 'version', 'updated_by']);

        $base = [
            'format' => 'railtime-mail-document',
            'version' => 2,
            'html' => $this->canonicalMailDocumentHtml(MailDocumentKind::Signature),
            'css' => 'td { color:#111820; }',
            'media' => $this->portableSystemMedia(MailDocumentKind::Signature),
            'expected_hash' => $document->content_hash,
        ];

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.draft-import', $document), [
                ...$base,
                'kind' => MailDocumentKind::Template->value,
                'html' => $this->canonicalMailDocumentHtml(MailDocumentKind::Template),
                'media' => $this->portableSystemMedia(MailDocumentKind::Template),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.draft-import', $document), [
                ...$base,
                'kind' => MailDocumentKind::Signature->value,
                'expected_hash' => str_repeat('0', 64),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_hash');

        $this->assertSame($before, $document->fresh()?->only([
            'html',
            'css',
            'content_hash',
            'version',
            'updated_by',
        ]));
        $this->assertSame(0, MailDocumentVersion::query()->count());
    }

    private function document(MailDocumentKind $kind): MailDocument
    {
        return MailDocument::query()->where('kind', $kind->value)->firstOrFail();
    }

    private function renderSystemMail(string $line = 'Kanonischer Anwendungsinhalt'): string
    {
        $message = (new MailMessage)
            ->greeting('Guten Tag')
            ->line($line)
            ->action('RailTime öffnen', 'https://rail-time.example');

        return (string) app(Markdown::class)
            ->render($message->markdown ?: 'notifications::email', $message->data());
    }

    /** @return list<string> */
    private function trainUrlsFromSystemMail(string $html): array
    {
        preg_match_all(
            '~https?://[^"\'\s\)]+zug-dampf-light\.gif[^"\'\s\)]*~i',
            $html,
            $matches,
        );

        return array_map(
            static fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5),
            $matches[0] ?? [],
        );
    }

    public function test_systemmail_verwendet_veroeffentlichte_vorlage_und_signatur_genau_einmal(): void
    {
        $this->createCanonicalMailDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $signature = $this->document(MailDocumentKind::Signature);

        $templateHtml = str_replace(
            '<table class="rt-shell"',
            '<!-- RT-RUNTIME-TEMPLATE --><table class="rt-shell"',
            (string) $template->html,
        );
        $signatureHtml = str_replace(
            '{{POSITION}}',
            'RT-RUNTIME-SIGNATURE {{POSITION}}',
            (string) $signature->html,
        );

        foreach ([[$template, $templateHtml], [$signature, $signatureHtml]] as [$document, $html]) {
            $document->forceFill([
                'html' => $html,
                'builder_data' => [
                    'pages' => [['name' => $document->kind->label(), 'component' => $html]],
                    'styles' => [],
                    'railtime' => ['document' => $document->kind->value, 'schema' => 4],
                ],
                'content_hash' => MailDocument::contentHashFor(
                    [
                        'pages' => [['name' => $document->kind->label(), 'component' => $html]],
                        'styles' => [],
                        'railtime' => ['document' => $document->kind->value, 'schema' => 4],
                    ],
                    $html,
                    '',
                ),
            ])->save();

            $this->actingAs($this->admin())
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertOk();
        }

        $this->app->forgetScopedInstances();
        $html = $this->renderSystemMail();

        $this->assertSame(1, substr_count($html, 'Kanonischer Anwendungsinhalt'));
        $this->assertSame(1, substr_count($html, 'RT-RUNTIME-TEMPLATE'));
        $this->assertSame(1, substr_count($html, 'RT-RUNTIME-SIGNATURE'));
        $this->assertSame(1, preg_match_all('/class="[^"]*rt-sign-cell[^"]*"/', $html));
        $this->assertSame(0, substr_count($html, 'data-rt-outlook-train '));
        $this->assertSame(0, substr_count($html, 'data-rt-outlook-train-still'));
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*rt-sign-cell[^"]*"[^>]*\sbackground=/',
            $html,
        );
        $this->assertStringNotContainsString('data-rt-train-main-image', $html);
        $this->assertStringNotContainsString('data-rt-train-main-layer', $html);
        $this->assertSame(0, substr_count($html, 'rt-classic-outlook-train'));
        // Moderne Clients erhalten genau ein unten verankertes GIF. Classic
        // Outlook erhaelt direkt davor genau ein statisches PNG als Flow-IMG.
        $this->assertSame(1, substr_count($html, 'zug-dampf-light.gif'));
        $this->assertSame(1, substr_count($html, 'zug-dampf-light.png'));
        $this->assertSame(
            1,
            preg_match_all(
                '/<img\b(?=[^>]*class="[^"]*\brt-sign-train(?!-)[^"]*")|<img\b(?=[^>]*\bdata-rt-train(?:\s|=|>))/i',
                $html,
            ),
        );
        $this->assertSame(1, substr_count($html, 'class="rt-sign-stage"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train-mso"'));
        $this->assertSame(1, substr_count($html, 'data-rt-train-mso="1"'));
        $this->assertSame(0, substr_count($html, 'data-rt-train-background'));
        $this->assertStringNotContainsString('<!--[if mso]><tr><td class="rt-sign-train-mso"', $html);
        $this->assertStringContainsString('class="rt-sign-train-mso"', $html);
        $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $html);
        $this->assertStringContainsString('zug-dampf-idle-light.gif', $html);
        $this->assertStringContainsString('data-rt-train-idle-overlay', $html);
        $this->assertStringContainsString('data-rt-train-idle-image', $html);
        $this->assertStringContainsString('animation-delay: 13s;', $html);
        $this->assertStringContainsString('rt-train-idle-reveal', $html);
        $this->assertStringContainsString('rgba(255,255,255,0)', $html);
        $this->assertStringNotContainsString('rgba(255,255,255,.30)', $html);
        $this->assertSame(
            1,
            preg_match('/<td[^>]*class="[^"]*rt-sign-cell[^"]*"[^>]*>/', $html, $trainCarrier),
        );
        $this->assertMatchesRegularExpression('/padding:\s*0;/', $trainCarrier[0]);
        $this->assertStringNotContainsString('zug-dampf-light.gif', $trainCarrier[0]);
        $this->assertStringContainsString('class="rt-sign-cell"', $trainCarrier[0]);
        $this->assertStringNotContainsString('data-rt-train-background', $trainCarrier[0]);
        $this->assertMatchesRegularExpression('/background-repeat:\s*no-repeat;/', $trainCarrier[0]);
        $this->assertMatchesRegularExpression('/background-position:\s*center center;/', $trainCarrier[0]);
        $this->assertMatchesRegularExpression('/background-size:\s*100% 100%;/', $trainCarrier[0]);
        $this->assertStringNotContainsString('signatur-raster-', $html);
        $this->assertStringNotContainsString('signatur-marke-', $html);
        $this->assertSame(1, substr_count($html, 'class="rt-pad rt-sign-content"'));
        $this->assertStringNotContainsString('data:image', $html);
        // V21 ergaenzt den gemeinsamen Runtime-Block um einen kleinen, gezielt
        // versionierten Flow-Reset. Auch V20-Ausgaben bleiben damit unter 61 KiB.
        $this->assertLessThan(61 * 1024, strlen($html));
    }

    public function test_schema_27_behaelt_v14_bytegleich_und_migriert_v15_bis_v20_in_die_fail_open_buehne(): void
    {
        $this->createCanonicalMailDocuments();
        $canonical = (string) $this->document(MailDocumentKind::Signature)->published_html;

        SignatureDocumentContract::assertValid($canonical);
        SignatureDocumentContract::assertRuntimeValid($canonical);

        $canonicalLayerStyle = 'style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;"';
        $this->assertStringContainsString($canonicalLayerStyle, $canonical);
        $this->assertStringContainsString('class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;"', $canonical);
        $this->assertStringContainsString('class="rt-sign-train-frame" role="presentation" width="100%" height="200"', $canonical);
        $this->assertStringContainsString('class="rt-sign-train-slot" height="200" valign="bottom"', $canonical);
        $this->assertStringContainsString('class="rt-sign-content-frame" role="presentation" width="100%" height="200"', $canonical);
        $this->assertSame($canonical, SignatureTrainCarrier::normalize($canonical));

        $v15LegacyGeometry = preg_replace(
            '/^<tr>/',
            '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.SignatureArtifactVersion::V15.'">',
            $canonical,
            1,
            $v15MarkerCount,
        );
        $this->assertIsString($v15LegacyGeometry);
        $this->assertSame(1, $v15MarkerCount);
        $v15 = SignatureTrainCarrier::normalize($v15LegacyGeometry);
        SignatureDocumentContract::assertValid($v15);
        SignatureDocumentContract::assertRuntimeValid($v15);
        $this->assertStringContainsString(
            'class="rt-sign-stage" style="position:relative;height:auto;min-height:200px;overflow:visible;"',
            $v15,
        );
        $this->assertStringContainsString(
            'style="position:relative;z-index:0;display:block;width:100%;height:200px;',
            $v15,
        );
        $this->assertStringContainsString('width="720" height="61" alt=""', $v15);
        $this->assertStringContainsString(
            'style="position:relative;z-index:1;width:100%;height:200px;border-collapse:collapse;"',
            $v15,
        );
        $this->assertSame($v15, SignatureTrainCarrier::normalize($v15));
        $v16 = str_replace(
            [
                SignatureArtifactVersion::V15,
                'data-rt-layer-mobile="train"',
            ],
            [
                SignatureArtifactVersion::V16,
                'data-rt-layer-mobile="stop60"',
            ],
            $v15,
            $v16ReplacementCount,
        );
        $this->assertSame(2, $v16ReplacementCount);
        SignatureDocumentContract::assertValid($v16);
        SignatureDocumentContract::assertRuntimeValid($v16);
        $this->assertStringContainsString('data-rt-layer-mobile="stop60"', $v16);
        $this->assertSame($v16, SignatureTrainCarrier::normalize($v16));

        $v17Base = str_replace(
            SignatureArtifactVersion::V16,
            SignatureArtifactVersion::V17,
            $v16,
            $v17MarkerCount,
        );
        $this->assertSame(1, $v17MarkerCount);
        $v17Base = str_replace('width="720" height="61" alt=""', 'width="720" alt=""', $v17Base, $v17HeightCount);
        $this->assertSame(1, $v17HeightCount);
        $v17 = $this->legacyV17ContactLayout($v17Base);
        SignatureDocumentContract::assertValid($v17);
        SignatureDocumentContract::assertRuntimeValid($v17);
        $this->assertStringContainsString('dir="rtl"', $v17);
        $this->assertStringContainsString('rowspan="2"', $v17);
        $this->assertStringContainsString('rt-sign-company-row', $v17);
        $this->assertStringContainsString('width="720" alt=""', $v17);
        $this->assertStringNotContainsString('width="720" height="61" alt=""', $v17);
        $this->assertSame($v17, SignatureTrainCarrier::normalize($v17));

        $v18 = str_replace(
            SignatureArtifactVersion::V17,
            SignatureArtifactVersion::V18,
            $v17Base,
            $v18MarkerCount,
        );
        $this->assertSame(1, $v18MarkerCount);
        SignatureDocumentContract::assertValid($v18);
        SignatureDocumentContract::assertRuntimeValid($v18);
        $this->assertStringNotContainsString('dir="rtl"', $v18);
        $this->assertStringNotContainsString('rowspan=', $v18);
        $this->assertStringNotContainsString('rt-sign-company-row', $v18);
        $this->assertSame($v18, SignatureTrainCarrier::normalize($v18));

        $v19LegacyGeometry = str_replace(
            SignatureArtifactVersion::V18,
            SignatureArtifactVersion::V19,
            $v18,
            $v19MarkerCount,
        );
        $this->assertSame(1, $v19MarkerCount);
        $v19 = SignatureTrainCarrier::normalize($v19LegacyGeometry);
        SignatureDocumentContract::assertValid($v19);
        SignatureDocumentContract::assertRuntimeValid($v19);
        $this->assertStringContainsString(
            'style="position:absolute;z-index:0;left:0;right:0;top:auto;bottom:0;display:block;width:100%;height:61px;max-height:61px;',
            $v19,
        );
        $this->assertStringContainsString(
            'class="rt-sign-train-frame" role="presentation" width="100%" height="61"',
            $v19,
        );
        $this->assertStringContainsString(
            'class="rt-sign-train-slot" height="61" valign="bottom"',
            $v19,
        );
        $this->assertStringContainsString(
            'width="720" height="61" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:block;width:720px;max-width:100%;height:auto;margin:0;',
            $v19,
        );
        $this->assertStringNotContainsString('margin-bottom:-200px', $v19);
        $this->assertStringNotContainsString('dir="rtl"', $v19);
        $this->assertStringNotContainsString('rowspan=', $v19);
        $this->assertSame($v19, SignatureTrainCarrier::normalize($v19));

        $v20FromV18 = str_replace(
            SignatureArtifactVersion::V18,
            SignatureArtifactVersion::V20,
            $v18,
            $v20MarkerCount,
        );
        $this->assertSame(1, $v20MarkerCount);
        SignatureDocumentContract::assertValid($v20FromV18);
        SignatureDocumentContract::assertRuntimeValid($v20FromV18);
        $this->assertStringContainsString(
            'style="position:relative;z-index:0;display:block;width:100%;height:200px;',
            $v20FromV18,
        );
        $this->assertStringContainsString('margin-bottom:-200px', $v20FromV18);
        $this->assertStringContainsString('width="720" alt=""', $v20FromV18);
        $this->assertStringNotContainsString('width="720" height="61" alt=""', $v20FromV18);
        $this->assertStringNotContainsString('position:absolute;z-index:0', $v20FromV18);
        $this->assertSame($v20FromV18, SignatureTrainCarrier::normalize($v20FromV18));

        $v20FromV19 = str_replace(
            SignatureArtifactVersion::V19,
            SignatureArtifactVersion::V20,
            $v19,
            $v20FromV19MarkerCount,
        );
        $this->assertSame(1, $v20FromV19MarkerCount);
        $this->assertSame(
            $v20FromV18,
            SignatureTrainCarrier::normalize($v20FromV19),
            'Nur der vollstaendig kanonische V19-Zugvertrag darf auf die V18-Geometrie von V20 zurueckprojiziert werden.',
        );

        $tamperedV20FromV19 = str_replace(
            'height:61px;max-height:61px;',
            'height:62px;max-height:61px;',
            $v20FromV19,
            $tamperedV20HeightCount,
        );
        $this->assertSame(1, $tamperedV20HeightCount);
        try {
            SignatureTrainCarrier::normalize($tamperedV20FromV19);
            $this->fail('V20 akzeptierte einen manipulierten V19-Zugvertrag als sichere Migration.');
        } catch (\RuntimeException $exception) {
            $this->assertMatchesRegularExpression(
                '/(?:Zug-Layer|kanonischen Bild-Layer|mail-sichere Geometrie|feste Pixelstruktur|feste Tabellenhoehe)/',
                $exception->getMessage(),
            );
        }

        foreach ([
            'RTL-Reordering' => str_replace(
                'class="rt-sign-layout"',
                'class="rt-sign-layout" dir="rtl"',
                $v18,
            ),
            'Rowspan' => str_replace(
                'class="rt-sign-identity"',
                'class="rt-sign-identity" rowspan="2"',
                $v18,
            ),
        ] as $label => $invalidV18) {
            try {
                SignatureDocumentContract::assertValid($invalidV18);
                $this->fail("V18 akzeptierte die fragile Weiterleitungsstruktur: {$label}.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'weder RTL-Reordering noch Rowspan',
                    $exception->getMessage(),
                    $label,
                );
            }
        }
        foreach ([
            'verstecktes Layout' => str_replace(
                'style="width:100%;table-layout:fixed;border-collapse:collapse;position:relative;z-index:1;"',
                'style="display:none;width:100%;table-layout:fixed;border-collapse:collapse;position:relative;z-index:1;"',
                $v18,
            ),
            'versteckte Firma' => str_replace(
                'class="rt-sign-company" dir="ltr" width="50%" valign="top" align="right" style="direction:ltr;',
                'class="rt-sign-company" dir="ltr" width="50%" valign="top" align="right" style="visibility:hidden;direction:ltr;',
                $v18,
            ),
        ] as $label => $hiddenV18) {
            try {
                SignatureDocumentContract::assertValid($hiddenV18);
                $this->fail("V18 akzeptierte einen unsichtbaren Pflichtknoten: {$label}.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('nicht ausblenden', $exception->getMessage(), $label);
            }
        }
        $this->assertSame($canonical, SignatureTrainCarrier::normalize($canonical));

        foreach ([
            'prozentualer Schema-24-Overlap' => '-7.3611%',
            'individueller alter Pixel-Overlap' => '-72px',
        ] as $label => $legacyOverlap) {
            $legacy = $this->legacySchema24Signature($canonical, $legacyOverlap);
            SignatureDocumentContract::assertRuntimeValid($legacy);
            $normalized = SignatureTrainCarrier::normalize($legacy);
            SignatureDocumentContract::assertValid($normalized);
            $this->assertStringContainsString($canonicalLayerStyle, $normalized, $label);
            $this->assertStringNotContainsString('margin-bottom:'.$legacyOverlap, $normalized, $label);
            $this->assertSame($normalized, SignatureTrainCarrier::normalize($normalized), $label);
            try {
                SignatureDocumentContract::assertValid($legacy);
                $this->fail("Der Save-Vertrag akzeptierte die alte variable Geometrie: {$label}.");
            } catch (\RuntimeException $exception) {
                $this->assertMatchesRegularExpression(
                    '/(?:mail-sichere Geometrie|feste Pixelstruktur|Zug-Layer-Stil|kanonischen Bild-Layer)/',
                    $exception->getMessage(),
                    $label,
                );
            }
        }

        $canonicalGeometryAttacks = [
            'fehlende Groessenangabe' => str_replace(
                ' data-rt-layer-size="125"',
                '',
                $canonical,
            ),
            'fehlender Mobilausschnitt' => str_replace(
                ' data-rt-layer-mobile="train"',
                '',
                $canonical,
            ),
            'prozentuale Bildbreite' => str_replace(
                'width="720" alt=""',
                'width="100%" alt=""',
                $canonical,
            ),
            'fremdes height-Attribut am Zugbild' => str_replace(
                'width="720" alt=""',
                'width="720" height="9999" alt=""',
                $canonical,
            ),
            'fremdes border-Attribut am Zugbild' => str_replace(
                'width="720" alt=""',
                'width="720" border="88" alt=""',
                $canonical,
            ),
            'nicht leerer Alternativtext am Zugbild' => str_replace(
                'width="720" alt=""',
                'width="720" alt="x"',
                $canonical,
            ),
            'fremdes Datenattribut am Zugbild' => str_replace(
                'width="720" alt=""',
                'width="720" alt="" data-foreign="x"',
                $canonical,
            ),
        ];
        foreach ($canonicalGeometryAttacks as $name => $invalidGeometry) {
            $this->assertNotSame($canonical, $invalidGeometry, $name);
            foreach ([
                'Save-Vertrag' => static fn () => SignatureDocumentContract::assertValid($invalidGeometry),
                'Runtime-Vertrag' => static fn () => SignatureDocumentContract::assertRuntimeValid($invalidGeometry),
            ] as $contract => $assertion) {
                try {
                    $assertion();
                    $this->fail("{$name}: {$contract} akzeptierte die unvollstaendige Schema-18-Geometrie.");
                } catch (\RuntimeException $exception) {
                    $this->assertMatchesRegularExpression(
                        '/Geometrieangaben|720-Pixel-Fallback|fremde oder fehlende Attribute|fremde oder ungueltige Bildattribute/',
                        $exception->getMessage(),
                        "{$name}: {$contract}",
                    );
                }
            }
        }

        $outerPadding = function (string $declarations) use ($canonical): string {
            $html = preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}'.$declarations,
                $canonical,
                1,
                $count,
            );
            $this->assertIsString($html);
            $this->assertSame(1, $count);

            return $html;
        };
        $innerPadding = function (string $declarations) use ($canonical): string {
            $html = preg_replace(
                '/(<td class="rt-pad rt-sign-content"[^>]*style=")padding:[^;]+;/i',
                '${1}'.$declarations,
                $canonical,
                1,
                $count,
            );
            $this->assertIsString($html);
            $this->assertSame(1, $count);

            return $html;
        };

        // GrapesJS kann dieselbe effektive Box als Kurzform, Longhands oder
        // als Kaskade aus beidem schreiben. Alle Fassungen muessen denselben
        // sicheren Vertrag erfuellen wie der kanonische Starter.
        foreach ([
            'Aussen-Null mit px' => $outerPadding('padding:0px;'),
            'Aussen-Null als Kurzform' => $outerPadding('padding:0 0px 0.0 0.00px;'),
            'Aussen-Null als Longhands' => $outerPadding(
                'padding-top:0px;padding-right:0;padding-bottom:0.0;padding-left:0.00px;'
            ),
            'Aussen-Null nach Kaskade' => $outerPadding(
                'padding:20px;padding-bottom:5px;padding:0 0 0 0;'
            ),
            'Inhalt als Longhands' => $innerPadding(
                'padding-top:18px;padding-right:36px;padding-bottom:0px;padding-left:36px;'
            ),
            'Inhalt nach Kaskade' => $innerPadding(
                'padding:1px;padding:18px 36px 20px;padding-bottom:0;'
            ),
        ] as $name => $equivalent) {
            SignatureDocumentContract::assertValid($equivalent);
            SignatureDocumentContract::assertRuntimeValid($equivalent);
        }

        $cases = [
            'Aussenpadding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:20px;',
                $canonical,
                1,
                $outerPaddingCount,
            ),
            'Padding-Longhand' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;padding-bottom:20px;',
                $canonical,
                1,
                $longhandCount,
            ),
            'Outlook-MSO-Padding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;MSO-PADDING-ALT:20px;',
                $canonical,
                1,
                $msoPaddingCount,
            ),
            'Entity-MSO-Padding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;mso-padding&#45;alt:20px;',
                $canonical,
                1,
                $entityMsoPaddingCount,
            ),
            'doppeltes MSO-Padding' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;mso-padding-alt:0;mso-padding-alt:20px;',
                $canonical,
                1,
                $duplicateMsoPaddingCount,
            ),
            'inneres MSO-Padding' => preg_replace(
                '/(<td class="rt-pad rt-sign-content"[^>]*style="padding:[^;]+;)/i',
                '${1}mso-padding-alt:20px;',
                $canonical,
                1,
                $innerMsoPaddingCount,
            ),
            'unterer Carrier-Rahmen' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;border-bottom:20px solid transparent;',
                $canonical,
                1,
                $bottomBorderCount,
            ),
            'Outlook-MSO-Rahmen' => preg_replace(
                '/(<td class="rt-sign-cell"[^>]*style=")padding:0;/i',
                '${1}padding:0;mso-border-alt:solid #000 20px;',
                $canonical,
                1,
                $msoBorderCount,
            ),
            'fehlende Contentklasse' => str_replace('rt-pad rt-sign-content', 'rt-pad', $canonical, $missingClassCount),
            'inneres Nullpadding' => preg_replace(
                '/(<td class="rt-pad rt-sign-content"[^>]*style=")padding:[^;]+;/i',
                '${1}padding:0;',
                $canonical,
                1,
                $innerPaddingCount,
            ),
            'negatives Inhaltspadding' => $innerPadding('padding:18px 36px -1px;'),
            'fremde Padding-Einheit' => $innerPadding('padding:18px 2rem 0;'),
            'unvollstaendige Padding-Longhands' => $innerPadding(
                'padding-top:18px;padding-right:36px;padding-bottom:0;'
            ),
            'unlesbare Padding-Deklaration' => $innerPadding('padding(18px);'),
            'unlesbares Outlook-Padding' => $innerPadding('padding:18px;mso-padding-alt(20px);'),
            'verschobener Content-Decoy' => str_replace(
                ['rt-pad rt-sign-content', 'rt-sign-identity'],
                ['rt-pad', 'rt-sign-content rt-sign-identity'],
                $canonical,
                $decoyCount,
            ),
        ];

        $this->assertSame(1, $outerPaddingCount);
        $this->assertSame(1, $longhandCount);
        $this->assertSame(1, $msoPaddingCount);
        $this->assertSame(1, $entityMsoPaddingCount);
        $this->assertSame(1, $duplicateMsoPaddingCount);
        $this->assertSame(1, $innerMsoPaddingCount);
        $this->assertSame(1, $bottomBorderCount);
        $this->assertSame(1, $msoBorderCount);
        $this->assertSame(1, $missingClassCount);
        $this->assertSame(1, $innerPaddingCount);
        $this->assertSame(2, $decoyCount);

        foreach ($cases as $name => $invalid) {
            $this->assertIsString($invalid, $name);
            try {
                SignatureDocumentContract::assertValid($invalid);
                $this->fail("Der Schema-7-Vertrag akzeptierte: {$name}");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('padding:0', $exception->getMessage(), $name);
            }
        }
    }

    public function test_runtime_erlaubt_fuer_altstaende_nur_die_exakte_schema_6_carrierform(): void
    {
        $this->createCanonicalMailDocuments();
        $canonical = (string) $this->document(MailDocumentKind::Signature)->published_html;
        $legacy = preg_replace(
            '~<table\b[^>]*>\s*<tr>\s*<td class="rt-pad rt-sign-content"[^>]*style="padding:([^;]+);position:relative;z-index:1;vertical-align:bottom;">~i',
            '',
            $canonical,
            1,
            $wrapperOpenCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(1, $wrapperOpenCount);
        preg_match('/rt-pad rt-sign-content"[^>]*style="padding:([^;]+);/', $canonical, $paddingMatch);
        $this->assertArrayHasKey(1, $paddingMatch);
        $legacy = preg_replace(
            '~</td>\s*</tr>\s*</table>\s*(?=</div>\s*</td>\s*</tr>\s*<!-- RT_SIGNATURE_MAIN_END -->)~i',
            '',
            $legacy,
            1,
            $wrapperCloseCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(1, $wrapperCloseCount);
        // Der historische Schema-6-Carrier hatte unmittelbar eine rt-stack-
        // Zeile. Die heutige Vorlage besitzt davor eine eigene Logozeile;
        // fuer diesen isolierten Altvertrag stellen wir den damaligen
        // Fingerabdruck am ersten Layout-TR explizit wieder her.
        $legacy = preg_replace(
            '~(<table\b[^>]*class="rt-sign-layout"[^>]*>\s*)<tr>~i',
            '$1<tr class="rt-stack">',
            $legacy,
            1,
            $legacyStackCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(1, $legacyStackCount);
        $legacy = str_replace(
            'style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;"',
            'style="display:block;width:100%;max-width:1815px;margin:0 auto;margin-bottom:-7.3611%;overflow:hidden;font-size:0;line-height:0;text-align:left;"',
            $legacy,
            $legacyLayerCount,
        );
        $legacy = preg_replace(
            '~<table class="rt-sign-train-frame"[^>]*>\s*<tr>\s*<td class="rt-sign-train-slot"[^>]*>\s*~i',
            '',
            $legacy,
            1,
            $legacyFrameOpenCount,
        );
        $legacy = preg_replace(
            '~\s*</td>\s*</tr>\s*</table>\s*(?=</div>)~i',
            '',
            $legacy,
            1,
            $legacyFrameCloseCount,
        );
        $legacy = str_replace(
            'text-decoration:none;vertical-align:bottom;mso-hide:all;',
            'text-decoration:none;vertical-align:top;mso-hide:all;',
            $legacy,
            $legacyImageCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(
            [1, 1, 1, 1],
            [$legacyLayerCount, $legacyFrameOpenCount, $legacyFrameCloseCount, $legacyImageCount],
        );
        $legacy = str_replace(
            '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">',
            '',
            $legacy,
            $legacyStageOpenCount,
        );
        $legacy = preg_replace(
            '~</div>\s*(?=</td>\s*</tr>\s*<!-- RT_SIGNATURE_MAIN_END -->)~i',
            '',
            $legacy,
            1,
            $legacyStageCloseCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame([1, 1], [$legacyStageOpenCount, $legacyStageCloseCount]);
        $legacy = preg_replace_callback(
            '/<td class="rt-sign-cell"([^>]*style=")padding:0;/i',
            static fn (array $match): string => '<td class="rt-pad rt-sign-cell"'.$match[1]
                .'padding:'.$paddingMatch[1].';',
            $legacy,
            1,
            $carrierCount,
        );
        $this->assertIsString($legacy);
        $this->assertSame(1, $carrierCount);

        SignatureDocumentContract::assertRuntimeValid($legacy);

        $this->expectException(\RuntimeException::class);
        SignatureDocumentContract::assertValid($legacy);
    }

    public function test_systemmail_normalisiert_einen_legacy_idle_layer_vor_der_tokenersetzung(): void
    {
        $this->createCanonicalMailDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $legacy = $this->legacyBackgroundSignature(
            (string) $signature->published_html,
            withIdle: true,
        );
        $signature->forceFill([
            'html' => $legacy,
            'published_html' => $legacy,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $html = MailSignature::forCompany(playbackNonce: 'legacy-contract')->render();
        $this->assertSame(
            1,
            preg_match('/<td[^>]*class="[^"]*rt-sign-cell[^"]*"[^>]*>/', $html, $carrier),
        );

        // Der Legacy-Idle-Layer wird normalisiert: Hauptzug und Outlook-PNG
        // fliessen, nur der hoehenlose Idle-Holder liegt darueber.
        $this->assertSame(1, substr_count($html, 'zug-dampf-light.gif'));
        $this->assertSame(1, substr_count($html, 'zug-dampf-light.png'));
        $this->assertStringContainsString('zug-dampf-idle-light.gif', $html);
        $this->assertSame(1, substr_count($html, 'class="rt-sign-stage"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train-mso"'));
        $this->assertSame(1, substr_count($html, 'data-rt-train-mso="1"'));
        $this->assertSame(0, substr_count($html, 'data-rt-train-background'));
        $this->assertStringNotContainsString('<!--[if mso]><tr><td class="rt-sign-train-mso"', $html);
        $this->assertStringContainsString('rt-sign-train-mso', $html);
        $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $html);
        $this->assertStringContainsString('data-rt-train-idle-overlay', $html);
        $this->assertStringNotContainsString('data-rt-train-main-image', $html);
        $this->assertStringNotContainsString('data-rt-train-main-layer', $html);
        $this->assertStringContainsString('data-rt-train-idle-image', $html);
        $this->assertStringNotContainsString('zug-dampf-idle-light.gif', $carrier[0]);
        $this->assertStringNotContainsString('zug-dampf-light.gif', $carrier[0]);
        $this->assertStringContainsString(
            'background-repeat:no-repeat;',
            $carrier[0],
        );
        $this->assertStringContainsString(
            'background-position:center center;',
            $carrier[0],
        );
        $this->assertStringContainsString(
            'background-size:100% 100%;',
            $carrier[0],
        );
        $this->assertStringNotContainsString('signatur-raster-', $html);
        $this->assertStringNotContainsString('signatur-marke-', $html);
        $this->assertStringContainsString(
            'linear-gradient(rgba(255,255,255,0),rgba(255,255,255,0))',
            $carrier[0],
        );
        $this->assertStringNotContainsString('left bottom', $carrier[0]);
        $this->assertStringNotContainsString(
            '&amp;p='.substr(hash('sha256', 'legacy-contract'), 0, 32),
            $carrier[0],
        );
        $this->assertStringContainsString(
            '&amp;p='.substr(hash('sha256', 'legacy-contract'), 0, 32),
            $html,
        );

        // Die serverkontrollierten IMG entstehen bewusst erst nach dem
        // Editor-Sanitizer. Fuer die finale Ausgabe gilt der Runtime-IMG-Vertrag.
        SignatureTrainCarrier::assertRuntimeImages($html);
    }

    public function test_aktuelle_zugstruktur_wird_bytegleich_idempotent_normalisiert(): void
    {
        $this->createCanonicalMailDocuments();
        $published = (string) $this->document(MailDocumentKind::Signature)->published_html;
        $signature = MailSignature::forCompany(playbackNonce: 'idempotent-contract');
        $normalizer = new \ReflectionMethod($signature, 'normalizePublishedTrainCarrier');
        $normalizer->setAccessible(true);

        $once = $normalizer->invoke($signature, $published);
        $twice = $normalizer->invoke($signature, $once);

        $this->assertSame($published, $once);
        $this->assertSame($once, $twice);
    }

    public function test_zugnormalisierung_aendert_nur_das_echte_style_attribut_des_carriers(): void
    {
        $this->createCanonicalMailDocuments();
        $published = (string) $this->document(MailDocumentKind::Signature)->published_html;
        $legacy = $this->legacyBackgroundSignature($published, withIdle: true);
        $decoy = <<<'HTML'
data-decoy='<td class="rt-sign-cell" style="background:none">'
HTML;
        $withDecoy = str_replace(
            '<td class="rt-sign-cell"',
            '<td '.$decoy.' class="rt-sign-cell"',
            $legacy,
            $carrierCount,
        );

        $this->assertSame(1, $carrierCount);
        $normalized = SignatureTrainCarrier::normalize($withDecoy);

        $this->assertStringContainsString($decoy, $normalized);
        $this->assertStringNotContainsString('{{TRAIN_IDLE_SRC}}', $normalized);
        $this->assertSame(1, substr_count($normalized, '{{TRAIN_SRC}}'));
    }

    public function test_mehrdeutige_oder_malformed_background_listen_brechen_fail_closed_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $published = $this->legacyBackgroundSignature(
            (string) $this->document(MailDocumentKind::Signature)->published_html,
        );
        $signature = MailSignature::forCompany(playbackNonce: 'malformed-contract');
        $normalizer = new \ReflectionMethod($signature, 'normalizePublishedTrainCarrier');
        $normalizer->setAccessible(true);
        $withTrailingBackgroundShorthand = preg_replace(
            '/(<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*style="[^"]*)(?=")/i',
            '${1}background:none;',
            $published,
            1,
            $shorthandReplacementCount,
        );
        $this->assertSame(1, $shorthandReplacementCount);
        $this->assertIsString($withTrailingBackgroundShorthand);
        $withDuplicateRealStyle = str_replace(
            '<td class="rt-sign-cell"',
            '<td style="background:none" class="rt-sign-cell"',
            $published,
            $duplicateRealStyleCount,
        );
        $this->assertSame(1, $duplicateRealStyleCount);

        $cases = [
            'missing main' => str_replace('{{TRAIN_SRC}}', '{{BROKEN_TRAIN_SRC}}', $published),
            'duplicate main' => str_replace(
                'src="{{TRAIN_SRC}}"',
                'src="{{TRAIN_SRC}}{{TRAIN_SRC}}"',
                $published,
            ),
            'duplicate idle' => str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<img class="rt-train-idle-overlay rt-train-idle-image" data-rt-train-idle-overlay data-rt-train-idle-image src="{{TRAIN_IDLE_SRC}}"><!-- RT_SIGNATURE_MAIN_END -->',
                $published,
            ),
            'non parallel lists' => str_replace(
                'background-repeat:repeat,no-repeat,no-repeat;',
                'background-repeat:repeat,no-repeat;',
                $published,
            ),
            'unbalanced function' => str_replace('linear-gradient(', 'linear-gradient((', $published),
            'duplicate declaration' => str_replace(
                'background-size:',
                'background-size:auto;background-size:',
                $published,
            ),
            'missing background size' => preg_replace(
                '/background-size:[^;]*;/',
                '',
                $published,
                1,
            ),
            'duplicate background image declaration' => str_replace(
                'background-image:',
                'background-image:none;background-image:',
                $published,
            ),
            'commented duplicate background image declaration' => str_replace(
                'background-image:',
                'background-image:none;background-image/**/:',
                $published,
            ),
            'background shorthand after longhands' => $withTrailingBackgroundShorthand,
            'commented background shorthand after longhands' => str_replace(
                'background:none;',
                'background/**/:none;',
                $withTrailingBackgroundShorthand,
            ),
            'entity encoded comment shorthand after longhands' => str_replace(
                'background:none;',
                'background&#47;*;*&#47;:none;',
                $withTrailingBackgroundShorthand,
            ),
            'entity encoded escaped semicolon before background image' => str_replace(
                'background-image:',
                'x&#92;;background-image:',
                $published,
            ),
            'duplicate actual carrier style attribute' => $withDuplicateRealStyle,
            'raw text fake carrier' => '<script type="text/plain"><td class="rt-sign-cell" style="background:none"></script>'.$published,
            'bogus declaration fake carrier' => '<!fake <td class="rt-sign-cell" style="background:none">>'.$published,
        ];

        foreach ($cases as $label => $malformed) {
            try {
                $normalizer->invoke($signature, $malformed);
                $this->fail("{$label}: Die mehrdeutige Signatur wurde nicht abgelehnt.");
            } catch (\ReflectionException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->assertInstanceOf(\RuntimeException::class, $exception, $label);
            }
        }
    }

    public function test_outlook_bildprojektion_umgeht_den_signaturvertrag_nicht(): void
    {
        $this->createCanonicalMailDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $legacy = $this->legacyBackgroundSignature((string) $signature->published_html);
        $malformedForBackgroundRuntime = str_replace(
            'background-repeat:repeat,no-repeat,no-repeat,no-repeat;',
            'background-repeat:repeat,no-repeat,no-repeat;',
            $legacy,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $signature->forceFill([
            'published_html' => $malformedForBackgroundRuntime,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $builder = new EmailTemplateBuilder(User::factory()->create(['name' => 'Outlook Test']));
        $method = new \ReflectionMethod($builder, 'buildOutlookSignatureHtml');
        $method->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $method->invoke($builder, 'light', 'RailTime_files');
    }

    public function test_systemmail_urls_bleiben_cachebar_und_nur_explizite_vorschauen_erhalten_playback_nonce(): void
    {
        $this->createCanonicalMailDocuments();

        $first = MailSignature::forCompany();
        $firstValues = $first->values();
        $this->assertSame($firstValues['TRAIN_SRC'], $first->values()['TRAIN_SRC']);
        $this->assertSame($firstValues['TRAIN_IDLE_SRC'], $first->values()['TRAIN_IDLE_SRC']);
        $this->assertNotSame('', $firstValues['TRAIN_IDLE_SRC']);

        parse_str((string) parse_url($firstValues['TRAIN_SRC'], PHP_URL_QUERY), $firstTrainQuery);
        parse_str((string) parse_url($firstValues['TRAIN_IDLE_SRC'], PHP_URL_QUERY), $firstIdleQuery);
        $this->assertArrayHasKey('v', $firstTrainQuery);
        $this->assertArrayNotHasKey('p', $firstTrainQuery);
        $this->assertArrayNotHasKey('p', $firstIdleQuery);

        $secondValues = MailSignature::forCompany()->values();
        parse_str((string) parse_url($secondValues['TRAIN_SRC'], PHP_URL_QUERY), $secondQuery);
        $this->assertArrayNotHasKey('p', $secondQuery);
        $this->assertSame($firstValues['TRAIN_SRC'], $secondValues['TRAIN_SRC']);

        $explicitA = MailSignature::forCompany(playbackNonce: 'mail/42')->values();
        $explicitB = MailSignature::forCompany(playbackNonce: 'mail/42')->values();
        $this->assertSame($explicitA['TRAIN_SRC'], $explicitB['TRAIN_SRC']);
        $this->assertStringContainsString(
            '&p='.substr(hash('sha256', 'mail/42'), 0, 32),
            $explicitA['TRAIN_SRC'],
        );
        $this->assertStringNotContainsString('mail%2F42', $explicitA['TRAIN_SRC']);

        $withoutAnimation = MailSignature::forCompany(animated: false)->values();
        parse_str((string) parse_url($withoutAnimation['TRAIN_SRC'], PHP_URL_QUERY), $staticQuery);
        $this->assertArrayNotHasKey('p', $staticQuery);

        $mailA = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Replay A</p>'));
        $mailB = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Replay B</p>'));
        $urlsA = $this->trainUrlsFromSystemMail($mailA);
        $urlsB = $this->trainUrlsFromSystemMail($mailB);

        // Eine URL steht im regulaeren Zugbild. Produktive Mails teilen die
        // dateiversionierte Asset-Identitaet, damit Outlook/Proxy-Caches beim
        // erneuten Oeffnen keinen kalten GIF-Download erzwingen.
        $this->assertCount(1, $urlsA);
        $this->assertCount(1, $urlsB);
        $this->assertCount(1, array_unique($urlsA));
        $this->assertCount(1, array_unique($urlsB));
        $this->assertSame($urlsA[0], $urlsB[0]);
        $this->assertStringNotContainsString('&p=', $urlsA[0]);
        $this->assertMatchesRegularExpression(
            '/<p>Replay A<\/p><\/td><\/tr><tr><td height="14" bgcolor="[^"]+" style="height:14px;background:[^;"]+;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;<\/td><\/tr>/',
            $mailA,
        );
    }

    public function test_systemmail_zeigt_identische_firmen_und_notfallnummer_genau_einmal(): void
    {
        CompanyData::save(array_merge(CompanyData::defaults(), [
            'phone' => '04171 546803',
            'emergency_phone' => '+49 (0) 4171 546803',
        ]));
        $this->createCanonicalMailDocuments();

        $html = $this->renderSystemMail();

        $this->assertSame(1, substr_count($html, 'href="tel:+494171546803"'));
        $this->assertSame(1, preg_match_all('/>04171 546803<\/a>/', $html));
        $this->assertStringNotContainsString('>+49 (0) 4171 546803</a>', $html);
        $this->assertSame(1, substr_count($html, 'href="mailto:info@rail-time.de"'));
        $this->assertSame(1, preg_match_all('/>info@rail-time\.de<\/a>/', $html));
    }

    public function test_systemmail_schlaegt_bei_fehlender_freigabe_in_migrierter_installation_fehl(): void
    {
        $this->createCanonicalMailDocuments();
        $this->document(MailDocumentKind::Template)->forceFill([
            'status' => MailDocumentStatus::Draft,
            'published_html' => null,
            'published_css' => null,
            'published_at' => null,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('veröffentlichte Maildokument');

        $this->renderSystemMail();
    }

    public function test_systemmail_hat_vor_der_maildocument_migration_einen_bootstrap_fallback(): void
    {
        Schema::drop('mail_documents');
        $this->app->forgetScopedInstances();

        $html = $this->renderSystemMail('Bootstrap-Inhalt');

        $this->assertSame(1, substr_count($html, 'Bootstrap-Inhalt'));
        $this->assertSame(1, preg_match_all('/class="[^"]*rt-sign-cell[^"]*"/', $html));
        $this->assertStringContainsString('mail-assets/', $html);
    }

    public function test_ohne_veroeffentlichte_fassung_bleibt_alles_wie_bisher(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        UserProfile::create(['user_id' => $user->id, 'position' => 'Disposition']);

        $builder = new EmailTemplateBuilder($user->fresh());
        $withoutTable = $builder->build('vorlage-html')['content'];

        $this->seedDocuments();

        // Angelegt, aber nicht veroeffentlicht: der Rueckfall bleibt in Kraft.
        $this->assertNull(EmailTemplateBuilder::publishedDocument(MailDocumentKind::Template));
        $this->assertSame($withoutTable, (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content']);
    }

    public function test_veroeffentlichte_vorlage_wird_bevorzugt(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();

        $document = $this->document(MailDocumentKind::Template);
        $document->forceFill([
            'published_html' => str_replace(
                '{{SIGNATURE_BLOCK}}',
                '<tr><td>RT-EDITORFASSUNG {{VORNAME_NACHNAME}}</td></tr>{{SIGNATURE_BLOCK}}',
                (string) $document->html,
            ),
            'published_css' => '',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();

        $html = (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content'];

        // Die veroeffentlichte Fassung ist Token-HTML: Profilwert UND Palette
        // werden weiterhin erst beim Rendern eingesetzt.
        $this->assertStringContainsString('RT-EDITORFASSUNG Mara Beispiel', $html);
        $this->assertStringContainsString(EmailTemplateBuilder::emailThemeValues('light')['SURFACE_BG'], $html);
        // Die Inhaltsplatzhalter der Vorlage ({{BETREFF}} …) bleiben stehen —
        // sie fuellt der Absender im Mailprogramm.
        $this->assertStringNotContainsString('{{SIGNATURE_BLOCK}}', $html);
        $this->assertStringNotContainsString('{{SURFACE_BG}}', $html);
    }

    public function test_veroeffentlichter_signaturblock_ersetzt_die_blade_quelle(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();

        $signature = $this->document(MailDocumentKind::Signature);
        $publishedHtml = str_replace(
            '{{VORNAME_NACHNAME}}',
            'RT-SIGNATUR {{VORNAME_NACHNAME}}',
            (string) $signature->html,
        );
        // Simuliert eine bereits vor dem Fix veroeffentlichte Datenbank-
        // version. Der Runtime-Pfad muss das kachelnde Word-Attribut auch
        // ohne vorherigen Installationslauf sicher entfernen.
        $publishedHtml = preg_replace(
            '/<td class="rt-sign-cell"/',
            '<td class="rt-sign-cell" background="{{TRAIN_STILL_SRC}}"',
            $publishedHtml,
            1,
            $legacyBackgroundCount,
        );
        $this->assertIsString($publishedHtml);
        $this->assertSame(1, $legacyBackgroundCount);
        $signature->forceFill([
            'published_html' => $publishedHtml,
            'published_css' => '.rt-sign-name{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();
        $templateDocument = $this->document(MailDocumentKind::Template);
        $templateDocument->forceFill([
            'published_html' => (string) $templateDocument->html,
            'published_css' => '',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();
        $this->app->forgetScopedInstances();

        $builder = new EmailTemplateBuilder($user->fresh());

        $template = $builder->build('vorlage-html')['content'];
        $standalone = $builder->build('signatur-hell')['content'];
        $systemSignature = MailSignature::forCompany()->render();
        $systemMail = view('vendor.mail.html.layout', [
            'slot' => 'Systemmail-Test',
            'subcopy' => '',
            'footer' => '',
        ])->render();

        $this->assertStringContainsString('RT-SIGNATUR Mara Beispiel', $template);
        $this->assertStringContainsString('RT-SIGNATUR Mara Beispiel', $standalone);
        // OHNE Firmenname: Ohne sendende Person bleibt VORNAME_NACHNAME leer.
        // Der frueher hier greifende Rueckfall auf den Firmennamen ist
        // entfallen — die Marke steht bereits als Wortmarke in der rechten
        // Spalte, der Name darunter war eine Doppelung.
        $this->assertStringContainsString('RT-SIGNATUR', $systemSignature);
        $this->assertStringNotContainsString('RT-SIGNATUR RT Rail Time GmbH', $systemSignature);

        // CSS steht in den echten Dokumentkoepfen, nicht im <tr>-Fragment.
        $this->assertStringContainsString('data-rt-mail-document-css="signature"', $template);
        $this->assertStringContainsString('.rt-sign-name{letter-spacing:0;}', $standalone);
        $this->assertStringNotContainsString('<style', $systemSignature);
        $this->assertLessThan(stripos($standalone, '</head>'), stripos($standalone, 'data-rt-mail-document-css="signature"'));

        // Vorlage, eigenstaendige Signatur und Systemmail verwenden denselben
        // Schema-23-Runtimepfad: Der moderne IMG-Layer steht vor dem Inhalt
        // und ist absolut an der unteren Buehnenkante verankert. Das bedingte
        // Outlook-Standbild steht ebenfalls davor, dort aber im normalen Flow.
        foreach ([
            'Vorlage' => $template,
            'Signatur' => $standalone,
            'Systemmail' => $systemMail,
        ] as $channel => $rendered) {
            $this->assertStringContainsString('data-rt-train-idle-overlay', $rendered, $channel);
            $this->assertStringContainsString('data-rt-train-idle-image', $rendered, $channel);
            $this->assertStringContainsString('rt-train-idle-reveal', $rendered, $channel);
            $this->assertSame(
                1,
                preg_match(
                    '/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/',
                    $rendered,
                    $carrier,
                ),
                $channel,
            );
            $this->assertStringContainsString(
                'background-repeat:no-repeat;',
                $carrier[0],
                $channel,
            );
            $this->assertStringNotContainsString('signatur-raster-', $rendered, $channel);
            $this->assertStringNotContainsString('signatur-marke-', $rendered, $channel);
            $this->assertStringNotContainsString('data-rt-train-background', $carrier[0], $channel);
            $this->assertStringNotContainsString(',75% bottom;', $carrier[0], $channel);
            $this->assertSame(1, substr_count($rendered, 'class="rt-sign-stage"'), $channel);
            $this->assertSame(1, substr_count($rendered, 'class="rt-sign-train"'), $channel);
            $this->assertSame(1, substr_count($rendered, 'class="rt-sign-train-mso"'), $channel);
            $this->assertSame(1, substr_count($rendered, 'data-rt-train-mso="1"'), $channel);
            $this->assertStringContainsString('class="rt-sign-train-mso"', $rendered, $channel);
            $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $rendered, $channel);
            $this->assertStringNotContainsString('<!--[if mso]><tr><td class="rt-sign-train-mso"', $rendered, $channel);
        }

        $this->assertStringContainsString('.rt-sign-name{letter-spacing:0;}', $systemMail);
        $this->assertStringContainsString('RT-SIGNATUR', $systemMail);
        $this->assertStringNotContainsString('data-rt-outlook-train', $systemMail);
        $this->assertStringContainsString('data-rt-train-idle-overlay', $systemMail);
        $this->assertStringNotContainsString('data-rt-train-main-image', $systemMail);
        $this->assertStringNotContainsString('data-rt-train-main-layer', $systemMail);
        $this->assertStringContainsString('data-rt-train-idle-image', $systemMail);
        $this->assertSame(1, substr_count($systemMail, 'zug-dampf-light.gif'));
        $this->assertSame(1, preg_match_all('/<img\b[^>]*zug-dampf-light\.gif[^>]*>/i', $systemMail));
        $this->assertSame(0, substr_count($systemMail, 'rt-classic-outlook-train'));
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*rt-sign-cell[^"]*"[^>]*\sbackground=/',
            $systemMail,
        );
        $this->assertSame(
            1,
            preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $systemMail, $systemTrainCarrier),
        );
        $this->assertStringContainsString('padding:0;', $systemTrainCarrier[0]);
        $this->assertStringNotContainsString('zug-dampf-light.gif', $systemTrainCarrier[0]);
        $this->assertStringNotContainsString('data-rt-train-background', $systemTrainCarrier[0]);
        $this->assertStringContainsString('background-repeat:no-repeat;', $systemTrainCarrier[0]);
        $this->assertStringContainsString('background-position:center center;', $systemTrainCarrier[0]);
        $this->assertSame(1, substr_count($systemMail, 'class="rt-pad rt-sign-content"'));
        $this->assertSame(1, substr_count($systemMail, 'zug-dampf-light.png'));

        // Nur die bekannten Starterabstaende werden fuer den eigenstaendigen
        // Download auf den kompakten Vertrag abgebildet.
        $this->assertStringContainsString('padding:0 28px 15px;', $standalone);
        $this->assertStringContainsString('padding:11px 28px;', $standalone);
        $this->assertStringNotContainsString('border-top:5px solid #e4002b;', $standalone);

        $outlookMethod = new \ReflectionMethod($builder, 'buildOutlookSignatureHtml');
        $outlookMethod->setAccessible(true);
        $outlook = $outlookMethod->invoke($builder, 'light', 'RailTime_files');
        $this->assertIsString($outlook);
        $this->assertStringContainsString('RT-SIGNATUR Mara Beispiel', $outlook);
        $this->assertStringNotContainsString('data-rt-outlook-train', $outlook);
        $this->assertSame(1, substr_count($outlook, 'class="rt-sign-stage"'));
        $this->assertSame(1, substr_count($outlook, 'class="rt-sign-train"'));
        $this->assertSame(1, substr_count($outlook, 'class="rt-sign-train-mso"'));
        $this->assertSame(1, substr_count($outlook, 'data-rt-train-mso="1"'));
        $this->assertSame(
            1,
            preg_match_all('/<img\b(?=[^>]*class="[^"]*\brt-sign-train(?!-)[^"]*")|<img\b(?=[^>]*\bdata-rt-train(?:\s|=|>))/', $outlook),
        );
        $this->assertSame(
            1,
            preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $outlook, $outlookTrainCarrier),
        );
        $this->assertStringNotContainsString('data-rt-train-background', $outlookTrainCarrier[0]);
        $this->assertStringNotContainsString('RailTime_files/zug-dampf.gif', $outlookTrainCarrier[0]);
        $this->assertStringContainsString('RailTime_files/zug-dampf.png', $outlook);
        $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $outlook);
    }

    public function test_systemmail_css_und_signatur_html_teilen_einen_request_lokalen_snapshot(): void
    {
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $versionA = str_replace(
            '{{VORNAME_NACHNAME}}',
            'SNAPSHOT-A {{VORNAME_NACHNAME}}',
            (string) $signature->html,
        );
        $signature->forceFill([
            'published_html' => $versionA,
            'published_css' => '.snapshot-a{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();

        // Das Layout liest zuerst CSS; der Footer erzeugt danach eine zweite
        // MailSignature-Instanz. Beide muessen trotzdem Version A sehen.
        $cssFromLayout = MailSignature::forCompany()->publishedCss();

        $versionB = str_replace('SNAPSHOT-A', 'SNAPSHOT-B', $versionA);
        $signature->forceFill([
            'published_html' => $versionB,
            'published_css' => '.snapshot-b{letter-spacing:1px;}',
            'published_at' => now()->addSecond(),
        ])->save();

        $htmlFromFooter = MailSignature::forCompany()->render();

        $this->assertStringContainsString('.snapshot-a{letter-spacing:0;}', $cssFromLayout);
        $this->assertStringContainsString('SNAPSHOT-A', $htmlFromFooter);
        $this->assertStringNotContainsString('SNAPSHOT-B', $htmlFromFooter);

        // Laravel leert scoped Bindings zwischen HTTP-/Octane-Requests und
        // Queue-Jobs. Der naechste Scope liest deshalb atomar Version B.
        $this->app->forgetScopedInstances();

        $freshCss = MailSignature::forCompany()->publishedCss();
        $freshHtml = MailSignature::forCompany()->render();

        $this->assertStringContainsString('.snapshot-b{letter-spacing:1px;}', $freshCss);
        $this->assertStringContainsString('SNAPSHOT-B', $freshHtml);
        $this->assertStringNotContainsString('SNAPSHOT-A', $freshHtml);
    }

    public function test_regulaerer_signaturdownload_respektiert_individuelle_editorabstaende(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $custom = strtr((string) $signature->html, [
            'padding:0 36px 15px;' => 'padding:21px 41px 29px;',
            'border-top:5px solid #e4002b;' => 'border-top:7px solid #123456;',
            'padding:14px 36px;' => 'padding:19px 41px;',
        ]);
        $signature->forceFill([
            'published_html' => $custom,
            'published_css' => '',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();

        $html = (new EmailTemplateBuilder($user))->build('signatur-hell')['content'];

        $this->assertStringContainsString('padding:21px 41px 29px;', $html);
        $this->assertStringContainsString('border-top:7px solid #123456;', $html);
        $this->assertStringContainsString('padding:19px 41px;', $html);
    }

    public function test_editorseite_ist_administratoren_vorbehalten(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();

        // auth:sanctum greift vor role:admin und schickt Gaeste zur
        // allgemeinen Anmeldung.
        $this->get(route('admin.mail-documents.editor'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get(route('admin.mail-documents.editor'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.mail-documents.editor'))
            ->assertOk()
            ->assertSee('pageBuilderOpen: false', escape: false)
            ->assertSee('data-mail-document-library', escape: false)
            ->assertSee(route('admin.mail-documents.editor', [
                'dokument' => MailDocumentKind::Template->value,
                'slot' => $this->document(MailDocumentKind::Template)->public_id,
                'open' => 1,
            ]))
            ->assertDontSee('data-mail-document-config', escape: false)
            ->assertDontSee('data-mail-document-root', escape: false)
            ->assertDontSee('data-page-builder-workspace', escape: false);

        $this->actingAs($admin)
            ->get(route('admin.mail-documents.editor', ['open' => 1]))
            ->assertOk()
            ->assertSee('pageBuilderOpen: true', escape: false)
            ->assertSee('data-mail-document-config', escape: false)
            ->assertSee('data-mail-document-root', escape: false)
            ->assertSee('data-mail-editor-mode="mail"', escape: false)
            ->assertSee('LMZ Page Builder wird im Mailmodus geladen', escape: false)
            // Dokumentwahl, Inhalt, Bearbeitung, Vorschau und Freigabe teilen
            // sich exakt eine Werkzeugleiste im Vollbildkopf.
            ->assertSee('data-page-builder-single-toolbar', escape: false)
            ->assertSee('data-mail-studio-toolbar', escape: false)
            ->assertSee('data-mail-toolbar-region="documents"', escape: false)
            ->assertSee('data-mail-toolbar-region="preview"', escape: false)
            ->assertSee('data-mail-toolbar-region="actions"', escape: false)
            ->assertSee('data-mail-toolbar-menu="document"', escape: false)
            ->assertSee('data-mail-toolbar-menu="content"', escape: false)
            ->assertSee('data-mail-toolbar-menu="edit"', escape: false)
            ->assertSee('data-mail-toolbar-menu="view"', escape: false)
            // Design-Slots und ihre Versionen liegen in einem ausreichend
            // grossen Modal statt in einem schmalen Toolbar-Dropdown.
            ->assertSee('data-mail-design-manager-trigger', escape: false)
            ->assertSee('data-mail-toolbar-menu="designs-versions"', escape: false)
            ->assertSee('data-mail-design-manager-host', escape: false)
            ->assertSee('data-mail-design-manager', escape: false)
            ->assertSee('data-mail-design-slot-list', escape: false)
            ->assertSee('data-mail-toolbar-menu="tools"', escape: false)
            ->assertSee('data-mail-builder-panel="left:layers"', escape: false)
            ->assertSee('data-mail-builder-panel="right:traits"', escape: false)
            ->assertSee('data-mail-builder-action="assets"', escape: false)
            ->assertSee('data-mail-document-status', escape: false)
            ->assertSee('data-mail-document-save', escape: false)
            ->assertSee('data-mail-document-publish', escape: false)
            ->assertSee('Mail-Notifications sowie Systemmails veröffentlichen', escape: false)
            // OHNE escape: false — Blade escaped das Trennzeichen & der
            // Query im href zu &amp;. Die rohe URL steht so nie im Markup.
            ->assertSee(route('admin.mail-documents.editor', ['dokument' => 'signature', 'open' => 1]))
            ->assertSee('data-mail-document-back', escape: false)
            ->assertSee('data-mail-theme-button="light"', escape: false)
            ->assertSee('data-mail-theme-button="dark"', escape: false)
            ->assertSee('data-mail-view-mode="delivery"', escape: false)
            ->assertSee('Kompilierte produktive Systemmail-Quelle im Browser', escape: false)
            ->assertSee('data-mail-compiler-parity-note', escape: false)
            ->assertSee('clientspezifische Medien- und Wrapper-Anpassungen', escape: false)
            ->assertSee('Compiler-Parität', escape: false)
            ->assertSee('Keine Ansicht emuliert Outlook oder iPhone Mail', escape: false)
            ->assertSee('data-mail-view-shortcut', escape: false)
            ->assertSee("let selectedViewMode = 'delivery'")
            ->assertSee('rt-mail-studio-toolbar__primary-actions', escape: false)
            ->assertSee('data-mail-preview-device="desktop"', escape: false)
            ->assertSee('data-mail-preview-device="tablet"', escape: false)
            ->assertSee('data-mail-preview-device="mobile"', escape: false)
            ->assertSee('data-mail-preview-replay', escape: false)
            ->assertSee('data-mail-preview-width', escape: false)
            ->assertSee('data-mail-preview-resizer', escape: false)
            ->assertSee('role="separator"', escape: false)
            ->assertSee('restartAllGifs', escape: false)
            ->assertSee('data-mail-editor-frame', escape: false)
            ->assertSee('data-page-builder-preview-first', escape: false)
            ->assertSee('data-page-builder-preview-replay', escape: false)
            ->assertSee('animate=1', escape: false)
            ->assertSee('data-page-builder-assist', escape: false)
            ->assertSee('Mail- &amp; Signatur-Editor', escape: false);
    }

    public function test_design_slot_wird_als_unabhaengiger_entwurf_dupliziert(): void
    {
        $this->createCanonicalMailDocuments(published: true);
        $admin = $this->admin();
        $source = $this->document(MailDocumentKind::Template);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.slots.store', $source), [
                'name' => '  Sommer   2027  ',
                'expected_hash' => $source->content_hash,
            ])
            ->assertCreated()
            ->assertJsonPath('document.name', 'Sommer 2027')
            ->assertJsonPath('document.status', MailDocumentStatus::Draft->value)
            ->assertJsonPath('document.is_active', false)
            ->assertJsonCount(1, 'document.versions');

        $copy = MailDocument::query()
            ->where('public_id', $response->json('document.id'))
            ->firstOrFail();

        $this->assertSame($source->kind, $copy->kind);
        $this->assertSame('Sommer 2027', $copy->name);
        $this->assertNull($copy->is_active);
        $this->assertFalse($copy->isActive());
        $this->assertSame($source->builder_data, $copy->builder_data);
        $this->assertSame($source->html, $copy->html);
        $this->assertSame($source->css, $copy->css);
        $this->assertSame($source->content_hash, $copy->content_hash);
        $this->assertNull($copy->published_html);
        $this->assertNull($copy->published_css);
        $this->assertNull($copy->published_at);
        $this->assertSame(1, $copy->version);
        $this->assertSame(2, MailDocument::query()->where('kind', MailDocumentKind::Template->value)->count());
        $this->assertSame(1, MailDocument::query()->where('kind', MailDocumentKind::Template->value)->active()->count());
        $this->assertDatabaseHas('mail_document_versions', [
            'mail_document_id' => $copy->getKey(),
            'revision' => 1,
            'action' => 'duplicated',
            'created_by' => $admin->getKey(),
        ]);
        $this->assertSame(route('admin.mail-documents.editor', [
            'dokument' => MailDocumentKind::Template->value,
            'slot' => $copy->public_id,
            'open' => 1,
        ]), $response->json('redirect'));
    }

    public function test_veroeffentlichen_wechselt_den_aktiven_slot_atomar(): void
    {
        $this->createCanonicalMailDocuments(published: true);
        $admin = $this->admin();
        $previous = $this->document(MailDocumentKind::Template);
        $previousPublishedHtml = $previous->published_html;
        $candidate = $this->createCanonicalMailDocument(
            MailDocumentKind::Template,
            published: false,
            name: 'Alternative Freigabe',
            isActive: false,
        );
        $candidateHtml = str_replace(
            '{{SIGNATURE_BLOCK}}',
            '<tr><td>RT-AKTIVER-DESIGN-SLOT</td></tr>{{SIGNATURE_BLOCK}}',
            (string) $candidate->html,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $candidateBuilderData = $candidate->builder_data;
        data_set($candidateBuilderData, 'pages.0.component', $candidateHtml);
        $candidate->forceFill([
            'builder_data' => $candidateBuilderData,
            'html' => $candidateHtml,
            'content_hash' => MailDocument::contentHashFor(
                $candidateBuilderData,
                $candidateHtml,
                (string) $candidate->css,
            ),
        ])->save();

        // Ein Konflikt vor der Freigabe darf den bisherigen aktiven Slot
        // weder deaktivieren noch seinen Versandabzug veraendern.
        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $candidate), [
                'expected_hash' => str_repeat('0', 64),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_hash');

        $this->assertTrue($previous->fresh()->isActive());
        $this->assertFalse($candidate->fresh()->isActive());
        $this->assertSame($previousPublishedHtml, $previous->fresh()->published_html);
        $this->assertSame(1, MailDocument::query()->where('kind', MailDocumentKind::Template->value)->active()->count());

        $this->postJson(route('admin.mail-documents.publish', $candidate), [
            'expected_hash' => $candidate->content_hash,
        ])->assertOk()
            ->assertJsonPath('document.id', $candidate->public_id)
            ->assertJsonPath('document.is_active', true)
            ->assertJsonPath('document.status', MailDocumentStatus::Published->value);

        $previous = $previous->fresh();
        $candidate = $candidate->fresh();
        $this->assertFalse($previous->isActive());
        $this->assertNull($previous->is_active);
        $this->assertSame(MailDocumentStatus::Draft, $previous->status);
        $this->assertSame($previousPublishedHtml, $previous->published_html);
        $this->assertTrue($candidate->isActive());
        $this->assertSame(MailDocumentStatus::Published, $candidate->status);
        $this->assertSame(1, MailDocument::query()->where('kind', MailDocumentKind::Template->value)->active()->count());

        app()->forgetScopedInstances();
        $this->assertStringContainsString(
            'RT-AKTIVER-DESIGN-SLOT',
            (string) EmailTemplateBuilder::publishedDocument(MailDocumentKind::Template),
        );
    }

    public function test_aktiver_slot_ist_geschuetzt_und_inaktiver_slot_wird_vollstaendig_geloescht(): void
    {
        $this->createCanonicalMailDocuments(published: true);
        $admin = $this->admin();
        $active = $this->document(MailDocumentKind::Template);
        $activeSnapshot = [
            ...$active->only(['content_hash', 'published_html', 'published_css']),
            'published_at' => $active->published_at?->toIso8601String(),
        ];

        $created = $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.slots.store', $active), [
                'name' => 'Loeschbarer Entwurf',
                'expected_hash' => $active->content_hash,
            ])
            ->assertCreated();
        $draft = MailDocument::query()->where('public_id', $created->json('document.id'))->firstOrFail();
        $draftVersion = $draft->versions()->firstOrFail();

        $this->deleteJson(route('admin.mail-documents.slots.destroy', $active), [
            'expected_hash' => $active->content_hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('slot');

        $this->assertNotNull($active->fresh());
        $this->assertTrue($active->fresh()->isActive());

        $this->deleteJson(route('admin.mail-documents.slots.destroy', $draft), [
            'expected_hash' => $draft->content_hash,
        ])->assertOk()
            ->assertJsonPath('redirect', route('admin.mail-documents.editor', [
                'dokument' => MailDocumentKind::Template->value,
                'slot' => $active->public_id,
                'open' => 1,
            ]));

        $this->assertNull($draft->fresh());
        $this->assertDatabaseMissing('mail_documents', ['id' => $draft->getKey()]);
        $this->assertDatabaseMissing('mail_document_versions', ['id' => $draftVersion->getKey()]);
        $freshActive = $active->fresh();
        $this->assertSame($activeSnapshot, [
            ...$freshActive->only(['content_hash', 'published_html', 'published_css']),
            'published_at' => $freshActive->published_at?->toIso8601String(),
        ]);
        $this->assertSame(1, MailDocument::query()->where('kind', MailDocumentKind::Template->value)->count());
        $this->assertSame(1, MailDocument::query()->where('kind', MailDocumentKind::Template->value)->active()->count());
    }

    public function test_historienversion_kann_geloescht_werden_aber_die_letzte_bleibt_erhalten(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();
        $document = $this->document(MailDocumentKind::Template);
        $snapshot = [
            'mail_document_id' => $document->getKey(),
            'action' => 'saved',
            'builder_data' => $document->builder_data,
            'html' => (string) $document->html,
            'css' => (string) $document->css,
            'content_hash' => (string) $document->content_hash,
            'was_published' => false,
            'created_by' => $admin->getKey(),
        ];
        $first = MailDocumentVersion::query()->create([...$snapshot, 'revision' => 1]);
        $second = MailDocumentVersion::query()->create([...$snapshot, 'revision' => 2]);

        $this->actingAs($admin)
            ->deleteJson(route('admin.mail-documents.versions.destroy', [$document, $second]), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'document.versions')
            ->assertJsonPath('document.versions.0.id', $first->public_id);

        $this->assertDatabaseMissing('mail_document_versions', ['id' => $second->getKey()]);
        $this->assertDatabaseHas('mail_document_versions', ['id' => $first->getKey()]);

        $this->deleteJson(route('admin.mail-documents.versions.destroy', [$document, $first]), [
            'expected_hash' => $document->content_hash,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $this->assertDatabaseHas('mail_document_versions', ['id' => $first->getKey()]);
        $this->assertSame(1, $document->versions()->count());
    }

    public function test_versandvorschau_kompiliert_ungespeicherte_template_und_signaturkandidaten_ohne_dokumentmutation(): void
    {
        $this->createCanonicalMailDocuments(published: true);
        $template = $this->document(MailDocumentKind::Template);
        $candidateHtml = str_replace(
            'Ihre Nachricht von',
            'VERSANDVORSCHAU AKTUELL · Nachricht von',
            (string) $template->html,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $candidateBuilderData = $template->builder_data;
        data_set($candidateBuilderData, 'pages.0.component', $candidateHtml);

        $snapshot = static fn (): array => MailDocument::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (MailDocument $document): array => [
                'id' => $document->getKey(),
                'builder_data' => $document->builder_data,
                'html' => $document->html,
                'css' => $document->css,
                'published_html' => $document->published_html,
                'published_css' => $document->published_css,
                'published_at' => $document->published_at?->toIso8601String(),
                'content_hash' => $document->content_hash,
                'version' => $document->version,
                'updated_at' => $document->updated_at?->toIso8601String(),
            ])
            ->all();
        $before = $snapshot();
        $versionCount = MailDocumentVersion::query()->count();

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.delivery-preview', $template), [
                'builder_data' => $candidateBuilderData,
                'html' => $candidateHtml,
                'css' => (string) $template->css,
                'expected_hash' => (string) $template->content_hash,
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('preview.rendering', 'compiled-system-mail')
            ->assertJsonPath('document.html', $candidateHtml)
            ->assertJsonStructure([
                'preview' => ['html', 'html_bytes', 'rendering'],
                'report',
                'source_compatibility' => ['status'],
                'compatibility' => ['status', 'html_bytes', 'findings'],
            ]);

        $compiled = (string) $response->json('preview.html');
        $this->assertStringContainsString('VERSANDVORSCHAU AKTUELL', $compiled);
        $this->assertStringContainsString('RailTime Kompatibilitätsprüfung', $compiled);
        $this->assertStringNotContainsString('Sicher abgestimmt.', $compiled);
        $this->assertStringNotContainsString('<script', strtolower($compiled));
        $this->assertSame(strlen($compiled), $response->json('preview.html_bytes'));

        $signature = $this->document(MailDocumentKind::Signature);
        $signatureCss = '.rt-sign-name{letter-spacing:0;}.rt-company-contact-text{font-weight:bold;}';
        $signatureResponse = $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.delivery-preview', $signature), [
                'builder_data' => $signature->builder_data,
                'html' => (string) $signature->html,
                'css' => $signatureCss,
                'expected_hash' => (string) $signature->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('preview.rendering', 'compiled-system-mail');
        $compiledSignature = (string) $signatureResponse->json('preview.html');
        $this->assertStringContainsString('data-rt-mail-document-css="signature"', $compiledSignature);
        $this->assertStringContainsString($signatureCss, $compiledSignature);

        // Die Versandansicht muss dieselbe CSS-Inlining-Stufe wie die echte
        // Mail durchlaufen, nicht bloss die unveraenderte HTML-Schale zeigen.
        $previewDom = new \DOMDocument;
        $previousLibxmlErrors = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($previewDom->loadHTML($compiledSignature));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlErrors);
        }
        $previewXpath = new \DOMXPath($previewDom);
        $companyCell = $previewXpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " rt-company-contact-text ")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $companyCell);
        $this->assertMatchesRegularExpression('/font-weight\s*:\s*bold/i', $companyCell->getAttribute('style'));

        $this->assertSame($before, $snapshot());
        $this->assertSame($versionCount, MailDocumentVersion::query()->count());
    }

    public function test_editorconfig_liefert_echte_vorschau_assets_ohne_die_dokumenttokens_zu_veraendern(): void
    {
        $this->seedDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $signature = $this->document(MailDocumentKind::Signature);
        $legacySignatureHtml = $this->legacySchema24Signature((string) $signature->html);
        $legacySignatureBuilderData = $signature->builder_data;
        data_set($legacySignatureBuilderData, 'pages.0.component', $legacySignatureHtml);
        data_set($legacySignatureBuilderData, 'railtime.schema', 24);
        $signature->forceFill([
            'builder_data' => $legacySignatureBuilderData,
            'html' => $legacySignatureHtml,
            'content_hash' => MailDocument::contentHashFor(
                $legacySignatureBuilderData,
                $legacySignatureHtml,
                (string) $signature->css,
            ),
        ])->save();
        $signature->refresh();
        $originalTemplateBuilderData = $template->builder_data;
        $originalSignatureBuilderData = $signature->builder_data;
        $originalSignatureHtml = (string) $signature->html;
        $originalTemplateAttributes = $template->getRawOriginal();
        $originalSignatureAttributes = $signature->getRawOriginal();
        $repairedSignatureHtml = SignatureTrainCarrier::normalize($originalSignatureHtml);

        $admin = $this->admin();
        $response = $this->actingAs($admin)
            ->get(route('admin.mail-documents.editor', ['open' => 1]))
            ->assertOk();

        // JSON in einem <script> darf kein rohes < enthalten (Blade escaped
        // es), deshalb ist [^<]* hier sicher und laeuft linear.
        $this->assertSame(1, preg_match(
            '/<script[^>]*data-mail-document-config[^>]*>([^<]*)<\/script>/',
            (string) $response->getContent(),
            $match,
        ));
        $config = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

        $signatureResponse = $this->actingAs($admin)
            ->get(route('admin.mail-documents.editor', [
                'dokument' => MailDocumentKind::Signature->value,
                'open' => 1,
            ]))
            ->assertOk();
        $this->assertSame(1, preg_match(
            '/<script[^>]*data-mail-document-config[^>]*>([^<]*)<\/script>/',
            (string) $signatureResponse->getContent(),
            $signatureMatch,
        ));
        $signatureConfig = json_decode($signatureMatch[1], true, flags: JSON_THROW_ON_ERROR);

        // Jede Vollbildseite transportiert nur das tatsaechlich geoeffnete
        // GrapesJS-Projekt. Der Dokumentwechsel ist ein harter Seitenaufruf;
        // das zweite grosse Projekt darf den Livewire-DOM nicht verdoppeln.
        $this->assertSame(['template'], array_keys(data_get($config, 'documents')));
        $this->assertSame(['signature'], array_keys(data_get($signatureConfig, 'documents')));

        // Direkte Vendor-Dateien laufen nicht durch Vite. Ein Inhalts-Hash
        // verhindert auch bei timestamp-erhaltenden Deployments, dass ein
        // langer Livewire-Tab eine alte Builder-Laufzeit weiterverwendet.
        foreach (['builderJs', 'builderCss', 'coreJs', 'coreCss'] as $assetKey) {
            $source = (string) data_get($config, 'vendor.'.$assetKey);
            $this->assertMatchesRegularExpression('/[?&]v=[a-f0-9]{16}(?:&|$)/', $source, $assetKey);
        }
        parse_str((string) parse_url((string) data_get($config, 'vendor.builderJs'), PHP_URL_QUERY), $builderQuery);
        $this->assertSame((string) data_get($config, 'vendor.builderVersion'), $builderQuery['runtime'] ?? null);
        $this->assertSame(
            substr((string) hash_file('sha256', public_path('vendor/lmz-builder/2.4.5/lmz-builder.js')), 0, 16),
            $builderQuery['v'] ?? null,
        );
        $expectedRuntimeVersion = substr(hash('sha256', implode('|', array_map(
            static fn (string $filename): string => substr((string) hash_file(
                'sha256',
                public_path('vendor/lmz-builder/2.4.5/'.$filename),
            ), 0, 16),
            ['lmz-builder.js', 'lmz-builder.css', 'lmz-builder-core.js', 'lmz-builder-core.css'],
        ))), 0, 16);
        $this->assertSame($expectedRuntimeVersion, (string) data_get($config, 'vendor.builderVersion'));

        // Alle Vorschauquellen sind gleich-originige Mailassets. Dadurch
        // bleiben GIFs animiert, ohne den Livewire-DOM mit mehreren MiB
        // Base64 zu blockieren.
        foreach (['light.logo', 'dark.logo', 'light.mark', 'dark.mark', 'light.train', 'dark.train'] as $asset) {
            $source = (string) data_get($config, 'previewAssets.'.$asset);
            $this->assertStringContainsString('/mail-assets/', $source, $asset);
            $this->assertStringNotContainsString('data:', $source, $asset);
        }

        foreach (['location', 'phone', 'mobile', 'email', 'web'] as $icon) {
            $source = (string) data_get($config, 'previewAssets.icons.'.$icon);
            $this->assertStringContainsString('/mail-assets/', $source, $icon);
            $this->assertStringNotContainsString('data:', $source, $icon);
        }

        foreach (['light', 'dark'] as $theme) {
            foreach (['template' => $config, 'signature' => $signatureConfig] as $kind => $documentConfig) {
                $this->assertSame(
                    EmailTemplateBuilder::emailThemeValues($theme),
                    data_get($documentConfig, 'previewThemeValues.'.$theme),
                    $kind.' / '.$theme,
                );
            }
            $responsiveCss = (string) data_get($config, 'previewResponsiveCss.'.$theme);
            $this->assertStringContainsString('@media only screen and (max-width: 860px)', $responsiveCss);
            $this->assertStringContainsString('tr.rt-stack > td', $responsiveCss);
        }

        // V10 bis V20 besitzen eigene mobile Geometrievertraege; V11 bis V20
        // trennen zusaetzlich die sichere Vollfassung vom kompakten
        // Systemprofil. V14 bis V21 ergaenzen explizite Medien- und Layout-
        // Vertraege. Trotz doppelter Vorschau-CSS fuer Hell und Dunkel bleibt
        // die komplette Editor-Konfiguration unter 176 KiB.
        $this->assertLessThan(176 * 1024, strlen((string) $match[1]));

        $mailAssets = data_get($config, 'mailAssets');
        $this->assertIsArray($mailAssets);
        $this->assertCount(9, $mailAssets);
        foreach ($mailAssets as $asset) {
            $this->assertSame('image', $asset['type'] ?? null);
            $this->assertStringContainsString('/mail-assets/', (string) ($asset['src'] ?? ''));
            $this->assertStringNotContainsString('/administrator/', (string) ($asset['src'] ?? ''));
            $this->assertIsInt($asset['width'] ?? null);
            $this->assertGreaterThan(0, $asset['width']);
            $this->assertIsInt($asset['height'] ?? null);
            $this->assertGreaterThan(0, $asset['height']);
        }

        $portableMedia = data_get($config, 'portableMedia');
        $this->assertIsArray($portableMedia);
        $this->assertSame(
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Template),
            data_get($config, 'portableMediaRequirements.template'),
        );
        $this->assertSame(
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
            data_get($config, 'portableMediaRequirements.signature'),
        );
        $expectedPortableIds = array_map(
            static fn (string $path): string => basename($path),
            glob(public_path('mail-assets/*.{gif,png,jpg,jpeg,webp}'), GLOB_BRACE) ?: [],
        );
        sort($expectedPortableIds, SORT_NATURAL | SORT_FLAG_CASE);
        $this->assertSame($expectedPortableIds, array_column($portableMedia, 'id'));
        foreach ($portableMedia as $asset) {
            $path = public_path('mail-assets/'.($asset['id'] ?? ''));
            $this->assertFileExists($path);
            $this->assertSame(filesize($path), $asset['bytes'] ?? null);
            $this->assertSame(hash_file('sha256', $path), $asset['sha256'] ?? null);
            $this->assertStringContainsString('/mail-assets/', (string) ($asset['source'] ?? ''));
            $required = in_array(
                $asset['id'] ?? '',
                PortableMediaCatalog::requiredSystemAssetIds(MailDocumentKind::Template),
                true,
            );
            $this->assertSame($required, $asset['required'] ?? null);
            $this->assertSame($required, $asset['included'] ?? null);
        }

        $this->assertSame(
            route('admin.mail-documents.validate-code', $template),
            data_get($config, 'documents.template.endpoints.validate'),
        );
        $this->assertSame(
            route('admin.mail-documents.validate-code', $signature),
            data_get($signatureConfig, 'documents.signature.endpoints.validate'),
        );

        $this->assertStringContainsString(
            '{{LOGO_SRC}}',
            (string) data_get($signatureConfig, 'documents.signature.builderData.pages.0.component'),
        );
        // Der Body wird im Builder editiert; die vollstaendige HTML-Fassung
        // bleibt als serverautoritative Baseline fuer Head, Markenfragment
        // und Dokumenthuelle im Payload. CSS und Builderprojekt muessen
        // daneben unverkuerzt ankommen.
        $this->assertSame((string) $template->html, data_get($config, 'documents.template.html'));
        $this->assertSame($repairedSignatureHtml, data_get($signatureConfig, 'documents.signature.html'));
        $this->assertSame((string) $template->css, data_get($config, 'documents.template.css'));
        $this->assertSame((string) $signature->css, data_get($signatureConfig, 'documents.signature.css'));
        $this->assertSame($originalTemplateBuilderData, data_get($config, 'documents.template.builderData'));
        $this->assertSame(
            SignatureDocumentContract::SCHEMA,
            data_get($signatureConfig, 'documents.signature.builderData.railtime.schema'),
        );
        $this->assertSame(
            $repairedSignatureHtml,
            data_get($signatureConfig, 'documents.signature.builderData.pages.0.component'),
        );
        $this->assertTrue((bool) data_get($signatureConfig, 'documents.signature.autoRepaired'));
        $this->assertFalse((bool) data_get($config, 'documents.template.autoRepaired'));
        $this->assertSame($originalTemplateBuilderData, $template->fresh()->builder_data);
        $this->assertSame($originalSignatureBuilderData, $signature->fresh()->builder_data);
        $this->assertSame($originalSignatureHtml, (string) $signature->fresh()->html);
        // Beide GETs duerfen auch Version, Freigabe und gespeicherte Farben
        // nicht veraendern: Die Palette ist ausschliesslich Vorschaukonfiguration.
        $this->assertSame($originalTemplateAttributes, $template->fresh()->getRawOriginal());
        $this->assertSame($originalSignatureAttributes, $signature->fresh()->getRawOriginal());
    }

    public function test_editorseite_erklaert_fehlende_dokumente_statt_abzustuerzen(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.mail-documents.editor'))
            ->assertOk()
            ->assertSee('Dieses Dokument fehlt noch', escape: false)
            ->assertSee('JSON-Bundle importieren', escape: false)
            ->assertSee('data-mail-document-bootstrap', escape: false)
            ->assertDontSee('data-page-builder-shell-toolbar', escape: false)
            ->assertDontSee('data-mail-document-root', escape: false);
    }

    public function test_portables_medienbundle_prueft_hash_und_speichert_bilder_inhaltsadressiert(): void
    {
        Storage::fake('public');
        $binary = file_get_contents(public_path('mail-assets/contact-phone.png'));
        $this->assertIsString($binary);
        $source = 'https://alte-installation.example/mail-assets/contact-phone.png?v=1';
        $entry = [
            'id' => 'contact-phone.png',
            'name' => 'Telefon Icon',
            'source' => $source,
            'mime_type' => 'image/png',
            'bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
            'data' => base64_encode($binary),
        ];

        $controller = app(MailDocumentController::class);
        $prepare = new \ReflectionMethod($controller, 'preparePortableMedia');
        [$html, $css, $files] = $prepare->invoke(
            $controller,
            '<img src="'.$source.'" alt="">',
            '.x{background-image:url('.$source.');}',
            [$entry],
        );
        $expectedPath = 'mail-imports/'.$entry['sha256'].'.png';
        $this->assertStringContainsString('/storage/'.$expectedPath, $html);
        $this->assertStringContainsString('/storage/'.$expectedPath, $css);
        $this->assertSame($expectedPath, $files[0]['path'] ?? null);

        $store = new \ReflectionMethod($controller, 'storePortableMedia');
        $store->invoke($controller, $files);
        Storage::disk('public')->assertExists($expectedPath);
        $this->assertSame($binary, Storage::disk('public')->get($expectedPath));

        $document = new MailDocument;
        $document->forceFill([
            'html' => '<img src="'.URL::to(Storage::disk('public')->url($expectedPath)).'" alt="">',
            'css' => '',
        ]);
        $editor = (new \ReflectionClass(MailDocumentEditor::class))->newInstanceWithoutConstructor();
        $editor->kind = MailDocumentKind::Signature->value;
        $catalog = new \ReflectionMethod($editor, 'portableMediaAssets');
        $portable = $catalog->invoke($editor, ['signature' => $document]);
        $imported = collect($portable)->firstWhere('id', $expectedPath);
        $this->assertIsArray($imported);
        $this->assertTrue($imported['required'] ?? false);
        $this->assertTrue($imported['included'] ?? false);
        $this->assertSame($entry['sha256'], $imported['sha256'] ?? null);

        $entry['sha256'] = str_repeat('0', 64);
        $this->expectException(ValidationException::class);
        $prepare->invoke($controller, '<img src="'.$source.'" alt="">', '', [$entry]);
    }

    public function test_codeimport_prueft_die_v12_bis_v14_medienvertraege_aus_dem_kandidaten_html(): void
    {
        Storage::fake('public');
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);

        foreach ([
            SignatureArtifactVersion::V12 => 'zug-dampf-v12-dark.png',
            SignatureArtifactVersion::V13 => 'zug-dampf-v13-dark.png',
            SignatureArtifactVersion::V14 => 'zug-dampf-v13-dark.png',
        ] as $version => $missingAsset) {
            $builderData = $document->builder_data ?: [];
            $html = preg_replace(
                '/^<tr>/',
                '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.$version.'">',
                (string) $document->html,
                1,
                $markerCount,
            );
            $this->assertIsString($html);
            $this->assertSame(1, $markerCount);
            data_set($builderData, 'pages.0.component', $html);
            $media = $this->portableSystemMedia(MailDocumentKind::Signature, $version);
            $payload = [
                'builder_data' => $builderData,
                'html' => $html,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
                'portable_media' => $media,
            ];

            $this->actingAs($this->admin())
                ->postJson(route('admin.mail-documents.validate-code', $document), $payload)
                ->assertOk()
                ->assertJsonPath('compatibility.status', 'warn')
                ->assertJsonPath('compatibility.counts.block', 0)
                ->assertJsonPath('compatibility.rendering_verified', false)
                ->assertJsonFragment(['rule_id' => 'EMAIL-LAYOUT-007']);

            $payload['portable_media'] = array_values(array_filter(
                $media,
                static fn (array $entry): bool => ($entry['id'] ?? '') !== $missingAsset,
            ));
            $this->actingAs($this->admin())
                ->postJson(route('admin.mail-documents.validate-code', $document), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('portable_media')
                ->assertJsonFragment([
                    'Im Bundle fehlen erforderliche Medien: '.$missingAsset.'.',
                ]);
        }
    }

    public function test_v21_flowvertrag_erlaubt_farbhintergrund_und_weist_fremde_strukturen_ab(): void
    {
        $html = <<<'HTML'
<tr data-rt-artifact-version="v21"><td class="rt-sign-cell" style="background:#fff;"><div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;"><table class="rt-sign-content-frame" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tbody><tr><td>Inhalt</td></tr></tbody></table><div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="left" style="display:block;width:100%;max-width:720px;margin:0 auto 0 0;overflow:hidden;font-size:0;line-height:0;text-align:left;"><table class="rt-sign-train-frame" role="presentation" width="100%" height="61" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:61px;border-collapse:collapse;"><tr><td class="rt-sign-train-slot" height="61" valign="bottom" style="height:61px;padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;"><img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="720" height="61" alt="" style="display:block;width:100%;max-width:720px;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;"></td></tr></table></div></div></td></tr>
HTML;

        $this->assertSame($html, SignatureTrainCarrier::normalize($html));

        // iPhone-Regression: Ein Shorthand-Reset allein loescht beim echten
        // Laravel-Inliner die alte margin-bottom:-200px-Regel nicht.
        $inliner = new CssToInlineStyles;
        $inlined = $inliner->convert('<html><head><style>'.EmailTemplateBuilder::responsiveCss().'</style></head><body><table>'.$html.'</table></body></html>');
        foreach ([$inlined, $inliner->convert($inlined)] as $mailHtml) {
            $mailDom = new \DOMDocument;
            $previousLibxmlErrors = libxml_use_internal_errors(true);
            try {
                $this->assertTrue($mailDom->loadHTML($mailHtml));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousLibxmlErrors);
            }
            $mailXpath = new \DOMXPath($mailDom);
            $layer = $mailXpath->query('//*[@class="rt-sign-train-layer"]')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $layer);
            $layerStyle = $layer->getAttribute('style');
            foreach (['top', 'bottom', 'left'] as $side) {
                $this->assertMatchesRegularExpression('/margin-'.$side.'\s*:\s*0(?:px)?\s*!important/i', $layerStyle);
            }
            $this->assertMatchesRegularExpression('/margin-right\s*:\s*auto\s*!important/i', $layerStyle);
            $this->assertDoesNotMatchRegularExpression('/margin-(?:top|bottom)\s*:\s*-\d/', $layerStyle);
        }

        $invalid = [
            str_replace('class="rt-sign-stage"', 'class="rt-sign-stage fremd"', $html),
            str_replace('class="rt-sign-content-frame"', 'class="rt-sign-content-frame fremd"', $html),
            str_replace(
                '</td></tr></table></div></div></td></tr>',
                '</td></tr></table><span>Fremder Layer-Inhalt</span></div></div></td></tr>',
                $html,
            ),
            str_replace(
                '<img class="rt-sign-train"',
                '<span>Fremder Slot-Inhalt</span><img class="rt-sign-train"',
                $html,
            ),
        ];

        foreach ($invalid as $fragment) {
            try {
                SignatureTrainCarrier::assertFlowSafeImage($fragment);
                $this->fail('Eine fremde V21-Struktur wurde unerwartet akzeptiert.');
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_signatur_artefaktversion_erkennt_v7_fallback_bis_v21_marker(): void
    {
        $canonical = $this->canonicalMailDocumentHtml(MailDocumentKind::Signature);
        $v7 = str_replace(
            'data-rt-layer-align="center"',
            'data-rt-layer-align="left"',
            $canonical,
            $alignmentCount,
        );
        $v7 = str_replace(
            'data-rt-layer-mobile="train"',
            'data-rt-layer-mobile="left"',
            $v7,
            $mobileCount,
        );

        $this->assertSame([1, 1], [$alignmentCount, $mobileCount]);
        $this->assertSame(SignatureArtifactVersion::V7, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v7,
        ));

        $v8 = preg_replace(
            '/^<tr>/',
            '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.SignatureArtifactVersion::V8.'">',
            $canonical,
            1,
            $markerCount,
        );

        $this->assertIsString($v8);
        $this->assertSame(1, $markerCount);
        $this->assertSame(SignatureArtifactVersion::V8, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v8,
        ));
        $this->assertNull(SignatureArtifactVersion::detect(
            MailDocumentKind::Template,
            $v8,
        ));

        $v9 = str_replace(
            SignatureArtifactVersion::V8,
            SignatureArtifactVersion::V9,
            $v8,
            $v9MarkerCount,
        );
        $this->assertSame(1, $v9MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V9, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v9,
        ));
        $v10 = str_replace(
            SignatureArtifactVersion::V9,
            SignatureArtifactVersion::V10,
            $v9,
            $v10MarkerCount,
        );
        $this->assertSame(1, $v10MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V10, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v10,
        ));
        $v11 = str_replace(
            SignatureArtifactVersion::V10,
            SignatureArtifactVersion::V11,
            $v10,
            $v11MarkerCount,
        );
        $this->assertSame(1, $v11MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V11, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v11,
        ));
        $v12 = str_replace(
            SignatureArtifactVersion::V11,
            SignatureArtifactVersion::V12,
            $v11,
            $v12MarkerCount,
        );
        $this->assertSame(1, $v12MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V12, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v12,
        ));
        $v13 = str_replace(
            SignatureArtifactVersion::V12,
            SignatureArtifactVersion::V13,
            $v12,
            $v13MarkerCount,
        );
        $this->assertSame(1, $v13MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V13, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v13,
        ));
        $v14 = str_replace(
            SignatureArtifactVersion::V13,
            SignatureArtifactVersion::V14,
            $v13,
            $v14MarkerCount,
        );
        $this->assertSame(1, $v14MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V14, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v14,
        ));
        $v15 = str_replace(
            SignatureArtifactVersion::V14,
            SignatureArtifactVersion::V15,
            $v14,
            $v15MarkerCount,
        );
        $this->assertSame(1, $v15MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V15, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v15,
        ));
        $v16 = str_replace(
            SignatureArtifactVersion::V15,
            SignatureArtifactVersion::V16,
            $v15,
            $v16MarkerCount,
        );
        $this->assertSame(1, $v16MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V16, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v16,
        ));
        $v17 = str_replace(
            SignatureArtifactVersion::V16,
            SignatureArtifactVersion::V17,
            $v16,
            $v17MarkerCount,
        );
        $this->assertSame(1, $v17MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V17, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v17,
        ));
        $v18 = str_replace(
            SignatureArtifactVersion::V17,
            SignatureArtifactVersion::V18,
            $v17,
            $v18MarkerCount,
        );
        $this->assertSame(1, $v18MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V18, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v18,
        ));
        $v19 = str_replace(
            SignatureArtifactVersion::V18,
            SignatureArtifactVersion::V19,
            $v18,
            $v19MarkerCount,
        );
        $this->assertSame(1, $v19MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V19, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v19,
        ));
        $v20 = str_replace(
            SignatureArtifactVersion::V19,
            SignatureArtifactVersion::V20,
            $v19,
            $v20MarkerCount,
        );
        $this->assertSame(1, $v20MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V20, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v20,
        ));
        $v21 = str_replace(
            SignatureArtifactVersion::V20,
            SignatureArtifactVersion::V21,
            $v20,
            $v21MarkerCount,
        );
        $this->assertSame(1, $v21MarkerCount);
        $this->assertSame(SignatureArtifactVersion::V21, SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $v21,
        ));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V8));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V9));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V10));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V11));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V12));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V13));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V14));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V15));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V16));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V17));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V18));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V19));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V20));
        $this->assertTrue(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V21));
        $this->assertFalse(SignatureArtifactVersion::usesArrivalHoldTrain(SignatureArtifactVersion::V7));
        $this->assertFalse(SignatureArtifactVersion::usesOptimizedArrivalTrain(SignatureArtifactVersion::V11));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedArrivalTrain(SignatureArtifactVersion::V12));
        $this->assertFalse(SignatureArtifactVersion::usesOptimizedArrivalTrain(SignatureArtifactVersion::V13));
        $this->assertFalse(SignatureArtifactVersion::usesSmokeSafeArrivalTrain(SignatureArtifactVersion::V12));
        $this->assertTrue(SignatureArtifactVersion::usesSmokeSafeArrivalTrain(SignatureArtifactVersion::V13));
        $this->assertTrue(SignatureArtifactVersion::usesSmokeSafeArrivalTrain(SignatureArtifactVersion::V14));
        $this->assertFalse(SignatureArtifactVersion::usesSmokeSafeArrivalTrain(SignatureArtifactVersion::V15));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedMailAssets(SignatureArtifactVersion::V15));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedMailAssets(SignatureArtifactVersion::V16));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedMailAssets(SignatureArtifactVersion::V17));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedMailAssets(SignatureArtifactVersion::V18));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedMailAssets(SignatureArtifactVersion::V19));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedMailAssets(SignatureArtifactVersion::V20));
        $this->assertTrue(SignatureArtifactVersion::usesOptimizedMailAssets(SignatureArtifactVersion::V21));
        $this->assertTrue(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V15));
        $this->assertTrue(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V16));
        $this->assertTrue(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V17));
        $this->assertTrue(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V18));
        $this->assertTrue(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V19));
        $this->assertTrue(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V20));
        $this->assertTrue(SignatureArtifactVersion::usesAspectSafeTrain(SignatureArtifactVersion::V17));
        $this->assertTrue(SignatureArtifactVersion::usesAspectSafeTrain(SignatureArtifactVersion::V18));
        $this->assertFalse(SignatureArtifactVersion::usesAspectSafeTrain(SignatureArtifactVersion::V19));
        $this->assertTrue(SignatureArtifactVersion::usesAspectSafeTrain(SignatureArtifactVersion::V20));
        $this->assertTrue(SignatureArtifactVersion::usesV17TrainAssets(SignatureArtifactVersion::V17));
        $this->assertTrue(SignatureArtifactVersion::usesV17TrainAssets(SignatureArtifactVersion::V18));
        $this->assertFalse(SignatureArtifactVersion::usesV17TrainAssets(SignatureArtifactVersion::V19));
        $this->assertFalse(SignatureArtifactVersion::usesV17TrainAssets(SignatureArtifactVersion::V20));
        $this->assertTrue(SignatureArtifactVersion::usesForwardSafeAbsoluteTrain(SignatureArtifactVersion::V19));
        $this->assertTrue(SignatureArtifactVersion::usesV19MailAssets(SignatureArtifactVersion::V19));
        $this->assertFalse(SignatureArtifactVersion::usesForwardSafeAbsoluteTrain(SignatureArtifactVersion::V20));
        $this->assertTrue(SignatureArtifactVersion::usesV19MailAssets(SignatureArtifactVersion::V20));
        $this->assertTrue(SignatureArtifactVersion::usesV19MailAssets(SignatureArtifactVersion::V21));
        $this->assertTrue(SignatureArtifactVersion::usesFlowSafeTrain(SignatureArtifactVersion::V21));
        $this->assertFalse(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V21));
        $this->assertFalse(SignatureArtifactVersion::usesAspectSafeTrain(SignatureArtifactVersion::V21));
        $this->assertFalse(SignatureArtifactVersion::usesForwardSafeAbsoluteTrain(SignatureArtifactVersion::V21));
        $this->assertFalse(SignatureArtifactVersion::usesFlowSafeTrain(SignatureArtifactVersion::V20));
        $this->assertFalse(SignatureArtifactVersion::usesForwardSafeAbsoluteTrain(SignatureArtifactVersion::V18));
        $this->assertFalse(SignatureArtifactVersion::usesV19MailAssets(SignatureArtifactVersion::V18));
        $this->assertFalse(SignatureArtifactVersion::usesFailOpenStage(SignatureArtifactVersion::V14));
        $this->assertSame(
            PortableMediaCatalog::requiredSystemAssetIds(
                MailDocumentKind::Signature,
                SignatureArtifactVersion::V8,
            ),
            PortableMediaCatalog::requiredSystemAssetIds(
                MailDocumentKind::Signature,
                SignatureArtifactVersion::V9,
            ),
        );
        $this->assertSame(
            PortableMediaCatalog::requiredSystemAssetIds(
                MailDocumentKind::Signature,
                SignatureArtifactVersion::V10,
            ),
            PortableMediaCatalog::requiredSystemAssetIds(
                MailDocumentKind::Signature,
                SignatureArtifactVersion::V11,
            ),
        );
        $this->assertStringContainsString(
            '/zug-dampf-v8-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V11,
            ),
        );
        $v12Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V12,
        );
        foreach (['zug-dampf-v12-light.gif', 'zug-dampf-v12-light.png', 'zug-dampf-v12-dark.gif', 'zug-dampf-v12-dark.png'] as $asset) {
            $this->assertContains($asset, $v12Assets);
        }
        $this->assertNotContains('zug-dampf-v8-light.gif', $v12Assets);
        $this->assertStringContainsString(
            '/zug-dampf-v12-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V12,
            ),
        );

        $v13Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V13,
        );
        foreach (['zug-dampf-v13-light.gif', 'zug-dampf-v13-light.png', 'zug-dampf-v13-dark.gif', 'zug-dampf-v13-dark.png'] as $asset) {
            $this->assertContains($asset, $v13Assets);
        }
        $this->assertNotContains('zug-dampf-v12-light.gif', $v13Assets);
        $this->assertNotContains('zug-dampf-idle-light.gif', $v13Assets);
        $this->assertStringContainsString(
            '/zug-dampf-v13-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V13,
            ),
        );

        $v15Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V15,
        );
        foreach ([
            'zug-dampf-v15-light.gif',
            'zug-dampf-v15-light.png',
            'zug-dampf-v15-dark.gif',
            'zug-dampf-v15-dark.png',
            'wortmarke-signature-v15-light.gif',
            'wortmarke-signature-v15-light.png',
            'wortmarke-mail-v15-dark.gif',
            'wortmarke-mail-v15-dark.png',
        ] as $asset) {
            $this->assertContains($asset, $v15Assets);
        }
        foreach (['zug-dampf-v13-light.gif', 'wortmarke-signature-light.gif'] as $legacyAsset) {
            $this->assertNotContains($legacyAsset, $v15Assets);
        }
        $this->assertArrayHasKey(
            SignatureArtifactVersion::V15,
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
        );
        $v16Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V16,
        );
        $this->assertSame($v15Assets, $v16Assets);
        $this->assertArrayHasKey(
            SignatureArtifactVersion::V16,
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
        );
        $v17Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V17,
        );
        $v18Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V18,
        );
        $this->assertSame($v17Assets, $v18Assets);
        $this->assertArrayHasKey(
            SignatureArtifactVersion::V18,
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
        );
        $v19Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V19,
        );
        foreach ([
            'icon-rt-v19-light.gif',
            'icon-rt-v19-light.png',
            'icon-rt-v19-dark.gif',
            'icon-rt-v19-dark.png',
            'wortmarke-signature-v19-light.gif',
            'wortmarke-signature-v19-light.png',
            'wortmarke-mail-v19-dark.gif',
            'wortmarke-mail-v19-dark.png',
            'zug-dampf-v19-light.gif',
            'zug-dampf-v19-light.png',
            'zug-dampf-v19-dark.gif',
            'zug-dampf-v19-dark.png',
        ] as $asset) {
            $this->assertContains($asset, $v19Assets);
        }
        foreach (['icon-rt-light.gif', 'wortmarke-signature-v15-light.gif', 'zug-dampf-v17-light.gif'] as $legacyAsset) {
            $this->assertNotContains($legacyAsset, $v19Assets);
        }
        $this->assertArrayHasKey(
            SignatureArtifactVersion::V19,
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
        );
        $v20Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V20,
        );
        $this->assertSame($v19Assets, $v20Assets);
        $this->assertArrayHasKey(
            SignatureArtifactVersion::V20,
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
        );
        $v21Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V21,
        );
        $this->assertSame($v19Assets, $v21Assets);
        $this->assertArrayHasKey(
            SignatureArtifactVersion::V21,
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
        );
        $this->assertStringContainsString(
            '/zug-dampf-v15-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V15,
            ),
        );
        $this->assertSame(
            'wortmarke-signature-v15-light.gif',
            EmailTemplateBuilder::signatureLogoAsset('light', SignatureArtifactVersion::V15),
        );
        $this->assertSame(
            'wortmarke-mail-v15-dark.gif',
            EmailTemplateBuilder::signatureLogoAsset('dark', SignatureArtifactVersion::V15),
        );
        $this->assertStringContainsString(
            '/zug-dampf-v19-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V19,
            ),
        );
        $this->assertSame(
            'icon-rt-v19-light.gif',
            EmailTemplateBuilder::emailMarkAsset('light', SignatureArtifactVersion::V19),
        );
        $this->assertSame(
            'icon-rt-v19-dark.gif',
            EmailTemplateBuilder::emailMarkAsset('dark', SignatureArtifactVersion::V19),
        );
        $this->assertSame(
            'wortmarke-signature-v19-light.gif',
            EmailTemplateBuilder::signatureLogoAsset('light', SignatureArtifactVersion::V19),
        );
        $this->assertSame(
            'wortmarke-mail-v19-dark.gif',
            EmailTemplateBuilder::signatureLogoAsset('dark', SignatureArtifactVersion::V19),
        );
        $this->assertStringContainsString(
            '/zug-dampf-v19-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V20,
            ),
        );
        $this->assertSame(
            'icon-rt-v19-light.gif',
            EmailTemplateBuilder::emailMarkAsset('light', SignatureArtifactVersion::V20),
        );
        $this->assertSame(
            'wortmarke-signature-v19-light.gif',
            EmailTemplateBuilder::signatureLogoAsset('light', SignatureArtifactVersion::V20),
        );
        $this->assertStringContainsString(
            '/zug-dampf-v19-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V21,
            ),
        );
        $this->assertSame(
            'icon-rt-v19-light.gif',
            EmailTemplateBuilder::emailMarkAsset('light', SignatureArtifactVersion::V21),
        );
        $this->assertSame(
            'wortmarke-signature-v19-light.gif',
            EmailTemplateBuilder::signatureLogoAsset('light', SignatureArtifactVersion::V21),
        );

        $v20Canonical = SignatureTrainCarrier::normalize(str_replace(
            SignatureArtifactVersion::V18,
            SignatureArtifactVersion::V20,
            SignatureTrainCarrier::normalize($v18),
        ));
        SignatureDocumentContract::assertValid($v20Canonical);
        $v20CompanyHtml = MailSignature::forCompany()->renderDocument($v20Canonical);
        $this->assertStringContainsString('/mail-assets/zug-dampf-v19-light.gif', $v20CompanyHtml);
        $this->assertStringContainsString('/mail-assets/wortmarke-signature-v19-light.gif', $v20CompanyHtml);
        $this->assertStringContainsString('margin-bottom:-200px', $v20CompanyHtml);
        $this->assertStringContainsString('width="720" alt=""', $v20CompanyHtml);
        $this->assertStringNotContainsString('position:absolute;z-index:0', $v20CompanyHtml);

        $v15Canonical = SignatureTrainCarrier::normalize($v15);
        $v15CompanyHtml = MailSignature::forCompany()->renderDocument($v15Canonical);
        $this->assertStringContainsString('/mail-assets/zug-dampf-v15-light.gif', $v15CompanyHtml);
        $this->assertStringContainsString('/mail-assets/wortmarke-signature-v15-light.gif', $v15CompanyHtml);
        $this->assertStringNotContainsString('&amp;p=', $v15CompanyHtml);
        $this->assertStringContainsString('height="61"', $v15CompanyHtml);

        $v15CidHtml = MailSignature::forCompany()->renderDocument(
            $v15Canonical,
            overrides: [
                'LOGO_SRC' => 'cid:railtime-v15-logo',
                'LOGO_STILL_SRC' => 'cid:railtime-v15-logo-still',
                'TRAIN_SRC' => 'cid:railtime-v15-train',
                'TRAIN_STILL_SRC' => 'cid:railtime-v15-train-still',
            ],
        );
        foreach ([
            'cid:railtime-v15-logo',
            'cid:railtime-v15-logo-still',
            'cid:railtime-v15-train',
            'cid:railtime-v15-train-still',
        ] as $cidSource) {
            $this->assertStringContainsString($cidSource, $v15CidHtml);
        }
        $this->assertStringNotContainsString('/mail-assets/zug-dampf-v15-light.gif', $v15CidHtml);
        $this->assertStringNotContainsString('/mail-assets/wortmarke-signature-v15-light.gif', $v15CidHtml);

        $v16Canonical = SignatureTrainCarrier::normalize(str_replace(
            [SignatureArtifactVersion::V15, 'data-rt-layer-mobile="train"'],
            [SignatureArtifactVersion::V16, 'data-rt-layer-mobile="stop60"'],
            $v15Canonical,
        ));
        $v16CompanyHtml = MailSignature::forCompany()->renderDocument($v16Canonical);
        $this->assertStringContainsString('/mail-assets/zug-dampf-v15-light.gif', $v16CompanyHtml);
        $this->assertStringContainsString('/mail-assets/wortmarke-signature-v15-light.gif', $v16CompanyHtml);
        $this->assertStringContainsString('data-rt-layer-mobile="stop60"', $v16CompanyHtml);

        $v14Assets = PortableMediaCatalog::requiredSystemAssetIds(
            MailDocumentKind::Signature,
            SignatureArtifactVersion::V14,
        );
        $this->assertSame($v13Assets, $v14Assets);
        $this->assertArrayHasKey(
            SignatureArtifactVersion::V14,
            PortableMediaCatalog::requiredSystemAssetContracts(MailDocumentKind::Signature),
        );
        $this->assertStringContainsString(
            '/zug-dampf-v13-light.gif',
            EmailTemplateBuilder::signatureTrainUrl(
                'light',
                animated: true,
                artifactVersion: SignatureArtifactVersion::V14,
            ),
        );

        foreach ([
            SignatureArtifactVersion::V11 => $v11,
            SignatureArtifactVersion::V12 => $v12,
            SignatureArtifactVersion::V13 => $v13,
            SignatureArtifactVersion::V14 => $v14,
            SignatureArtifactVersion::V15 => $v15Canonical,
            SignatureArtifactVersion::V16 => $v16Canonical,
        ] as $version => $versionHtml) {
            $companyHtml = MailSignature::forCompany(
                playbackNonce: $version.'-density-company',
            )->renderDocument($versionHtml);
            $this->assertSame(1, substr_count(
                $companyHtml,
                'data-rt-signature-density="compact"',
            ));

            $forgedPersonalHtml = str_replace(
                '<tr data-rt-artifact-version="'.$version.'">',
                '<tr data-rt-artifact-version="'.$version.'" data-rt-signature-density="compact">',
                $versionHtml,
                $forgedDensityCount,
            );
            $this->assertSame(1, $forgedDensityCount);
            $personalHtml = MailSignature::forUser(
                User::factory()->create(),
                remoteAssets: true,
            )->renderDocument($forgedPersonalHtml);
            $this->assertStringNotContainsString('data-rt-signature-density', $personalHtml);
        }
    }

    public function test_testmail_zeigt_artefakt_dokumentversion_und_pruefkennung_in_mail_und_json(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();
        $recipient = 'mail-layout-test@rail-time.test';
        Setting::setValue('mails', 'admin_email', $recipient);

        $document = $this->document(MailDocumentKind::Signature);
        $baseHtml = (string) $document->html;
        $baseBuilderData = $document->builder_data ?: [];

        foreach ([SignatureArtifactVersion::V12, SignatureArtifactVersion::V13, SignatureArtifactVersion::V14] as $index => $version) {
            $html = preg_replace(
                '/^<tr>/',
                '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.$version.'">',
                $baseHtml,
                1,
                $markerCount,
            );
            $this->assertIsString($html);
            $this->assertSame(1, $markerCount);

            $builderData = $baseBuilderData;
            data_set($builderData, 'pages.0.component', $html);
            $documentVersion = 27 + $index;
            $contentHash = MailDocument::contentHashFor($builderData, $html, (string) $document->css);
            $document->forceFill([
                'builder_data' => $builderData,
                'html' => $html,
                'content_hash' => $contentHash,
                'version' => $documentVersion,
            ])->save();

            Notification::fake();

            $response = $this->actingAs($admin)->postJson(
                route('admin.mail-documents.test-mail', $document),
                ['expected_hash' => $contentHash],
            );

            $shortHash = substr($contentHash, 0, 12);
            $response->assertOk()
                ->assertJsonPath('recipient', $recipient)
                ->assertJsonPath('compatibility.catalog_version', '1.0.1')
                ->assertJsonPath('layout_version', $version)
                ->assertJsonPath('document_version', $documentVersion)
                ->assertJsonPath('content_hash', $contentHash);
            $this->assertStringContainsString('Layout '.$version, (string) $response->json('message'));
            $this->assertStringContainsString('Dokumentversion '.$documentVersion, (string) $response->json('message'));
            $this->assertStringContainsString('Prüfung '.$shortHash, (string) $response->json('message'));
            $this->assertGreaterThan(strlen($html), $response->json('compatibility.html_bytes'));

            Notification::assertSentOnDemand(
                MailDocumentTestNotification::class,
                function (MailDocumentTestNotification $notification, array $channels, object $notifiable) use (
                    $contentHash,
                    $documentVersion,
                    $recipient,
                    $shortHash,
                    $version,
                ): bool {
                    $mail = $notification->toMail($notifiable);
                    $expectedSubject = '[TEST] '.MailDocumentKind::Signature->label()
                        .' · Layout '.$version
                        .' · Dokumentversion '.$documentVersion
                        .' · Prüfung '.$shortHash;

                    return $channels === ['mail']
                        && $notifiable->routeNotificationFor('mail') === $recipient
                        && $mail->subject === $expectedSubject
                        && in_array('Verwendete Layoutversion: '.$version.'.', $mail->introLines, true)
                        && in_array('Gespeicherte Dokumentversion: '.$documentVersion.'.', $mail->introLines, true)
                        && in_array('Prüfkennung: '.$shortHash.'.', $mail->introLines, true)
                        && strlen($contentHash) === 64;
                },
            );
        }
    }

    public function test_jede_systemmail_bettet_alle_lokalen_bilder_als_mime_cid_ein(): void
    {
        $this->createCanonicalMailDocuments();

        $signature = $this->document(MailDocumentKind::Signature);
        $markedSignature = preg_replace(
            '/^<tr>/',
            '<tr '.SignatureArtifactVersion::ATTRIBUTE.'="'.SignatureArtifactVersion::V20.'">',
            (string) $signature->published_html,
            1,
            $markerCount,
        );
        $this->assertIsString($markedSignature);
        $this->assertSame(1, $markerCount);
        $v20Signature = SignatureTrainCarrier::normalize($markedSignature);
        SignatureDocumentContract::assertValid($v20Signature);
        $builderData = $signature->builder_data ?: [];
        data_set($builderData, 'pages.0.component', $v20Signature);
        data_set($builderData, 'railtime.schema', SignatureDocumentContract::SCHEMA);
        $signature->forceFill([
            'builder_data' => $builderData,
            'html' => $v20Signature,
            'published_html' => $v20Signature,
            'content_hash' => MailDocument::contentHashFor($builderData, $v20Signature, ''),
            'version' => 20,
        ])->save();
        $this->app->forgetScopedInstances();

        $compiledHtml = EmailTemplateBuilder::buildSystemMailHtml(
            new HtmlString('<p>MIME-CID-Vertrag</p>'),
        );
        preg_match_all(
            '~<img\b[^>]*\ssrc\s*=\s*(["\'])(https?://[^"\']+)\1~i',
            $compiledHtml,
            $sourceMatches,
        );

        $expectedFilenames = [];
        foreach ($sourceMatches[2] as $source) {
            $path = parse_url(
                html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                PHP_URL_PATH,
            );

            if (is_string($path) && str_contains($path, '/mail-assets/')) {
                $expectedFilenames[] = basename($path);
            }
        }
        $expectedFilenames = array_values(array_unique($expectedFilenames));
        sort($expectedFilenames);
        $this->assertNotEmpty($expectedFilenames);
        foreach ([
            'icon-rt-v19-light.gif',
            'icon-rt-v19-light.png',
            'wortmarke-signature-v19-light.gif',
            'wortmarke-signature-v19-light.png',
            'zug-dampf-v19-light.gif',
            'zug-dampf-v19-light.png',
        ] as $optimizedFilename) {
            $this->assertContains($optimizedFilename, $expectedFilenames);
        }
        $this->assertStringContainsString('data-rt-artifact-version="v20"', $compiledHtml);
        $this->assertStringContainsString('margin-bottom:-200px', $compiledHtml);
        $this->assertDoesNotMatchRegularExpression(
            '/class="rt-sign-train-layer"[^>]*style="[^"]*position\s*:\s*absolute/i',
            $compiledHtml,
        );
        $this->assertStringNotContainsString('icon-rt-light.gif', $compiledHtml);
        $this->assertStringNotContainsString('wortmarke-signature-v15-light.gif', $compiledHtml);
        $this->assertStringNotContainsString('zug-dampf-v17-light.gif', $compiledHtml);

        $mailer = app(MailFactory::class)->mailer();
        $transport = $mailer->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $transport->flush();

        Notification::route('mail', 'cid-systemmail@rail-time.test')->notify(
            new MailDocumentTestNotification(
                MailDocumentKind::Signature,
                20,
                SignatureArtifactVersion::V20,
                hash('sha256', 'mime-cid-systemmail'),
            ),
        );

        $sent = $transport->messages()->sole();
        $email = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        $deliveredHtml = (string) $email->getHtmlBody();
        $this->assertStringContainsString('data-rt-artifact-version="v20"', $deliveredHtml);
        $this->assertMatchesRegularExpression(
            '/class="rt-sign-train-layer"[^>]*style="[^"]*position\s*:\s*relative[^\"]*margin-bottom\s*:\s*-200px/i',
            $deliveredHtml,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="rt-sign-train-layer"[^>]*style="[^"]*position\s*:\s*absolute/i',
            $deliveredHtml,
        );
        $this->assertStringContainsString(SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE, $deliveredHtml);
        $this->assertDoesNotMatchRegularExpression(
            '~<img\b[^>]*\ssrc\s*=\s*(["\'])https?://[^"\']*/mail-assets/~i',
            $deliveredHtml,
        );
        preg_match_all(
            '~<img\b[^>]*\ssrc\s*=\s*(["\'])cid:([^"\']+)\1~i',
            $deliveredHtml,
            $cidMatches,
        );
        $contentIds = array_values(array_unique($cidMatches[2]));

        $attachments = array_values(array_filter(
            $email->getAttachments(),
            static fn (mixed $attachment): bool => $attachment instanceof DataPart,
        ));
        $this->assertCount(count($expectedFilenames), $contentIds);
        $this->assertCount(count($expectedFilenames), $attachments);

        $actualFilenames = [];
        foreach ($attachments as $attachment) {
            $this->assertSame('inline', $attachment->getDisposition());
            $this->assertStringContainsString('@', $attachment->getContentId());
            $this->assertContains($attachment->getContentId(), $contentIds);
            $actualFilenames[] = (string) $attachment->getFilename();
        }
        sort($actualFilenames);
        $this->assertSame($expectedFilenames, $actualFilenames);

        $rawMime = $sent->toString();
        $this->assertStringContainsString('multipart/related', $rawMime);
        $this->assertStringContainsString('Content-Disposition: inline', $rawMime);

        $attachmentCount = count($email->getAttachments());
        $this->assertSame(0, app(SystemMailInlineImageEmbedder::class)->embed($email));
        $this->assertCount($attachmentCount, $email->getAttachments());

        Storage::fake('public');
        $importedBinary = (string) file_get_contents(public_path('mail-assets/contact-email.png'));
        $importedFilename = hash('sha256', $importedBinary).'.png';
        Storage::disk('public')->put('mail-imports/'.$importedFilename, $importedBinary);
        $importedUrl = URL::to(Storage::disk('public')->url('mail-imports/'.$importedFilename));
        $importedHtml = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString(
            '<p>Portables Medium</p><img src="'.htmlspecialchars(
                $importedUrl,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            ).'" alt="">',
        ));

        $transport->flush();
        $mailer->html($importedHtml, static function ($message): void {
            $message->to('cid-import@rail-time.test')->subject('CID-Importvertrag');
        });

        $importedEmail = $transport->messages()->sole()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $importedEmail);
        $this->assertStringNotContainsString($importedUrl, (string) $importedEmail->getHtmlBody());
        $importedParts = array_values(array_filter(
            $importedEmail->getAttachments(),
            static fn (DataPart $attachment): bool => $attachment->getFilename() === $importedFilename,
        ));
        $this->assertCount(1, $importedParts);
        $this->assertSame('inline', $importedParts[0]->getDisposition());
        $this->assertStringContainsString(
            'cid:'.$importedParts[0]->getContentId(),
            (string) $importedEmail->getHtmlBody(),
        );

        $foreignHtml = '<html><body><img src="'.URL::asset('mail-assets/contact-email.png').'" alt=""></body></html>';
        $foreignMail = (new Email)->html($foreignHtml);
        $this->assertSame(0, app(SystemMailInlineImageEmbedder::class)->embed($foreignMail));
        $this->assertSame($foreignHtml, $foreignMail->getHtmlBody());
        $this->assertSame([], $foreignMail->getAttachments());
    }

    public function test_speichern_verlangt_den_aktuellen_fingerabdruck(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => ['pages' => []],
                'html' => '<table><tr><td>Neu</td></tr></table>',
                'css' => '',
                'expected_hash' => str_repeat('a', 64),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_hash');

        $this->assertSame(1, $document->fresh()->version);
    }

    public function test_speichern_haertet_das_markup_und_meldet_die_beanstandung(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $unsafeHtml = str_replace(
            '{{APPLICATION_CONTENT}}',
            '<tr><td onclick="alert(1)">Text</td></tr>{{APPLICATION_CONTENT}}<script>alert(2)</script>',
            (string) $document->html,
        );

        $response = $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => [
                    'pages' => [[
                        'name' => '  Eigene Nachrichtenschale  ',
                        'component' => '<script>ungepruefter Zweitspeicher</script>',
                    ]],
                    'styles' => [['selectors' => ['body'], 'style' => ['behavior' => 'url(x.htc)']]],
                    'railtime' => [
                        'document' => 'signature',
                        'schema' => 1,
                        'unsafe' => '<script>alert(3)</script>',
                    ],
                    'unsafe' => '<iframe src="https://example.test"></iframe>',
                ],
                'html' => $unsafeHtml,
                'css' => 'td { color:#111820; } body { behavior:url(x.htc); }',
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk();

        $saved = $document->fresh();

        $this->assertStringNotContainsString('<script', $saved->html);
        $this->assertStringNotContainsString('onclick', $saved->html);
        $this->assertStringContainsString('Text', $saved->html);
        $this->assertStringNotContainsString('behavior', (string) $saved->css);
        $this->assertStringContainsString('color:#111820', (string) $saved->css);
        $this->assertSame(2, $saved->version);
        $this->assertSame(['pages', 'styles', 'railtime'], array_keys($saved->builder_data));
        $this->assertSame('Eigene Nachrichtenschale', data_get($saved->builder_data, 'pages.0.name'));
        $this->assertSame($saved->html, data_get($saved->builder_data, 'pages.0.component'));
        $this->assertSame([], $saved->builder_data['styles']);
        $this->assertSame([
            'document' => MailDocumentKind::Template->value,
            'schema' => 1,
        ], $saved->builder_data['railtime']);

        // Der gespeicherte Fingerabdruck gehoert zum GEHAERTETEN Inhalt.
        $this->assertSame(
            MailDocument::contentHashFor($saved->builder_data, $saved->html, (string) $saved->css),
            $saved->content_hash,
        );
        $this->assertSame($saved->content_hash, $response->json('document.content_hash'));
        $this->assertSame($saved->builder_data, $response->json('document.builder_data'));
        $this->assertSame($saved->html, $response->json('document.html'));
        $this->assertSame($saved->css, $response->json('document.css'));
        $this->assertFalse($response->json('report.clean'));
        $this->assertNotEmpty($response->json('report.messages'));
        $this->assertSame('1.0.1', $response->json('compatibility.catalog_version'));
        $this->assertContains($response->json('compatibility.status'), ['pass', 'warn']);
    }

    public function test_fehlender_kompatibilitaetskatalog_erlaubt_entwurf_aber_blockiert_freigabe(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $missingPath = storage_path('framework/testing/missing-email-catalog-'.bin2hex(random_bytes(8)).'.csv');
        $catalog = new EmailCompatibilityCatalog($missingPath);
        $this->app->instance(EmailCompatibilityCatalog::class, $catalog);
        $this->app->instance(EmailCompatibilityAuditor::class, new EmailCompatibilityAuditor($catalog));

        $saved = $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $document->html,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('compatibility.catalog_version', 'unavailable')
            ->assertJsonPath('compatibility.status', 'block')
            ->assertJsonPath('compatibility.findings.0.diagnostic_code', 'EMAIL_CATALOG_UNAVAILABLE');

        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $saved->json('document.content_hash'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('compatibility');

        $fresh = $document->fresh();
        $this->assertSame(MailDocumentStatus::Draft, $fresh->status);
        $this->assertNull($fresh->published_html);
    }

    public function test_signatursave_entfernt_editorattribute_und_verlangt_die_kanonische_zugquelle(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $legacyHtml = $this->legacySchema24Signature((string) $document->html);
        $legacyBuilderData = $document->builder_data;
        data_set($legacyBuilderData, 'pages.0.component', $legacyHtml);
        data_set($legacyBuilderData, 'railtime.schema', 24);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $legacyBuilderData,
                'html' => $legacyHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('document.builder_data.railtime.schema', SignatureDocumentContract::SCHEMA);

        $document = $document->fresh();
        SignatureDocumentContract::assertValid((string) $document->html);
        $this->assertStringContainsString('margin-bottom:-200px;', (string) $document->html);
        $this->assertStringNotContainsString('margin-bottom:-7.3611%;', (string) $document->html);
        $withPreviewAttribute = preg_replace(
            '/<td class="rt-sign-cell"/',
            '<td data-rt-mail-preview-train="TRAIN_SRC" class="rt-sign-cell"',
            (string) $document->html,
            1,
        );
        $this->assertIsString($withPreviewAttribute);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $withPreviewAttribute,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $saved = $document->fresh();
        $canonicalHtml = (string) $saved->html;
        $this->assertStringNotContainsString('data-rt-mail-', $canonicalHtml);
        $this->assertSame(1, substr_count($canonicalHtml, '{{TRAIN_SRC}}'));
        $this->assertSame($canonicalHtml, data_get($saved->builder_data, 'pages.0.component'));

        $attacks = [
            'preview pixel statt token' => str_replace(
                '{{TRAIN_SRC}}',
                EmailHtmlSanitizer::PREVIEW_TRANSPARENT_PIXEL,
                $canonicalHtml,
            ),
            'zweite zugquelle' => str_replace(
                'src="{{TRAIN_SRC}}"',
                'src="{{TRAIN_SRC}}{{TRAIN_SRC}}"',
                $canonicalHtml,
            ),
            'frei erfundene traegerklasse' => str_replace(
                'rt-sign-cell',
                'custom-sign-cell',
                $canonicalHtml,
            ),
        ];

        foreach ($attacks as $label => $attackHtml) {
            $this->assertNotSame($canonicalHtml, $attackHtml, $label);

            $this->putJson(route('admin.mail-documents.update', $saved), [
                'builder_data' => $saved->builder_data,
                'html' => $attackHtml,
                'css' => (string) $saved->css,
                'expected_hash' => $saved->content_hash,
            ])->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $saved->fresh()->html, $label);
        }

        $this->putJson(route('admin.mail-documents.update', $saved), [
            'builder_data' => $saved->builder_data,
            'html' => $canonicalHtml,
            'css' => '[data-rt-mail-preview-train]{color:#111820;}',
            'expected_hash' => $saved->content_hash,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('css');

        $this->assertSame('', trim((string) $saved->fresh()->css));

        $this->postJson(route('admin.mail-documents.publish', $saved), [
            'expected_hash' => $saved->content_hash,
        ])->assertOk()
            ->assertJsonPath('document.status', MailDocumentStatus::Published->value);
    }

    public function test_signaturpublish_lehnt_preview_artefakte_und_mehrdeutige_zugquellen_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $validHtml = (string) $document->html;
        $validBuilderData = $document->builder_data;
        $legacyHtml = $this->legacySchema24Signature($validHtml);
        $legacyBuilderData = $validBuilderData;
        data_set($legacyBuilderData, 'pages.0.component', $legacyHtml);
        data_set($legacyBuilderData, 'railtime.schema', 24);
        $document->forceFill([
            'builder_data' => $legacyBuilderData,
            'html' => $legacyHtml,
            'content_hash' => MailDocument::contentHashFor($legacyBuilderData, $legacyHtml, ''),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('document.builder_data.railtime.schema', SignatureDocumentContract::SCHEMA);

        $document->refresh();
        SignatureDocumentContract::assertValid((string) $document->published_html);
        $this->assertStringContainsString('margin-bottom:-200px;', (string) $document->published_html);
        $document->forceFill([
            'builder_data' => $validBuilderData,
            'html' => $validHtml,
            'css' => '',
            'content_hash' => MailDocument::contentHashFor($validBuilderData, $validHtml, ''),
            'published_html' => null,
            'published_css' => null,
            'published_at' => null,
            'status' => MailDocumentStatus::Draft,
        ])->save();

        $attacks = [
            'preview attribut' => preg_replace(
                '/<td class="rt-sign-cell"/',
                '<td data-rt-mail-preview-train="TRAIN_SRC" class="rt-sign-cell"',
                $validHtml,
                1,
            ),
            'preview pixel' => str_replace(
                '{{TRAIN_SRC}}',
                EmailHtmlSanitizer::PREVIEW_TRANSPARENT_PIXEL,
                $validHtml,
            ),
            'mehrere train tokens' => str_replace(
                'src="{{TRAIN_SRC}}"',
                'src="{{TRAIN_SRC}}{{TRAIN_SRC}}"',
                $validHtml,
            ),
            'serverseitiger MSO fallback im quell-html' => str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<!--[if mso]><v:rect class="rt-sign-train-mso" fill="true" stroke="false">'
                    .'<v:fill src="{{TRAIN_STILL_SRC}}" /></v:rect><![endif]-->'
                    .'<!-- RT_SIGNATURE_MAIN_END -->',
                $validHtml,
            ),
        ];

        foreach ($attacks as $label => $attackHtml) {
            $this->assertIsString($attackHtml);
            $this->assertNotSame($validHtml, $attackHtml, $label);
            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $attackHtml,
                    (string) $document->css,
                ),
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertNull($document->fresh()->published_at, $label);
        }

        $previewCss = '[data-rt-mail-preview-train]{color:#111820;}';
        $document->forceFill([
            'html' => $validHtml,
            'css' => $previewCss,
            'content_hash' => MailDocument::contentHashFor(
                $document->builder_data,
                $validHtml,
                $previewCss,
            ),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('css');

        $this->assertNull($document->fresh()->published_at);

        $customOverlapHtml = str_replace(
            'style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;"',
            'style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-72px!important;overflow:hidden;font-size:0;line-height:0;text-align:left;"',
            $validHtml,
            $overlapReplacementCount,
        );
        $this->assertSame(1, $overlapReplacementCount);
        $customBuilderData = $validBuilderData;
        data_set($customBuilderData, 'pages.0.component', $customOverlapHtml);
        $document->forceFill([
            'builder_data' => $customBuilderData,
            'html' => $customOverlapHtml,
            'css' => '',
            'content_hash' => MailDocument::contentHashFor($customBuilderData, $customOverlapHtml, ''),
            'published_html' => null,
            'published_css' => null,
            'published_at' => null,
            'status' => MailDocumentStatus::Draft,
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);
    }

    public function test_signatur_save_und_publish_lehnen_background_kurzform_am_zugcarrier_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $shorthandHtml = str_replace(
            'background-image:',
            'background:',
            $canonicalHtml,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $shorthandHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

        $document->forceFill([
            'html' => $shorthandHtml,
            'content_hash' => MailDocument::contentHashFor(
                $document->builder_data,
                $shorthandHtml,
                (string) $document->css,
            ),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);
    }

    public function test_signatur_lehnt_den_css_kommentarzustand_bypass_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $attackHtml = preg_replace(
            '/(<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*style="[^"]*)(?=")/i',
            '${1}background/*;*/:none;',
            $canonicalHtml,
            1,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $this->assertIsString($attackHtml);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $attackHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

        $document->forceFill([
            'html' => $attackHtml,
            'content_hash' => MailDocument::contentHashFor(
                $document->builder_data,
                $attackHtml,
                (string) $document->css,
            ),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);

        $document->forceFill([
            'published_html' => $attackHtml,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();
        $this->app->forgetScopedInstances();

        try {
            MailSignature::forCompany(playbackNonce: 'comment-state-bypass')->render();
            $this->fail('Der CSS-Kommentarzustand-BYPASS wurde beim Rendern nicht abgelehnt.');
        } catch (\RuntimeException $exception) {
            $this->assertMatchesRegularExpression('/CSS|Zug-Carrier|mail-sichere/', $exception->getMessage());
        }
    }

    public function test_signatur_lehnt_den_css_escape_zustand_bypass_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $attackHtml = str_replace(
            'background-image:',
            'x\\;background-image:',
            $canonicalHtml,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $attackHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

        $document->forceFill([
            'html' => $attackHtml,
            'content_hash' => MailDocument::contentHashFor(
                $document->builder_data,
                $attackHtml,
                (string) $document->css,
            ),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);

        $document->forceFill([
            'published_html' => $attackHtml,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();
        $this->app->forgetScopedInstances();

        try {
            MailSignature::forCompany(playbackNonce: 'escape-state-bypass')->render();
            $this->fail('Der CSS-Escape-Zustand-BYPASS wurde beim Rendern nicht abgelehnt.');
        } catch (\RuntimeException $exception) {
            $this->assertMatchesRegularExpression('/CSS|Zug-Carrier|rt-pad|mail-sichere/', $exception->getMessage());
        }
    }

    public function test_signatur_lehnt_html_entity_delimiter_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $attackHtml = preg_replace(
            '/(<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*style="[^"]*)(?=")/i',
            '${1}&#59;background:none;',
            $canonicalHtml,
            1,
            $replacementCount,
        );
        $this->assertSame(1, $replacementCount);
        $this->assertIsString($attackHtml);

        $this->actingAs($admin)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $attackHtml,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertSame($canonicalHtml, (string) $document->fresh()->html);

        $document->forceFill([
            'html' => $attackHtml,
            'content_hash' => MailDocument::contentHashFor(
                $document->builder_data,
                $attackHtml,
                (string) $document->css,
            ),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);

        $document->forceFill([
            'published_html' => $attackHtml,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();
        $this->app->forgetScopedInstances();

        try {
            MailSignature::forCompany(playbackNonce: 'entity-delimiter-bypass')->render();
            $this->fail('Der HTML-Entity-Delimiter wurde beim Rendern nicht abgelehnt.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('background-Kurzform', $exception->getMessage());
        }
    }

    public function test_signatur_lehnt_ungueltige_css_envelope_zustaende_beim_save_publish_und_rendern_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $canonicalBuilderData = $document->builder_data;
        $canonicalCss = (string) $document->css;
        $canonicalHash = MailDocument::contentHashFor(
            $canonicalBuilderData,
            $canonicalHtml,
            $canonicalCss,
        );
        $canonicalPublishedHtml = $document->published_html;
        $canonicalPublishedCss = $document->published_css;
        $canonicalPublishedAt = $document->published_at;
        $canonicalStatus = $document->status;

        $attacks = [
            'raw vertical tab' => [
                str_replace('background-image:', "\vbackground-image:", $canonicalHtml),
                'CSS-Steuerzeichen',
            ],
            'entity vertical tab' => [
                str_replace('background-image:', '&#11;background-image:', $canonicalHtml),
                'CSS-Steuerzeichen',
            ],
            'unmatched opening brace' => [
                str_replace('background-image:', 'x{;background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'unmatched opening bracket' => [
                str_replace('background-image:', 'x[;background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'stray closing brace' => [
                str_replace('background-image:', 'x};background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'stray closing bracket' => [
                str_replace('background-image:', 'x];background-image:', $canonicalHtml),
                'CSS-Klammern',
            ],
            'raw carriage return in url string' => [
                str_replace(
                    'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
                    "url('{{SIGNATURE_TRAIN_WASH}}\r')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'raw line feed in url string' => [
                str_replace(
                    'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
                    "url('{{SIGNATURE_TRAIN_WASH}}\n')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'raw form feed in url string' => [
                str_replace(
                    'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
                    "url('{{SIGNATURE_TRAIN_WASH}}\f')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'entity carriage return in url string' => [
                str_replace(
                    'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
                    "url('{{SIGNATURE_TRAIN_WASH}}&#13;')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'entity line feed in url string' => [
                str_replace(
                    'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
                    "url('{{SIGNATURE_TRAIN_WASH}}&#10;')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
            'entity form feed in url string' => [
                str_replace(
                    'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
                    "url('{{SIGNATURE_TRAIN_WASH}}&#12;')",
                    $canonicalHtml,
                ),
                'CSS-Stringumbruch',
            ],
        ];

        foreach ($attacks as $label => [$attackHtml, $runtimeMessage]) {
            $this->assertNotSame($canonicalHtml, $attackHtml, $label);
            $document->forceFill([
                'html' => $canonicalHtml,
                'content_hash' => $canonicalHash,
                'published_html' => $canonicalPublishedHtml,
                'published_css' => $canonicalPublishedCss,
                'published_at' => $canonicalPublishedAt,
                'status' => $canonicalStatus,
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $canonicalBuilderData,
                    'html' => $attackHtml,
                    'css' => $canonicalCss,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $document->fresh()->html, $label);

            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $attackHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalPublishedAt, $document->fresh()->published_at, $label);

            $document->forceFill([
                'published_html' => $attackHtml,
                'published_at' => now(),
                'status' => MailDocumentStatus::Published,
                'is_active' => true,
            ])->save();
            $this->app->forgetScopedInstances();

            try {
                MailSignature::forCompany(playbackNonce: 'css-envelope-'.$label)->render();
                $this->fail("{$label}: Der ungueltige CSS-Envelope wurde beim Rendern nicht abgelehnt.");
            } catch (\RuntimeException $exception) {
                $this->assertMatchesRegularExpression(
                    '/CSS|Zug-Carrier|rt-pad|mail-sichere|Background/',
                    $exception->getMessage(),
                    $label.' ('.$runtimeMessage.')',
                );
            }
        }
    }

    public function test_signatur_erlaubt_nur_den_gekoppelten_kanonischen_layervertrag_beim_save_publish_und_rendern(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $canonicalBuilderData = $document->builder_data;
        $canonicalCss = (string) $document->css;
        $canonicalHash = MailDocument::contentHashFor(
            $canonicalBuilderData,
            $canonicalHtml,
            $canonicalCss,
        );
        $canonicalPublishedHtml = $document->published_html;
        $canonicalPublishedCss = $document->published_css;
        $canonicalPublishedAt = $document->published_at;
        $canonicalStatus = $document->status;

        $extraLayer = function (string $image) use ($canonicalHtml): string {
            $html = str_replace(
                'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}});',
                'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}}),'.$image.';',
                $canonicalHtml,
                $imageCount,
            );
            $html = str_replace(
                'background-repeat:no-repeat;',
                'background-repeat:no-repeat,no-repeat;',
                $html,
                $repeatCount,
            );
            $html = str_replace(
                'background-position:center center;',
                'background-position:center center,right bottom;',
                $html,
                $positionCount,
            );
            $html = str_replace(
                'background-size:100% 100%;',
                'background-size:100% 100%,auto 100%;',
                $html,
                $sizeCount,
            );

            $this->assertSame([1, 1, 1, 1], [$imageCount, $repeatCount, $positionCount, $sizeCount]);

            return $html;
        };
        $importantLonghand = function (string $property) use ($canonicalHtml): string {
            $html = preg_replace(
                '/('.preg_quote($property, '/').':[^;]*);/',
                '$1!important;',
                $canonicalHtml,
                1,
                $replacementCount,
            );
            $this->assertSame(1, $replacementCount);
            $this->assertIsString($html);

            return $html;
        };

        $attacks = [
            'unknown image function' => [
                str_replace(
                    'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
                    'foo()',
                    $canonicalHtml,
                ),
                'bildfreie Basis-Ebene',
            ],
            'global repeat value' => [
                str_replace(
                    'background-repeat:no-repeat;',
                    'background-repeat:inherit;',
                    $canonicalHtml,
                ),
                'Basis-Layer',
            ],
            'bogus position value' => [
                str_replace(
                    'background-position:center center;',
                    'background-position:bogus;',
                    $canonicalHtml,
                ),
                'Basis-Layer',
            ],
            'bogus size value' => [
                str_replace(
                    'background-size:100% 100%;',
                    'background-size:bogus;',
                    $canonicalHtml,
                ),
                'Basis-Layer',
            ],
            'foreign url layer' => [
                $extraLayer('url(https://rail-time.de/foreign-train.gif)'),
                'Background-Layerzahl',
            ],
            'foreign data uri layer' => [
                $extraLayer('url(data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==)'),
                'Background-Layerzahl',
            ],
            'important background image' => [$importantLonghand('background-image'), 'Background-Listen'],
            'important background repeat' => [$importantLonghand('background-repeat'), 'Background-Listen'],
            'important background position' => [$importantLonghand('background-position'), 'Background-Listen'],
            'important background size' => [$importantLonghand('background-size'), 'Background-Listen'],
        ];

        foreach ($attacks as $label => [$attackHtml, $runtimeMessage]) {
            $this->assertNotSame($canonicalHtml, $attackHtml, $label);
            $document->forceFill([
                'html' => $canonicalHtml,
                'content_hash' => $canonicalHash,
                'published_html' => $canonicalPublishedHtml,
                'published_css' => $canonicalPublishedCss,
                'published_at' => $canonicalPublishedAt,
                'status' => $canonicalStatus,
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $canonicalBuilderData,
                    'html' => $attackHtml,
                    'css' => $canonicalCss,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $document->fresh()->html, $label);

            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $attackHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertEquals($canonicalPublishedAt, $document->fresh()->published_at, $label);

            $document->forceFill([
                'published_html' => $attackHtml,
                'published_at' => now(),
                'status' => MailDocumentStatus::Published,
                'is_active' => true,
            ])->save();
            $this->app->forgetScopedInstances();

            try {
                MailSignature::forCompany(playbackNonce: 'layer-contract-'.$label)->render();
                $this->fail("{$label}: Der ungueltige Layervertrag wurde beim Rendern nicht abgelehnt.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString($runtimeMessage, $exception->getMessage(), $label);
            }
        }
    }

    public function test_signatur_save_und_publish_lehnen_unvollstaendige_oder_mehrdeutige_longhand_vertraege_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonicalHtml = (string) $document->html;
        $canonicalBuilderData = $document->builder_data;
        $canonicalCss = (string) $document->css;

        $missingSizeHtml = preg_replace(
            '/background-size:[^;]*;/',
            '',
            $canonicalHtml,
            1,
            $missingSizeCount,
        );
        $duplicateImageHtml = str_replace(
            'background-image:',
            'background-image:none;background-image:',
            $canonicalHtml,
            $duplicateImageCount,
        );
        $nonParallelHtml = str_replace(
            'background-repeat:no-repeat;',
            'background-repeat:no-repeat,no-repeat;',
            $canonicalHtml,
            $nonParallelCount,
        );

        $this->assertSame(1, $missingSizeCount);
        $this->assertIsString($missingSizeHtml);
        $this->assertSame(1, $duplicateImageCount);
        $this->assertSame(1, $nonParallelCount);

        $attacks = [
            'fehlendes background-size' => $missingSizeHtml,
            'doppeltes background-image' => $duplicateImageHtml,
            'nicht parallele background-Listen' => $nonParallelHtml,
        ];

        foreach ($attacks as $label => $attackHtml) {
            $document->forceFill([
                'html' => $canonicalHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $canonicalHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $canonicalBuilderData,
                    'html' => $attackHtml,
                    'css' => $canonicalCss,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertSame($canonicalHtml, (string) $document->fresh()->html, $label);

            $document->forceFill([
                'html' => $attackHtml,
                'content_hash' => MailDocument::contentHashFor(
                    $canonicalBuilderData,
                    $attackHtml,
                    $canonicalCss,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $this->assertNull($document->fresh()->published_at, $label);
        }
    }

    public function test_separate_mail_css_spalten_sperren_important_und_reservierte_zugquellen(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();
        $attacks = [
            '.rt-sign-cell{background:none!important;}',
            '*{opacity:1&#33;important;}',
            '.rt-train-idle-overlay{visibility:visible!\\69mportant;}',
            '.x{content:"unterminated;}',
            ".x{content:\"line\nbreak\";}",
            ".x{content:\"line\rbreak\";}",
            ".x{content:\"line\fbreak\";}",
            sprintf('.x{content:"unfinished%c', 92),
            '@keyframes injected{from{opacity:0}to{opacity:1}}',
            '.x{animation:injected 1s linear;}',
            '.x{animation-delay:1s;}',
            '.x{anim\\61 tion:injected 1s;}',
            'td{mso-padding-alt:20px;}',
            'td{MSO-PADDING-ALT:20px;}',
            'td{mso-padding&#45;alt:20px;}',
            'td{mso-padd\\69 ng-alt:20px;}',
            'td{mso-padding/**/-alt:20px;}',
            '.rt-sign-\\63 ell::before{content:"x";}',
            '.rt-train-main-image{opacity:0;}',
            '.rt-train-main-layer{height:auto;}',
            '.rt-sign-train{display:none;}',
            '.rt-train-idle-overlay::before{content:"x";}',
            '.rt-train-idle-surface::after{content:"x";}',
            '.rt-train-idle-image{opacity:0;}',
            '.rt-classic-outlook-train::before{content:"x";}',
            '[data-rt-train-main-image]{visibility:hidden;}',
            '[data-rt-train-main-layer]{height:auto;}',
            '[data-rt-train]{visibility:hidden;}',
            '[data-rt-train-idle-overlay]{opacity:1;}',
            '[data-rt-train-idle-image="1"]{visibility:hidden;}',
            '[class]{color:red;}',
            '[class="foo rt-sign-cell"]::before{content:"x";}',
            '[cl\\61 ss~="rt-sign-\\63 ell"]::before{content:"x";}',
            '[class*="train-idle"]::after{content:"x";}',
            '[class~="ordinary-card"]{color:inherit;}',
            'td[class$=" rt-sign-cell"]{background:none;}',
            'td[class^="rt-pad rt-sign-\\63 ell"]{background:none;}',
            "[cl\\61\r\nss~='ordinary-card']{color:inherit;}",
            ".rt-sign-\\63\r\nell::before{content:\"x\";}",
            ".rt-sign-\\63\rell::before{content:\"x\";}",
            ".rt-sign-\\63\fell::before{content:\"x\";}",
            '.rt-sign-\\63&#13;&#10;ell::before{content:"x";}',
            "*{opacity:1!imp\\6f\r\nrtant;}",
            "*{opacity:1!imp\\6f\frtant;}",
            '.rt-sign-logo{background-image:url({{TRAIN_SRC}});}',
            '.rt-sign-logo{background-image:url({{TRAIN_IDLE_SRC}});}',
            '.rt-sign-logo{background-image:url({{TRAIN_STILL_SRC}});}',
            '.x{content:"{{RESPONSIVE_CSS}}";}',
            '.x{content:"{{SIGNATURE_BLOCK}}";}',
            '.x{content:"{{APPLICATION_CONTENT}}";}',
        ];

        foreach (MailDocumentKind::cases() as $kind) {
            $document = $this->document($kind);
            $canonicalCss = (string) $document->css;

            foreach ($attacks as $css) {
                $document->forceFill([
                    'css' => $canonicalCss,
                    'content_hash' => MailDocument::contentHashFor(
                        $document->builder_data,
                        (string) $document->html,
                        $canonicalCss,
                    ),
                ])->save();
                $document->refresh();

                $this->actingAs($admin)
                    ->putJson(route('admin.mail-documents.update', $document), [
                        'builder_data' => $document->builder_data,
                        'html' => (string) $document->html,
                        'css' => $css,
                        'expected_hash' => $document->content_hash,
                    ])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('css');

                $document->forceFill([
                    'css' => $css,
                    'content_hash' => MailDocument::contentHashFor(
                        $document->builder_data,
                        (string) $document->html,
                        $css,
                    ),
                ])->save();
                $document->refresh();

                $this->actingAs($admin)
                    ->postJson(route('admin.mail-documents.publish', $document), [
                        'expected_hash' => $document->content_hash,
                    ])
                    ->assertStatus(422)
                    ->assertJsonValidationErrors('css');
            }
        }
    }

    public function test_template_style_und_signatur_slot_bleiben_kanonisch_und_strukturell_echt(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $admin = $this->admin();
        $canonical = (string) $document->html;
        $withoutSignature = str_replace('{{SIGNATURE_BLOCK}}', '', $canonical);
        $attacks = [
            'extra style' => str_replace('</head>', '<style>*{opacity:1!important}</style></head>', $canonical),
            'commented canonical style' => str_replace(
                ['<style>', '</style>'],
                ['<!-- <style>', '</style> -->'],
                $canonical,
            ),
            'changed canonical style' => str_replace(
                'a { color: inherit; }',
                'a { color: inherit; } * { opacity: 1 !important; }',
                $canonical,
            ),
            'missing Outlook mark still' => str_replace('{{ICON_RT_STILL_SRC}}', '', $canonical),
            'duplicate Outlook mark still' => str_replace(
                '<!-- RT_TEMPLATE_MARK_END -->',
                '<img src="{{ICON_RT_STILL_SRC}}" alt=""><!-- RT_TEMPLATE_MARK_END -->',
                $canonical,
            ),
            'animated mark without mso hide' => str_replace(';mso-hide:all;', ';', $canonical),
            'mark still outside conditional' => str_replace(
                ['<!--[if mso]><img class="rt-mark"', '<![endif]-->'],
                ['<img class="rt-mark"', ''],
                $canonical,
            ),
            'comment signature slot' => str_replace(
                '{{SIGNATURE_BLOCK}}',
                '<!-- {{SIGNATURE_BLOCK}} -->',
                $canonical,
            ),
            'head signature slot' => str_replace(
                '</head>',
                '{{SIGNATURE_BLOCK}}</head>',
                $withoutSignature,
            ),
            'early train source' => str_replace(
                '</body>',
                '<img src="{{TRAIN_SRC}}" alt=""></body>',
                $canonical,
            ),
            'text application marker' => str_replace(
                '<!-- RT_APPLICATION_CONTENT_START -->',
                'RT_APPLICATION_CONTENT_START',
                $canonical,
            ),
            'attribute application marker' => str_replace(
                '<!-- RT_APPLICATION_CONTENT_START -->',
                '<span data-marker="<!-- RT_APPLICATION_CONTENT_START -->"></span>',
                $canonical,
            ),
        ];

        foreach ($attacks as $html) {
            $document->forceFill([
                'html' => $canonical,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $canonical,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $document->builder_data,
                    'html' => $html,
                    'css' => (string) $document->css,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $document->forceFill([
                'html' => $html,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $html,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }
    }

    public function test_signatur_html_sperrt_eigene_stylebloecke_und_standzugquellen(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonical = (string) $document->html;

        foreach ([
            $canonical.'<style>.rt-sign-cell{background:none!important}</style>',
            str_replace(
                'RT_SIGNATURE_MAIN_END',
                'RT_SIGNATURE_MAIN_END<img src="{{TRAIN_STILL_SRC}}" alt="">',
                $canonical,
            ),
            str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<!--[if mso]><v:rect class="rt-sign-train-mso" fill="true" stroke="false">'
                    .'<v:fill src="{{TRAIN_STILL_SRC}}" /></v:rect><![endif]-->'
                    .'<!-- RT_SIGNATURE_MAIN_END -->',
                $canonical,
            ),
        ] as $html) {
            $document->forceFill([
                'html' => $canonical,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $canonical,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $document->builder_data,
                    'html' => $html,
                    'css' => (string) $document->css,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }
    }

    public function test_runtime_erlaubt_train_still_nicht_als_bild_oder_zweiten_zug(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $canonical = (string) $document->html;
        $runtimeValues = MailSignature::forCompany(playbackNonce: 'runtime-contract')->values();
        $runtimeTokens = [];
        foreach ($runtimeValues as $key => $value) {
            $runtimeTokens['{{'.$key.'}}'] = $value;
        }
        $runtime = strtr($canonical, $runtimeTokens);
        $runtimeSourceCount = substr_count($runtime, (string) $runtimeValues['TRAIN_SRC']);
        $this->assertSame(1, $runtimeSourceCount);
        SignatureTrainCarrier::assertRuntimeImages($runtime);
        $spacedFallback = preg_replace(
            '/(<div class="rt-sign-stage"[^>]*>)/',
            '$1'."<!--  [if   mso]   >\n<img class=\"rt-sign-train-mso\" data-rt-train-mso=\"1\" src=\"https://rail-time.de/mail-assets/zug-dampf-light.png\" width=\"720\" alt=\"\" style=\"position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto;margin:0;border:0;outline:none;text-decoration:none;z-index:0;mso-position-horizontal:left;mso-position-horizontal-relative:text;mso-position-vertical:bottom;mso-position-vertical-relative:text;\">\n<!   [endif]   -->\n",
            $runtime,
            1,
            $spacedFallbackCount,
        );
        $this->assertIsString($spacedFallback);
        $this->assertSame(1, $spacedFallbackCount);
        try {
            SignatureTrainCarrier::withMsoFallback(
                $spacedFallback,
                'https://rail-time.de/mail-assets/zug-dampf-light.png',
            );
            $this->fail('Ein semantisch vorhandener MSO-Fallback darf nicht ein zweites Mal injiziert werden.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Outlook-Zugfallback', $exception->getMessage());
        }

        $storedFallback = str_replace(
            '<!-- RT_SIGNATURE_MAIN_END -->',
            '<!--[if mso]><v:rect class="rt-sign-train-mso" fill="true" stroke="false">'
                .'<v:fill src="{{TRAIN_STILL_SRC}}" /></v:rect><![endif]-->'
                .'<!-- RT_SIGNATURE_MAIN_END -->',
            $canonical,
            $storedFallbackCount,
        );
        $this->assertSame(1, $storedFallbackCount);
        $document->forceFill([
            'published_html' => $storedFallback,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();
        $this->app->forgetScopedInstances();
        try {
            MailSignature::forCompany(playbackNonce: 'invalid-stored-mso-fallback')->render();
            $this->fail('Ein gespeicherter serverseitiger MSO-Fallback muss im Runtime-Einstieg scheitern.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Outlook-Zugfallback darf nicht im Signaturentwurf gespeichert werden',
                $exception->getMessage(),
            );
        }

        $invalid = str_replace(
            'RT_SIGNATURE_MAIN_END',
            'RT_SIGNATURE_MAIN_END<img src="{{TRAIN_STILL_SRC}}" alt="">',
            $canonical,
        );
        $document->forceFill([
            'published_html' => $invalid,
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        MailSignature::forCompany(playbackNonce: 'invalid-still-source')->render();
    }

    public function test_signaturmarker_muessen_exakte_geordnete_kommentarpaare_bleiben(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $admin = $this->admin();
        $canonical = (string) $document->html;
        $attacks = [
            str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<!-- RT_SIGNATURE_MAIN_END_EXTRA -->',
                $canonical,
            ),
            str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<!-- RT_SIGNATURE_MAIN_END --><!-- RT_SIGNATURE_MAIN_END -->',
                $canonical,
            ),
            str_replace(
                '<!-- RT_PHONE_START -->',
                '<!-- RT_PHONE_START_EXTRA -->',
                $canonical,
            ),
            strtr($canonical, [
                '<!-- RT_PHONE_START -->' => '<!-- RT_PHONE_END -->',
                '<!-- RT_PHONE_END -->' => '<!-- RT_PHONE_START -->',
            ]),
            str_replace('<!-- RT_WEBSITE_START -->', '', $canonical),
            str_replace(
                '<!-- RT_COMPANY_PHONE_END -->',
                '<!-- RT_COMPANY_PHONE_END --><!-- RT_COMPANY_PHONE_END -->',
                $canonical,
            ),
            str_replace(
                '<!-- RT_SIGNATURE_MAIN_END -->',
                '<span><!-- RT_SIGNATURE_MAIN_END --></span>',
                $canonical,
            ),
            str_replace(
                '<!-- RT_PHONE_END -->',
                '<tr><td>unerlaubte Zusatzzeile</td></tr><!-- RT_PHONE_END -->',
                $canonical,
            ),
            str_replace(
                '<table class="rt-contact"',
                '<table class="rt-contact rt-company-contact"',
                $canonical,
            ),
            str_replace(
                'class="rt-contact rt-company-contact"',
                'class="rt-contact"',
                $canonical,
            ),
            str_replace('{{DURCHWAHL}}', '{{MOBIL}}', $canonical),
            str_replace('{{FIRMEN_WEBSITE_LABEL}}', '{{FIRMEN_TELEFON}}', $canonical),
        ];

        foreach ($attacks as $html) {
            $document->forceFill([
                'html' => $canonical,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $canonical,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->putJson(route('admin.mail-documents.update', $document), [
                    'builder_data' => $document->builder_data,
                    'html' => $html,
                    'css' => (string) $document->css,
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');

            $document->forceFill([
                'html' => $html,
                'content_hash' => MailDocument::contentHashFor(
                    $document->builder_data,
                    $html,
                    (string) $document->css,
                ),
            ])->save();
            $document->refresh();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $document), [
                    'expected_hash' => $document->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }
    }

    public function test_runtime_bettet_editierbares_css_vor_trusted_regeln_ein_und_liefert_ein_unten_verankertes_zugbild(): void
    {
        $this->createCanonicalMailDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $signature = $this->document(MailDocumentKind::Signature);

        $template->forceFill([
            'published_css' => '.rt-title{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $signature->forceFill([
            'published_css' => '.rt-sign-name{letter-spacing:0;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $html = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>CSS-Vertrag</p>'));
        $templateCss = strpos($html, 'data-rt-mail-document-css="template"');
        $signatureCss = strpos($html, 'data-rt-mail-document-css="signature"');
        $trusted = strpos($html, '/* RT_SERVER_SIGNATURE_RUNTIME_START');

        $this->assertIsInt($templateCss);
        $this->assertIsInt($signatureCss);
        $this->assertIsInt($trusted);
        $this->assertLessThan($trusted, $templateCss);
        $this->assertLessThan($trusted, $signatureCss);
        $this->assertSame(1, substr_count($html, 'class="rt-sign-stage"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train"'));
        $this->assertSame(1, substr_count($html, 'class="rt-sign-train-mso"'));
        $this->assertSame(1, substr_count($html, 'data-rt-train-mso="1"'));
        $this->assertSame(0, substr_count($html, 'data-rt-train-background'));
        $this->assertStringNotContainsString('<!--[if mso]><tr><td class="rt-sign-train-mso"', $html);
        $this->assertStringContainsString('class="rt-sign-train-mso"', $html);
        $this->assertDoesNotMatchRegularExpression('/<v:(?:rect|fill)\b/i', $html);
        $this->assertSame(
            1,
            preg_match('/<td\b[^>]*class="[^"]*\brt-sign-cell\b[^"]*"[^>]*>/', $html, $carrier),
        );
        $this->assertStringContainsString(
            'background-repeat:no-repeat;',
            $carrier[0],
        );
        $this->assertStringContainsString(
            'background-position:center center;',
            $carrier[0],
        );
        $this->assertStringNotContainsString('signatur-raster-', $html);
        $this->assertStringNotContainsString('signatur-marke-', $html);
        $this->assertStringNotContainsString('zug-dampf-light.gif', $carrier[0]);
        $this->assertStringNotContainsString(',75% bottom;', $carrier[0]);
        $this->assertStringContainsString('data-rt-train-idle-overlay', $html);
        $this->assertStringContainsString('data-rt-train-idle-image', $html);
        $this->assertStringContainsString('zug-dampf-idle-', $html);
        $this->assertStringContainsString('@keyframes rt-train-idle-reveal', $html);
    }

    public function test_legacy_published_css_mit_important_bricht_runtime_fail_closed_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => '*{opacity:1!important;}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_template_css_mit_keyframes_bricht_runtime_fail_closed_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => '@keyframes injected{from{opacity:0}to{opacity:1}}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_signature_css_mit_escape_geschuetztem_selector_bricht_runtime_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => '.rt-sign-\\63 ell::before{content:"zweiter Zug";}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_signature_css_mit_crlf_hexescape_bricht_runtime_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => ".rt-sign-\\63\r\nell::before{content:\"zweiter Zug\";}",
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectExceptionMessage(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_template_css_mit_crlf_important_bricht_runtime_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => "*{opacity:1!imp\\6f\r\nrtant;}",
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_template_css_mit_responsive_runtime_token_bricht_fail_closed_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $template = $this->document(MailDocumentKind::Template);
        $template->forceFill([
            'published_css' => '.x{content:"{{RESPONSIVE_CSS}}";}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_signature_css_mit_html_runtime_token_bricht_fail_closed_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => '.x{content:"{{SIGNATURE_BLOCK}} {{APPLICATION_CONTENT}}";}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_legacy_published_signature_css_mit_zugtoken_bricht_runtime_fail_closed_ab(): void
    {
        $this->createCanonicalMailDocuments();
        $signature = $this->document(MailDocumentKind::Signature);
        $signature->forceFill([
            'published_css' => '.rt-sign-logo{background-image:url({{TRAIN_SRC}});}',
            'published_at' => now(),
            'status' => MailDocumentStatus::Published,
        ])->save();
        $this->app->forgetScopedInstances();

        $this->expectException(\RuntimeException::class);
        EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Fail closed</p>'));
    }

    public function test_unveraendertes_speichern_zaehlt_die_version_nicht_hoch(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $document->html,
                'css' => (string) $document->css,
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk();

        $this->assertSame(1, $document->fresh()->version);
        $this->assertSame($document->content_hash, $document->fresh()->content_hash);
    }

    public function test_veroeffentlichen_schreibt_den_abzug_und_wirkt_sofort(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $document->forceFill([
            'html' => str_replace('{{SIGNATURE_BLOCK}}', '<tr><td>RT-FREIGABE</td></tr>{{SIGNATURE_BLOCK}}', (string) $document->html),
        ])->save();
        $document->forceFill([
            'content_hash' => MailDocument::contentHashFor($document->builder_data, $document->html, (string) $document->css),
        ])->save();

        $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('compatibility.catalog_version', '1.0.1')
            ->assertJsonPath('document.status', MailDocumentStatus::Published->value)
            ->assertJsonPath('document.has_unpublished_changes', false);

        $published = $document->fresh();

        $this->assertNotNull($published->published_at);
        $this->assertStringContainsString('RT-FREIGABE', (string) $published->published_html);
        $this->assertStringContainsString(
            'RT-FREIGABE',
            (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content'],
        );
    }

    public function test_reine_css_aenderung_bleibt_bis_zur_neuen_freigabe_offen_und_wirkt_im_download(): void
    {
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertOk()
            ->assertJsonPath('document.has_unpublished_changes', false);

        $document = $document->fresh();
        $css = '.rt-title{letter-spacing:0;}';
        $saved = $this->putJson(route('admin.mail-documents.update', $document), [
            'builder_data' => $document->builder_data,
            'html' => $document->html,
            'css' => $css,
            'expected_hash' => $document->content_hash,
        ])->assertOk()
            ->assertJsonPath('document.has_unpublished_changes', true);

        $this->assertTrue($document->fresh()->hasUnpublishedChanges());
        $this->assertStringNotContainsString($css, (new EmailTemplateBuilder($user))->build('vorlage-html')['content']);

        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $saved->json('document.content_hash'),
        ])->assertOk()
            ->assertJsonPath('document.has_unpublished_changes', false);

        $html = (new EmailTemplateBuilder($user->fresh()))->build('vorlage-html')['content'];
        $this->assertStringContainsString($css, $html);
        $this->assertStringContainsString('data-rt-mail-document-css="template"', $html);
        $this->assertLessThan(stripos($html, '</head>'), stripos($html, 'data-rt-mail-document-css="template"'));
    }

    public function test_veroeffentlichen_verlangt_den_aktuellen_fingerabdruck(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $staleHash = $document->content_hash;
        $changedHtml = str_replace('Sicher abgestimmt.', 'Neu abgestimmt.', (string) $document->html);

        $saved = $this->actingAs($this->admin())
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => $document->builder_data,
                'html' => $changedHtml,
                'css' => (string) $document->css,
                'expected_hash' => $staleHash,
            ])
            ->assertOk();

        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $staleHash,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('expected_hash');

        $this->assertNull($document->fresh()->published_at);

        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $saved->json('document.content_hash'),
        ])->assertOk();
    }

    public function test_veroeffentlichen_erhaelt_die_strukturvertraege_beider_dokumentarten(): void
    {
        $this->seedDocuments();
        $admin = $this->admin();
        $template = $this->document(MailDocumentKind::Template);

        foreach ([
            str_replace('{{SIGNATURE_BLOCK}}', '', (string) $template->html),
            str_replace('{{SIGNATURE_BLOCK}}', '{{SIGNATURE_BLOCK}}{{SIGNATURE_BLOCK}}', (string) $template->html),
        ] as $invalidTemplate) {
            $template->forceFill([
                'html' => $invalidTemplate,
                'content_hash' => MailDocument::contentHashFor($template->builder_data, $invalidTemplate, (string) $template->css),
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $template), [
                    'expected_hash' => $template->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }

        $signature = $this->document(MailDocumentKind::Signature);
        $validSignature = (string) $signature->html;
        $invalidSignature = '<div>Syntaxsauber, aber keine Signatur und ohne Pflichtangaben.</div>';
        $signature->forceFill([
            'html' => $invalidSignature,
            'content_hash' => MailDocument::contentHashFor($signature->builder_data, $invalidSignature, (string) $signature->css),
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('admin.mail-documents.publish', $signature), [
                'expected_hash' => $signature->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        foreach ([
            '{{LOGO_SRC}}',
            '{{VORNAME_NACHNAME}}',
            '{{POSITION}}',
            '{{E_MAIL}}',
            '{{FIRMENNAME}}',
            '{{FIRMENSTRASSE}}',
            '{{FIRMEN_PLZ_ORT}}',
            '{{FIRMEN_TELEFON}}',
            '{{FIRMEN_EMAIL}}',
            'RT_COMPANY_PHONE_START',
            'RT_COMPANY_PHONE_END',
            'RT_COMPANY_EMAIL_START',
            'RT_COMPANY_EMAIL_END',
        ] as $requiredSignatureNeedle) {
            $withoutRequiredCompanyData = str_replace($requiredSignatureNeedle, '', $validSignature);
            $signature->forceFill([
                'html' => $withoutRequiredCompanyData,
                'content_hash' => MailDocument::contentHashFor(
                    $signature->builder_data,
                    $withoutRequiredCompanyData,
                    (string) $signature->css,
                ),
            ])->save();

            $this->actingAs($admin)
                ->postJson(route('admin.mail-documents.publish', $signature), [
                    'expected_hash' => $signature->content_hash,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('html');
        }

        $this->assertNull($template->fresh()->published_at);
        $this->assertNull($signature->fresh()->published_at);
    }

    public function test_veroeffentlichen_lehnt_verbotene_syntax_ab(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);

        // Am Editor vorbei eingeschleust: die Freigabe ist die letzte Instanz.
        $document->forceFill(['html' => '<table><tr><td><script>alert(1)</script></td></tr></table>'])->save();

        $this->actingAs($this->admin())
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('html');

        $this->assertNull($document->fresh()->published_at);
        $this->assertNull(EmailTemplateBuilder::publishedDocument(MailDocumentKind::Template));
    }

    public function test_nicht_administratoren_erhalten_403_statt_einer_html_weiterleitung(): void
    {
        $this->seedDocuments();
        $document = $this->document(MailDocumentKind::Template);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->putJson(route('admin.mail-documents.update', $document), [
                'builder_data' => ['pages' => []],
                'html' => '<table><tr><td>Fremd</td></tr></table>',
                'css' => '',
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(403);

        $this->actingAs($user)
            ->postJson(route('admin.mail-documents.publish', $document), [
                'expected_hash' => $document->content_hash,
            ])
            ->assertStatus(403);
    }

    public function test_downloads_bleiben_fuer_alle_offen(): void
    {
        $this->seedDocuments();
        $user = User::factory()->create(['role' => 'user', 'name' => 'Mara Beispiel']);

        $this->actingAs($user)
            ->get(route('email-templates.download', ['template' => 'signatur-hell']))
            ->assertOk();
    }

    public function test_v22_hintergrund_bleibt_optional_responsiv_und_ohne_ueberlappung(): void
    {
        $source = $this->v22SignatureHtml();
        foreach (SignatureBackgroundContract::SIZES as $size) {
            $candidate = str_replace(
                ['data-rt-bg-desktop="110"', 'background-size:110% auto'],
                ['data-rt-bg-desktop="'.$size.'"', 'background-size:'.$size.'% auto'],
                $source,
            );
            SignatureDocumentContract::assertValid($candidate);
            $this->assertSame($candidate, SignatureTrainCarrier::normalize($candidate));
            $this->assertSame($candidate, app(EmailHtmlSanitizer::class)->assertClean($candidate)->html);
        }
        $disabled = str_replace(
            ['data-rt-signature-background="1"', "background-image:url('{{TRAIN_SRC}}')"],
            ['data-rt-signature-background="0"', 'background-image:none'],
            $source,
        );
        SignatureDocumentContract::assertValid($disabled);
        $this->assertSame($disabled, SignatureBackgroundContract::render($disabled, ''));
        $this->assertStringNotContainsString('{{TRAIN_SRC}}', $disabled);
        $rendered = MailSignature::forCompany()->renderDocument($disabled);
        $this->assertStringContainsString('background-image:none', $rendered);
        $this->assertStringNotContainsString('zug-dampf', $rendered);
        $this->assertStringNotContainsString('rt-sign-train-layer', $rendered);

        $this->assertSame(
            PortableMediaCatalog::requiredSystemAssetIds('signature', SignatureArtifactVersion::V20),
            PortableMediaCatalog::requiredSystemAssetContracts('signature')[SignatureArtifactVersion::V22],
        );
    }

    public function test_v22_hintergrund_weist_fremde_geometrie_und_bildbindungen_ab(): void
    {
        $source = $this->v22SignatureHtml();
        $contentStyle = static fn (string $declaration): string => (string) preg_replace(
            '/(<td\b[^>]*class="rt-pad rt-sign-content"[^>]*style=")/',
            '$1'.$declaration.';',
            $source,
            1,
        );
        foreach ([
            'unknown size' => str_replace('data-rt-bg-mobile="175"', 'data-rt-bg-mobile="300"', $source),
            'missing size' => str_replace('data-rt-bg-tablet="150"', '', $source),
            'conflicting size' => str_replace('background-size:110% auto', 'background-size:200% auto', $source),
            'unknown toggle' => str_replace('data-rt-signature-background="1"', 'data-rt-signature-background="yes"', $source),
            'disabled image' => str_replace('data-rt-signature-background="1"', 'data-rt-signature-background="0"', $source),
            'foreign source' => str_replace('{{TRAIN_SRC}}', 'https://outside.example/train.gif', $source),
            'fixed height' => str_replace('class="rt-sign-content-frame"', 'class="rt-sign-content-frame" height="200"', $source),
            'negative margin' => str_replace('background-repeat:no-repeat', 'margin-bottom:-200px;background-repeat:no-repeat', $source),
            'overlapping legal row' => str_replace('background:{{SIGNATURE_LEGAL_BG}};', 'background:{{SIGNATURE_LEGAL_BG}};margin-top:-200px;', $source),
            'duplicate background' => str_replace('background-repeat:no-repeat', 'background-repeat:repeat!important;background-repeat:no-repeat', $source),
            'positioned content' => str_replace('background-repeat:no-repeat', 'position:relative;background-repeat:no-repeat', $source),
            'second background image' => $contentStyle('background-image:url(https://app.rail-time.de/mail-assets/contact-email.png)'),
            'second disabled background' => $contentStyle('background-image:none'),
            'background shorthand image' => $contentStyle('background:url(https://app.rail-time.de/mail-assets/contact-email.png)'),
            'background shorthand gradient' => $contentStyle('background:linear-gradient(red,white)'),
            'background shorthand image set' => $contentStyle('background:image-set(url(https://app.rail-time.de/mail-assets/contact-email.png) 1x)'),
            'legal background image' => str_replace('background:{{SIGNATURE_LEGAL_BG}};', 'background:{{SIGNATURE_LEGAL_BG}};background-image:none;', $source),
            'grid content' => $contentStyle('display:grid'),
            'flex content' => $contentStyle('display:inline-flex'),
            'source static position' => $contentStyle('position:static'),
            'source zero minimum height' => $contentStyle('min-height:0'),
            'source zero maximum height' => $contentStyle('max-height:0px'),
        ] as $label => $candidate) {
            $this->assertNotSame($source, $candidate, $label.' must mutate the fixture');
            try {
                SignatureDocumentContract::assertValid($candidate);
                $this->fail('V22 akzeptierte '.$label);
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage(), $label);
            }
        }
        foreach (['javascript:alert(1)', 'https://outside.example/train.gif', "https://rail-time.test/train.gif'x"] as $sourceUrl) {
            try {
                SignatureBackgroundContract::render($source, $sourceUrl);
                $this->fail('V22 akzeptierte eine fremde oder unsichere Laufzeitquelle.');
            } catch (\RuntimeException|ValidationException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $runtime = MailSignature::forCompany(remoteAssets: true)->renderDocument($source);
        SignatureBackgroundContract::assertRuntime(str_replace(
            'background-repeat:no-repeat',
            'position:static;min-height:0;max-height:none;background-repeat:no-repeat',
            $runtime,
        ));
    }

    public function test_v22_speichern_publish_und_alle_renderwege_behalten_den_zellhintergrund(): void
    {
        $this->createCanonicalMailDocuments();
        $document = $this->document(MailDocumentKind::Signature);
        $publishedBefore = $document->published_html;
        $html = $this->v22SignatureHtml();
        $builderData = $document->builder_data;
        data_set($builderData, 'pages.0.component', $html);
        data_set($builderData, 'railtime.schema', SignatureDocumentContract::SCHEMA);
        $this->actingAs($this->admin())->putJson(route('admin.mail-documents.update', $document), [
            'builder_data' => $builderData,
            'html' => $html,
            'css' => '',
            'expected_hash' => $document->content_hash,
        ])->assertOk();
        $document->refresh();
        $this->assertSame($publishedBefore, $document->published_html);
        $this->assertStringContainsString('data-rt-bg-mobile="175"', $document->html);
        $preview = app(PageBuilderPreviewService::class)->mail($document, $this->admin(), 'light');
        $this->assertStringContainsString('data-rt-bg-mobile="175"', $preview['html']);
        $this->assertTrue(preg_match('/background-size:\s*175% auto\s*!important/', $preview['html']) === 1);
        $legacyCss = TrustedEmailCss::responsive('#dfe3e6', false);
        $backgroundCss = TrustedEmailCss::responsive('#dfe3e6', true);
        $this->assertStringNotContainsString('data-rt-artifact-version="v22"', $legacyCss);
        $this->assertStringContainsString('data-rt-artifact-version="v22"', $backgroundCss);
        TrustedEmailCss::assertResponsive($legacyCss, '#dfe3e6', false);
        TrustedEmailCss::assertResponsive($backgroundCss, '#dfe3e6', true);
        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $document->content_hash,
        ])->assertOk();
        $this->app->forgetScopedInstances();

        $builder = new EmailTemplateBuilder(User::factory()->create(['name' => 'Mara Beispiel']));
        foreach (['light', 'dark'] as $theme) {
            $company = MailSignature::forCompany($theme, remoteAssets: true)->renderDocument($html);
            SignatureBackgroundContract::assertRuntime($company);
            $this->assertStringContainsString('zug-dampf-v19-'.$theme.'.gif', $company);
            $this->assertStringNotContainsString('rt-sign-train-layer', $company);
            $this->assertStringNotContainsString('rt-sign-stage', $company);
            $this->assertStringNotContainsString('TRAIN_', $company);
            $this->assertStringNotContainsString('margin-bottom:-', $company);
            $this->assertStringNotContainsString('rt-sign-train-mso', $company);

            foreach ([
                $builder->buildSignatureCopyHtml($theme),
                $builder->buildOutlookAddinSignatureHtml($theme),
                $builder->build($theme === 'light' ? 'signatur-hell' : 'signatur-dunkel')['content'],
                $builder->build($theme === 'light' ? 'vorlage-html' : 'vorlage-dunkel-html')['content'],
            ] as $rendered) {
                $this->assertStringContainsString('data-rt-artifact-version="v22"', $rendered);
                $this->assertStringContainsString('data-rt-signature-background="1"', $rendered);
                $this->assertStringNotContainsString('class="rt-sign-train-layer"', $rendered);
                $this->assertStringContainsString('background-image:', $rendered);
            }
        }
        $mail = (new MailMessage)->greeting('V22')->line('Normaler Inhaltsfluss');
        $compiled = (string) app(Markdown::class)->render($mail->markdown ?: 'notifications::email', $mail->data());
        $this->assertStringContainsString('data-rt-artifact-version="v22"', $compiled);
        $this->assertStringContainsString('background-size: 110% auto', $compiled);
        $this->assertStringContainsString('data-rt-bg-tablet="150"', $compiled);
        $this->assertStringContainsString('data-rt-bg-mobile="175"', $compiled);
        $this->assertStringNotContainsString('class="rt-sign-train-layer"', $compiled);
    }

    public function test_v22_portabler_import_behaelt_medien_und_breakpoints_als_entwurf(): void
    {
        Storage::fake('public');
        $html = $this->v22SignatureHtml();
        $response = $this->actingAs($this->admin())->postJson(route('admin.mail-documents.import'), [
            'format' => 'railtime-mail-document',
            'version' => 2,
            'kind' => 'signature',
            'html' => $html,
            'css' => '',
            'media' => $this->portableSystemMedia(MailDocumentKind::Signature, SignatureArtifactVersion::V22),
        ]);
        $response->assertCreated()->assertJsonPath('document.status', MailDocumentStatus::Draft->value);
        $document = $this->document(MailDocumentKind::Signature);
        $this->assertSame($html, $document->html);
        $this->assertSame(SignatureDocumentContract::SCHEMA, data_get($document->builder_data, 'railtime.schema'));
        $this->assertNull($document->published_html);
        $this->assertNull($document->published_at);
    }

    public function test_v23_import_speichern_publish_und_versand_behalten_den_optionalen_hintergrund(): void
    {
        Storage::fake('public');
        $this->createCanonicalMailDocument(MailDocumentKind::Template);
        $html = str_replace('data-rt-artifact-version="v22"', 'data-rt-artifact-version="v23"', $this->v22SignatureHtml());
        SignatureDocumentContract::assertValid($html);
        $this->assertSame($html, SignatureTrainCarrier::normalize($html));
        $this->assertSame($html, app(EmailHtmlSanitizer::class)->assertClean($html)->html);
        $this->assertSame(SignatureArtifactVersion::V23, SignatureArtifactVersion::detect('signature', $html));
        $this->assertNull(SignatureArtifactVersion::detect('template', $html));
        $this->assertSame(
            PortableMediaCatalog::requiredSystemAssetContracts('signature')[SignatureArtifactVersion::V22],
            PortableMediaCatalog::requiredSystemAssetContracts('signature')[SignatureArtifactVersion::V23],
        );
        $media = $this->portableSystemMedia(MailDocumentKind::Signature, SignatureArtifactVersion::V23);
        $this->assertCount(17, $media);
        $this->actingAs($this->admin())->postJson(route('admin.mail-documents.import'), [
            'format' => 'railtime-mail-document',
            'version' => 2,
            'kind' => 'signature',
            'html' => $html,
            'css' => '',
            'media' => $media,
        ])->assertCreated()->assertJsonPath('document.status', MailDocumentStatus::Draft->value);
        $document = $this->document(MailDocumentKind::Signature);
        $this->assertSame($html, $document->html);
        $this->assertSame(29, data_get($document->builder_data, 'railtime.schema'));
        $this->assertNull($document->published_at);

        $html = str_replace(['data-rt-bg-desktop="110"', 'background-size:110% auto'], ['data-rt-bg-desktop="125"', 'background-size:125% auto'], $html);
        $builderData = $document->builder_data;
        data_set($builderData, 'pages.0.component', $html);
        $this->putJson(route('admin.mail-documents.update', $document), [
            'builder_data' => $builderData,
            'html' => $html,
            'css' => '',
            'expected_hash' => $document->content_hash,
        ])->assertOk();
        $document->refresh();
        $this->assertSame($html, $document->html);
        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $document->content_hash,
        ])->assertOk();
        $this->app->forgetScopedInstances();

        foreach (['light', 'dark'] as $theme) {
            $rendered = MailSignature::forCompany($theme, remoteAssets: true)->renderDocument($html);
            SignatureBackgroundContract::assertRuntime($rendered);
            $this->assertStringContainsString('zug-dampf-v19-'.$theme.'.gif', $rendered);
            $this->assertStringNotContainsString('rt-sign-train-layer', $rendered);
            $this->assertStringNotContainsString('rt-sign-stage', $rendered);
        }
        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $builder = new EmailTemplateBuilder($user);
        foreach ([$builder->buildSignatureCopyHtml('light'), $builder->buildOutlookAddinSignatureHtml('light')] as $rendered) {
            $this->assertStringContainsString('data-rt-artifact-version="v23"', $rendered);
            $this->assertStringContainsString('data-rt-signature-background="1"', $rendered);
            $this->assertStringNotContainsString('class="rt-sign-train-layer"', $rendered);
        }
        $outlook = app(OutlookAddinPayloadService::class)->forUser($user);
        $nativeTemplate = $outlook['templates'][0];
        $this->assertSame('native', $nativeTemplate['signatureMode']);
        $this->assertStringContainsString('RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE', $nativeTemplate['composeHtml']);
        $this->assertStringNotContainsString('RT-SIGNATURE-VERSION:', $nativeTemplate['composeHtml']);
        $this->assertStringNotContainsString('data-rt-signature-background', $nativeTemplate['composeHtml']);
        $this->assertStringNotContainsString('data-rt-artifact-version="v23"', $nativeTemplate['composeHtml']);
        $this->assertStringNotContainsString('.rt-sign-', $nativeTemplate['composeHtml']);
        $this->assertCount(2, $nativeTemplate['composeMedia']);
        $this->assertStringContainsString('data-rt-signature-background="1"', $outlook['signature']['html']);
        $this->assertStringContainsString('data-rt-artifact-version="v23"', $outlook['signature']['html']);
        $this->assertStringContainsString('RT-SIGNATURE-VERSION:'.$outlook['version']['signature'], $outlook['signature']['html']);
        foreach (['vorlage-eml', 'vorlage-dunkel-eml'] as $variant) {
            $eml = $builder->build($variant)['content'];
            $this->assertSame(1, substr_count($eml, 'Content-ID: <railtime-train>'));
            $this->assertSame(0, substr_count($eml, 'Content-ID: <railtime-train-still>'));
            $this->assertSame(0, substr_count($eml, 'Content-ID: <railtime-train-idle>'));
            preg_match('/Content-Type: text\/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--=_rt_rel_/s', $eml, $htmlPart);
            $this->assertArrayHasKey(1, $htmlPart);
            $emlHtml = base64_decode(preg_replace('/\s+/', '', $htmlPart[1]), true);
            $this->assertIsString($emlHtml);
            preg_match_all('/cid:(railtime-[a-z0-9-]+)/', $emlHtml, $references);
            preg_match_all('/Content-ID: <(railtime-[a-z0-9-]+)>/', $eml, $included);
            $this->assertEqualsCanonicalizing(array_unique($references[1]), $included[1]);
        }
        $mail = (new MailMessage)->greeting('V23')->line('Normaler Inhaltsfluss');
        $compiled = (string) app(Markdown::class)->render($mail->markdown ?: 'notifications::email', $mail->data());
        $this->assertStringContainsString('data-rt-artifact-version="v23"', $compiled);
        $this->assertStringContainsString('background-size: 125% auto', $compiled);
        $this->assertStringContainsString('data-rt-bg-tablet="150"', $compiled);
        $this->assertStringContainsString('data-rt-bg-mobile="175"', $compiled);
        $this->assertStringNotContainsString('class="rt-sign-train-layer"', $compiled);
        $email = (new Email)->html(SystemMailInlineImageEmbedder::mark($compiled));
        $this->assertGreaterThan(0, app(SystemMailInlineImageEmbedder::class)->embed($email));
        $trainAttachments = array_values(array_filter($email->getAttachments(), static fn ($part): bool => $part->getFilename() === 'zug-dampf-v19-light.gif'));
        $this->assertCount(1, $trainAttachments);
        $this->assertStringContainsString("background-image: url('cid:".$trainAttachments[0]->getContentId()."')", html_entity_decode((string) $email->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Kein zusaetzliches IMG in V23: auch eine reine CSS-CID-Referenz
        // muss im versandfertigen MIME zu related gehoeren, nicht zu mixed.
        $body = $email->getBody();
        $this->assertInstanceOf(RelatedPart::class, $body);
        $this->assertContains($trainAttachments[0], $body->getParts());
        $mailer = app(MailFactory::class)->mailer();
        $transport = $mailer->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $transport->flush();
        $mailer->html($compiled, static function ($message): void {
            $message->to('v23-mime@rail-time.test')->subject('CSS-Hintergrund');
        });
        $sent = $transport->messages()->sole();
        $sentBody = $sent->getOriginalMessage()->getBody();
        $this->assertInstanceOf(RelatedPart::class, $sentBody);
        $sentTrain = array_values(array_filter($sentBody->getParts(), static fn ($part): bool => $part instanceof DataPart && $part->getFilename() === 'zug-dampf-v19-light.gif'));
        $this->assertCount(1, $sentTrain);
        $this->assertSame(1, substr_count($sent->toString(), 'Content-ID: <'.$sentTrain[0]->getContentId().'>'));
        $this->assertStringNotContainsString('data-rt-artifact-version="v23"', TrustedEmailCss::responsive('#dfe3e6', false));
        $this->assertStringContainsString('tr[data-rt-artifact-version="v23"]', TrustedEmailCss::responsive('#dfe3e6', true));

        $disabled = str_replace(['data-rt-signature-background="1"', "background-image:url('{{TRAIN_SRC}}')"], ['data-rt-signature-background="0"', 'background-image:none'], $html);
        SignatureDocumentContract::assertValid($disabled);
        $this->assertSame($disabled, SignatureBackgroundContract::render($disabled, ''));
    }

    public function test_v23_contain_bleibt_beim_import_rendern_und_inlining_optional_und_responsiv(): void
    {
        Storage::fake('public');
        $this->createCanonicalMailDocument(MailDocumentKind::Template);
        $legacy = str_replace('data-rt-artifact-version="v22"', 'data-rt-artifact-version="v23"', $this->v22SignatureHtml());
        $html = str_replace(
            ['data-rt-bg-desktop="110"', 'background-position:65% bottom', 'background-size:110% auto'],
            ['data-rt-bg-desktop="110" data-rt-bg-desktop-fit="contain"', 'background-position:left bottom', 'background-size:contain'],
            $legacy,
        );
        SignatureDocumentContract::assertValid($html);
        $this->assertSame($html, SignatureTrainCarrier::normalize($html));
        $this->assertSame($html, app(EmailHtmlSanitizer::class)->assertClean($html)->html);
        $this->actingAs($this->admin())->postJson(route('admin.mail-documents.import'), [
            'format' => 'railtime-mail-document', 'version' => 2, 'kind' => 'signature',
            'html' => $html, 'css' => '',
            'media' => $this->portableSystemMedia(MailDocumentKind::Signature, SignatureArtifactVersion::V23),
        ])->assertCreated();
        $document = $this->document(MailDocumentKind::Signature);
        $this->assertSame($html, $document->html);
        $this->assertSame(29, data_get($document->builder_data, 'railtime.schema'));
        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $document->content_hash,
        ])->assertOk();
        $this->app->forgetScopedInstances();

        foreach (['light', 'dark'] as $theme) {
            $rendered = MailSignature::forCompany($theme, remoteAssets: true)->renderDocument($html);
            SignatureBackgroundContract::assertRuntime($rendered);
            $this->assertStringContainsString('background-size:contain', $rendered);
            $this->assertStringContainsString('background-position:left bottom', $rendered);
            $this->assertStringContainsString('zug-dampf-v19-'.$theme.'.gif', $rendered);
            $this->assertStringNotContainsString('rt-sign-train-layer', $rendered);
            $legacyRendered = MailSignature::forCompany($theme, remoteAssets: true)->renderDocument($legacy);
            $this->assertStringContainsString('background-size:110% auto', $legacyRendered);
            $this->assertStringContainsString('background-position:65% bottom', $legacyRendered);
            $this->assertStringNotContainsString('data-rt-bg-desktop-fit', $legacyRendered);
        }

        $mail = (new MailMessage)->greeting('Contain')->line('Normaler Inhaltsfluss');
        $compiled = (string) app(Markdown::class)->render($mail->markdown ?: 'notifications::email', $mail->data());
        $dom = new \DOMDocument;
        @$dom->loadHTML($compiled, LIBXML_NONET);
        $xpath = new \DOMXPath($dom);
        $carrier = $xpath->query('//td[@data-rt-bg-desktop-fit="contain"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $carrier);
        $this->assertSame('110', $carrier->getAttribute('data-rt-bg-desktop'));
        $this->assertMatchesRegularExpression('/(?:^|;)\s*background-size:\s*contain\s*;/', $carrier->getAttribute('style'));
        $this->assertMatchesRegularExpression('/(?:^|;)\s*background-position:\s*left bottom\s*;/', $carrier->getAttribute('style'));
        $this->assertStringContainsString('background-size: 150% auto !important;', $compiled);
        $this->assertStringContainsString('background-size: 175% auto !important;', $compiled);
        $this->assertStringContainsString('background-position: 65% bottom !important;', $compiled);
        SignatureBackgroundContract::assertRuntime($compiled);

        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $outlook = app(OutlookAddinPayloadService::class)->forUser($user);
        $this->assertStringContainsString('background-position:left bottom;background-size:contain;', $outlook['signature']['html']);
        $this->assertStringContainsString('data-rt-bg-desktop="110"', $outlook['signature']['html']);
        $this->assertStringContainsString('data-rt-bg-tablet="150"', $outlook['signature']['html']);
        $this->assertStringContainsString('data-rt-bg-mobile="175"', $outlook['signature']['html']);
        $this->assertStringNotContainsString('background-size:contain!important', $outlook['signature']['html']);
        $this->assertLessThanOrEqual(30000, mb_strlen($outlook['signature']['html']));

        $disabled = str_replace(['data-rt-signature-background="1"', "background-image:url('{{TRAIN_SRC}}')"], ['data-rt-signature-background="0"', 'background-image:none'], $html);
        SignatureDocumentContract::assertValid($disabled);
        $this->assertSame($disabled, SignatureBackgroundContract::render($disabled, ''));
        $this->assertStringNotContainsString('data-rt-outlook-signature-background-css', TrustedOutlookSignatureCss::style(MailSignature::forCompany(remoteAssets: true)->renderDocument($disabled)));
    }

    public function test_v23_contain_lehnt_ungebundene_abweichende_und_mobile_blockierende_geometrie_ab(): void
    {
        $legacy = str_replace('data-rt-artifact-version="v22"', 'data-rt-artifact-version="v23"', $this->v22SignatureHtml());
        $contain = str_replace(
            ['data-rt-bg-desktop="110"', 'background-position:65% bottom', 'background-size:110% auto'],
            ['data-rt-bg-desktop="110" data-rt-bg-desktop-fit="contain"', 'background-position:left bottom', 'background-size:contain'],
            $legacy,
        );
        foreach ([
            'empty' => str_replace('data-rt-bg-desktop-fit="contain"', 'data-rt-bg-desktop-fit=""', $contain),
            'cover' => str_replace('data-rt-bg-desktop-fit="contain"', 'data-rt-bg-desktop-fit="cover"', $contain),
            'case' => str_replace('data-rt-bg-desktop-fit="contain"', 'data-rt-bg-desktop-fit="Contain"', $contain),
            'space' => str_replace('data-rt-bg-desktop-fit="contain"', 'data-rt-bg-desktop-fit=" contain "', $contain),
            'missing flag' => str_replace(' data-rt-bg-desktop-fit="contain"', '', $contain),
            'wrong size' => str_replace('background-size:contain', 'background-size:110% auto', $contain),
            'wrong position' => str_replace('background-position:left bottom', 'background-position:65% bottom', $contain),
            'missing percent' => str_replace('data-rt-bg-desktop="110"', '', $contain),
            'invalid retained percent' => str_replace('data-rt-bg-desktop="110"', 'data-rt-bg-desktop="999"', $contain),
            'important size' => str_replace('background-size:contain', 'background-size:contain!important', $contain),
            'important position' => str_replace('background-position:left bottom', 'background-position:left bottom !important', $contain),
            'foreign carrier' => str_replace('class="rt-sign-content-frame"', 'class="rt-sign-content-frame" data-rt-bg-desktop-fit="contain"', $legacy),
        ] as $label => $invalid) {
            try {
                SignatureDocumentContract::assertValid($invalid);
                $this->fail('Contain accepted invalid geometry: '.$label);
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage(), $label);
            }
        }
    }

    public function test_v23_behaelt_die_sichere_tabellenstruktur_und_weist_ueberlappungen_ab(): void
    {
        $source = str_replace('data-rt-artifact-version="v22"', 'data-rt-artifact-version="v23"', $this->v22SignatureHtml());
        foreach ([
            str_replace('class="rt-sign-layout"', 'class="missing-layout"', $source),
            str_replace('class="rt-sign-company"', 'class="missing-company"', $source),
            preg_replace('/(<td\b[^>]*class="rt-pad rt-sign-content"[^>]*style=")/', '$1margin-bottom:-150px;', $source),
            str_replace('background-position:65% bottom', 'background-position:right bottom', $source),
        ] as $invalid) {
            $this->assertNotSame($source, $invalid);
            try {
                SignatureDocumentContract::assertValid($invalid);
                $this->fail('V23 darf die Tabellen- und Hintergrundregeln nicht umgehen.');
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_v25_import_publish_und_cid_versand_behalten_fluides_img_und_mobilen_kopf(): void
    {
        Storage::fake('public');
        $this->createCanonicalMailDocument(MailDocumentKind::Template);
        $html = $this->v25SignatureHtml();
        SignatureDocumentContract::assertValid($html);
        $this->assertSame($html, SignatureTrainCarrier::normalize($html));
        $this->assertSame($html, app(EmailHtmlSanitizer::class)->assertClean($html)->html);
        $this->assertSame(SignatureArtifactVersion::V25, SignatureArtifactVersion::detect('signature', $html));
        $this->assertFalse(SignatureArtifactVersion::usesOptionalBackground(SignatureArtifactVersion::V25));
        $this->assertSame(
            PortableMediaCatalog::requiredSystemAssetContracts('signature')[SignatureArtifactVersion::V19],
            PortableMediaCatalog::requiredSystemAssetContracts('signature')[SignatureArtifactVersion::V25],
        );
        $media = $this->portableSystemMedia(MailDocumentKind::Signature, SignatureArtifactVersion::V25);
        $this->assertCount(17, $media);
        $this->actingAs($this->admin())->postJson(route('admin.mail-documents.import'), [
            'format' => 'railtime-mail-document', 'version' => 2, 'kind' => 'signature',
            'html' => $html, 'css' => '', 'media' => $media,
        ])->assertCreated()->assertJsonPath('document.status', MailDocumentStatus::Draft->value);
        $document = $this->document(MailDocumentKind::Signature);
        $this->assertSame($html, $document->html);
        $this->assertSame(29, data_get($document->builder_data, 'railtime.schema'));
        $this->assertNull($document->published_at);
        $this->postJson(route('admin.mail-documents.publish', $document), [
            'expected_hash' => $document->content_hash,
        ])->assertOk();
        $this->app->forgetScopedInstances();

        foreach (['light', 'dark'] as $theme) {
            $rendered = MailSignature::forCompany($theme, remoteAssets: true)->renderDocument($html);
            SignatureTrainCarrier::assertRuntimeImages($rendered);
            $this->assertStringContainsString('zug-dampf-v19-'.$theme.'.gif', $rendered);
            $this->assertStringNotContainsString('background-image', $rendered);
            $this->assertStringContainsString('rt-sign-heading-logo', $rendered);
        }
        $withoutBackground = TrustedEmailCss::forDocument($html, '#dfe3e6', false);
        $this->assertStringContainsString('.rt-sign-heading-logo', $withoutBackground);
        foreach ([
            TrustedOutlookSignatureCss::responsive($html),
            TrustedOutlookSignatureCss::composeStylesheet($html, [$withoutBackground], 'rtt012345abcdef', '#dfe3e6'),
        ] as $outlookCss) {
            $this->assertStringContainsString('.rt-sign-heading-logo', $outlookCss);
            $this->assertStringContainsString('display:table-header-group!important', $outlookCss);
            $this->assertStringContainsString('display:table-row-group!important', $outlookCss);
            $this->assertDoesNotMatchRegularExpression('/margin-bottom:\s*-\d/i', $outlookCss);
            $this->assertStringNotContainsString('data-rt-artifact-version="v21"', $outlookCss);
        }

        $user = User::factory()->create(['name' => 'Mara Beispiel']);
        $payload = app(OutlookAddinPayloadService::class)->forUser($user);
        $this->assertStringContainsString('data-rt-artifact-version="v25"', $payload['signature']['html']);
        $this->assertStringNotContainsString('background-image', $payload['signature']['html']);
        $this->assertStringContainsString('class="rt-sign-train"', $payload['signature']['html']);
        $this->assertStringContainsString('class="rt-sign-train-mso"', $payload['signature']['html']);
        $this->assertLessThanOrEqual(30000, mb_strlen($payload['signature']['html']));

        $mail = (new MailMessage)->greeting('V25')->line('Normaler Bildfluss');
        $compiled = (string) app(Markdown::class)->render($mail->markdown ?: 'notifications::email', $mail->data());
        $email = (new Email)->html(SystemMailInlineImageEmbedder::mark($compiled));
        $this->assertGreaterThan(0, app(SystemMailInlineImageEmbedder::class)->embed($email));
        $trains = array_values(array_filter($email->getAttachments(), static fn ($part): bool => $part->getFilename() === 'zug-dampf-v19-light.gif'));
        $this->assertCount(1, $trains);
        $this->assertMatchesRegularExpression('/<img\b[^>]*class="rt-sign-train"[^>]*src="cid:'.preg_quote($trains[0]->getContentId(), '/').'"/i', (string) $email->getHtmlBody());
        $this->assertInstanceOf(RelatedPart::class, $email->getBody());
    }

    public function test_v25_hoehen_und_margin_resets_ueberstehen_zwei_inliner_durchlaeufe(): void
    {
        $html = $this->v25SignatureHtml();
        $inliner = new CssToInlineStyles;
        $inlined = $inliner->convert('<html><head><style>'.EmailTemplateBuilder::responsiveCss().'</style></head><body><table>'.$html.'</table></body></html>');
        foreach ([$inlined, $inliner->convert($inlined)] as $mailHtml) {
            $dom = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $this->assertTrue($dom->loadHTML($mailHtml));
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $xpath = new \DOMXPath($dom);
            foreach (['rt-sign-stage', 'rt-sign-content-frame', 'rt-sign-train-layer', 'rt-sign-train-frame', 'rt-sign-train-slot'] as $class) {
                $element = $xpath->query('//*[@class="'.$class.'"]')->item(0);
                $this->assertInstanceOf(\DOMElement::class, $element);
                $this->assertFalse($element->hasAttribute('height'));
                $this->assertMatchesRegularExpression('/(?:^|;)\s*height\s*:\s*auto\s*!important/i', $element->getAttribute('style'));
                $this->assertDoesNotMatchRegularExpression('/margin-(?:top|bottom)\s*:\s*-\d/i', $element->getAttribute('style'));
            }
            $layer = $xpath->query('//*[@class="rt-sign-train-layer"]')->item(0);
            $this->assertMatchesRegularExpression('/margin-bottom\s*:\s*0(?:px)?\s*!important/i', $layer->getAttribute('style'));
            $image = $xpath->query('//img[@class="rt-sign-train"]')->item(0);
            $this->assertSame('720', $image->getAttribute('width'));
            $this->assertSame('61', $image->getAttribute('height'));
            foreach (['width' => '100%', 'max-width' => 'none', 'height' => 'auto'] as $property => $value) {
                $this->assertMatchesRegularExpression('/(?:^|;)\s*'.$property.'\s*:\s*'.preg_quote($value, '/').'\s*(?:!important\s*)?(?:;|$)/i', $image->getAttribute('style'));
            }
        }
    }

    public function test_v25_rejects_background_overlap_fixed_slot_and_legacy_marker_substitution(): void
    {
        $html = $this->v25SignatureHtml();
        foreach ([
            str_replace('class="rt-sign-cell"', 'class="rt-sign-cell" background="{{TRAIN_SRC}}"', $html),
            str_replace('margin:0 auto 0 0;', 'margin:0 auto -150px 0;', $html),
            str_replace('class="rt-sign-train-frame"', 'class="rt-sign-train-frame" height="61"', $html),
            str_replace('class="rt-sign-train-slot"', 'class="rt-sign-train-slot" height="61"', $html),
            str_replace('class="rt-sign-stage"', 'class="rt-sign-stage foreign"', $html),
            str_replace('data-rt-artifact-version="v25"', 'data-rt-artifact-version="v21"', $html),
        ] as $invalid) {
            $this->assertNotSame($html, $invalid);
            try {
                SignatureDocumentContract::assertValid($invalid);
                $this->fail('The V25 opt-in must not relax legacy or IMG-flow safety.');
            } catch (\RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    /** Existing contact tokens and markers, with the optional aligned header. */
    private function v25SignatureHtml(): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $this->assertTrue($dom->loadHTML('<?xml encoding="UTF-8"><table id="v25-fixture"><tbody>'.$this->v22SignatureHtml().'</tbody></table>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $carrier = $xpath->query('//td[@class="rt-sign-cell"]')->item(0);
        $carrier->parentNode->setAttribute('data-rt-artifact-version', 'v25');
        foreach (['data-rt-signature-background', 'data-rt-bg-desktop', 'data-rt-bg-tablet', 'data-rt-bg-mobile'] as $attribute) {
            $carrier->removeAttribute($attribute);
        }
        $carrier->setAttribute('style', 'width:100%;padding:0;background-color:{{SIGNATURE_BG}};border-top:5px solid #e4002b;');
        $frame = $xpath->query('//table[@class="rt-sign-content-frame"]')->item(0);
        $frame->setAttribute('style', 'width:100%;border-collapse:collapse;');
        $logo = $xpath->query('//td[@class="rt-sign-logo"]')->item(0);
        $person = $xpath->query('//div[@class="rt-person-kopf"]')->item(0);
        $heading = $dom->createElement('table');
        foreach (['class' => 'rt-sign-heading-table', 'role' => 'presentation', 'width' => '100%', 'border' => '0', 'cellspacing' => '0', 'cellpadding' => '0', 'style' => 'width:100%;border-collapse:collapse;'] as $key => $value) {
            $heading->setAttribute($key, $value);
        }
        $row = $heading->appendChild($dom->createElement('tbody'))->appendChild($dom->createElement('tr'));
        $personalCell = $row->appendChild($dom->createElement('td'));
        $logoCell = $row->appendChild($dom->createElement('td'));
        foreach ([[$personalCell, 'rt-sign-heading-person', 'left'], [$logoCell, 'rt-sign-heading-logo', 'right']] as [$cell, $class, $align]) {
            $cell->setAttribute('class', $class);
            $cell->setAttribute('width', '50%');
            $cell->setAttribute('valign', 'top');
            $cell->setAttribute('style', 'width:50%;padding:0;text-align:'.$align.';vertical-align:top;');
        }
        $personalCell->appendChild($person);
        while ($logo->firstChild) {
            $logoCell->appendChild($logo->firstChild);
        }
        $logo->appendChild($heading);
        $frameHtml = $dom->saveHTML($frame);
        $result = '';
        foreach ($dom->getElementById('v25-fixture')->firstElementChild->childNodes as $node) {
            $result .= $dom->saveHTML($node);
        }
        $train = '<div class="rt-sign-train-layer" data-rt-layer-train="" data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="left" style="display:block;width:100%;max-width:none;margin:0 auto 0 0;overflow:hidden;font-size:0;line-height:0;text-align:left;"><table class="rt-sign-train-frame" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tr><td class="rt-sign-train-slot" valign="bottom" style="padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;"><img class="rt-sign-train" data-rt-train="" src="{{TRAIN_SRC}}" width="720" height="61" alt="" style="display:block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;"></td></tr></table></div>';
        $result = str_replace($frameHtml, '<div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;">'.$frameHtml.$train.'</div>', $result);

        return trim(str_ireplace(['%7B', '%7D'], ['{', '}'], $result));
    }

    /** Reale bestehende Kontakte behalten, lediglich die neue Traegerstruktur aufbauen. */
    private function v22SignatureHtml(): string
    {
        $source = $this->canonicalMailDocumentHtml(MailDocumentKind::Signature);
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $this->assertTrue($dom->loadHTML('<?xml encoding="UTF-8"><table id="v22-fixture"><tbody>'.$source.'</tbody></table>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($dom);
        $carrier = $xpath->query('//td[@class="rt-sign-cell"]')->item(0);
        $frame = $xpath->query('//table[@class="rt-sign-content-frame"]')->item(0);
        $stage = $xpath->query('//div[@class="rt-sign-stage"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $carrier);
        $this->assertInstanceOf(\DOMElement::class, $frame);
        $this->assertInstanceOf(\DOMElement::class, $stage);
        $carrier->replaceChild($frame, $stage);
        $carrier->parentNode->setAttribute('data-rt-artifact-version', 'v22');
        foreach (['signature-background' => '1', 'bg-desktop' => '110', 'bg-tablet' => '150', 'bg-mobile' => '175'] as $key => $value) {
            $carrier->setAttribute('data-rt-'.$key, $value);
        }
        $carrier->setAttribute('style', "width:100%;padding:0;background-color:{{SIGNATURE_BG}};background-image:url('{{TRAIN_SRC}}');background-repeat:no-repeat;background-position:65% bottom;background-size:110% auto;border-top:5px solid #e4002b;");
        foreach ([$carrier, ...iterator_to_array($carrier->getElementsByTagName('*'))] as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }
            $style = $element->getAttribute('style');
            $style = preg_replace('/(?:^|;)\s*(?:position|z-index)\s*:[^;]*/i', '', $style) ?? $style;
            if (strtolower($element->tagName) !== 'img') {
                $element->removeAttribute('height');
                $style = preg_replace('/(?:^|;)\s*(?:height|min-height|max-height)\s*:[^;]*/i', '', $style) ?? $style;
            }
            if ($element->hasAttribute('style')) {
                $element->setAttribute('style', ltrim($style, ';'));
            }
        }
        $tbody = $dom->getElementById('v22-fixture')->firstElementChild;
        $result = '';
        foreach ($tbody->childNodes as $node) {
            $result .= $dom->saveHTML($node);
        }

        return trim(str_ireplace(['%7B', '%7D'], ['{', '}'], $result));
    }
}
