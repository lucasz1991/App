<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\CompanyData;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\OutlookTemplateWording;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignaturePersonalColumns;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\SignatureTrainCarrier;
use App\Support\Mail\SystemMailInlineImageEmbedder;
use App\Support\Mail\TemplateDocumentContract;
use App\Support\MailSignature;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookAddinUserSnapshotStore;
use App\Support\OutlookAddin\OutlookClientPresentation;
use App\Support\OutlookAddin\OutlookDesktopFlow;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Parser\Parser;
use Symfony\Component\Mime\Email;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class V27PersonalLayoutTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $person;

    private UserProfile $profile;

    private MailDocument $system;

    private MailDocument $personal;

    private MailDocument $template;

    private MailDocument $variant;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        Http::preventStrayRequests();
        config(['outlook_addin.snapshots.auto_refresh' => false]);
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_08_09_000100_create_mail_documents_table.php', '2026_08_22_000200_create_mail_document_versions_table.php',
            '2026_08_27_000100_add_design_slots_to_mail_documents.php', '2026_09_06_010000_add_outlook_library_to_mail_documents.php',
            '2026_09_07_190000_separate_mail_document_delivery_channels.php', '2026_09_08_120000_add_mail_document_signature_pairing.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->person = User::withoutEvents(static fn () => User::factory()->create([
            'name' => 'Mara Muster', 'email' => 'mara@example.test', 'role' => 'admin', 'status' => true, 'email_verified_at' => now(),
        ]));
        $this->profile = UserProfile::withoutEvents(fn () => UserProfile::create([
            'user_id' => $this->person->id, 'first_name' => 'Mara', 'last_name' => 'Muster', 'position' => 'Disposition',
            'phone' => '+49 30 5550101', 'mobile' => '+49 170 5550101',
        ]));
        Setting::setValue('company', 'profile', array_replace(CompanyData::defaults(), [
            'name' => 'Beispiel Rail GmbH', 'street' => 'Musterweg 12', 'postal_code' => '10115', 'city' => 'Berlin',
            'phone' => '+49 30 5550000', 'emergency_phone' => '+49 170 5550202', 'email' => 'office@example.test',
            'website' => 'https://example.test', 'managing_directors' => 'M. Muster', 'register_court' => 'Beispielgericht',
            'commercial_register_number' => '123456', 'vat_id' => 'DE123456789', 'tax_number' => '00/000/00000',
        ]));
        $this->system = $this->publish(MailDocumentKind::Signature, $this->source(), '', [
            'id' => 9, 'name' => 'Synthetic system signature', 'is_active' => true,
        ]);
        $this->personal = $this->publish(MailDocumentKind::Signature, $this->source(personal: true), '', [
            'id' => 16, 'name' => 'Synthetic personal signature', 'is_active' => null, 'outlook_default' => true,
        ]);
        $this->template = $this->publish(MailDocumentKind::Template, self::TEMPLATE, self::TEMPLATE_CSS, [
            'id' => 12, 'name' => 'Synthetic default template', 'is_active' => true, 'outlook_default' => true,
            'outlook_released' => true, 'published_signature_document_id' => $this->personal->id,
            'signature_document_id' => $this->personal->id,
        ]);
        $withGreeting = preg_replace('~(<p class="design-spacer"[^>]*>.*?</p>)~s', '<p class="design-salutation">Guten Tag,</p>$1<p class="design-greeting">Mit freundlichen Grüßen,</p>', self::TEMPLATE, 1, $greetingCount);
        self::assertSame(1, $greetingCount);
        $this->variant = $this->publish(MailDocumentKind::Template, $withGreeting, self::TEMPLATE_CSS, [
            'id' => 15, 'name' => 'Synthetic offered template', 'is_active' => null, 'outlook_released' => true,
            'published_signature_document_id' => $this->personal->id, 'signature_document_id' => $this->personal->id,
        ]);
        $this->app->forgetScopedInstances();
    }

    public function test_separate_sources_are_valid_and_company_render_remains_exactly_the_unmodified_delivery_path(): void
    {
        foreach ([$this->source(), $this->source(personal: true)] as $source) {
            SignatureDocumentContract::assertValid($source);
            $clean = (new EmailHtmlSanitizer)->sanitize($source);
            self::assertFalse($clean->hasViolations());
            self::assertSame($source, $clean->html);
        }
        self::assertSame($this->source(personal: true), str_replace('design-train-logo', 'design-train-logo design-personal-columns', $this->source()));
        self::assertFalse(SignaturePersonalColumns::requested($this->source()));
        self::assertTrue(SignaturePersonalColumns::requested($this->source(personal: true)));
        $before = $this->storedSources();
        foreach (['light', 'dark'] as $theme) {
            $company = MailSignature::forCompany($theme, remoteAssets: true);
            $values = $company->valuesForDocument($this->source(), $this->cidValues());
            $tokens = [];
            foreach ($values as $name => $value) {
                $tokens['{{'.$name.'}}'] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            $resolved = strtr(SignatureTrainCarrier::withoutDecorativeBaseBackgrounds($this->source()), $tokens);
            $resolved = trim(EmailTemplateBuilder::stripEmptyContactRows($resolved, array_replace($values, ['FIRMEN_TELEFON' => '', 'FIRMEN_EMAIL' => ''])));
            $expected = SignatureTableOverlapDelivery::project($resolved, 'cid:train-still.png');
            $actual = $company->renderDocument($this->source(), overrides: $this->cidValues());
            self::assertSame($expected, $actual, 'The new personal runtime must never rewrite an unmarked system source.');
            self::assertStringNotContainsString('data-rt-personal-columns=', $actual);
            self::assertSame($actual, SignaturePersonalColumns::project($actual));
            $xpath = $this->xpath($actual);
            $brand = $this->one($xpath, 'rt-ledger-brand');
            self::assertSame(1, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]', $brand)->length);
        }
        self::assertSame($before, $this->storedSources());
        Http::assertNothingSent();
    }

    public function test_personal_wording_is_explicit_and_changes_all_variant_fields_but_no_signature_or_default_bytes(): void
    {
        $this->wordingVariant();
        $this->person->forceFill(['name' => 'Login Alias'])->saveQuietly();
        $service = app(OutlookAddinPayloadService::class);
        $beforeFingerprint = $service->sourceFingerprint($this->person->fresh());
        $before = $service->forUser($this->person->fresh());
        $rows = $this->storedSources();
        $this->wording('Hallo & willkommen,', 'Viele Grüße,');
        self::assertNotSame($beforeFingerprint, $service->sourceFingerprint($this->person->fresh()));
        $after = $service->forUser($this->person->fresh());
        self::assertSame($before['signature'], $after['signature']);
        self::assertSame($before['version']['signature'], $after['version']['signature']);
        self::assertSame($before['version']['template'], $after['version']['template']);
        $oldDefault = collect($before['templates'])->firstWhere('isDefault', true);
        self::assertSame($oldDefault, collect($after['templates'])->firstWhere('isDefault', true));
        $old = collect($before['templates'])->firstWhere('isDefault', false);
        $new = collect($after['templates'])->firstWhere('isDefault', false);
        self::assertNotSame($old['version'], $new['version']);
        self::assertNotSame($old['hash'], $new['hash']);
        self::assertSame($old['signature'], $new['signature']);
        foreach (['html', 'composeHtml', 'combinedComposeHtml'] as $field) {
            self::assertStringContainsString('Hallo &amp; willkommen,', $new[$field]);
            self::assertMatchesRegularExpression('~Viele Grüße,<br\s*/?>Mara Muster~u', $new[$field]);
            self::assertStringNotContainsString('Login Alias', $new[$field]);
            self::assertStringNotContainsString('OUTLOOK_ANREDE', $new[$field]);
            self::assertStringNotContainsString('OUTLOOK_GRUSS', $new[$field]);
        }
        $mobile = OutlookMobileSignature::payload($after);
        $mobileVariant = collect($mobile['templates'])->firstWhere('isDefault', false);
        self::assertStringContainsString('Hallo & willkommen,', html_entity_decode(strip_tags($mobileVariant['mobileComposeHtml']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertMatchesRegularExpression('~Viele Grüße,<br\s*/?>Mara Muster~u', $mobileVariant['mobileComposeHtml']);
        self::assertSame($rows, $this->storedSources());
        Http::assertNothingSent();
    }

    public function test_wording_preferences_do_not_change_any_unopted_r55_payload_or_fingerprint(): void
    {
        $service = app(OutlookAddinPayloadService::class);
        $beforeFingerprint = $service->sourceFingerprint($this->person->fresh());
        $before = $service->forUser($this->person->fresh());
        $this->wording('Andere Anrede,', 'Anderer Gruß,');
        self::assertSame($beforeFingerprint, $service->sourceFingerprint($this->person->fresh()));
        self::assertSame($before, $service->forUser($this->person->fresh()));
    }

    public function test_generic_template_defaults_and_system_mails_do_not_consume_personal_wording(): void
    {
        $source = $this->wordingVariant();
        $system = EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>System application</p>'));
        $this->wording('Individuelle Anrede,', 'Individueller Gruß,');
        self::assertSame($system, EmailTemplateBuilder::buildSystemMailHtml(new HtmlString('<p>System application</p>')));
        $store = app(PublishedMailDocumentSnapshotStore::class);
        $store->useSnapshot(MailDocumentKind::Template, $source, self::TEMPLATE_CSS);
        $builder = new class($this->person->fresh()) extends EmailTemplateBuilder
        {
            public function generic(): string
            {
                return $this->buildEmailHtml(inlineImages: false, cidOutlookImages: true);
            }
        };
        $generic = $builder->generic();
        self::assertStringContainsString('Guten Tag,', $generic);
        self::assertStringContainsString('Mit freundlichen Grüßen,<br>Mara Muster', $generic);
        self::assertStringNotContainsString('Individuelle Anrede,', $generic);
        self::assertStringNotContainsString('OUTLOOK_ANREDE', $generic);
        self::assertStringNotContainsString('OUTLOOK_GRUSS', $generic);
        $personal = $builder->buildOutlookAddinTemplateHtml();
        self::assertStringContainsString('Individuelle Anrede,', $personal);
        self::assertStringContainsString('Individueller Gruß,<br>Mara Muster', $personal);
    }

    public function test_new_wording_invalidates_the_encrypted_user_snapshot_without_changing_signature_version(): void
    {
        Storage::fake('private');
        config(['outlook_addin.snapshots.disk' => 'private']);
        $this->wordingVariant();
        $store = app(OutlookAddinUserSnapshotStore::class);
        $before = $store->currentForUser($this->person->fresh());
        self::assertTrue($store->isCurrentForUser($this->person->fresh()));
        $this->wording('Persönlich willkommen,', 'Beste Grüße,');
        self::assertFalse($store->isCurrentForUser($this->person->fresh()));
        $after = $store->currentForUser($this->person->fresh());
        self::assertNotSame($before['version']['personal'], $after['version']['personal']);
        self::assertSame($before['version']['signature'], $after['version']['signature']);
        self::assertSame($before['signature'], $after['signature']);
        self::assertTrue($store->isCurrentForUser($this->person->fresh()));
    }

    public function test_optin_is_sanitizer_preserved_and_half_pairs_are_rejected_before_publication(): void
    {
        $source = $this->wordingVariant();
        $clean = (new EmailHtmlSanitizer)->sanitize($source);
        self::assertFalse($clean->hasViolations());
        self::assertSame($source, $clean->html);
        TemplateDocumentContract::assertValid($source);
        $this->expectException(RuntimeException::class);
        TemplateDocumentContract::assertValid(str_replace(OutlookTemplateWording::FAREWELL_TOKEN, 'Literal closing', $source));
    }

    public function test_maximum_plain_wording_preserves_every_character_within_unchanged_native_and_mobile_budgets(): void
    {
        $this->wordingVariant();
        foreach ([str_repeat('A', 120), str_repeat('&', 120), str_repeat('"', 120), str_repeat("'", 120),
            str_repeat('😀', 120), str_repeat('&a', 60), str_repeat('&copy;', 20), str_repeat('&notit;', 17)] as $wording) {
            $this->wording($wording, $wording);
            $canonical = app(OutlookAddinPayloadService::class)->forUser($this->person->fresh());
            $desktop = OutlookClientPresentation::payload($canonical);
            $mobile = OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical));
            foreach ([$desktop, $mobile] as $payload) {
                $variant = collect($payload['templates'])->firstWhere('isDefault', false);
                $field = isset($variant['mobileComposeHtml']) ? 'mobileComposeHtml' : 'combinedComposeHtml';
                $text = html_entity_decode(strip_tags($variant[$field]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                self::assertSame(2, substr_count($text, $wording));
                if ($field === 'mobileComposeHtml') {
                    self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($variant[$field], 'UTF-16LE', 'UTF-8')), 2));
                }
                self::assertStringContainsString('overflow-wrap:anywhere', $variant[$field]);
                self::assertStringContainsString('word-break:break-word', $variant[$field]);
            }
        }
    }

    private function wordingVariant(): string
    {
        $source = preg_replace('~(<p class="design-spacer"[^>]*>.*?</p>)~s',
            '<p class="design-salutation">{{OUTLOOK_ANREDE}}</p>$1<p class="design-greeting">{{OUTLOOK_GRUSS}}</p>', self::TEMPLATE, 1, $count);
        self::assertSame(1, $count);
        $this->variant->forceFill(['html' => $source, 'published_html' => $source])->saveQuietly();
        app(PublishedMailDocumentSnapshotStore::class)->forget(MailDocumentKind::Template);

        return $source;
    }

    private function wording(string $greeting, string $farewell): void
    {
        Setting::setValue('outlook_addin', 'template_wording_user_'.$this->person->id,
            ['schema' => 1, 'greeting' => $greeting, 'farewell' => $farewell, 'nonce' => (string) Str::uuid()]);
    }

    public function test_personal_default_serves_both_html_download_themes_and_copy_while_source_rows_stay_immutable(): void
    {
        $before = $this->storedSources();
        $builder = new EmailTemplateBuilder($this->person->fresh());
        foreach (['light', 'dark'] as $theme) {
            foreach ([
                MailSignature::forUser($this->person->fresh(), $theme, animated: true, remoteAssets: true)->render(),
                $builder->build($theme === 'light' ? 'signatur-hell' : 'signatur-dunkel')['content'],
                $builder->buildSignatureCopyHtml($theme),
                $builder->build($theme === 'light' ? 'vorlage-html' : 'vorlage-dunkel-html')['content'],
            ] as $html) {
                $this->assertPersonalGroups($html);
                $this->assertFullTrain($html);
                self::assertStringContainsString('design-hotline-spaced', $html);
            }
        }
        self::assertSame($before, $this->storedSources());
        Http::assertNothingSent();
    }

    public function test_system_mail_ignores_employee_pairing_and_addin_temporarily_restores_the_company_snapshot(): void
    {
        $before = MailSignature::forCompany()->render();
        $mail = (new MailMessage)->greeting('Synthetic system message')->line('Only the company signature belongs here.');
        $compiled = (string) app(Markdown::class)->render($mail->markdown ?: 'notifications::email', $mail->data());
        self::assertStringNotContainsString('data-rt-personal-columns=', $compiled);
        self::assertStringNotContainsString('mara@example.test', $compiled);
        self::assertStringContainsString('Beispiel Rail GmbH', $compiled);
        $email = (new Email)->from('sender@example.test')->to('recipient@example.test')->subject('Synthetic')->html($compiled);
        self::assertGreaterThan(0, app(SystemMailInlineImageEmbedder::class)->embed($email));
        $this->assertAttachedCids((string) $email->getHtmlBody(), array_map(static fn ($part) => ['contentId' => $part->getContentId(), 'base64' => base64_encode($part->getBody())], $email->getAttachments()));
        app(OutlookAddinPayloadService::class)->forUser($this->person->fresh());
        self::assertSame($before, MailSignature::forCompany()->render(), 'A paired add-in render must not leak its signature override into the company scope.');
        Http::assertNothingSent();
    }

    public function test_get_editor_preview_personalizes_only_the_explicit_personal_source_without_saving(): void
    {
        $before = $this->storedSources();
        $personal = $this->actingAs($this->person)->get(route('admin.mail-documents.preview', $this->personal))
            ->assertOk();
        $this->assertPersonalGroups($personal->getContent());
        $personal->assertSee('Mara Muster')->assertSee('mara@example.test')->assertSee('data-rt-personal-columns="v1"', escape: false);
        $this->actingAs($this->person)->get(route('admin.mail-documents.preview', $this->system))
            ->assertOk()->assertDontSee('mara@example.test')->assertDontSee('data-rt-personal-columns=', escape: false);
        self::assertSame($before, $this->storedSources());
        Http::assertNothingSent();
    }

    public function test_explicit_source_and_template_pairing_override_the_personal_default_without_changing_defaults(): void
    {
        $before = $this->storedSources();
        $store = app(PublishedMailDocumentSnapshotStore::class);
        $store->useSnapshot(MailDocumentKind::Signature, $this->source(), '');
        self::assertStringNotContainsString('data-rt-personal-columns=', MailSignature::forUser($this->person->fresh())->render());
        $store->forget(MailDocumentKind::Signature);
        $this->assertPersonalGroups(MailSignature::forUser($this->person->fresh())->render());
        MailDocument::withoutEvents(fn () => $this->variant->update(['signature_document_id' => $this->system->id, 'published_signature_document_id' => $this->system->id]));
        $this->app->forgetScopedInstances();
        $payload = app(OutlookAddinPayloadService::class)->forUser($this->person->fresh());
        $this->assertPersonalGroups($payload['signature']['html']);
        $paired = collect($payload['templates'])->firstWhere('id', $this->variant->public_id);
        self::assertNotNull($paired);
        self::assertStringNotContainsString('data-rt-personal-columns=', $paired['signature']['html']);
        $wire = OutlookClientPresentation::payload($payload);
        $systemPair = collect($wire['templates'])->firstWhere('id', $this->variant->public_id);
        self::assertTrue(OutlookDesktopFlow::projected($systemPair['signature']['html']), 'The versioned signature frame may carry the same trusted scope as its DIV root.');
        self::assertSame(1, preg_match('/\brts[0-9a-f]{10}\b/', $paired['signature']['html'], $scope));
        $this->assertNativeRuntimeRejected($paired['signature']['html'].'<div class="'.$scope[0].'"></div>');
        self::assertTrue($this->system->fresh()->is_active);
        self::assertNull($this->system->fresh()->outlook_default);
        self::assertTrue($this->personal->fresh()->outlook_default);
        self::assertNull($this->personal->fresh()->is_active);
        MailDocument::withoutEvents(fn () => $this->variant->update(['signature_document_id' => $this->personal->id, 'published_signature_document_id' => $this->personal->id]));
        self::assertSame($before, $this->storedSources());
        Http::assertNothingSent();
    }

    public function test_native_desktop_mobile_and_both_combined_variants_preserve_groups_cids_and_full_asset_bytes(): void
    {
        $before = $this->storedSources();
        $canonical = app(OutlookAddinPayloadService::class)->forUser($this->person->fresh());
        $canonicalBefore = $canonical;
        $desktop = OutlookClientPresentation::payload($canonical);
        $mobile = OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical));
        self::assertSame($canonicalBefore, $canonical);
        self::assertStringNotContainsString('rt-train-window', $canonical['signature']['html']);
        self::assertStringNotContainsString('rt-train-window', $mobile['signature']['html']);
        self::assertSame($this->msoTrain($canonical['signature']['html']), $this->msoTrain($desktop['signature']['html']));
        self::assertSame($this->msoTrain($canonical['signature']['html']), $this->msoTrain($mobile['signature']['html']));
        $this->assertPersonalGroups($desktop['signature']['html']);
        $this->assertPersonalGroups($mobile['signature']['html']);
        $this->assertBudget($desktop['signature']['html'], 30000, 12288);
        $this->assertBudget($mobile['signature']['html'], 30000, 12288);
        $this->assertAttachedCids($desktop['signature']['html'], $desktop['signature']['media']);
        $this->assertAttachedCids($mobile['signature']['html'], $mobile['signature']['media']);
        $this->assertOriginalTrainMedia($desktop['signature']['media']);
        self::assertSame($canonical['signature']['media'], $desktop['signature']['media']);
        self::assertSame($canonical['signature']['media'], $mobile['signature']['media']);
        self::assertTrue(OutlookDesktopFlow::projected($desktop['signature']['html']));
        self::assertFalse(OutlookDesktopFlow::projected($mobile['signature']['html']));
        self::assertSame($desktop['signature']['html'], OutlookDesktopFlow::project($desktop['signature']['html']));
        SignaturePersonalColumns::assertRuntime($desktop['signature']['html']);
        $nativeXpath = $this->xpath($desktop['signature']['html']);
        $nativeContainer = $this->one($nativeXpath, 'rt-personal-columns');
        self::assertSame('table', $nativeContainer->tagName);
        self::assertSame('0', $nativeContainer->getAttribute('cellpadding'));
        self::assertSame('0', $nativeContainer->getAttribute('cellspacing'));
        $nativeCell = $nativeXpath->query('./tbody/tr/td', $nativeContainer)->item(0);
        self::assertInstanceOf(DOMElement::class, $nativeCell);
        foreach ([SignaturePersonalColumns::NATIVE_FLOW_ATTRIBUTE, 'data-rt-desktop-flow'] as $attribute) {
            $invalid = $this->xpath($desktop['signature']['html']);
            $this->one($invalid, 'rt-personal-columns')->removeAttribute($attribute);
            $this->assertNativeRuntimeRejected($invalid->document->saveHTML());
        }
        $invalid = $this->xpath($desktop['signature']['html']);
        $invalidContainer = $this->one($invalid, 'rt-personal-columns');
        $invalid->query('./tbody/tr/td', $invalidContainer)->item(0)->insertBefore($this->one($invalid, 'rt-df-train'), $this->one($invalid, 'rt-personal-right'));
        $this->assertNativeRuntimeRejected($invalid->document->saveHTML());
        foreach (['rt-personal-logo-column', 'rt-personal-left', 'rt-personal-right', 'rt-df-train'] as $class) {
            $item = $this->one($nativeXpath, $class);
            self::assertTrue($item->parentNode->isSameNode($class === 'rt-df-train' ? $nativeXpath->query('./tbody/tr[2]/td', $nativeContainer)->item(0) : $nativeCell));
            self::assertSame('100%', $item->getAttribute('width'));
            self::assertStringContainsString('display:'.(in_array($class, ['rt-personal-left', 'rt-df-train'], true) ? 'inline-table' : 'inline-block').';', $item->getAttribute('style'), 'Without STYLE blocks, inline groups retain their natural reading order.');
            self::assertStringContainsString('width:100%;min-width:0;max-width:340px;', $item->getAttribute('style'));
        }
        foreach (['rt-personal-company-block' => '220', 'rt-personal-logo-frame' => '200'] as $class => $maximum) {
            $table = $this->one($nativeXpath, $class);
            self::assertSame($maximum, $table->getAttribute('width'));
            self::assertStringContainsString('width:100%;max-width:'.$maximum.'px;table-layout:fixed;', $table->getAttribute('style'));
        }
        preg_match_all('~<!--\[if mso\]>(.*?)<!\[endif\]-->~s', $canonical['signature']['html'], $canonicalMso);
        preg_match_all('~<!--\[if mso\]>(.*?)<!\[endif\]-->~s', $desktop['signature']['html'], $desktopMso);
        $logoFallbacks = array_values(array_filter($canonicalMso[1], static fn (string $branch): bool => preg_match('~\A\s*<img\b[^>]*\bclass="rt-logo"[^>]*>\s*\z~', $branch) === 1));
        self::assertCount(1, $logoFallbacks);
        self::assertSame(array_values(array_diff($canonicalMso[1], $logoFallbacks)), $desktopMso[1], 'Only the proven equal PNG logo fallback is removed; the complete train and other MSO branches stay byte-identical.');
        foreach ($desktop['templates'] as $index => $item) {
            $html = $item['combinedComposeHtml'];
            $this->assertPersonalGroups($html);
            $this->assertFullTrain($html);
            $this->assertAttachedCids($html, $item['combinedComposeMedia']);
            $this->assertBudget($html, 99000, 24576);
            $mobileHtml = $mobile['templates'][$index]['mobileComposeHtml'];
            $this->assertPersonalGroups($mobileHtml);
            $this->assertFullTrain($mobileHtml);
            $this->assertAttachedCids($mobileHtml, $mobile['templates'][$index]['mobileComposeMedia']);
            $this->assertBudget($mobileHtml, 30000, 12288);
        }
        self::assertSame($before, $this->storedSources());
        Http::assertNothingSent();
    }

    public function test_optional_phone_rows_and_long_personal_values_preserve_the_two_complete_groups(): void
    {
        foreach ([false, true] as $long) {
            $first = $long ? 'Mara Christina' : 'Mara';
            $last = $long ? 'von Muster-Lange und Beispielhausen' : 'Muster';
            $email = $long ? 'mara.christina.fernverkehr-disposition.betriebssteuerung@example.test' : 'mara@example.test';
            User::withoutEvents(fn () => $this->person->update(['name' => $first.' '.$last, 'email' => $email]));
            UserProfile::withoutEvents(fn () => $this->profile->update([
                'first_name' => $first, 'last_name' => $last,
                'position' => $long ? 'Leitung Disposition und internationale Betriebssteuerung' : '',
                'phone' => $long ? '+49 30 555010112345678901234567890' : '', 'mobile' => '',
            ]));
            $html = MailSignature::forUser($this->person->fresh(), animated: true, remoteAssets: true)->render();
            $this->assertPersonalGroups($html, $first.' '.$last, $email);
            $xpath = $this->xpath($html);
            $left = $this->one($xpath, 'rt-personal-left');
            self::assertSame($long ? 2 : 1, $xpath->query('.//table[contains(concat(" ",normalize-space(@class)," ")," rt-contact ")]//tr', $left)->length);
            $payload = app(OutlookAddinPayloadService::class)->forUser($this->person->fresh());
            foreach ([OutlookClientPresentation::payload($payload), OutlookClientPresentation::payload(OutlookMobileSignature::payload($payload))] as $wire) {
                $this->assertPersonalGroups($wire['signature']['html'], $first.' '.$last, $email);
                $this->assertBudget($wire['signature']['html'], 30000, 12288);
            }
        }
        Http::assertNothingSent();
    }

    public function test_personal_runtime_is_idempotent_legacy_bypasses_and_duplicate_groups_are_rejected(): void
    {
        $html = MailSignature::forUser($this->person->fresh(), animated: true, remoteAssets: true)->render();
        self::assertTrue(SignaturePersonalColumns::applies($html));
        SignaturePersonalColumns::assertRuntime($html);
        self::assertSame($html, SignaturePersonalColumns::project($html));
        $css = SignaturePersonalColumns::css($html);
        $xpath = $this->xpath($html);
        self::assertStringContainsString('width:100%;max-width:920px;', $this->one($xpath, 'rt-personal-columns')->getAttribute('style'));
        self::assertMatchesRegularExpression('~\.rt-personal-columns\{(?=[^}]*width:100%!important;)(?=[^}]*max-width:920px!important;)(?=[^}]*text-align:left!important;)[^}]*\}~', $css);
        self::assertMatchesRegularExpression('~\.rt-personal-company-block\{float:none!important;margin:0!important;\}~', $css);
        foreach (['left', 'right'] as $side) {
            self::assertStringContainsString('padding:0 40px;', $this->one($xpath, 'rt-personal-cell-'.$side)->getAttribute('style'));
            self::assertMatchesRegularExpression('~\.rt-personal-cell-'.$side.'\{padding:0 40px!important;[^}]*\}~', $css);
        }
        self::assertMatchesRegularExpression('~table\.rt-personal-logo-frame\{(?=[^}]*width:200px!important;)(?=[^}]*max-width:100%!important;)[^}]*\}~', $css);
        self::assertSame(1, preg_match('~@media only screen and \(max-width:560px\)\{((?:[^{}]+\{[^{}]*\})+)\}~', $css, $narrow));
        self::assertStringContainsString('.rt-personal-column{width:100%!important;min-width:0!important;}', $narrow[1]);
        self::assertStringContainsString('.rt-personal-cell-left{padding:0 22px!important;}', $narrow[1]);
        self::assertStringContainsString('.rt-personal-cell-right{padding:24px 22px 0!important;}', $narrow[1]);
        self::assertStringContainsString('.rt-personal-company-block{float:none!important;margin:0!important;}', $narrow[1]);
        $unmarked = MailSignature::forCompany()->render();
        self::assertFalse(SignaturePersonalColumns::applies($unmarked));
        self::assertSame($unmarked, SignaturePersonalColumns::project($unmarked));
        $legacy = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27.html')));
        foreach ([
            $this->source().'<p class="design-personal-columns">Unrelated fixture note</p>',
            str_replace('design-personal-columns', 'design-personal-columns-other', $this->source(personal: true)),
        ] as $unrelated) {
            self::assertFalse(SignaturePersonalColumns::requested($unrelated));
            self::assertSame($unrelated, SignaturePersonalColumns::project($unrelated));
        }
        foreach (['v27', 'v28', 'v29'] as $version) {
            $other = str_replace('v27', $version, $legacy);
            self::assertFalse(SignaturePersonalColumns::applies($other));
            self::assertSame($other, SignaturePersonalColumns::project($other));
        }
        $xpath = $this->xpath($html);
        $right = $this->one($xpath, 'rt-personal-right');
        $right->parentNode->appendChild($right->cloneNode(true));
        $this->expectException(RuntimeException::class);
        SignaturePersonalColumns::assertRuntime($xpath->document->saveHTML());
    }

    public function test_noinline_keeps_the_same_groups_all_style_blocks_and_native_transport_bounds(): void
    {
        $canonical = app(OutlookAddinPayloadService::class)->forUser($this->person->fresh());
        foreach ([OutlookClientPresentation::payload($canonical), OutlookClientPresentation::payload(OutlookMobileSignature::payload($canonical))] as $wire) {
            $html = $wire['signature']['html'];
            $stripTag = static fn (string $tag): string => preg_replace('~\sstyle=(?:"[^"]*"|\'[^\']*\')~i', '', $tag);
            $noInline = preg_replace_callback('~<style\b[^>]*>.*?</style\s*>|<!--.*?-->|</?[a-z][^>]*>~is', static function ($match) use ($stripTag): string {
                if (str_starts_with(strtolower($match[0]), '<style')) {
                    return $match[0];
                }

                return str_starts_with($match[0], '<!--') ? preg_replace_callback('~</?[a-z][^>]*>~is', static fn ($tag) => $stripTag($tag[0]), $match[0]) : $stripTag($match[0]);
            }, $html);
            preg_match_all('~<style\b[^>]*>.*?</style\s*>~is', $html, $before);
            preg_match_all('~<style\b[^>]*>.*?</style\s*>~is', $noInline, $after);
            self::assertSame($before[0], $after[0]);
            self::assertSame($this->imageSources($html), $this->imageSources($noInline));
            $this->assertPersonalGroups($noInline);
            $this->assertBudget($noInline, 30000, 12288);
            if (str_contains($html, 'data-rt-mobile-hybrid="v1"')) {
                $styleless = preg_replace('~<style\b[^>]*>.*?</style\s*>~is', '', $html);
                $this->assertMobileHybridGroups($html, true);
                $this->assertMobileHybridGroups($styleless, true);
                $this->assertMobileHybridGroups($noInline, false);
                self::assertSame($this->imageSources($html), $this->imageSources($styleless));
                self::assertSame($this->msoTrain($html), $this->msoTrain($styleless));
                $this->assertPersonalGroups($styleless);
                continue;
            }
            self::assertStringContainsString(OutlookDesktopFlow::projected($html) ? 'padding-right:24px!important' : 'padding-right:18px!important', $html);
            $xpath = $this->xpath($noInline);
            $company = $this->one($xpath, 'rt-personal-company-block');
            $container = $this->one($xpath, 'rt-personal-columns');
            self::assertSame('0', $this->winningExplicitRule($xpath, $company, 'margin'));
            self::assertSame('none', $this->winningExplicitRule($xpath, $company, 'float'));
            self::assertSame('100%', $this->winningExplicitRule($xpath, $container, 'width'));
            self::assertSame(OutlookDesktopFlow::projected($html) ? '680px' : '920px', $this->winningExplicitRule($xpath, $container, 'max-width'));
            self::assertSame('14px', $this->winningExplicitRule($xpath, $this->one($xpath, 'rt-personal-direct'), 'padding-top'));
            self::assertSame(OutlookDesktopFlow::projected($html) ? '0' : '14px', $this->winningExplicitRule($xpath, $this->one($xpath, 'rt-personal-company-contacts'), 'padding-top'));
            if (OutlookDesktopFlow::projected($html)) {
                $window = $this->one($xpath, 'rt-train-window');
                $image = $this->one($xpath, 'rt-delivery-train');
                self::assertTrue($image->parentNode->isSameNode($window));
                self::assertSame('', $window->getAttribute('dir'));
                self::assertSame('hidden', $this->winningExplicitRule($xpath, $window, 'overflow'));
                self::assertNull($this->winningExplicitRule($xpath, $window, 'direction'));
                self::assertSame('100%', $this->winningExplicitRule($xpath, $image, 'width'));
                self::assertSame('100%', $this->winningExplicitRule($xpath, $image, 'max-width'));
                self::assertSame('auto', $this->winningExplicitRule($xpath, $image, 'height'));
                self::assertNull($this->winningExplicitRule($xpath, $window, 'max-width'));
                self::assertSame('9.2%', $this->winningExplicitRule($xpath, $window, 'padding-top'));
                self::assertSame('scale(1.733333)', $this->winningExplicitRule($xpath, $image, 'transform'));
                self::assertSame('table', $this->winningExplicitRule($xpath, $container, 'display'));
                self::assertSame('hidden', $this->winningExplicitRule($xpath, $container, 'overflow'));
                self::assertSame('ltr', $this->winningExplicitRule($xpath, $container, 'direction'));
                self::assertSame('0 auto 0 0', $this->winningExplicitRule($xpath, $container, 'margin'));
                self::assertSame('ltr', $container->getAttribute('dir'));
                $flowCell = $xpath->query('./tbody/tr/td|./tr/td', $container)->item(0);
                self::assertSame('ltr', $flowCell->getAttribute('dir'));
                self::assertSame('left', $flowCell->getAttribute('align'));
                foreach (['rt-personal-logo-column', 'rt-personal-left', 'rt-personal-right', 'rt-df-train'] as $class) {
                    $item = $this->one($xpath, $class);
                    self::assertSame('100%', $this->winningExplicitRule($xpath, $item, 'width'));
                    self::assertSame('0', $this->winningExplicitRule($xpath, $item, 'min-width'));
                    self::assertSame('340px', $this->winningExplicitRule($xpath, $item, 'max-width'));
                    self::assertNull($this->winningExplicitRule($xpath, $item, 'float'));
                }
                $train = $this->one($xpath, 'rt-df-train');
                self::assertNull($this->winningExplicitRule($xpath, $train, 'clear'));
                self::assertNull($this->winningExplicitRule($xpath, $train, 'position'));
                self::assertNull($this->winningExplicitRule($xpath, $train, 'top'));
                self::assertStringNotContainsString('display:grid', $html);
                self::assertStringNotContainsString('grid-column:', $html);
                self::assertStringNotContainsString('width:calc(', $html);
                foreach (['rt-personal-cell-logo', 'rt-personal-cell-left', 'rt-personal-cell-right'] as $class) {
                    self::assertSame('0', $this->winningExplicitRule($xpath, $this->one($xpath, $class), 'padding'));
                    self::assertSame('40px solid transparent', $this->winningExplicitRule($xpath, $this->one($xpath, $class)->parentNode->parentNode->parentNode, 'border-left'), 'Native gutters belong to their responsive TABLE owner.');
                }
                foreach (['rt-personal-company-block' => '220px', 'rt-personal-logo-frame' => '200px', 'rt-logo' => '200px'] as $class => $maximum) {
                    $node = $this->one($xpath, $class);
                    self::assertSame('100%', $this->winningExplicitRule($xpath, $node, 'width'), 'Nested native logo geometry must shrink inside its fluid column.');
                    self::assertSame($maximum, $this->winningExplicitRule($xpath, $node, 'max-width'));
                    self::assertSame($class === 'rt-logo' ? 'auto' : 'fixed', $this->winningExplicitRule($xpath, $node, $class === 'rt-logo' ? 'height' : 'table-layout'));
                }
                $noSticky = $this->xpath(str_replace('position:sticky!important;', '', $noInline));
                self::assertNull($this->winningExplicitRule($noSticky, $this->one($noSticky, 'rt-df-train'), 'position'));
                self::assertNull($this->winningExplicitRule($noSticky, $this->one($noSticky, 'rt-df-train'), 'clear'));
            } else {
                self::assertSame(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-train-window ")]')->length);
            }
            if (! OutlookDesktopFlow::projected($html)) {
                $name = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-sign-name ") or contains(concat(" ",normalize-space(@class)," ")," xf ")]')->item(0);
                $role = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-person-kopf ") or contains(concat(" ",normalize-space(@class)," ")," xi ")]/p[2]')->item(0);
                self::assertInstanceOf(DOMElement::class, $name);
                self::assertInstanceOf(DOMElement::class, $role);
                self::assertSame('22px', $this->winningExplicitRule($xpath, $name, 'font-size'));
                self::assertSame('26px', $this->winningExplicitRule($xpath, $name, 'line-height'));
                self::assertSame('10px', $this->winningExplicitRule($xpath, $role, 'font-size'));
                self::assertSame('16px', $this->winningExplicitRule($xpath, $role, 'line-height'));
            }
        }
    }

    /** Compare the retained matching font rules, including important alias mirrors. */
    private function winningExplicitRule(DOMXPath $xpath, DOMElement $target, string $property): ?string
    {
        $winner = null;
        $priority = [-1, -1, -1];
        $order = 0;
        $converter = new CssSelectorConverter;
        $parser = new Parser;
        foreach ($xpath->query('//style') as $style) {
            // This helper evaluates the desktop identity. Responsive native
            // geometry is asserted separately at the explicit narrow rule.
            $css = $style->hasAttribute('data-rt-desktop-flow-css')
                ? preg_replace('~@media\b[^{}]*\{(?:[^{}]*\{[^{}]*\})*\s*\}~s', '', $style->textContent)
                : $style->textContent;
            preg_match_all('~([^{}]+)\{([^{}]*)\}~s', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                foreach (explode(',', trim($rule[1])) as $selector) {
                    try {
                        $nodes = $xpath->query($converter->toXPath($selector));
                        $specificity = $parser->parse($selector)[0]->getSpecificity()->getValue();
                    } catch (\Throwable) {
                        continue;
                    }
                    $matches = false;
                    foreach ($nodes as $node) {
                        $matches = $matches || $node->isSameNode($target);
                    }
                    if (! $matches) {
                        continue;
                    }
                    preg_match_all('~(?:^|;)\s*'.preg_quote($property, '~').'\s*:\s*([^;]+)~i', $rule[2], $values);
                    foreach ($values[1] as $value) {
                        $important = preg_match('/\s*!important\s*$/i', $value) === 1;
                        $current = [(int) $important, $specificity, ++$order];
                        if ($current >= $priority) {
                            $priority = $current;
                            $winner = trim(preg_replace('/\s*!important\s*$/i', '', $value));
                        }
                    }
                }
            }
        }

        return $winner;
    }

    private function assertPersonalGroups(string $html, string $name = 'Mara Muster', string $email = 'mara@example.test'): void
    {
        $xpath = $this->xpath($html);
        $hybrid = str_contains($html, 'data-rt-mobile-hybrid="v1"');
        $left = $this->one($xpath, $hybrid ? 'rt-mhy-person' : 'rt-personal-left');
        $right = $this->one($xpath, $hybrid ? 'rt-mhy-company' : 'rt-personal-right');
        self::assertStringContainsString($name, preg_replace('/\s+/u', ' ', $left->textContent));
        self::assertStringContainsString($email, $left->textContent);
        self::assertStringNotContainsString('office@example.test', $left->textContent);
        self::assertStringContainsString('office@example.test', $right->textContent);
        self::assertStringContainsString('Musterweg 12', $right->textContent);
        self::assertStringNotContainsString($email, $right->textContent);
        $mobile = OutlookDesktopFlow::mobile($html);
        $native = OutlookDesktopFlow::projected($html);
        $logoOwner = $hybrid ? $this->one($xpath, 'rt-mhy-logo') : ($native ? $this->one($xpath, 'rt-personal-logo-column') : ($mobile ? $left : $right));
        self::assertSame($mobile && ! $hybrid ? 1 : 0, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ") or contains(concat(" ",normalize-space(@class)," ")," rt-client-logo ")]', $left)->length);
        self::assertSame($mobile || $native || $hybrid ? 0 : 1, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ") or contains(concat(" ",normalize-space(@class)," ")," rt-client-logo ")]', $right)->length);
        $logoFrame = $this->one($xpath, 'rt-personal-logo-frame');
        self::assertSame('table', $logoFrame->tagName);
        self::assertSame('200', $logoFrame->getAttribute('width'));
        self::assertSame('presentation', $logoFrame->getAttribute('role'));
        self::assertSame(1, $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," rt-personal-logo-frame ")]', $logoOwner)->length);
        self::assertSame('left', $this->one($xpath, 'rt-personal-company-block')->getAttribute('align'));
        $companyContacts = $this->one($xpath, 'rt-personal-company-contacts');
        if ($companyContacts->hasAttribute('style')) {
            self::assertStringContainsString('padding-top:'.($native ? '0' : ($hybrid ? '4px!important;' : '14px')), $companyContacts->getAttribute('style'));
        }
        if ($hybrid) {
            $this->assertMobileHybridGroups($html, $left->hasAttribute('style'));
        }
        self::assertSame(1, $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," design-hotline-row ") and (contains(concat(" ",normalize-space(@class)," ")," design-hotline-spaced ") or @data-rt-brand-hotline="v1")]')->length);
        self::assertStringNotContainsString('data-rt-train-bottom-overlay=', $html);
    }

    /** STYLE-free delivery keeps inline geometry; total inline removal has only the structural contract. */
    private function assertMobileHybridGroups(string $html, bool $inline): void
    {
        $xpath = $this->xpath($html);
        $flow = $this->one($xpath, 'rt-mobile-hybrid-v1');
        self::assertSame('v1', $flow->getAttribute('data-rt-mobile-hybrid'));
        self::assertSame('v1', $flow->getAttribute('data-rt-personal-columns'));
        self::assertSame('ltr', $flow->getAttribute('dir'));
        self::assertCount(2, $xpath->query('./tbody/tr|./tr', $flow));
        $cells = $xpath->query('./tbody/tr/td|./tr/td', $flow);
        self::assertCount(2, $cells);
        foreach ($cells as $cell) {
            self::assertSame('ltr', $cell->getAttribute('dir'));
            self::assertSame('left', $cell->getAttribute('align'));
        }
        self::assertCount(3, $xpath->query('./table', $cells->item(0)));
        self::assertCount(1, $xpath->query('./table', $cells->item(1)));
        $groups = $xpath->query('./tbody/tr/td/table|./tr/td/table', $flow);
        self::assertCount(4, $groups);
        if ($inline) {
            self::assertStringContainsString('max-width:680px;', $flow->getAttribute('style'));
            self::assertStringContainsString('text-align:left;', $flow->getAttribute('style'));
        }
        foreach (['logo', 'person', 'company', 'train'] as $index => $kind) {
            $group = $this->one($xpath, 'rt-mhy-'.$kind);
            self::assertTrue($group->isSameNode($groups->item($index)), 'Mobile reading order stays Logo, Person, Company, Train.');
            self::assertTrue($group->parentNode->isSameNode($cells->item($kind === 'train' ? 1 : 0)));
            self::assertSame('ltr', $group->getAttribute('dir'));
            self::assertSame('100%', $group->getAttribute('width'));
            $groupCells = $xpath->query('./tbody/tr/td|./tr/td', $group);
            self::assertCount(1, $groupCells);
            self::assertSame('ltr', $groupCells->item(0)->getAttribute('dir'));
            self::assertSame('left', $groupCells->item(0)->getAttribute('align'));
            if ($kind === 'person') {
                self::assertSame('left', $group->getAttribute('align'));
                self::assertSame('50', $group->getAttribute('height'));
            }
            if (! $inline) {
                continue;
            }
            self::assertStringContainsString('display:'.(in_array($kind, ['person', 'train'], true) ? 'inline-table' : 'inline-block').';', $group->getAttribute('style'));
            self::assertStringContainsString('width:100%;max-width:340px;', $group->getAttribute('style'));
            self::assertStringContainsString('border-width:0 18px;', $group->getAttribute('style'));
            self::assertStringContainsString('vertical-align:'.($kind === 'train' ? 'bottom' : 'top').';', $group->getAttribute('style'));
            self::assertDoesNotMatchRegularExpression('/(?:^|;)\s*(?:clear|position|top)\s*:|float\s*:\s*right|\b(?:min|max|calc)\s*\(/i', $group->getAttribute('style'));
            if ($kind === 'person') {
                self::assertStringContainsString('float:left;', $group->getAttribute('style'));
            } else {
                self::assertDoesNotMatchRegularExpression('/(?:^|;)\s*float\s*:/i', $group->getAttribute('style'));
            }
            if (in_array($kind, ['person', 'company'], true)) {
                self::assertStringContainsString('font-size:13px;line-height:18px;', $groupCells->item(0)->getAttribute('style'));
            }
        }
    }

    private function assertFullTrain(string $html): void
    {
        $xpath = $this->xpath($html);
        $images = $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train ") or contains(concat(" ",normalize-space(@class)," ")," xr ") or contains(concat(" ",normalize-space(@class)," ")," rt-mhy-image ")]');
        self::assertCount(1, $images);
        self::assertSame('300', $images->item(0)->getAttribute('width'));
        self::assertSame('38', $images->item(0)->getAttribute('height'));
        self::assertStringContainsString('height:auto', $images->item(0)->getAttribute('style'));
        self::assertStringNotContainsString('-4.647303%', $html);
        self::assertMatchesRegularExpression('~<!--\[if mso\]><img\b[^>]*rt-delivery-train-mso[^>]*width="220" height="28"~', $html);
        if (str_contains($html, 'data-rt-mobile-hybrid="v1"')) {
            $this->assertMobileHybridGroups($html, true);
            $window = $images->item(0)->parentNode;
            self::assertSame('div', $window->tagName);
            self::assertSame(1, preg_match('/\Art-w([0-9a-f]{6})\z/', $window->getAttribute('class'), $enhancement));
            self::assertSame('rt-mhy-image rt-i'.$enhancement[1], $images->item(0)->getAttribute('class'));
            self::assertFalse($window->hasAttribute('dir'));
            self::assertFalse($window->hasAttribute('style'));
            self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $images->item(0)->getAttribute('style'));
            self::assertStringContainsString('.rt-w'.$enhancement[1].'{overflow:hidden;padding-top:9.2%}.rt-i'.$enhancement[1].'{transform:scale(1.733333);transform-origin:100% 100%}', $html);
            self::assertStringNotContainsString('.rt-mhy-image{transform:', $html);
        } elseif (OutlookDesktopFlow::projected($html)) {
            $container = $this->one($xpath, 'rt-personal-columns');
            self::assertSame('v1', $container->getAttribute(SignaturePersonalColumns::NATIVE_FLOW_ATTRIBUTE));
            self::assertSame('outlook-desktop-flow-v6', $container->getAttribute('data-rt-desktop-flow'));
            self::assertSame('table', $container->tagName);
            self::assertCount(2, $xpath->query('./tbody/tr', $container));
            self::assertCount(2, $xpath->query('./tbody/tr/td', $container));
            self::assertCount(3, $xpath->query('./tbody/tr[1]/td/table', $container));
            self::assertCount(1, $xpath->query('./tbody/tr[2]/td/table', $container));
            $children = $xpath->query('./tbody/tr/td/table', $container);
            self::assertCount(4, $children);
            foreach (['rt-personal-logo-column', 'rt-personal-left', 'rt-personal-right', 'rt-df-train'] as $index => $class) {
                self::assertTrue($children->item($index)->isSameNode($this->one($xpath, $class)), 'DOM reading order stays Logo, Person, Company, Train.');
                self::assertSame('table', $children->item($index)->tagName);
            }
            self::assertSame('left', $children->item(1)->getAttribute('align'));
            self::assertSame('50', $children->item(1)->getAttribute('height'));
            self::assertSame(1, $xpath->query('./tr|./tbody/tr', $this->one($xpath, 'rt-sign-content-frame'))->length);
            self::assertSame(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train-cell ") or contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train-gap ")]')->length);
            $window = $this->one($xpath, 'rt-train-window');
            self::assertTrue($images->item(0)->parentNode->isSameNode($window));
            self::assertFalse($window->hasAttribute('dir'));
            self::assertFalse($window->hasAttribute('style'));
            self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $images->item(0)->getAttribute('style'));
            self::assertStringContainsString('vertical-align:bottom;border-left:0;', $this->one($xpath, 'rt-df-train')->getAttribute('style'));
            self::assertStringContainsString('padding:0;', $this->one($xpath, 'rt-dfc-train')->getAttribute('style'));
            self::assertStringContainsString('border-right:40px solid transparent;', $this->one($xpath, 'rt-df-train')->getAttribute('style'));
        } else {
            self::assertStringNotContainsString('rt-train-window', $html);
            self::assertStringNotContainsString(SignaturePersonalColumns::NATIVE_FLOW_ATTRIBUTE, $html);
            self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $images->item(0)->getAttribute('style'));
        }
    }

    private function assertNativeRuntimeRejected(string $html): void
    {
        try {
            OutlookDesktopFlow::project($html);
            self::fail('A malformed native flow must not be treated as a trusted runtime projection.');
        } catch (RuntimeException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function msoTrain(string $html): string
    {
        self::assertSame(1, preg_match('~<!--\[if mso\]>(<img\b[^>]*rt-delivery-train-mso[^>]*>)<!\[endif\]-->~', $html, $match));

        return $match[1];
    }

    private function assertAttachedCids(string $html, array $media): void
    {
        preg_match_all('~\bsrc=["\']cid:([^"\']+)~i', $html, $matches);
        self::assertNotEmpty($matches[1]);
        self::assertSame([], array_values(array_diff(array_unique($matches[1]), array_column($media, 'contentId'))));
    }

    private function assertOriginalTrainMedia(array $media): void
    {
        $bytes = array_map(static fn ($item) => base64_decode($item['base64'], true), $media);
        foreach (['gif', 'png'] as $type) {
            $original = file_get_contents(resource_path('mail-templates/assets/zug-dampf-v27-delivery-light.'.$type));
            self::assertContains($original, $bytes);
            $size = getimagesizefromstring($original);
            self::assertSame([1205, 151], array_slice($size, 0, 2));
        }
    }

    private function assertBudget(string $html, int $characters, int $css): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $styles);
        self::assertLessThanOrEqual($css, array_sum(array_map('strlen', $styles[1])));
        self::assertLessThanOrEqual($characters, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
    }

    private function one(DOMXPath $xpath, string $class): DOMElement
    {
        $items = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        self::assertCount(1, $items, $class);

        return $items->item(0);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_NONET);

        return new DOMXPath($dom);
    }

    private function imageSources(string $html): array
    {
        preg_match_all('~\bsrc=["\']([^"\']+)~i', $html, $matches);

        return $matches[1];
    }

    private function storedSources(): string
    {
        return MailDocument::query()->orderBy('id')->get(['id', 'html', 'css', 'published_html', 'published_css', 'is_active', 'outlook_default', 'signature_document_id', 'published_signature_document_id'])->toJson();
    }

    private function publish(MailDocumentKind $kind, string $source, string $css, array $attributes): MailDocument
    {
        $builder = ['pages' => [['name' => 'Synthetic', 'component' => $source]], 'styles' => [], 'railtime' => ['document' => $kind->value, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::withoutEvents(fn () => MailDocument::create(array_replace([
            'kind' => $kind, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic', 'status' => MailDocumentStatus::Published,
            'is_active' => null, 'outlook_default' => null, 'html' => $source, 'css' => $css, 'builder_data' => $builder,
            'published_html' => $source, 'published_css' => $css, 'published_at' => now(),
            'content_hash' => MailDocument::contentHashFor($builder, $source, $css), 'version' => 1,
        ], $attributes)));
    }

    private function source(bool $personal = false): string
    {
        return $personal ? str_replace('design-train-logo', 'design-train-logo design-personal-columns', self::SOURCE) : self::SOURCE;
    }

    private function cidValues(): array
    {
        return ['TRAIN_SRC' => 'cid:train.gif', 'TRAIN_STILL_SRC' => 'cid:train-still.png', 'LOGO_SRC' => 'cid:logo.gif', 'LOGO_STILL_SRC' => 'cid:logo.png',
            'ICON_PHONE_SRC' => 'cid:phone.png', 'ICON_MOBILE_SRC' => 'cid:mobile.png', 'ICON_EMAIL_SRC' => 'cid:email.png',
            'ICON_LOCATION_SRC' => 'cid:location.png', 'ICON_WEB_SRC' => 'cid:web.png'];
    }

    private const SOURCE = <<<'SIGNATURE_HTML'
<tr data-rt-artifact-version="v27"><td width="100%" bgcolor="#ffffff" class="rt-sign-cell" style="background-color:#ffffff!important;width:100%;padding:0;border-top:0;"><table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#e60032" style="width:100%;border-collapse:collapse;background-color:#e60032;"><tbody><tr><td bgcolor="#e60032" class="design-hotline-row design-hotline-spaced" style="padding:3px 40px;padding-right:24px;background:#e60032;color:#ffffff;"><table role="presentation" width="240" border="0" cellspacing="0" cellpadding="0" style="width:auto;max-width:100%;margin:0;border-collapse:collapse;"><tbody><tr><td valign="middle" style="padding:0 8px 0 0;color:#ffffff;font-family:'Trebuchet MS',Arial,sans-serif;"><strong style="display:block;font-size:25px;line-height:26px;letter-spacing:-.5px;white-space:nowrap;">24/7</strong></td><td valign="middle" style="padding:0 20px 0 0;color:#ffffff;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:18px;font-weight:normal;letter-spacing:0;white-space:nowrap;"> erreichbar</td><td width="26" valign="middle" align="center" style="width:26px;padding:6px 0;"><a href="mailto:office@example.test" title="E-Mail an die Hotline" style="display:block;text-decoration:none;"><img src="{{ICON_EMAIL_SRC}}" width="26" alt="E-Mail an die Hotline" data-rt-image-align="left" style="display:block;width:100%;height:auto;border:0;max-width:26px;margin:0;"></a></td><td width="16" style="width:16px;padding:0;font-size:0;">&nbsp;</td><td width="26" valign="middle" align="center" style="width:26px;padding:6px 0;"><a href="tel:{{NOTFALLNUMMER_TEL}}" title="Hotline anrufen" style="display:block;text-decoration:none;"><img src="{{ICON_PHONE_SRC}}" width="26" alt="Hotline anrufen" data-rt-image-align="left" style="display:block;width:100%;height:auto;border:0;max-width:26px;margin:0;"></a></td></tr></tbody></table></td></tr></tbody></table><div class="rt-sign-stage" style="background-color:#ffffff!important;display:block;width:100%;overflow:visible;"><table bgcolor="#ffffff" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" class="rt-sign-content-frame" style="background-color:#ffffff!important;width:100%;table-layout:fixed;border-collapse:collapse;"><tbody><tr><td width="1%" valign="bottom" class="rt-v27-image-cell" style="width:1%;padding:0;font-size:0;line-height:0;vertical-align:bottom;"><table role="presentation" width="6031.746032%" border="0" cellspacing="0" cellpadding="0" class="rt-v27-anchor" style="width:6031.746032%;table-layout:fixed;border-collapse:collapse;"><tbody><tr><td dir="rtl" align="right" valign="bottom" class="rt-v27-image-slot" style="direction:rtl;padding:0;text-align:right;vertical-align:bottom;font-size:0;line-height:0;"><img data-rt-train="" src="{{TRAIN_SRC}}" width="1216" alt="" class="rt-sign-train" style="display:inline-block;width:100%;max-width:100%;height:auto;border:0;vertical-align:bottom;"></td></tr></tbody></table></td><td valign="top" class="rt-pad rt-sign-content rt-sign-ledger-content" style="padding:25px 40px 46px;vertical-align:top;font-family:'Trebuchet MS',Arial,sans-serif;"><table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" class="rt-sign-layout rt-sign-ledger design-train-logo" style="width:100%;table-layout:fixed;border-collapse:collapse;"><tbody><tr><td width="50%" valign="top" class="rt-ledger-brand" style="width:50%;padding:2px 22px 4px 0;vertical-align:top;border-right:2px solid #e90032;text-align:left;"><table role="presentation" width="180" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="width:180px;max-width:180px;table-layout:fixed;background-color:#ffffff!important;"><tbody><tr><td width="180" height="31" style="width:180px;height:31px;padding:0;font-size:0;line-height:0;"><img src="{{LOGO_SRC}}" width="180" height="31" alt="{{FIRMENNAME}}" class="rt-logo" style="display:block;width:180px!important;max-width:180px!important;height:31px!important;max-height:31px!important;border:0;margin:0;mso-hide:all;"><!--[if mso]><img class="rt-logo" src="{{LOGO_STILL_SRC}}" width="180" height="31" alt="{{FIRMENNAME}}" style="display:block;width:180px!important;max-width:180px!important;height:31px!important;max-height:31px!important;border:0;margin:0;"><![endif]--></td></tr></tbody></table><div style="padding-top:13px;"><div class="rt-person-kopf"><p class="rt-sign-name" style="margin:0;color:#142231;font-family:'Trebuchet MS',Arial,sans-serif;font-size:18px;line-height:24px;font-weight:bold;letter-spacing:-.25px;"><span style="background-color:#ffffff;">{{VORNAME_NACHNAME}}</span></p><p style="margin:0;color:#e4002b;font-family:'Trebuchet MS',Arial,sans-serif;font-size:9px;line-height:14px;font-weight:bold;letter-spacing:.8px;text-transform:uppercase;"><span style="background-color:#ffffff;">{{POSITION}}</span></p></div></div></td><td width="50%" valign="top" class="rt-ledger-contacts" style="width:50%;padding:0 0 0 22px;vertical-align:top;"><table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed;border-collapse:collapse;"><tbody><tr><td width="38%" valign="top" class="rt-ledger-direct" style="width:38%;padding:0 24px 0 0;vertical-align:top;"><table role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" class="rt-contact" style="direction:ltr;margin-left:0;margin-right:auto;margin-top:0;border-collapse:collapse;"><tbody><!-- RT_PHONE_START --><tr><td width="17" align="center" valign="middle" class="rt-contact-icon" style="width:17px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ICON_PHONE_SRC}}" width="17" height="17" alt="" style="display:block;width:17px;height:17px;margin:0 auto;"></td><td valign="middle" class="rt-contact-text" style="padding:0 0 8px 9px;color:#465568;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;"><a href="tel:{{DURCHWAHL_TEL}}" style="color:#142231;text-decoration:none;"><span style="background-color:#ffffff;">{{DURCHWAHL}}</span></a></td></tr><!-- RT_PHONE_END --><!-- RT_MOBILE_START --><tr><td width="17" align="center" valign="middle" class="rt-contact-icon" style="width:17px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ICON_MOBILE_SRC}}" width="17" height="17" alt="" style="display:block;width:17px;height:17px;margin:0 auto;"></td><td valign="middle" class="rt-contact-text" style="padding:0 0 8px 9px;color:#465568;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;"><a href="tel:{{MOBIL_TEL}}" style="color:#142231;text-decoration:none;"><span style="background-color:#ffffff;">{{MOBIL}}</span></a></td></tr><!-- RT_MOBILE_END --><tr><td width="17" align="center" valign="middle" class="rt-contact-icon" style="width:17px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ICON_EMAIL_SRC}}" width="17" height="17" alt="" style="display:block;width:17px;height:17px;margin:0 auto;"></td><td valign="middle" class="rt-contact-text" style="padding:0 0 0 9px;color:#465568;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;"><a href="mailto:{{E_MAIL}}" style="color:#142231;text-decoration:none;"><span style="background-color:#ffffff;">{{E_MAIL}}</span></a></td></tr></tbody></table></td><td width="62%" valign="top" class="rt-ledger-company" style="width:62%;padding:0;vertical-align:top;"><div align="left" style="text-align:left;"><table role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" class="rt-contact rt-company-contact" style="direction:ltr;margin-left:0;margin-right:auto;margin-top:0;border-collapse:collapse;"><tbody><tr><td width="17" align="center" valign="middle" class="rt-contact-icon rt-company-contact-icon" style="width:17px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ICON_LOCATION_SRC}}" width="17" height="17" alt="" style="display:block;width:17px;height:17px;margin:0 auto;"></td><td valign="middle" align="left" class="rt-contact-text rt-company-contact-text" style="padding:0 0 8px 9px;color:#667588;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;text-align:left;"><span style="background-color:#ffffff;">{{FIRMENSTRASSE}} </span><br class="rt-address-break"><span style="background-color:#ffffff;">{{FIRMEN_PLZ_ORT}}</span></td></tr><!-- RT_COMPANY_PHONE_START --><tr><td width="17" align="center" valign="middle" class="rt-contact-icon rt-company-contact-icon" style="width:17px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ICON_PHONE_SRC}}" width="17" height="17" alt="" style="display:block;width:17px;height:17px;margin:0 auto;"></td><td valign="middle" align="left" class="rt-contact-text rt-company-contact-text" style="padding:0 0 8px 9px;color:#667588;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;text-align:left;"><a href="tel:{{FIRMEN_TELEFON_TEL}}" style="color:#142231;text-decoration:none;"><span style="background-color:#ffffff;">{{FIRMEN_TELEFON}}</span></a></td></tr><!-- RT_COMPANY_PHONE_END --><!-- RT_COMPANY_EMAIL_START --><tr><td width="17" align="center" valign="middle" class="rt-contact-icon rt-company-contact-icon" style="width:17px;padding:0 0 8px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ICON_EMAIL_SRC}}" width="17" height="17" alt="" style="display:block;width:17px;height:17px;margin:0 auto;"></td><td valign="middle" align="left" class="rt-contact-text rt-company-contact-text" style="padding:0 0 8px 9px;color:#667588;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;text-align:left;"><a href="mailto:{{FIRMEN_EMAIL}}" style="color:#142231;text-decoration:none;"><span style="background-color:#ffffff;">{{FIRMEN_EMAIL}}</span></a></td></tr><!-- RT_COMPANY_EMAIL_END --><!-- RT_WEBSITE_START --><tr><td width="17" align="center" valign="middle" class="rt-contact-icon rt-company-contact-icon" style="width:17px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ICON_WEB_SRC}}" width="17" height="17" alt="" style="display:block;width:17px;height:17px;margin:0 auto;"></td><td valign="middle" align="left" class="rt-contact-text rt-company-contact-text" style="padding:0 0 0 9px;color:#667588;font-family:'Trebuchet MS',Arial,sans-serif;font-size:13px;line-height:20px;text-align:left;"><a href="{{FIRMEN_WEBSITE_HREF}}" style="color:#142231;text-decoration:none;"><span style="background-color:#ffffff;">{{FIRMEN_WEBSITE_LABEL}}</span></a></td></tr><!-- RT_WEBSITE_END --></tbody></table></div></td></tr></tbody></table></td></tr></tbody></table><div style="height:25px;line-height:25px;font-size:0;">&nbsp;</div></td></tr></tbody></table></div></td></tr>
<!-- RT_SIGNATURE_MAIN_END -->
<tr><td width="100%" bgcolor="#f3f5f7" class="rt-pad" style="width:100%;padding:12px 40px;background:#f3f5f7;color:#718096;font-family:'Trebuchet MS',Arial,sans-serif;font-size:9px;line-height:14px;">
        Geschäftsführung: {{GESCHAEFTSFUEHRUNG}} · Registergericht: {{REGISTERGERICHT}} · HRB {{HRB}} · USt-IdNr. {{UST_ID}} · Steuernummer {{STEUERNUMMER}}<br>
        Diese E-Mail kann vertrauliche Informationen enthalten. Sollten Sie nicht der vorgesehene Empfänger sein, informieren Sie bitte den Absender und löschen Sie diese Nachricht.
    </td></tr>
SIGNATURE_HTML;

    private const TEMPLATE = <<<'TEMPLATE_HTML'
<!doctype html>
<html style="background-color:#ffffff;" lang="de" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office"><head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="format-detection" content="telephone=no,address=no,email=no,date=no,url=no">
  <meta name="color-scheme" content="light only">
  <meta name="supported-color-schemes" content="light only">
  <title>{{FIRMENNAME}} · v27 Signal · Hell</title>
  <!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->
  <style>
    html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    table, td { border-collapse: collapse !important; mso-table-lspace: 0pt !important; mso-table-rspace: 0pt !important; }
    img { border: 0; display: block; height: auto; line-height: 100%; outline: none; text-decoration: none; }
    a { color: inherit; }
    .rt-shell { width: 100% !important; max-width: none !important; }
    {{RESPONSIVE_CSS}}
  </style>
</head><body data-rt-theme="light" bgcolor="#ffffff" style="background-color:#ffffff!important;margin:0;padding:0;color:#142231;font-family:'Trebuchet MS',Arial,Helvetica,sans-serif;"><div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">Ihre Nachricht von {{FIRMENNAME}}.</div><table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="background-color:#ffffff!important;width:100%;"><tbody><tr><td align="center" bgcolor="#ffffff" class="design-page-pad" style="background-color:#ffffff!important;padding:34px 0 38px;"><div style="background-color:#ffffff!important;width:100%;box-sizing:border-box;border-left:6px solid #e90032;overflow:hidden;"><table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" align="center" bgcolor="#ffffff" class="rt-shell design-v27" style="background-color:#ffffff!important;width:100%;max-width:1120px;margin:0 auto;"><tbody><tr><td bgcolor="#ffffff" class="rt-pad" style="background-color:#ffffff!important;padding:29px 40px 23px 40px;"><table dir="rtl" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="background-color:#ffffff!important;direction:rtl;width:100%;border-collapse:collapse;"><tbody><tr><td valign="top" align="right" bgcolor="#ffffff" style="background-color:#ffffff!important;vertical-align:top;text-align:right;"></td><!-- RT_TEMPLATE_MARK_START -->
                  <td width="44" valign="top" align="right" style="width:44px;padding-left:18px;text-align:right;vertical-align:top;font-size:0;line-height:0;">
                    <!-- Outlook-Desktop zeigt von einem GIF nur das erste
                         Einzelbild; beim Aufbau ist das fast leer. Es bekommt
                         deshalb das Standbild. -->
                    <img class="rt-mark" src="{{ICON_RT_SRC}}" width="44" height="44" alt="" style="display:block;width:44px!important;min-width:44px;max-width:44px!important;height:44px!important;min-height:44px;max-height:44px!important;margin-left:auto;border:0;mso-hide:all;">
                    <!--[if mso]><img class="rt-mark" src="{{ICON_RT_STILL_SRC}}" width="44" height="44" alt="" style="display:block;width:44px!important;min-width:44px;max-width:44px!important;height:44px!important;min-height:44px;max-height:44px!important;margin-left:auto;border:0;"><![endif]-->
                  </td>
                  <!-- RT_TEMPLATE_MARK_END --></tr></tbody></table></td></tr><!-- RT_APPLICATION_CONTENT_START -->{{APPLICATION_CONTENT}}<tr><td bgcolor="#ffffff" class="rt-pad" style="background-color:#ffffff;padding:0 40px 26px;color:#142231;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:26px;"><p class="design-spacer" style="margin:0 0 20px;font-size:16px;line-height:26px;"><br><br></p></td></tr><!-- RT_APPLICATION_CONTENT_END -->{{SIGNATURE_BLOCK}}</tbody></table></div></td></tr></tbody></table></body></html>
TEMPLATE_HTML;

    private const TEMPLATE_CSS = <<<'TEMPLATE_CSS'
.design-salutation{margin-top:0px;margin-right:0px;margin-bottom:20px;margin-left:0px;font-family:Arial, Helvetica, sans-serif;font-size:26px;line-height:34px;font-weight:bold;color:rgb(20, 34, 49);}.design-spacer{margin-top:0px;margin-right:0px;margin-bottom:20px;margin-left:0px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:26px;color:rgb(20, 34, 49);}.design-greeting{margin-top:0px;margin-right:0px;margin-bottom:0px;margin-left:0px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:26px;color:rgb(20, 34, 49);}
TEMPLATE_CSS;
}
