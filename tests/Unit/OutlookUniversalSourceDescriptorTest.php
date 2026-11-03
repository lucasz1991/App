<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookCompactReferencePresentation;
use App\Support\OutlookAddin\OutlookUniversalSourceDescriptor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OutlookUniversalSourceDescriptorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://mail.example.test']);
        Http::preventStrayRequests();
    }

    public function test_admitted_compiled_signature_uses_current_profile_dispatch_and_legal_text_without_author_content(): void
    {
        $source = $this->source('standalone');
        $output = OutlookUniversalSourceDescriptor::projectFromCompiled($source, $this->profile(), str_repeat('a', 64));
        self::assertNotNull($output);
        self::assertSame('Mara Muster', $output['descriptor']['identity']['name']);
        self::assertSame('Disposition', $output['descriptor']['identity']['role']);
        self::assertCount(3, $output['descriptor']['direct']);
        self::assertSame('mara@example.test', $output['descriptor']['direct'][0]['value']);
        self::assertSame('https://example.test', $output['descriptor']['company']['website']);
        self::assertStringContainsString('Beispielgericht', $output['descriptor']['company']['registration']);
        self::assertStringContainsString('vertrauliche Informationen', $output['descriptor']['company']['confidentiality']);
        self::assertSame('', $output['authorContent']->toHtml());
        self::assertSame(['showGreeting' => false, 'showFarewell' => false], $output['options']);
        Http::assertNothingSent();
    }

    public function test_combined_source_preserves_the_owned_author_paragraph_bytes_and_removes_only_its_ledger(): void
    {
        $source = $this->source('combined');
        preg_match('~<p class="design-spacer"[^>]*>.*?</p>~s', $source, $original);
        self::assertNotEmpty($original);
        $author = str_replace('<br><br>', '<span title="quoted &amp; safe">Exact &amp; unchanged</span><br>', $original[0]);
        $source = str_replace($original[0], $author, $source);
        $output = OutlookUniversalSourceDescriptor::projectFromCompiled($source, $this->profile(), str_repeat('a', 64));
        self::assertNotNull($output);
        self::assertStringContainsString($author, $output['authorContent']->toHtml());
        self::assertStringNotContainsString('RT-COMPOSE-EDITABLE-', $output['authorContent']->toHtml());
        self::assertStringNotContainsString('rt-office-metadata', $output['authorContent']->toHtml());
        self::assertSame(hash('sha256', $output['authorContent']->toHtml()), $output['sourceAuthorContentHash']);
    }

    public function test_optional_personal_wording_is_separated_once_and_disabled_wording_remains_absent(): void
    {
        $source = $this->source('combined');
        $needle = '<table class="rt-compose-editable rt-compose-editable-v1"';
        $before = '<p class="design-salutation">Hallo &amp; zusammen</p>';
        $after = '<p class="design-greeting">Beste Grüße<br>Mara Muster</p>';
        $source = str_replace($needle, $before.$needle, $source);
        $source = preg_replace('~(</td></tr></tbody></table>)(?=\s*</td>)~', '$1'.$after, $source, 1);
        $output = OutlookUniversalSourceDescriptor::projectFromCompiled($source, $this->profile(), str_repeat('a', 64));
        self::assertNotNull($output);
        self::assertSame(['showGreeting' => true, 'showFarewell' => true, 'greeting' => 'Hallo & zusammen', 'farewell' => 'Beste Grüße', 'farewellName' => true], $output['options']);
        self::assertStringNotContainsString('Hallo &amp;', $output['authorContent']->toHtml());
        self::assertStringNotContainsString('Beste Grüße', $output['authorContent']->toHtml());
        $plain = OutlookUniversalSourceDescriptor::projectFromCompiled($this->source('combined'), $this->profile(), str_repeat('a', 64));
        self::assertSame(['showGreeting' => false, 'showFarewell' => false], $plain['options']);
    }

    public function test_the_existing_canonical_v16_forward_contract_is_accepted_without_changing_author_data(): void
    {
        $source = $this->source('combined');
        $v16 = OutlookCompactReferencePresentation::canonicalizeToV16($source);
        $one = OutlookUniversalSourceDescriptor::projectFromCompiled($source, $this->profile(), str_repeat('a', 64));
        $two = OutlookUniversalSourceDescriptor::projectFromCompiled($v16, $this->profile(), str_repeat('a', 64));
        self::assertNotNull($two);
        self::assertSame($one['descriptor'], $two['descriptor']);
        self::assertSame($one['authorContent']->toHtml(), $two['authorContent']->toHtml());
    }

    public function test_unknown_custom_or_ambiguous_documents_do_not_claim_the_new_projection(): void
    {
        foreach (['<p>Ordinary authored source</p>', $this->source('combined').$this->source('standalone'),
            str_replace('rt-v17-dispatch-block', 'ordinary-card', $this->source('combined')),
            str_replace('width:100%;max-width:100%!important', 'width:17px;max-width:100%!important', $this->source('combined')),
            str_replace('RT-COMPOSE-EDITABLE-END-V1', 'OTHER-END', $this->source('combined'))] as $source) {
            self::assertNull(OutlookUniversalSourceDescriptor::projectFromCompiled($source, $this->profile(), str_repeat('a', 64)));
        }
    }

    public function test_profile_mismatch_invalid_hash_and_invalid_types_cannot_rebind_a_source(): void
    {
        foreach (['VORNAME_NACHNAME', 'POSITION', 'E_MAIL', 'FIRMENSTRASSE', 'FIRMEN_PLZ_ORT', 'FIRMEN_WEBSITE_LABEL'] as $key) {
            $values = $this->profile();
            $values[$key] = 'Different value';
            self::assertNull(OutlookUniversalSourceDescriptor::projectFromCompiled($this->source('standalone'), $values, str_repeat('a', 64)));
        }
        $values = $this->profile();
        $values['MOBIL'] = [];
        self::assertNull(OutlookUniversalSourceDescriptor::projectFromCompiled($this->source('standalone'), $values, str_repeat('a', 64)));
        self::assertNull(OutlookUniversalSourceDescriptor::projectFromCompiled($this->source('standalone'), $this->profile(), 'invalid'));
        foreach (['VORNAME_NACHNAME' => 'Mara', 'E_MAIL' => 'mara@example.tes', 'FIRMENSTRASSE' => 'Musterweg 1'] as $key => $prefix) {
            $values = $this->profile();
            $values[$key] = $prefix;
            self::assertNull(OutlookUniversalSourceDescriptor::projectFromCompiled($this->source('standalone'), $values, str_repeat('a', 64)));
        }
    }

    public function test_other_authored_content_is_not_silently_discarded(): void
    {
        foreach ([$this->source('combined').'<p>Custom footer</p>',
            str_replace('<table class="rt-compose-editable', '<p>Preserve this author sibling</p><table class="rt-compose-editable', $this->source('combined'))] as $index => $source) {
            $output = OutlookUniversalSourceDescriptor::projectFromCompiled($source, $this->profile(), str_repeat('a', 64));
            if ($index === 0) {
                self::assertNull($output);
            } else {
                self::assertNotNull($output);
                self::assertStringContainsString('<p>Preserve this author sibling</p>', $output['authorContent']->toHtml());
            }
        }
        $source = preg_replace('~(<div class="rt-combined-compose-document(?:\s[^"]*)?"[^>]*>)~', '$1<p>Custom header outside message</p>', $this->source('combined'), 1, $count);
        self::assertSame(1, $count);
        self::assertNull(OutlookUniversalSourceDescriptor::projectFromCompiled($source, $this->profile(), str_repeat('a', 64)));
    }

    private function source(string $kind): string
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/mail/outlook-v17-office-actions/configured-default.json')), true, 512, JSON_THROW_ON_ERROR)['source'][$kind];
    }

    private function profile(): array
    {
        return ['VORNAME_NACHNAME' => 'Mara Muster', 'POSITION' => 'Disposition', 'E_MAIL' => 'mara@example.test',
            'DURCHWAHL' => '+49 30 5550101', 'DURCHWAHL_TEL' => '+49305550101', 'MOBIL' => '+49 170 5550101', 'MOBIL_TEL' => '+491705550101',
            'FIRMENNAME' => 'Example GmbH', 'FIRMEN_WEBSITE_HREF' => 'https://example.test', 'FIRMEN_WEBSITE_LABEL' => 'example.test',
            'FIRMENSTRASSE' => 'Musterweg 12', 'FIRMEN_PLZ_ORT' => '10115 Berlin'];
    }
}
