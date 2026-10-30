<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Models\MailDocument;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SystemMailCssInliner;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

final class PublishedStaticSystemDeliveryTest extends TestCase
{
    private MailDocument $system;

    private MailDocument $linked;

    private MailDocument $template;

    protected function setUp(): void
    {
        parent::setUp();
        $fixture = new OutlookStaticLinkedTestControllersTest('test_system_preview_projects_only_the_exact_selected_published_signature_without_activating_it');
        (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
        $this->system = (new ReflectionProperty($fixture, 'system'))->getValue($fixture);
        $this->linked = (new ReflectionMethod($fixture, 'markedSystemSignature'))->invoke($fixture);
        $this->template = MailDocument::query()->where('kind', 'template')->where('is_active', true)->firstOrFail();
        Http::preventStrayRequests();
        config(['app.url' => 'https://mail.example.test']);
        app()->forgetScopedInstances();
    }

    public function test_effective_published_system_pair_uses_remote_pngs_through_the_real_inliner_and_embedder(): void
    {
        $this->template->forceFill(['published_signature_document_id' => $this->linked->id])->saveQuietly();
        $before = $this->rows();
        $compiled = $this->compiled();
        self::assertStringContainsString(SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE, $compiled);
        $html = (new SystemMailCssInliner)->convert($compiled);
        $message = $this->message($html);
        self::assertSame(0, (new SystemMailInlineImageEmbedder)->embed($message));
        self::assertSame($html, $message->getHtmlBody());
        self::assertCount(0, $message->getAttachments());
        self::assertStringContainsString('https://mail.example.test/m/', $html);
        self::assertDoesNotMatchRegularExpression('/\b(?:cid|data):|\.gif(?:[?"\'])|data-rt-static|data-rt-system-mail-inline-assets/i', $html);
        self::assertStringNotContainsString('Content-ID:', $message->toString());
        self::assertSame($before, $this->rows());
        self::assertTrue($this->system->fresh()->is_active, 'The explicit published pair wins without changing the global system default.');
        self::assertNull($this->linked->fresh()->is_active);
        Http::assertNothingSent();
    }

    public function test_unmarked_pair_is_byteidentical_and_outranks_a_separate_marked_active_signature(): void
    {
        $this->template->forceFill(['published_signature_document_id' => $this->system->id])->saveQuietly();
        $this->system->forceFill(['is_active' => null])->saveQuietly();
        $this->linked->forceFill(['is_active' => true, 'status' => 'published'])->saveQuietly();
        $compiled = $this->compiled();
        $store = app(PublishedMailDocumentSnapshotStore::class);
        $used = $store->snapshot(MailDocumentKind::Signature);
        $store->useSnapshot(MailDocumentKind::Signature, $used['html'], $used['css']);
        $expected = (new SystemMailCssInliner)->convert($compiled);
        $store->forget(MailDocumentKind::Signature);
        $actual = (new SystemMailCssInliner)->convert($compiled);
        self::assertSame($expected, $actual);
        self::assertStringContainsString(SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE, $actual);
        $message = $this->message($actual);
        self::assertGreaterThan(0, (new SystemMailInlineImageEmbedder)->embed($message));
        self::assertStringContainsString('cid:', $message->getHtmlBody());
    }

    public function test_normal_notification_markdown_render_uses_the_static_default_without_sending(): void
    {
        $this->template->forceFill(['published_signature_document_id' => $this->linked->id])->saveQuietly();
        $before = $this->rows();
        $notification = (new MailMessage)->subject('Synthetic notification')->greeting('Synthetic greeting')
            ->line('Synthetic system content.')->action('Open', 'https://mail.example.test/app');
        $html = (string) app(Markdown::class)->render($notification->markdown, $notification->data());
        $message = $this->message($html);
        self::assertSame(0, (new SystemMailInlineImageEmbedder)->embed($message));
        self::assertCount(0, $message->getAttachments());
        self::assertStringContainsString('Synthetic system content.', $html);
        self::assertStringContainsString('https://mail.example.test/m/', $html);
        self::assertDoesNotMatchRegularExpression('/\b(?:cid|data):|\.gif(?:[?"\'])|data-rt-static|data-rt-system-mail-inline-assets/i', $html);
        self::assertSame($before, $this->rows());
        Http::assertNothingSent();
    }

    public function test_explicit_preview_override_cannot_acquire_static_default_eligibility(): void
    {
        $this->template->forceFill(['published_signature_document_id' => $this->linked->id])->saveQuietly();
        $store = app(PublishedMailDocumentSnapshotStore::class);
        $store->useSnapshot(MailDocumentKind::Signature, $this->linked->published_html, (string) $this->linked->published_css);
        $html = (new SystemMailCssInliner)->convert($this->compiled());
        self::assertStringContainsString(SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE, $html);
        self::assertStringNotContainsString('https://mail.example.test/m/', $html);
    }

    public function test_published_signature_change_after_compilation_fails_without_replacing_the_scoped_snapshot(): void
    {
        $this->template->forceFill(['published_signature_document_id' => $this->linked->id])->saveQuietly();
        $compiled = $this->compiled();
        $store = app(PublishedMailDocumentSnapshotStore::class);
        $used = $store->snapshot(MailDocumentKind::Signature);
        $this->linked->forceFill(['published_css' => '.rt-sign-name{letter-spacing:1px;}'])->saveQuietly();
        try {
            (new SystemMailCssInliner)->convert($compiled);
            self::fail('A stale published source must not be sent as static.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('während der Ausgabe geändert', $error->getMessage());
        }
        self::assertSame($used, $store->snapshot(MailDocumentKind::Signature));
    }

    public function test_concurrent_publication_during_fresh_admission_is_rejected(): void
    {
        $this->template->forceFill(['published_signature_document_id' => $this->linked->id])->saveQuietly();
        $compiled = $this->compiled();
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'mail_documents')
                && str_contains($query->sql, '"id" = ?') && in_array($this->linked->id, $query->bindings, true)) {
                $changed = true;
                DB::table('mail_documents')->where('id', $this->linked->id)->update(['published_css' => '.rt-sign-name{letter-spacing:2px;}']);
            }
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('während der Ausgabe geändert');
        try {
            (new SystemMailCssInliner)->convert($compiled);
        } finally {
            self::assertTrue($changed);
        }
    }

    private function compiled(): string
    {
        return EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>Synthetic application content.</p>'));
    }

    private function message(string $html): Email
    {
        return (new Email)->from('system@example.test')->to('self@example.test')->subject('Synthetic static system delivery')->html($html);
    }

    private function rows(): array
    {
        return MailDocument::query()->orderBy('id')->get()->toArray();
    }
}
