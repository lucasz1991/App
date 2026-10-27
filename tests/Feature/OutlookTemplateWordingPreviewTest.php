<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Http\Controllers\Admin\MailDocumentController;
use App\Models\MailDocument;
use App\Models\User;
use App\Services\PageBuilder\PageBuilderPreviewService;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookTemplateWordingPreviewTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        Http::preventStrayRequests();
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        foreach (['2026_08_09_000100_create_mail_documents_table.php', '2026_08_27_000100_add_design_slots_to_mail_documents.php'] as $migration) {
            (include database_path('migrations/'.$migration))->up();
        }
        $fixture = new PageBuilderPreviewTest('test_template_preview_and_delivery_share_the_published_signature_but_signature_preview_keeps_its_draft');
        (new ReflectionMethod($fixture, 'createCanonicalMailDocuments'))->invoke($fixture);
    }

    public function test_token_opt_in_previews_defaults_before_unknown_token_fallback_without_source_writes(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'name' => 'Synthetic Preview']);
        $template = $this->template(true);
        $before = $this->documents();
        foreach (['light', 'dark'] as $theme) {
            $html = app(PageBuilderPreviewService::class)->mail($template, $user, $theme)['html'];
            self::assertStringContainsString('Guten Tag,', $html);
            self::assertStringContainsString('Mit freundlichen Grüßen,', $html);
            self::assertStringNotContainsString('{{OUTLOOK_ANREDE}}', $html);
            self::assertStringNotContainsString('{{OUTLOOK_GRUSS}}', $html);
            self::assertDoesNotMatchRegularExpression('~<p\b[^>]*class="design-(?:salutation|greeting)"[^>]*>\s*—\s*</p>~', $html);
        }
        self::assertSame($before, $this->documents());
        Http::assertNothingSent();
    }

    public function test_standard_without_wording_slots_gets_no_added_salutation_or_farewell(): void
    {
        $template = $this->template(false);
        $template->html = preg_replace('~<p\b[^>]*class="design-(?:salutation|greeting)"[^>]*>.*?</p>~s', '', $template->html);
        $user = User::factory()->create(['role' => 'admin']);
        $before = $this->documents();
        $html = app(PageBuilderPreviewService::class)->mail($template, $user, 'light')['html'];
        self::assertStringNotContainsString('Guten Tag,', $html);
        self::assertStringNotContainsString('Mit freundlichen Grüßen,', $html);
        self::assertStringNotContainsString('class="design-salutation"', $html);
        self::assertStringNotContainsString('class="design-greeting"', $html);
        self::assertSame($before, $this->documents());
    }

    public function test_system_candidate_masks_both_wording_slots_and_is_identical_to_the_literal_source(): void
    {
        $literal = $this->template(false);
        $tokens = $this->template(true);
        $compiler = new ReflectionMethod(MailDocumentController::class, 'compileFinalSystemMailCandidate');
        $before = $this->documents();
        $render = static fn (MailDocument $document): string => $compiler->invoke(app(MailDocumentController::class),
            app(PublishedMailDocumentSnapshotStore::class), $document, $document->html, $document->css);
        $baseline = $render($literal);
        $candidate = $render($tokens);
        self::assertSame($baseline, $candidate);
        self::assertStringNotContainsString('OUTLOOK_ANREDE', $candidate);
        self::assertStringNotContainsString('OUTLOOK_GRUSS', $candidate);
        self::assertStringNotContainsString('class="design-salutation"', $candidate);
        self::assertSame($before, $this->documents());
        Http::assertNothingSent();
    }

    private function template(bool $tokens): MailDocument
    {
        $template = MailDocument::query()->where('kind', MailDocumentKind::Template->value)->firstOrFail();
        // The opted-in V27 variant keeps both paragraphs in one content TD.
        // The historical bootstrap master has separate rows and stays unmarked.
        $content = '<tr><td><p class="design-salutation">'.($tokens ? '{{OUTLOOK_ANREDE}}' : 'Guten Tag,').'</p>'
            .'<p class="design-spacer">{{APPLICATION_CONTENT}}</p>'
            .'<p class="design-greeting">'.($tokens ? '{{OUTLOOK_GRUSS}}' : 'Mit freundlichen Grüßen,').'</p></td></tr>';
        $template->html = preg_replace('~(?<=<!-- RT_APPLICATION_CONTENT_START -->).*?(?=<!-- RT_APPLICATION_CONTENT_END -->)~s', $content, $template->html, 1, $count);
        self::assertSame(1, $count);

        return $template;
    }

    private function documents(): array
    {
        return DB::table('mail_documents')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }
}
