<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EmployeeAccountInvitation;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureDocumentContract;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/** Exercise the system notification path, not the separate Outlook Add-in renderer. */
final class SystemMailResponsiveDeliveryTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('sqlite', config('database.default'));
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        config([
            'app.url' => 'https://railtime.test',
            'mail.default' => 'array',
            'mail.mailers.array.transport' => 'array',
            'mail.from.address' => 'system@railtime.test',
            'mail.from.name' => 'Synthetic RailTime',
            'outlook_addin.snapshots.auto_refresh' => false,
        ]);
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
            (require database_path('migrations/'.$migration))->up();
        }
        Setting::setValue('company', 'profile', [
            'name' => 'Synthetic RailTime',
            'phone' => '+49 30 5550100',
            'emergency_phone' => '+49 30 5550101',
            'email' => 'company@railtime.test',
            'website' => 'https://railtime.test',
        ]);
    }

    public function test_invitation_markdown_keeps_mobile_column_rules_overridable_without_changing_published_sources(): void
    {
        $this->publishSources();
        $before = $this->publishedRows();
        $recipient = $this->recipient();
        $message = (new EmployeeAccountInvitation('synthetic-invitation-token'))->toMail($recipient);
        $html = (string) app(Markdown::class)->render($message->markdown, $message->data());

        $this->assertSystemContent($html);
        self::assertSame($before, $this->publishedRows());
        Http::assertNothingSent();
        $this->assertResponsiveColumns($html);
    }

    public function test_actual_array_notification_retains_mobile_rules_and_exact_inline_train_media(): void
    {
        $this->publishSources();
        $before = $this->publishedRows();
        $transport = Mail::mailer('array')->getSymfonyTransport();
        self::assertInstanceOf(ArrayTransport::class, $transport);
        $transport->flush();

        $this->recipient()->notify(new EmployeeAccountInvitation('synthetic-invitation-token'));

        self::assertCount(1, $transport->messages());
        $email = $transport->messages()->sole()->getOriginalMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('employee@railtime.test', $email->getTo()[0]->getAddress());
        $html = $email->getHtmlBody();
        self::assertIsString($html);
        $this->assertSystemContent($html);
        self::assertStringContainsString('src="cid:', $html);
        self::assertStringNotContainsString('src="data:', $html);

        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachments[$attachment->getFilename()] = $attachment;
        }
        foreach (['zug-dampf-v27-delivery-light.gif', 'zug-dampf-v27-delivery-light.png'] as $asset) {
            self::assertArrayHasKey($asset, $attachments);
            self::assertSame(file_get_contents(public_path('mail-assets/'.$asset)), $attachments[$asset]->getBody());
            self::assertSame('inline', $attachments[$asset]->getDisposition());
            self::assertStringContainsString('cid:'.$attachments[$asset]->getContentId(), $html);
        }
        $xpath = $this->xpath($html);
        $bannerImage = $xpath->query('//*[@data-test-hotline]//img')->item(0);
        self::assertInstanceOf(DOMElement::class, $bannerImage);
        self::assertStringStartsWith('cid:', $bannerImage->getAttribute('src'));
        self::assertSame($before, $this->publishedRows());
        Http::assertNothingSent();
        $this->assertResponsiveColumns($html);
    }

    private function publishSources(): void
    {
        $signature = trim((string) file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $publishedSignature = $this->document(MailDocumentKind::Signature, $signature);
        $master = (string) file_get_contents(EmailTemplateBuilder::masterPath('email-master.html'));
        $master = str_replace('<title>', '<title>SYSTEM-INVITATION-FIXTURE ', $master);
        $master = str_replace('{{SIGNATURE_BLOCK}}', '<tr><td data-test-hotline="1" bgcolor="#e60032" style="background:#e60032;color:#ffffff;">24/7 Hotline<img src="{{ICON_EMAIL_SRC}}" width="17" height="17" alt="Hotline"></td></tr>{{SIGNATURE_BLOCK}}', $master);
        $template = $this->document(MailDocumentKind::Template, $master, [
            'published_signature_document_id' => $publishedSignature->id,
        ]);
        $template->forceFill(['html' => str_replace('SYSTEM-INVITATION-FIXTURE', 'UNPUBLISHED-DRAFT-FIXTURE', $master)])->save();
        $this->document(MailDocumentKind::Template, str_replace('SYSTEM-INVITATION-FIXTURE', 'OUTLOOK-ONLY-FIXTURE', $master), [
            'is_active' => null,
            'is_outlook_template' => true,
            'outlook_released' => true,
            'outlook_default' => true,
        ]);
        app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Template);
        app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Signature);
    }

    private function document(MailDocumentKind $kind, string $html, array $attributes = []): MailDocument
    {
        $builder = ['pages' => [['name' => 'Synthetic invitation', 'component' => $html]], 'styles' => [], 'railtime' => ['document' => $kind->value, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::query()->create(array_merge([
            'kind' => $kind,
            'name' => 'Synthetic '.$kind->value,
            'status' => MailDocumentStatus::Published,
            'is_active' => true,
            'is_outlook_template' => false,
            'outlook_released' => false,
            'outlook_default' => null,
            'builder_data' => $builder,
            'html' => $html,
            'css' => '',
            'published_html' => $html,
            'published_css' => '',
            'published_at' => now(),
            'content_hash' => MailDocument::contentHashFor($builder, $html, ''),
            'version' => 1,
        ], $attributes));
    }

    private function recipient(): User
    {
        return User::factory()->create(['name' => 'Synthetic Employee', 'email' => 'employee@railtime.test', 'role' => 'staff']);
    }

    private function publishedRows(): array
    {
        return MailDocument::query()->orderBy('id')->get()->map(static fn (MailDocument $document) => $document->getAttributes())->all();
    }

    private function assertSystemContent(string $html): void
    {
        self::assertStringContainsString('SYSTEM-INVITATION-FIXTURE', $html);
        self::assertStringNotContainsString('UNPUBLISHED-DRAFT-FIXTURE', $html);
        self::assertStringNotContainsString('OUTLOOK-ONLY-FIXTURE', $html);
        self::assertStringContainsString('Synthetic Employee', $html);
        self::assertStringContainsString('Zugang einrichten', $html);
        self::assertStringContainsString('24/7 Hotline', $html);
        self::assertStringContainsString('company@railtime.test', $html);
        self::assertMatchesRegularExpression('/@media[^{}]*max-width:\s*860px/i', $html);
        self::assertMatchesRegularExpression('/\.rt-delivery-wide-column[^{}]*\{[^}]*display:\s*block\s*!important/i', $html);
        $logo = $this->xpath($html)->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]')->item(0);
        self::assertInstanceOf(DOMElement::class, $logo);
        self::assertMatchesRegularExpression('/(?:^|;)\s*width:\s*100%/', $logo->getAttribute('style'));
        self::assertMatchesRegularExpression('/(?:^|;)\s*max-width:\s*180px/', $logo->getAttribute('style'));
    }

    private function assertResponsiveColumns(string $html): void
    {
        $columns = $this->xpath($html)->query('//th[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-wide-column ")]');
        self::assertCount(2, $columns);
        foreach ($columns as $column) {
            self::assertDoesNotMatchRegularExpression('/(?:^|;)\s*display:\s*table-cell\s*!important/i', $column->getAttribute('style'), 'Desktop inline priority must not prevent the mobile columns from stacking.');
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }
}
