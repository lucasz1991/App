<?php

namespace Tests\Feature;

use App\Enums\MailDocumentStatus;
use App\Livewire\Admin\MailDocumentLibrary;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\Mail\MailDocumentArchive;
use App\Support\Mail\MailDocumentDeletion;
use App\Support\Mail\MailDocumentSignatureResolver;
use App\Support\Mail\MailDocumentVersionStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class MailDocumentArchiveTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->buildMinimalRailTimeSchema();
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        foreach ([
            '2026_08_09_000100_create_mail_documents_table',
            '2026_08_22_000200_create_mail_document_versions_table',
            '2026_08_27_000100_add_design_slots_to_mail_documents',
            '2026_09_06_010000_add_outlook_library_to_mail_documents',
            '2026_09_07_190000_separate_mail_document_delivery_channels',
            '2026_09_08_120000_add_mail_document_signature_pairing',
            '2026_10_30_220000_add_archive_to_mail_documents',
        ] as $migration) {
            (include database_path('migrations/'.$migration.'.php'))->up();
        }
    }

    private function document(string $name, string $kind = 'template'): MailDocument
    {
        $html = '<table><tr><td>'.$name.'</td></tr></table>';

        return MailDocument::create([
            'kind' => $kind, 'name' => $name, 'status' => MailDocumentStatus::Published,
            'is_active' => null, 'is_outlook_template' => $kind === 'template',
            'outlook_released' => $kind === 'template', 'outlook_default' => null,
            'html' => $html.'<!--pending draft-->', 'css' => '', 'builder_data' => [],
            'published_html' => $html, 'published_css' => '', 'published_at' => now(),
            'content_hash' => MailDocument::contentHashFor([], $html.'<!--pending draft-->', ''), 'version' => 7,
        ]);
    }

    public function test_archive_and_restore_preserve_exact_content_versions_and_no_delivery_flags(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->document('Standard');
        $old = $this->document('Alt');
        $version = app(MailDocumentVersionStore::class)->capture($old, $admin, 'saved');
        $versionBefore = $version->fresh()->getRawOriginal();
        $fields = ['html', 'css', 'builder_data', 'published_html', 'published_css', 'published_at', 'content_hash', 'version'];
        $before = $old->only($fields);
        $archived = app(MailDocumentArchive::class)->archive($admin, $old, $old->content_hash);
        $this->assertNull(MailDocument::find($old->id));
        $this->assertSame(2, MailDocument::withoutGlobalScope('unarchived')->count());
        $this->assertTrue($archived->isArchived());
        $this->assertFalse($archived->isPublished());
        $this->assertEquals($before, $archived->only($fields));
        $this->assertSame($versionBefore, $version->fresh()->getRawOriginal());
        $restored = app(MailDocumentArchive::class)->restore($admin, $archived, $old->content_hash, 'Alt wiederhergestellt');
        $this->assertNotNull(MailDocument::find($old->id));
        $this->assertFalse($restored->isArchived());
        $this->assertNull($restored->is_active);
        $this->assertNull($restored->outlook_default);
        $this->assertFalse($restored->outlook_released);
        $this->assertEquals($before, $restored->only($fields));
    }

    public function test_active_default_last_and_live_paired_signature_cannot_be_archived(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $signature = $this->document('Signatur', 'signature');
        $other = $this->document('Andere Signatur', 'signature');
        $template = $this->document('Standard');
        foreach (['is_active', 'outlook_default', 'pairing', 'last'] as $guard) {
            $signature->forceFill(['is_active' => $guard === 'is_active' ? true : null, 'outlook_default' => $guard === 'outlook_default' ? true : null])->save();
            $template->forceFill(['published_signature_document_id' => $guard === 'pairing' ? $signature->id : null])->save();
            if ($guard === 'last') {
                app(MailDocumentArchive::class)->archive($admin, $other, $other->content_hash);
            }
            try {
                app(MailDocumentArchive::class)->archive($admin, $signature, $signature->content_hash);
                $this->fail('Guard '.$guard.' accepted archive');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $this->assertNotNull(MailDocument::find($signature->id));
        }
    }

    public function test_stale_hash_rejected_and_library_has_reversible_archive_ui(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->document('Standard');
        $old = $this->document('Alt');
        try {
            app(MailDocumentArchive::class)->archive($admin, $old, str_repeat('0', 64));
            $this->fail('Stale content accepted');
        } catch (ValidationException) {
            $this->assertNotNull(MailDocument::find($old->id));
        }
        $ui = Livewire::actingAs($admin)->test(MailDocumentLibrary::class)
            ->call('prepareAction', 'archive', $old->public_id, $old->content_hash)
            ->call('confirmAction')->assertHasNoErrors()->assertDontSeeHtml('data-mail-library-document="'.$old->public_id.'"');
        $ui->call('selectFilter', 'archive')->assertSee('Alt')->assertSee('Aus Archiv wiederherstellen')
            ->call('prepareAction', 'unarchive', $old->public_id, $old->content_hash)
            ->set('restoreName', 'Alt zurück')->call('confirmAction')->assertHasNoErrors()
            ->call('selectFilter', 'all')->assertSee('Alt zurück');
    }

    public function test_archived_pairings_guard_permanent_deletion_and_template_restore(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->document('Standard');
        $this->document('Andere Signatur', 'signature');
        $signature = $this->document('Alte Signatur', 'signature');
        $template = $this->document('Alte Vorlage');
        $template->forceFill(['signature_document_id' => $signature->id, 'published_signature_document_id' => $signature->id])->save();
        $archive = app(MailDocumentArchive::class);
        $template = $archive->archive($admin, $template, $template->content_hash);
        try {
            app(MailDocumentDeletion::class)->delete($signature, $signature->content_hash);
            $this->fail('Archived pairing allowed permanent deletion');
        } catch (ValidationException) {
            $this->assertNotNull(MailDocument::find($signature->id));
        }
        $signature = $archive->archive($admin, $signature, $signature->content_hash);
        try {
            $archive->restore($admin, $template, $template->content_hash, 'Vorlage zurück');
            $this->fail('Template with hidden explicit partner restored');
        } catch (ValidationException) {
            $this->assertTrue($template->isArchived());
        }
        $signature = $archive->restore($admin, $signature, $signature->content_hash, 'Signatur zurück');
        $template = $archive->restore($admin, $template, $template->content_hash, 'Vorlage zurück');
        $this->assertSame($signature->id, app(MailDocumentSignatureResolver::class)->draftDocument($template)?->id);
    }
}
