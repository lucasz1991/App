<?php

namespace Tests\Feature;

use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Support\Mail\MailDocumentSignaturePairing;
use App\Support\Mail\MailDocumentSignatureResolver;
use App\Support\Mail\MailDocumentVersionStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class MailDocumentSignaturePairingTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        // This suite may rebuild schema only in its isolated in-memory DB.
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
        ] as $migration) {
            (include database_path('migrations/'.$migration.'.php'))->up();
        }
    }

    private function pairingMigration(): object
    {
        return include database_path('migrations/2026_09_08_120000_add_mail_document_signature_pairing.php');
    }

    private function document(string $name, string $kind = 'template', bool $published = false, bool $active = false): MailDocument
    {
        $html = '<table><tr><td>'.$name.'</td></tr></table>';

        return MailDocument::create([
            'kind' => $kind,
            'name' => $name,
            'status' => $published ? MailDocumentStatus::Published : MailDocumentStatus::Draft,
            'is_active' => $active ? true : null,
            'is_outlook_template' => false,
            'outlook_released' => false,
            'outlook_default' => null,
            'html' => $html,
            'css' => '',
            'builder_data' => [],
            'published_html' => $published ? $html : null,
            'published_css' => $published ? '' : null,
            'published_at' => $published ? now() : null,
            'content_hash' => MailDocument::contentHashFor([], $html, ''),
            'version' => 1,
        ]);
    }

    public function test_migration_adds_only_null_pairings_without_changing_defaults_hashes_or_releases(): void
    {
        $signature = $this->document('Standardsignatur', 'signature', published: true, active: true);
        $signature->update(['outlook_default' => true]);
        $template = $this->document('Standardvorlage', published: true, active: true);
        $template->update(['outlook_default' => true, 'outlook_released' => true]);
        $this->document('Unveröffentlichter Entwurf');
        $columns = Schema::getColumnListing('mail_documents');
        $before = DB::table('mail_documents')->orderBy('id')->get($columns)->toArray();
        $version = app(MailDocumentVersionStore::class)->capture($template, null, 'saved');
        $versionBefore = $version->fresh()->getRawOriginal();

        $this->assertFalse(MailDocumentSignatureResolver::available());
        $this->pairingMigration()->up();

        $this->assertTrue(MailDocumentSignatureResolver::available());
        $this->assertEquals($before, DB::table('mail_documents')->orderBy('id')->get($columns)->toArray());
        $this->assertSame(0, DB::table('mail_documents')->whereNotNull('signature_document_id')->count());
        $this->assertSame(0, DB::table('mail_documents')->whereNotNull('published_signature_document_id')->count());
        $this->assertNull($version->fresh()->signature_document_id);
        $this->assertSame($versionBefore, array_intersect_key($version->fresh()->getRawOriginal(), $versionBefore));
    }

    public function test_null_pairing_retains_the_exact_legacy_hash_bytes(): void
    {
        $builder = ['railtime' => ['schema' => 29, 'document' => 'template'], 'pages' => [['name' => 'Muster', 'component' => '<table>Ä</table>']]];
        $legacy = hash('sha256', '{"builder_data":{"pages":[{"component":"<table>Ä</table>","name":"Muster"}],"railtime":{"document":"template","schema":29}},"html":"<html>ä</html>","css":""}');

        $this->assertSame($legacy, MailDocument::contentHashFor($builder, '<html>ä</html>', ''));
        $this->assertSame($legacy, MailDocument::contentHashFor($builder, '<html>ä</html>', '', null));
        $this->assertNotSame($legacy, MailDocument::contentHashFor($builder, '<html>ä</html>', '', 1));
        $this->assertNotSame(
            MailDocument::contentHashFor($builder, '<html>ä</html>', '', 1),
            MailDocument::contentHashFor($builder, '<html>ä</html>', '', 2),
        );
    }

    public function test_resolver_keeps_unpaired_channel_defaults_and_legacy_preview_before_migration(): void
    {
        $system = $this->document('System', 'signature', published: true, active: true);
        $outlook = $this->document('Outlook', 'signature', published: true);
        $outlook->update(['outlook_default' => true]);
        $system->update(['html' => '<table>System-Entwurf</table>']);
        $template = $this->document('Vorlage');
        $resolver = app(MailDocumentSignatureResolver::class);

        $this->assertSame($system->id, $resolver->draftDocument($template)->id);
        $this->assertSame($system->published_html, $resolver->draftSnapshot($template)['html']);
        $this->assertSame($system->published_html, $resolver->publishedSnapshot($template)['html']);
        $this->assertSame($outlook->published_html, $resolver->publishedSnapshot($template, 'outlook')['html']);
        $this->assertFalse($resolver->draftSnapshot($template)['paired']);
        $this->assertFalse($resolver->publishedSnapshot($template, 'outlook')['paired']);
        $this->assertSame($system->html, $resolver->draftSnapshot($system)['html']);
    }

    public function test_explicit_draft_pair_uses_its_own_draft_html_and_css_without_mutation(): void
    {
        $this->pairingMigration()->up();
        $system = $this->document('System', 'signature', published: true, active: true);
        $system->update(['outlook_default' => true]);
        $paired = $this->document('Signal', 'signature', published: true);
        $paired->update(['html' => '<table>Neuer Signal-Entwurf</table>', 'css' => '.signal{color:red;}']);
        $template = $this->document('Signal Vorlage');
        $template->update(['signature_document_id' => $paired->id]);
        $before = DB::table('mail_documents')->orderBy('id')->get()->toArray();

        $snapshot = app(MailDocumentSignatureResolver::class)->draftSnapshot($template);

        $this->assertSame($paired->public_id, $snapshot['id']);
        $this->assertSame($paired->id, $snapshot['document_id']);
        $this->assertSame($paired->html, $snapshot['html']);
        $this->assertSame($paired->css, $snapshot['css']);
        $this->assertTrue($snapshot['paired']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['hash']);
        $this->assertTrue($template->signatureDocument->is($paired));
        $this->assertEquals($before, DB::table('mail_documents')->orderBy('id')->get()->toArray());
    }

    public function test_published_resolution_ignores_draft_pairing_and_partner_draft_changes(): void
    {
        $this->pairingMigration()->up();
        $released = $this->document('Freigegebene Signatur', 'signature', published: true);
        $released->update(['status' => MailDocumentStatus::Draft, 'html' => '<table>Nicht freigegebene Änderung</table>', 'css' => '.draft{color:red;}']);
        $draft = $this->document('Andere Entwurfssignatur', 'signature');
        $template = $this->document('Vorlage', published: true);
        $template->update(['signature_document_id' => $draft->id, 'published_signature_document_id' => $released->id]);
        $resolver = app(MailDocumentSignatureResolver::class);

        foreach (['system', 'outlook'] as $channel) {
            $snapshot = $resolver->publishedSnapshot($template, $channel);
            $this->assertSame($released->published_html, $snapshot['html']);
            $this->assertSame('', $snapshot['css']);
            $this->assertTrue($snapshot['paired']);
        }
        $this->assertSame($draft->html, $resolver->draftSnapshot($template)['html']);
        $this->assertTrue($template->publishedSignatureDocument->is($released));
        $this->assertNull($released->fresh()->is_active);
        $this->assertNull($released->fresh()->outlook_default);
    }

    public function test_unpaired_setup_preview_falls_back_to_first_draft_but_delivery_does_not(): void
    {
        $this->pairingMigration()->up();
        $first = $this->document('Erste Signatur', 'signature');
        $this->document('Zweite Signatur', 'signature');
        $template = $this->document('Vorlage');
        $resolver = app(MailDocumentSignatureResolver::class);

        $this->assertSame($first->id, $resolver->draftDocument($template)->id);
        $this->assertSame($first->html, $resolver->draftSnapshot($template)['html']);
        $this->assertNull($resolver->publishedSnapshot($template));
        $this->assertNull($resolver->publishedSnapshot($template, 'outlook'));
    }

    #[DataProvider('invalidPublishedPairings')]
    public function test_explicit_invalid_published_pair_fails_closed_instead_of_using_default(string $scenario): void
    {
        $this->pairingMigration()->up();
        $this->document('Gültiger globaler Standard', 'signature', published: true, active: true);
        $template = $this->document('Vorlage');
        $partner = match ($scenario) {
            'wrong_kind' => $this->document('Keine Signatur'),
            'unpublished' => $this->document('Unveröffentlicht', 'signature'),
            'empty_release' => $this->document('Leere Freigabe', 'signature', published: true),
            default => null,
        };
        if ($scenario === 'empty_release') {
            $partner->update(['published_html' => '   ']);
        }
        // Missing relations represent a stale in-memory model or corrupted
        // external state; the FK normally prevents this from being stored.
        $template->published_signature_document_id = $partner?->id ?? 999999;

        $this->expectException(RuntimeException::class);
        app(MailDocumentSignatureResolver::class)->publishedSnapshot($template);
    }

    public static function invalidPublishedPairings(): array
    {
        return [['missing'], ['wrong_kind'], ['unpublished'], ['empty_release']];
    }

    public function test_missing_explicit_draft_pair_does_not_fall_back(): void
    {
        $this->pairingMigration()->up();
        $this->document('Global', 'signature', published: true, active: true);
        $template = $this->document('Vorlage');
        $template->signature_document_id = 999999;

        $this->expectException(RuntimeException::class);
        app(MailDocumentSignatureResolver::class)->draftDocument($template);
    }

    public function test_signatures_cannot_be_pairing_owners(): void
    {
        $signature = $this->document('Signatur', 'signature');

        $this->expectException(RuntimeException::class);
        app(MailDocumentSignatureResolver::class)->assertAssignable($signature, null);
    }

    public function test_unsaved_signatures_cannot_be_assigned(): void
    {
        $template = $this->document('Vorlage');

        $this->expectException(RuntimeException::class);
        app(MailDocumentSignatureResolver::class)->assertAssignable($template, new MailDocument(['kind' => 'signature']));
    }

    public function test_pairing_only_change_is_unpublished_and_is_preserved_in_history(): void
    {
        $this->pairingMigration()->up();
        $signature = $this->document('Signatur', 'signature');
        $template = $this->document('Vorlage', published: true);
        $this->assertFalse($template->hasUnpublishedChanges());
        $template->update(['signature_document_id' => $signature->id]);

        $this->assertTrue($template->hasUnpublishedChanges());
        $version = app(MailDocumentVersionStore::class)->capture($template, null, 'paired');
        $this->assertSame($signature->id, $version->signature_document_id);
        $this->assertTrue($version->signatureDocument->is($signature));
        $this->assertFalse($version->was_published);

        $template->update(['published_signature_document_id' => $signature->id]);
        $this->assertFalse($template->hasUnpublishedChanges());
        $this->assertTrue(app(MailDocumentVersionStore::class)->capture($template, null, 'published')->was_published);
    }

    public function test_admin_can_assign_a_signature_to_only_the_template_draft(): void
    {
        $this->pairingMigration()->up();
        $admin = User::factory()->create(['role' => 'admin']);
        $signature = $this->document('V27 Signatur', 'signature', published: true, active: true);
        $template = $this->document('V27 Vorlage', published: true, active: true);
        $oldHash = $template->content_hash;

        $updated = app(MailDocumentSignaturePairing::class)->assign(
            $admin,
            $template,
            $signature->public_id,
            $oldHash,
        );

        $this->assertSame($signature->id, $updated->signature_document_id);
        $this->assertNull($updated->published_signature_document_id);
        $this->assertTrue($updated->is_active);
        $this->assertSame(2, $updated->version);
        $this->assertNotSame($oldHash, $updated->content_hash);
        $this->assertSame(
            MailDocument::contentHashFor($updated->builder_data ?: [], $updated->html, $updated->css, $signature->id),
            $updated->content_hash,
        );
        $this->assertSame('signature_assigned', $updated->versions()->first()->action);
        $this->assertTrue($updated->hasUnpublishedChanges());
    }

    #[DataProvider('pairingColumns')]
    public function test_foreign_keys_prevent_deleting_referenced_signatures(string $column): void
    {
        $this->pairingMigration()->up();
        $signature = $this->document('Signatur', 'signature');
        $template = $this->document('Vorlage');
        $template->update([$column => $signature->id]);

        $this->expectException(QueryException::class);
        $signature->delete();
    }

    public static function pairingColumns(): array
    {
        return [['signature_document_id'], ['published_signature_document_id']];
    }

    public function test_unused_migration_can_be_reversed_without_changing_documents(): void
    {
        $template = $this->document('Vorlage', published: true, active: true);
        $before = $template->fresh()->getRawOriginal();
        $this->pairingMigration()->up();
        $this->pairingMigration()->down();

        $this->assertFalse(MailDocumentSignatureResolver::available());
        $this->assertSame($before, $template->fresh()->getRawOriginal());
    }

    public function test_rollback_refuses_to_discard_pairing_history(): void
    {
        $this->pairingMigration()->up();
        $signature = $this->document('Signatur', 'signature');
        $template = $this->document('Vorlage');
        $template->update(['signature_document_id' => $signature->id]);
        app(MailDocumentVersionStore::class)->capture($template, null, 'paired');
        $template->update(['signature_document_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->pairingMigration()->down();
    }
}
