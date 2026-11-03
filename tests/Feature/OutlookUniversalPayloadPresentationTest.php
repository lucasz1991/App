<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\OutlookAddin\OutlookUniversalPayloadPresentation;
use App\Support\OutlookAddin\OutlookUniversalPresentation;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class OutlookUniversalPayloadPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://mail.example.test']);
        Http::preventStrayRequests();
    }

    public function test_additive_projection_preserves_every_legacy_field_and_adds_bounded_editor_documents(): void
    {
        $canonical = $this->payload();
        $canonicalBefore = $canonical;
        $wire = $canonical;
        $wire['binding'] = ['schema' => 1, 'mailboxAddress' => 'mara@example.test'];
        $before = $wire;
        $output = $this->projection()->append($wire, $canonical, $this->profile(), $this->pilot());
        self::assertArrayHasKey('universalSignature', $output);
        self::assertArrayHasKey('universalCompose', $output['templates'][0]);
        self::assertArrayHasKey('universalSignature', $output['templates'][0]);
        foreach ([$output['universalSignature'], $output['templates'][0]['universalCompose']] as $document) {
            $keys = array_keys($document);
            sort($keys);
            $bridgeKeys = ['schema', 'kind', 'html', 'version', 'marker', 'header', 'sourceHash', 'media'];
            sort($bridgeKeys);
            self::assertSame($bridgeKeys, $keys, 'The PHP envelope must match the strict Office.js document contract.');
            self::assertSame(1, $document['schema']);
            self::assertSame([], $document['media']);
            self::assertSame('u2e-'.$document['version'], $document['header']);
            self::assertSame('rt-sig-u2-'.$document['version'], $document['marker']);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{24}\z/', $document['version']);
            self::assertLessThanOrEqual(27000, intdiv(strlen(mb_convert_encoding($document['html'], 'UTF-16LE', 'UTF-8')), 2));
            self::assertStringNotContainsString('<style', $document['html']);
            self::assertStringNotContainsString('RT-FLUID-LAYOUT-', $document['html']);
            self::assertStringContainsString('width="1040"', $document['html']);
        }
        unset($output['universalSignature'], $output['templates'][0]['universalCompose'], $output['templates'][0]['universalSignature']);
        self::assertSame($before, $output);
        self::assertSame($before, $wire);
        self::assertSame($canonicalBefore, $canonical);
        Http::assertNothingSent();
    }

    public function test_mobile_wire_variants_get_the_same_host_free_new_editor_source(): void
    {
        $canonical = $this->payload();
        $desktop = $this->projection()->append($canonical, $canonical, $this->profile(), $this->pilot());
        $mobile = $canonical;
        $fixture = $this->fixture();
        $mobile['signature']['html'] = $fixture['mobile']['standalone'];
        $mobile['templates'][0]['combinedComposeHtml'] = $fixture['mobile']['combined'];
        $output = $this->projection()->append($mobile, $canonical, $this->profile(), $this->pilot());
        self::assertSame($desktop['universalSignature'], $output['universalSignature']);
        self::assertSame($desktop['templates'][0]['universalCompose'], $output['templates'][0]['universalCompose']);
        self::assertSame($fixture['mobile']['standalone'], $output['signature']['html']);
        self::assertSame($fixture['mobile']['combined'], $output['templates'][0]['combinedComposeHtml']);
    }

    public function test_draft_has_a_complete_send_document_and_a_separate_headless_editor_document(): void
    {
        $canonical = $this->payload();
        $send = $this->projection()->draft($canonical, $this->profile());
        $editor = $this->projection()->draft($canonical, $this->profile(), $canonical['templates'][0]['id'], 'editor');
        self::assertNotNull($send);
        self::assertNotNull($editor);
        self::assertSame('send', $send['mode']);
        self::assertStringStartsWith('<!doctype html>', $send['html']);
        self::assertSame(2, substr_count($send['headCss'], '@media'));
        self::assertSame(7, substr_count($send['html'], '<meta'));
        self::assertStringNotContainsString('<head', $editor['html']);
        self::assertSame('', $editor['headCss']);
        self::assertSame($send['marker'], $editor['marker']);
        self::assertSame($send['authorContentHash'], $editor['authorContentHash']);
        self::assertSame($send['header'], $this->projection()->draft($canonical, $this->profile())['header']);
        self::assertStringContainsString('class="design-spacer"', $send['html']);
        self::assertStringNotContainsString('RT-COMPOSE-EDITABLE-', $send['html']);
    }

    public function test_explicit_template_choice_unknown_id_and_no_template_are_respected(): void
    {
        $canonical = $this->payload();
        self::assertNull($this->projection()->draft($canonical, $this->profile(), 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'));
        $canonical['templates'] = [];
        self::assertNull($this->projection()->draft($canonical, $this->profile()));
        $this->expectException(RuntimeException::class);
        $this->projection()->draft($this->payload(), $this->profile(), null, 'unsafe');
    }

    public function test_current_profile_mismatch_does_not_publish_a_stale_personal_u2e_projection(): void
    {
        $canonical = $this->payload();
        $profile = $this->profile();
        $profile['VORNAME_NACHNAME'] = 'Another person';
        self::assertSame($canonical, $this->projection()->append($canonical, $canonical, $profile, $this->pilot()));
        self::assertNull($this->projection()->draft($canonical, $profile));
    }

    public function test_unadmitted_custom_authored_sources_remain_byte_identical(): void
    {
        $canonical = $this->payload();
        $canonical['signature']['html'] = '<p>Custom signature</p>';
        $canonical['templates'][0]['combinedComposeHtml'] = '<p>Custom author and footer</p>';
        self::assertSame($canonical, $this->projection()->append($canonical, $canonical, $this->profile(), $this->pilot()));
        self::assertNull($this->projection()->draft($canonical, $this->profile()));
    }

    public function test_preview_requires_admin_even_if_outer_middleware_is_absent(): void
    {
        $this->withoutMiddleware();
        $this->get('/administrator/mail-vorlagen/u2e-entwurf')->assertForbidden();
        $member = new User;
        $member->forceFill(['id' => 93, 'role' => 'employee']);
        $this->actingAs($member)->get('/administrator/mail-vorlagen/u2e-entwurf')->assertForbidden();
    }

    public function test_preview_rejects_invalid_query_before_reading_any_published_payload(): void
    {
        $this->withoutMiddleware();
        $admin = new User;
        $admin->forceFill(['id' => 93, 'role' => 'admin']);
        foreach (['mode=unsafe', 'template=not-a-uuid', 'download=arbitrary'] as $query) {
            $this->actingAs($admin)->getJson('/administrator/mail-vorlagen/u2e-entwurf?'.$query)->assertUnprocessable();
        }
    }

    public function test_non_pilot_and_missing_identity_strip_all_u2e_fields_even_with_forged_profile_or_stale_wire(): void
    {
        $canonical = $this->payload();
        $wire = $canonical;
        $wire['universalSignature'] = ['stale' => true];
        $wire['universalCompose'] = ['stale' => true];
        $wire['template']['universalSignature'] = ['stale' => true];
        $wire['template']['universalCompose'] = ['stale' => true];
        $wire['templates'][0]['universalCompose'] = ['stale' => true];
        $wire['templates'][0]['universalSignature'] = ['stale' => true];
        $profile = $this->profile();
        $profile['E_MAIL'] = 'it-technik@rail-time.de';
        foreach ([null, '', 'employee@example.test', 'it-technik+pilot@rail-time.de', 'it-technik@rail-time.de.example.test'] as $email) {
            $user = $email === null ? null : (new User)->forceFill(['email' => $email]);
            self::assertSame($canonical, $this->projection()->append($wire, $canonical, $profile, $user));
        }
        Http::assertNothingSent();
    }

    public function test_pilot_address_is_case_insensitive_but_not_taken_from_profile(): void
    {
        $pilot = (new User)->forceFill(['email' => 'IT-TECHNIK@RAIL-TIME.DE']);
        $output = $this->projection()->append($this->payload(), $this->payload(), $this->profile(), $pilot);
        self::assertArrayHasKey('universalSignature', $output);
        self::assertArrayHasKey('universalCompose', $output['templates'][0]);
    }

    private function pilot(): User
    {
        return (new User)->forceFill(['email' => 'it-technik@rail-time.de']);
    }

    private function projection(): OutlookUniversalPayloadPresentation
    {
        return new OutlookUniversalPayloadPresentation(new OutlookUniversalPresentation);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/mail/outlook-v17-office-actions/configured-default.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function payload(): array
    {
        $fixture = $this->fixture();
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $signature = ['html' => $fixture['source']['standalone'], 'media' => []];

        return ['schema' => 1, 'marker' => 'RT-SIGNATURE-MANAGED-V1', 'signature' => $signature,
            'template' => ['html' => $fixture['source']['combined'], 'media' => []],
            'templates' => [['id' => $id, 'key' => 'standard', 'name' => 'Standard', 'label' => 'Standard', 'active' => true, 'isDefault' => true,
                'html' => $fixture['source']['combined'], 'media' => [], 'signatureMode' => 'native',
                'combinedComposeHtml' => $fixture['source']['combined'], 'combinedComposeMedia' => [], 'signature' => $signature,
                'signatureVersion' => '0123456789abcdef', 'version' => '0123456789abcdef', 'hash' => str_repeat('a', 64)]],
            'automaticTemplateId' => $id, 'version' => ['signature' => '0123456789abcdef', 'template' => '0123456789abcdef']];
    }

    private function profile(): array
    {
        return ['VORNAME_NACHNAME' => 'Mara Muster', 'POSITION' => 'Disposition', 'E_MAIL' => 'mara@example.test',
            'DURCHWAHL' => '+49 30 5550101', 'DURCHWAHL_TEL' => '+49305550101', 'MOBIL' => '+49 170 5550101', 'MOBIL_TEL' => '+491705550101',
            'FIRMENNAME' => 'Example GmbH', 'FIRMEN_WEBSITE_HREF' => 'https://example.test', 'FIRMEN_WEBSITE_LABEL' => 'example.test',
            'FIRMENSTRASSE' => 'Musterweg 12', 'FIRMEN_PLZ_ORT' => '10115 Berlin'];
    }
}
