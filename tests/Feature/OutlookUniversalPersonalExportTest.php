<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookCompactReferencePresentation;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use App\Support\OutlookAddin\OutlookUniversalPersonalExport;
use App\Support\OutlookAddin\OutlookUniversalPresentation;
use App\Support\OutlookAddin\OutlookUniversalSourceDescriptor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookUniversalPersonalExportTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        $this->withoutMiddleware(LogActivity::class);
        config(['app.url' => 'https://mail.example.test']);
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_html_download_embeds_thirteen_images_and_preserves_the_author_and_complete_head(): void
    {
        $service = $this->service();
        $draft = $this->draft($service);
        $legacy = $this->legacy('text/html; charset=UTF-8');
        $result = $service->project($this->pilot(), 'vorlage-html', $legacy);
        self::assertSame($legacy['filename'], $result['filename']);
        self::assertSame($legacy['mime'], $result['mime']);
        self::assertStringContainsString($draft['marker'], $result['content']);
        self::assertSame(13, substr_count($result['content'], '<img '));
        self::assertSame(13, substr_count($result['content'], 'src="data:image/png;base64,'));
        self::assertSame(3, substr_count($result['content'], '@media'));
        self::assertSame(8, substr_count($result['content'], 'mso-line-height-rule:exactly'));
        preg_match('~<head>.*?</head>~s', $draft['html'], $head);
        self::assertStringContainsString($head[0], $result['content']);
        self::assertTrue(str_contains($result['content'], $this->author($service)));
        foreach ($this->imageBytes($result['content'], 'data') as $bytes) {
            self::assertSame('image/png', getimagesizefromstring($bytes)['mime']);
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_signature_download_and_remote_copy_have_one_signature_without_the_message_row(): void
    {
        $service = $this->service();
        $draft = $this->draft($service);
        $result = $service->project($this->pilot(), 'signatur-hell', $this->legacy('text/html; charset=UTF-8'));
        $copy = $service->signatureCopy($this->pilot(), '<p>Legacy copy</p>');
        foreach ([$result['content'], $copy] as $html) {
            self::assertStringContainsString($draft['marker'], $html);
            self::assertSame(1, substr_count($html, 'class="rt-shell"'));
            self::assertSame(13, substr_count($html, '<img '));
            self::assertFalse(str_contains($html, $this->author($service)));
            self::assertSame(3, substr_count($html, '@media'));
        }
        self::assertSame(13, substr_count($result['content'], 'src="data:image/png;base64,'));
        self::assertSame(13, substr_count($copy, 'src="https://mail.example.test/m/'));
        self::assertStringNotContainsString('data:image', $copy);
    }

    public function test_eml_is_a_download_only_draft_with_original_headers_and_thirteen_hash_valid_cid_parts(): void
    {
        $service = $this->service();
        $draft = $this->draft($service);
        $legacy = $this->legacy('message/rfc822');
        $legacy['content'] = "MIME-Version: 1.0\r\nSubject: =?UTF-8?B?VGVzdMOkw7bDvA==?=\r\nFrom: Test Person <it-technik@rail-time.de>\r\nTo: {{EMPFAENGER_E_MAIL}}\r\nX-Unsent: 1\r\nX-RailTime-Theme: light\r\nContent-Type: multipart/alternative; boundary=\"old\"\r\n\r\nOLD BODY";
        $result = $service->project($this->pilot(), 'vorlage-eml', $legacy);
        $mime = $result['content'];
        self::assertSame($legacy['filename'], $result['filename']);
        self::assertSame('message/rfc822', $result['mime']);
        foreach (['Subject: =?UTF-8?B?VGVzdMOkw7bDvA==?=', 'From: Test Person <it-technik@rail-time.de>', 'To: {{EMPFAENGER_E_MAIL}}', 'X-Unsent: 1', 'X-RailTime-Theme: light'] as $header) {
            self::assertStringContainsString($header."\r\n", $mime);
        }
        self::assertStringContainsString('X-RailTime-Template: '.$draft['header'], $mime);
        preg_match('~Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+/=\r\n]+)\r\n--~', $mime, $htmlPart);
        $html = base64_decode($htmlPart[1], true);
        self::assertIsString($html);
        self::assertSame(13, substr_count($html, '<img '));
        self::assertSame(13, substr_count($html, 'src="cid:railtime-u2e-'));
        self::assertSame(3, substr_count($html, '@media'));
        self::assertTrue(str_contains($html, $this->author($service)));
        preg_match_all('~Content-Type: image/png; name="([0-9a-f]{10}\.png)"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <([^>]+)>\r\nContent-Disposition: inline; filename="\1"\r\n\r\n([A-Za-z0-9+/=\r\n]+)\r\n--~', $mime, $parts, PREG_SET_ORDER);
        self::assertCount(13, $parts);
        foreach ($parts as $part) {
            $bytes = base64_decode($part[3], true);
            self::assertSame(hash_file('sha256', public_path('m/'.$part[1])), hash('sha256', $bytes));
            self::assertStringContainsString('src="cid:'.$part[2].'"', $html);
        }
        self::assertStringNotContainsString('src="https://', $html);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_nonpilot_zip_text_and_unknown_formats_keep_their_exact_legacy_output_without_source_reads(): void
    {
        $service = $this->service();
        $legacy = $this->legacy('application/zip');
        self::assertSame($legacy, $service->project((new User)->forceFill(['email' => 'another@example.test']), 'vorlage-html', $legacy));
        foreach (['signatur-outlook-hell', 'signatur-outlook-dunkel', 'signatur-text', 'unknown'] as $key) {
            self::assertSame($legacy, $service->project($this->pilot(), $key, $legacy));
        }
        self::assertSame('Legacy copy', $service->signatureCopy((new User)->forceFill(['email' => 'another@example.test']), 'Legacy copy'));
        self::assertSame([], $service->readUsers);
    }

    public function test_profile_mismatch_and_custom_source_keep_the_existing_export_and_copy(): void
    {
        foreach (['profile', 'source'] as $failure) {
            $service = $this->service();
            if ($failure === 'profile') {
                $service->values['VORNAME_NACHNAME'] = 'Other person';
            } else {
                $service->payload['templates'][0]['combinedComposeHtml'] = '<p>Custom source</p>';
            }
            $legacy = $this->legacy('text/html; charset=UTF-8');
            self::assertSame($legacy, $service->project($this->pilot(), 'vorlage-html', $legacy));
            self::assertSame('Legacy copy', $service->signatureCopy($this->pilot(), 'Legacy copy'));
        }
        Http::assertNothingSent();
    }

    public function test_bad_media_hash_or_nonlocal_path_never_causes_a_network_fetch_or_partial_export(): void
    {
        foreach (['hash', 'url'] as $failure) {
            $service = $this->service();
            $service->replacement = $this->draft($service);
            if ($failure === 'hash') {
                $service->replacement['media'][0]['sha256'] = str_repeat('0', 64);
            } else {
                $service->replacement['media'][0]['url'] = 'https://private.example/m/b983af0049.png';
            }
            $legacy = $this->legacy('text/html; charset=UTF-8');
            self::assertSame($legacy, $service->project($this->pilot(), 'vorlage-html', $legacy));
        }
        Http::assertNothingSent();
    }

    public function test_opaque_author_bytes_are_not_rewritten_when_they_mention_a_signature_asset_as_a_text_link(): void
    {
        $service = $this->service();
        $service->replacement = $this->draft($service);
        $needle = $this->author($service);
        $author = 'Autor: <a href="https://mail.example.test/m/14d7943c88.png">Link</a> &amp; <b>Text</b>';
        foreach (['html', 'bodyHtml'] as $key) {
            $service->replacement[$key] = str_replace($needle, $author, $service->replacement[$key]);
        }
        $result = $service->project($this->pilot(), 'vorlage-html', $this->legacy('text/html; charset=UTF-8'));
        self::assertStringContainsString($author, $result['content']);
        self::assertSame(13, substr_count($result['content'], '<img '));
    }

    public function test_a_nonportable_author_image_keeps_the_whole_existing_export_instead_of_rewriting_author_bytes(): void
    {
        $service = $this->service();
        $service->replacement = $this->draft($service);
        $author = $this->author($service);
        foreach (['html', 'bodyHtml'] as $key) {
            $service->replacement[$key] = str_replace($author, $author.'<img src="https://private.example/author.png">', $service->replacement[$key]);
        }
        $legacy = $this->legacy('text/html; charset=UTF-8');
        self::assertSame($legacy, $service->project($this->pilot(), 'vorlage-html', $legacy));
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_existing_dark_download_keys_use_the_same_approved_neutral_u2e_source(): void
    {
        $service = $this->service();
        foreach ([['vorlage-html', 'vorlage-dunkel-html'], ['signatur-hell', 'signatur-dunkel']] as [$light, $dark]) {
            $legacy = $this->legacy('text/html; charset=UTF-8');
            self::assertSame($service->project($this->pilot(), $light, $legacy), $service->project($this->pilot(), $dark, $legacy));
        }
    }

    public function test_controller_download_preview_and_copy_use_only_the_authenticated_pilot_without_business_changes(): void
    {
        $pilot = User::factory()->create(['name' => 'Mara Muster', 'email' => 'it-technik@rail-time.de', 'role' => 'staff', 'status' => true]);
        $foreign = User::factory()->create(['email' => 'foreign@example.test', 'role' => 'staff', 'status' => true]);
        $service = $this->service();
        $this->app->instance(OutlookUniversalPersonalExport::class, $service);
        $before = $this->businessHash();
        $download = $this->actingAs($pilot)->get('/email-templates/vorlage-html/download?user='.$foreign->id);
        $download->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        self::assertStringContainsString('rt-sig-u2-', $download->getContent());
        self::assertSame(13, substr_count($download->getContent(), 'src="data:image/png;base64,'));
        $preview = $this->get('/email-templates/vorlage-html/preview?user='.$foreign->id);
        $preview->assertOk();
        self::assertStringContainsString('rt-sig-u2-', $preview->getContent());
        $copy = $this->getJson('/email-templates/signature/copy?user='.$foreign->id);
        $copy->assertOk();
        self::assertStringContainsString('rt-sig-u2-', $copy->json('html'));
        self::assertSame([$pilot->id, $pilot->id, $pilot->id], $service->readUsers);
        self::assertSame($before, $this->businessHash());
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_guest_and_invalid_template_are_denied_before_the_projection(): void
    {
        $service = $this->service();
        $this->app->instance(OutlookUniversalPersonalExport::class, $service);
        $this->get('/email-templates/vorlage-html/download')->assertRedirect(route('login'));
        $user = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->actingAs($user)->get('/email-templates/not-a-template/download')->assertNotFound();
        self::assertSame([], $service->readUsers);
    }

    public function test_controller_nonadmitted_and_nonpilot_requests_keep_the_existing_download(): void
    {
        foreach (['it-technik@rail-time.de', 'other@example.test'] as $email) {
            $user = User::factory()->create(['email' => $email, 'role' => 'staff', 'status' => true]);
            $service = $this->service();
            $service->payload['templates'][0]['combinedComposeHtml'] = '<p>Custom source</p>';
            $this->app->instance(OutlookUniversalPersonalExport::class, $service);
            $response = $this->actingAs($user)->get('/email-templates/vorlage-html/download');
            $response->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
            self::assertStringNotContainsString('rt-sig-u2-', $response->getContent());
            self::assertCount($email === 'it-technik@rail-time.de' ? 1 : 0, $service->readUsers);
        }
    }

    private function imageBytes(string $html, string $delivery): array
    {
        preg_match_all('~src="data:image/png;base64,([A-Za-z0-9+/=]+)"~', $html, $matches);

        return array_map(static fn (string $bytes): string => base64_decode($bytes, true), $matches[1]);
    }

    private function legacy(string $mime): array
    {
        return ['filename' => 'unchanged-download.ext', 'mime' => $mime, 'content' => 'LEGACY EXPORT BYTES'];
    }

    private function pilot(): User
    {
        return (new User)->forceFill(['id' => 4, 'email' => 'it-technik@rail-time.de']);
    }

    private function draft($service): array
    {
        $review = (new OutlookUniversalPayloadPresentation(new OutlookUniversalPresentation))->review($service->payload, $service->values);
        $draft = $review['draft'];
        self::assertNotNull($draft, json_encode($review['admission']));

        return $draft;
    }

    private function author($service): string
    {
        $html = $service->payload['templates'][0]['combinedComposeHtml'];
        $source = OutlookUniversalSourceDescriptor::projectFromCompiled($html, $service->values, hash('sha256', OutlookUniversalPresentation::REVISION."\0".$html));
        self::assertNotNull($source);

        return $source['authorContent']->toHtml();
    }

    private function service(): OutlookUniversalPersonalExport
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/mail/outlook-v17-office-actions/configured-default.json')), true, 512, JSON_THROW_ON_ERROR);
        $source = static function (string $html): string {
            $html = OutlookCompactReferencePresentation::canonicalizeToV16($html);
            $html = str_replace('mara@example.test', 'it-technik@rail-time.de', $html);
            $at = strpos($html, 'href="tel:+49305550101"');
            $start = strrpos(substr($html, 0, $at), '<tr>');
            $end = strpos($html, '</tr>', $at) + strlen('</tr>');

            return OutlookCompactReferencePresentation::document(substr_replace($html, '', $start, $end - $start));
        };
        $signature = ['html' => $source($fixture['source']['standalone']), 'media' => []];
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $payload = ['signature' => $signature, 'automaticTemplateId' => $id,
            'templates' => [['id' => $id, 'key' => 'standard', 'active' => true, 'isDefault' => true, 'combinedComposeHtml' => $source($fixture['source']['combined'])]]];
        $values = ['VORNAME_NACHNAME' => 'Mara Muster', 'POSITION' => 'Disposition', 'E_MAIL' => 'it-technik@rail-time.de',
            'DURCHWAHL' => '', 'DURCHWAHL_TEL' => '', 'MOBIL' => '+49 170 5550101', 'MOBIL_TEL' => '+491705550101',
            'FIRMENNAME' => 'Example GmbH', 'FIRMEN_WEBSITE_HREF' => 'https://example.test', 'FIRMEN_WEBSITE_LABEL' => 'example.test',
            'FIRMENSTRASSE' => 'Musterweg 12', 'FIRMEN_PLZ_ORT' => '10115 Berlin'];
        $snapshots = $this->app->make(OutlookAddinUserSnapshotStore::class);

        return new class($snapshots, new OutlookUniversalPayloadPresentation(new OutlookUniversalPresentation), $payload, $values) extends OutlookUniversalPersonalExport
        {
            public array $readUsers = [];

            public ?array $replacement = null;

            public function __construct($snapshots, $projection, public array $payload, public array $values)
            {
                parent::__construct($snapshots, $projection);
            }

            protected function canonical(User $user): array
            {
                $this->readUsers[] = $user->id;

                return $this->payload;
            }

            protected function profile(User $user): array
            {
                return $this->values;
            }

            protected function draft(User $user): ?array
            {
                return $this->replacement ?? parent::draft($user);
            }
        };
    }

    private function businessHash(): string
    {
        $rows = [];
        foreach (['users', 'user_profiles', 'settings'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
