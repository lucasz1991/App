<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\OutlookSignatureInlineStyle;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\MailSignature;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookMobileSignature;
use App\Support\OutlookAddin\OutlookNativePersonalSignature;
use App\Support\OutlookAddin\OutlookTrainBottomOverlay;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookNativePersonalSignatureTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['outlook_addin.snapshots.auto_refresh' => false, 'outlook_addin.marker' => 'RT-SIGNATURE-MANAGED-V1']);
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_08_09_000100_create_mail_documents_table.php',
            '2026_08_22_000200_create_mail_document_versions_table.php',
            '2026_08_27_000100_add_design_slots_to_mail_documents.php',
            '2026_09_06_010000_add_outlook_library_to_mail_documents.php',
            '2026_09_07_190000_separate_mail_document_delivery_channels.php',
            '2026_09_08_120000_add_mail_document_signature_pairing.php',
        ] as $migration) {
            (include database_path('migrations/'.$migration))->up();
        }
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public static function layouts(): array
    {
        return ['direct logo' => [false], 'nested fluid logo' => [true]];
    }

    #[DataProvider('layouts')]
    public function test_projection_moves_whole_personal_groups_and_is_byte_reversible_with_retained_geometry_mirrors(bool $nested): void
    {
        $source = $this->source($nested);
        $this->publish($source, 'signature', true)->update(['outlook_default' => true]);
        $user = $this->employee();
        $this->app->forgetScopedInstances();
        $canonical = (new EmailTemplateBuilder($user))->buildOutlookAddinSignatureHtml();
        $projected = OutlookNativePersonalSignature::project($canonical);
        $this->assertPersonal($projected);
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($projected));
        self::assertSame($projected, OutlookNativePersonalSignature::project($projected));
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($canonical));
        self::assertSame($this->styles($canonical), $this->styles(OutlookNativePersonalSignature::restore($projected)), 'Every original published/runtime/alias style byte is restored.');
        self::assertSame($this->mediaAndLinks($canonical), $this->mediaAndLinks($projected));
        preg_match_all('~<!--.*?-->~s', $canonical, $before);
        preg_match_all('~<!--.*?-->~s', $projected, $after);
        $commentsBefore = $before[0];
        $commentsAfter = array_values(array_filter($after[0], static fn (string $comment): bool => ! str_contains($comment, 'RT-PERSONAL-')));
        sort($commentsBefore);
        sort($commentsAfter);
        self::assertSame($commentsBefore, $commentsAfter, 'MSO/normal image branches and contact markers move as exact bytes.');
        SignatureTableOverlapDelivery::assertRuntime($projected);
        SignatureDocumentContract::assertValid($source);
    }

    public function test_balanced_columns_reserve_personal_contact_room_at_the_observed_599px_mail_width(): void
    {
        $this->publish($this->source(false), 'signature', true)->update(['outlook_default' => true]);
        $user = $this->employee();
        $this->app->forgetScopedInstances();
        $html = OutlookNativePersonalSignature::project((new EmailTemplateBuilder($user))->buildOutlookAddinSignatureHtml());
        $this->assertPersonal($html);
        $xpath = $this->xpath($html);
        $left = $this->one($xpath, 'rt-ledger-brand', 'th');
        $right = $this->one($xpath, 'rt-ledger-contacts', 'th');
        self::assertStringContainsString('width:48%;', $left->getAttribute('style'));
        self::assertStringContainsString('width:52%;', $right->getAttribute('style'));
        // Observed mail box599, continuous accent6, content padding40+40,
        // existing left gutter22 + divider1, icon17 + text spacing9.
        $ledgerWidth = 599 - 6 - 80;
        $leftColumn = $ledgerWidth * .48;
        $textRoom = $leftColumn - 22 - 1 - 17 - 9;
        self::assertGreaterThanOrEqual(245, $leftColumn);
        self::assertGreaterThanOrEqual(190, $textRoom);
        self::assertStringNotContainsString(OutlookNativePersonalSignature::STYLE_ATTRIBUTE, $html);
        self::assertSame(1, preg_match('~<style data-rt-outlook-signature-css="1">(.*?)</style>~s', $html, $css));
        self::assertSame(1, preg_match('~\brts[0-9a-f]{10}\b~', $left->ownerDocument->saveHTML(), $scope));
        foreach (['brand' => 32, 'contacts' => 68] as $class => $width) {
            self::assertStringContainsString('.'.$scope[0].' .rt-ledger-'.$class.'.rt-delivery-wide-column{width:'.$width.'%!important;', $css[1]);
        }
        self::assertSame(1, preg_match('~<style data-rt-outlook-signature-inline-css="1">(.*?)</style>~s', $html, $inline));
        self::assertStringContainsString('.'.$scope[0].' .rt-pv2 .rt-phl.rt-delivery-wide-column{width:48%!important;}', $inline[1]);
        self::assertStringContainsString('.'.$scope[0].' .rt-pv2 .rt-phr.rt-delivery-wide-column{width:52%!important;}', $inline[1]);
        self::assertStringContainsString('.'.$scope[0].' .rt-pv2 .rt-ledger-brand.rt-delivery-wide-column{width:48%!important;}', $inline[1]);
        self::assertStringContainsString('.'.$scope[0].' .rt-pv2 .rt-ledger-contacts.rt-delivery-wide-column{width:52%!important;}', $inline[1]);
        self::assertStringContainsString('.'.$scope[0].' .rt-pv2 .rt-pnw{padding-top:0!important;}', $inline[1]);
        self::assertStringContainsString('.'.$scope[0].' .rt-pv2 .rt-ledger-direct.rt-delivery-group-cell{padding:14px 0 0!important;}', $inline[1]);
        self::assertStringContainsString('@media(max-width:860px){', $inline[1]);
        self::assertStringContainsString('display:block!important;width:100%!important;padding:0!important;border:0!important;', $inline[1]);
        self::assertStringContainsString('word-break:break-all;', $this->one($xpath, 'rt-ledger-direct', 'td')->ownerDocument->saveHTML());
    }

    public function test_already_cached_first_personal_projection_remains_exactly_restorable(): void
    {
        $this->publish($this->source(false), 'signature', true)->update(['outlook_default' => true]);
        $user = $this->employee();
        $this->app->forgetScopedInstances();
        $canonical = (new EmailTemplateBuilder($user))->buildOutlookAddinSignatureHtml();
        $old = $this->legacyProjection($canonical);
        $old = preg_replace('~<style '.OutlookNativePersonalSignature::STYLE_ATTRIBUTE.'="1">.*?</style>~s', '', $old);
        $old = preg_replace_callback('~<th\b[^>]*>~', static fn (array $match): string => str_replace(['width="48%"', 'width:48%;', 'width="52%"', 'width:52%;'], ['width="32%"', 'width:32%;', 'width="68%"', 'width:68%;'], $match[0]), $old);
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($old));
        self::assertSame($old, OutlookNativePersonalSignature::project($old));
    }

    public function test_already_cached_balanced_v1_is_valid_and_restores_without_being_silently_upgraded(): void
    {
        $this->publish($this->source(false), 'signature', true)->update(['outlook_default' => true]);
        $this->app->forgetScopedInstances();
        $canonical = (new EmailTemplateBuilder($this->employee()))->buildOutlookAddinSignatureHtml();
        $old = $this->legacyProjection($canonical);
        self::assertStringContainsString('data-rt-personal-layout="'.OutlookNativePersonalSignature::LEGACY_MARKER.'"', $old);
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($old));
        self::assertSame($old, OutlookNativePersonalSignature::project($old));
    }

    #[DataProvider('layouts')]
    public function test_plain_cached_v2_and_important_direct_padding_restore_the_same_exact_source(bool $nested): void
    {
        $this->publish($this->source($nested), 'signature', true)->update(['outlook_default' => true]);
        $this->app->forgetScopedInstances();
        $canonical = (new EmailTemplateBuilder($this->employee()))->buildOutlookAddinSignatureHtml();
        $projected = OutlookNativePersonalSignature::project($canonical);
        $direct = $this->one($this->xpath($projected), 'rt-ledger-direct', 'td');
        self::assertStringContainsString('padding:14px 0 0!important;', $direct->getAttribute('style'));
        self::assertStringNotContainsString('padding:14px 0 0;', $direct->getAttribute('style'));

        $cached = preg_replace_callback('~<td\b[^>]*\bclass="rt-ledger-direct\b[^>]*>~', static fn (array $match): string => str_replace('padding:14px 0 0!important;', 'padding:14px 0 0;', $match[0]), $projected, 1, $count);
        self::assertSame(1, $count);
        self::assertSame(strlen($cached) + 10, strlen($projected), 'Only the generated direct-cell priority changes the transport bytes.');
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($projected));
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($cached));
        self::assertSame($projected, OutlookNativePersonalSignature::project($projected));
        self::assertSame($cached, OutlookNativePersonalSignature::project($cached), 'Do not silently rewrite an already cached v2 signature.');
        self::assertSame($this->styles($cached), $this->styles($projected));
        self::assertSame($this->mediaAndLinks($canonical), $this->mediaAndLinks($projected));
    }

    public function test_every_v2_geometry_rule_is_isolated_from_legacy_quotes_sharing_the_same_scope_and_aliases(): void
    {
        $this->publish($this->source(false), 'signature', true)->update(['outlook_default' => true]);
        $this->app->forgetScopedInstances();
        $canonical = (new EmailTemplateBuilder($this->employee()))->buildOutlookAddinSignatureHtml();
        $legacy = $this->legacyProjection($canonical);
        $aligned = OutlookNativePersonalSignature::project($canonical);
        self::assertSame(1, preg_match('/\brts[0-9a-f]{10}\b/', $aligned, $scope));
        $css = (new \ReflectionMethod(OutlookNativePersonalSignature::class, 'alignmentCss'))->invoke(null, $scope[0]);
        $xpath = $this->xpath('<div id="current">'.$aligned.'</div><div id="quote">'.$legacy.'</div>');
        preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
        self::assertNotEmpty($rules);
        foreach ($rules as $rule) {
            foreach (explode(',', trim($rule[1])) as $selector) {
                self::assertStringStartsWith('.'.$scope[0].' ', $selector);
                self::assertSame(1, substr_count($selector, '.rt-pv2'));
                self::assertContains('rt-pv2', explode('.', substr(explode(' ', $selector)[1], 1)));
                $path = '';
                foreach (explode(' ', $selector) as $compound) {
                    self::assertSame(1, preg_match('/\A(?:\.[a-z][a-z0-9-]*)+\z/', $compound));
                    $predicates = array_map(static fn (string $class): string => 'contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")', explode('.', substr($compound, 1)));
                    $path .= '//*['.implode(' and ', $predicates).']';
                }
                self::assertGreaterThan(0, $xpath->query('//div[@id="current"]'.$path)->length, $selector);
                self::assertSame(0, $xpath->query('//div[@id="quote"]'.$path)->length, 'Current CSS must not address old same-scope rts/oi aliases.');
            }
        }
        foreach (['brand', 'contacts'] as $column) {
            $new = '.'.$scope[0].' .rt-pv2 .rt-ledger-'.$column.'.rt-delivery-wide-column';
            $old = '.'.$scope[0].' .rt-ledger-'.$column.'.rt-delivery-wide-column';
            self::assertSame(4, substr_count($new, '.'));
            self::assertSame(3, substr_count($old, '.'));
        }
        $mobile = '.'.$scope[0].' .rt-sign-ledger.rt-pv2 .rt-delivery-wide-column';
        self::assertSame(4, substr_count($mobile, '.'));
        self::assertStringContainsString('@media(max-width:860px){'.$mobile.'{display:block!important;width:100%!important;', $css);
        self::assertSame(1, preg_match('~<style data-rt-outlook-signature-css="1">.*?</style>~s', $legacy, $before));
        self::assertSame(1, preg_match('~<style data-rt-outlook-signature-css="1">.*?</style>~s', $aligned, $after));
        self::assertSame($before[0], $after[0], 'Original canonical runtime column rules remain byte-identical for quotes.');
        self::assertSame(1, substr_count($aligned, ' rt-pv2"'));
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($aligned));
    }

    public function test_removing_only_the_appended_v2_class_preserves_original_ledger_class_whitespace(): void
    {
        $this->publish($this->source(false), 'signature', true)->update(['outlook_default' => true]);
        $this->app->forgetScopedInstances();
        $canonical = (new EmailTemplateBuilder($this->employee()))->buildOutlookAddinSignatureHtml();
        $canonical = str_replace('class="rt-sign-ledger ', 'class="rt-sign-ledger  ', $canonical, $count);
        self::assertSame(1, $count);
        $aligned = OutlookNativePersonalSignature::project($canonical);
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($aligned));
        self::assertStringContainsString('class="rt-sign-ledger  ', $aligned);
    }

    #[DataProvider('layouts')]
    public function test_shared_rows_align_variable_headers_and_contact_starts_without_fixed_heights_or_stylesheets(bool $nested): void
    {
        $this->publish($this->source($nested), 'signature', true)->update(['outlook_default' => true]);
        $this->app->forgetScopedInstances();
        $canonical = (new EmailTemplateBuilder($this->employee()))->buildOutlookAddinSignatureHtml();
        $canonical = preg_replace('~(<td\b[^>]*class="rt-contact-text rt-company-contact-text[^>]*><p\b[^>]*>)([^<]*)~', '$1A deliberately very long company street address 12345 ', $canonical, 1, $addressCount);
        self::assertSame(1, $addressCount);
        $html = OutlookNativePersonalSignature::project($canonical);
        $this->assertPersonal($html);
        $xpath = $this->xpath(preg_replace('~<style\b[^>]*>.*?</style>~s', '', $html));
        $nameWrapper = $this->one($xpath, 'rt-pnw', 'div');
        $direct = $this->one($xpath, 'rt-ledger-direct', 'td');
        $company = $this->one($xpath, 'rt-ledger-company', 'td');
        self::assertStringContainsString('padding-top:0;', $nameWrapper->getAttribute('style'));
        self::assertStringContainsString('padding:14px 0 0!important;', $direct->getAttribute('style'));
        self::assertStringContainsString('padding:14px 0 0;', $company->getAttribute('style'));
        foreach ([$direct, $company] as $cell) {
            self::assertSame('top', $cell->getAttribute('valign'));
        }
        foreach (['rt-phl', 'rt-phr', 'rt-ledger-brand', 'rt-ledger-contacts'] as $class) {
            $cell = $this->one($xpath, $class, 'th');
            self::assertSame('top', $cell->getAttribute('valign'));
            self::assertFalse($cell->hasAttribute('height'));
            self::assertDoesNotMatchRegularExpression('/(?:^|;)(?:min-|max-)?height:/', $cell->getAttribute('style'));
        }
        self::assertSame($canonical, OutlookNativePersonalSignature::restore($html));
        self::assertSame($this->mediaAndLinks($canonical), $this->mediaAndLinks($html));
        self::assertStringContainsString('A deliberately very long company street address 12345', $html);
        self::assertStringContainsString('Alexandra Christine von RailTime Musterhausen', $html);
        $this->assertBudgetAndMedia(['html' => $html, 'media' => []], false);
    }

    public function test_global_and_paired_native_payloads_are_personal_while_mobile_restores_existing_semantics(): void
    {
        $source = $this->source(true);
        $this->publish($source, 'signature', true)->update(['outlook_default' => true]);
        $paired = $this->publish($source."\n", 'signature');
        $this->publish(file_get_contents(EmailTemplateBuilder::masterPath('email-master.html')), 'template', true)->update([
            'published_signature_document_id' => $paired->id, 'outlook_released' => true, 'outlook_default' => true,
        ]);
        $user = $this->employee();
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $this->app->forgetScopedInstances();
        $service = app(OutlookAddinPayloadService::class);
        $fingerprint = $service->sourceFingerprint($user);
        $desktop = $service->forUser($user);
        $original = $desktop;
        $restored = $desktop;
        $restored['signature']['html'] = OutlookNativePersonalSignature::restore($restored['signature']['html']);
        $restored['templates'][0]['signature']['html'] = OutlookNativePersonalSignature::restore($restored['templates'][0]['signature']['html']);
        $mobile = OutlookMobileSignature::payload($desktop);
        self::assertSame(OutlookMobileSignature::payload($restored), $mobile, 'The personal desktop projection cannot change the existing mobile result.');
        self::assertSame($original, $desktop);
        self::assertSame($paired->public_id, $desktop['templates'][0]['signatureDocumentId']);
        foreach ([['signature'], ['templates', 0, 'signature']] as $path) {
            $signature = $desktop;
            $mobileSignature = $mobile;
            foreach ($path as $key) {
                $signature = $signature[$key];
                $mobileSignature = $mobileSignature[$key];
            }
            $this->assertPersonal($signature['html']);
            foreach (['desktop' => $signature, 'mobile' => $mobileSignature] as $profile => $document) {
                try {
                    $this->assertBudgetAndMedia($document);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException($profile.' personal transport: '.$exception->getMessage(), 0, $exception);
                }
                self::assertSame(1, substr_count($document['html'], 'Alexandra Christine von RailTime Musterhausen'));
                self::assertSame(2, preg_match_all('/RT-SIGNATURE-VERSION:[0-9a-f]{16}/', $document['html']));
                self::assertLessThan(strpos($document['html'], 'RT-SIGNATURE-VERSION:'), strpos($document['html'], 'RT-SIGNATURE-MANAGED-V1'));
            }
            self::assertSame($signature['media'], $mobileSignature['media']);
            $xpath = $this->xpath($mobileSignature['html']);
            self::assertStringNotContainsString('data-rt-personal-layout', $mobileSignature['html']);
            $brand = $this->one($xpath, 'rt-ledger-brand', 'td');
            $contacts = $this->one($xpath, 'rt-ledger-contacts', 'td');
            self::assertSame(1, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]', $brand)->length);
            self::assertSame(1, $xpath->query('.//td[contains(concat(" ",normalize-space(@class)," ")," rt-ledger-direct ")]', $contacts)->length);
            self::assertSame(0, $xpath->query('//th')->length);
        }
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        self::assertSame($fingerprint, $service->sourceFingerprint($user));
        self::assertSame($source, $this->source(true));
    }

    public function test_company_and_unrelated_legacy_output_is_not_rewritten(): void
    {
        $source = $this->source(false);
        $this->publish($source, 'signature', true);
        $this->app->forgetScopedInstances();
        $company = MailSignature::forCompany(remoteAssets: true)->render();
        self::assertSame('', $this->one($this->xpath('<table>'.$company.'</table>'), 'rt-sign-name', 'p')->textContent);
        self::assertSame($company, OutlookNativePersonalSignature::project($company));
        foreach (['<div>Legacy signature</div>', '<table><tr><td>Generic company</td></tr></table>'] as $legacy) {
            self::assertSame($legacy, OutlookNativePersonalSignature::project($legacy));
            self::assertSame($legacy, OutlookNativePersonalSignature::restore($legacy));
        }
        $companyWithCss = '<style>.rt-person-kopf{display:block;}</style>'.str_replace('class="rt-person-kopf"', 'class="company-caption"', $company);
        self::assertSame($companyWithCss, OutlookNativePersonalSignature::project($companyWithCss), 'A stylesheet mention is not a personal identity opt-in.');
    }

    public static function malformed(): array
    {
        return [
            'foreign marker' => ['marker'], 'missing presentation role' => ['role'],
            'duplicate direct group' => ['direct'], 'changed clone marker' => ['clone'],
            'self closing projected cell' => ['cell'], 'unclosed ledger' => ['unclosed'],
            'duplicate clone marker' => ['duplicate'], 'modified clone geometry' => ['geometry'],
            'missing balanced css' => ['no-css'], 'changed balanced css' => ['changed-css'], 'duplicate balanced css' => ['duplicate-css'],
            'changed name offset' => ['name-padding'], 'changed contact gap' => ['contact-padding'],
            'changed important spelling' => ['contact-important'], 'encoded contact priority' => ['contact-encoded-priority'],
            'duplicate contact padding' => ['contact-duplicate-padding'], 'extra contact side padding' => ['contact-side-padding'],
            'changed header clone' => ['header-geometry'], 'duplicate header marker' => ['header-marker'],
            'missing company slot' => ['company-slot'], 'missing identity slot' => ['identity-slot'],
            'swapped shared rows' => ['row-order'], 'changed mirrored contact gap' => ['mirror-gap'],
            'extra visible header text' => ['header-text'], 'conflicting name padding' => ['duplicate-padding'],
            'missing v2-only class' => ['missing-v2-class'], 'duplicate v2-only class' => ['duplicate-v2-class'],
        ];
    }

    #[DataProvider('malformed')]
    public function test_restore_rejects_ambiguous_or_modified_projected_shapes(string $mutation): void
    {
        $this->publish($this->source(false), 'signature', true)->update(['outlook_default' => true]);
        $user = $this->employee();
        $this->app->forgetScopedInstances();
        $html = OutlookNativePersonalSignature::project((new EmailTemplateBuilder($user))->buildOutlookAddinSignatureHtml());
        $html = match ($mutation) {
            'marker' => str_replace('data-rt-personal-layout="'.OutlookNativePersonalSignature::MARKER.'"', 'data-rt-personal-layout="foreign"', $html),
            'role' => preg_replace('~(<th\b[^>]*\brole=")presentation~', '$1columnheader', $html, 1),
            'direct' => str_replace('rt-personal-logo ', 'rt-personal-logo rt-ledger-direct ', $html),
            'clone' => str_replace('data-rt-personal-direct="1"', 'data-rt-personal-direct="0"', $html),
            'cell' => preg_replace('~(<td\b[^>]*\bclass="rt-personal-logo[^>]*)(>)~', '$1/>', $html, 1),
            'unclosed' => substr($html, 0, -6),
            'duplicate' => str_replace('data-rt-personal-logo="1"', 'data-rt-personal-logo="1" data-rt-personal-direct="1"', $html),
            'geometry' => preg_replace('~(<table\b[^>]*data-rt-personal-direct="1")~', '$1 data-foreign="1"', $html, 1),
            'no-css' => preg_replace('~<style data-rt-outlook-signature-inline-css="1">.*?</style>~s', '', $html),
            'changed-css' => str_replace('{width:48%!important;}', '{width:50%!important;}', $html),
            'duplicate-css' => preg_replace('~(<style data-rt-outlook-signature-inline-css="1">.*?</style>)~s', '$1$1', $html),
            'name-padding' => preg_replace('~(<div\b[^>]*style=")padding-top:0;~', '$1padding-top:2px;', $html, 1),
            'contact-padding' => preg_replace('~(<td\b[^>]*class="rt-ledger-direct[^>]*style="[^\"]*)padding:14px 0 0!important;~', '$1padding:12px 0 0!important;', $html, 1),
            'contact-important' => preg_replace('~(<td\b[^>]*class="rt-ledger-direct[^>]*style="[^\"]*)padding:14px 0 0!important;~', '$1padding:14px 0 0!IMPORTANT;', $html, 1),
            'contact-encoded-priority' => preg_replace('~(<td\b[^>]*class="rt-ledger-direct[^>]*style="[^\"]*)padding:14px 0 0!important;~', '$1padding:14px 0 0&#33;important;', $html, 1),
            'contact-duplicate-padding' => preg_replace('~(<td\b[^>]*class="rt-ledger-direct[^>]*style="[^\"]*)padding:14px 0 0!important;~', '$1padding:14px 0 0!important;padding:14px 0 0;', $html, 1),
            'contact-side-padding' => preg_replace('~(<td\b[^>]*class="rt-ledger-direct[^>]*style="[^\"]*)padding:14px 0 0!important;~', '$1padding:14px 0 0!important;padding-top:14px!important;', $html, 1),
            'header-geometry' => preg_replace('~(<th\b[^>]*class="rt-phl[^>]*width=")48%~', '$150%', $html, 1),
            'header-marker' => str_replace('data-rt-personal-header="1"', 'data-rt-personal-header="1" data-rt-personal-contacts="1"', $html),
            'company-slot' => str_replace('<!-- RT-PERSONAL-COMPANY-SLOT-V2 -->', '', $html),
            'identity-slot' => str_replace('<!-- RT-PERSONAL-IDENTITY-SLOT-V2 -->', '', $html),
            'row-order' => str_replace('data-rt-personal-header="1"', 'data-rt-personal-header="0"', $html),
            'mirror-gap' => str_replace('.rt-delivery-group-cell{padding:14px 0 0!important;}', '.rt-delivery-group-cell{padding:12px 0 0!important;}', $html),
            'header-text' => str_replace('<tr data-rt-personal-header="1">', '<tr data-rt-personal-header="1">foreign text', $html),
            'duplicate-padding' => preg_replace('~(<div\b[^>]*style=")padding-top:0;~', '$1padding-top:0;padding:2px!important;', $html, 1),
            'missing-v2-class' => str_replace(' rt-pv2"', '"', $html),
            'duplicate-v2-class' => str_replace('class="rt-phl ', 'class="rt-phl rt-pv2 ', $html),
        };
        $this->expectException(RuntimeException::class);
        OutlookNativePersonalSignature::restore($html);
    }

    private function assertPersonal(string $html): void
    {
        $xpath = $this->xpath($html);
        $ledger = $this->one($xpath, 'rt-sign-ledger', 'table');
        self::assertSame(OutlookNativePersonalSignature::MARKER, $ledger->getAttribute('data-rt-personal-layout'));
        self::assertStringContainsString('rt-pv2', $ledger->getAttribute('class'));
        $rows = $xpath->query('./tr|./tbody/tr', $ledger);
        self::assertSame(2, $rows->length);
        $brand = $this->one($xpath, 'rt-ledger-brand', 'th');
        $contacts = $this->one($xpath, 'rt-ledger-contacts', 'th');
        self::assertSame('48%', $brand->getAttribute('width'));
        self::assertSame('52%', $contacts->getAttribute('width'));
        $leftHeader = $this->one($xpath, 'rt-phl', 'th');
        $rightHeader = $this->one($xpath, 'rt-phr', 'th');
        self::assertTrue($leftHeader->parentNode->isSameNode($rows->item(0)));
        self::assertTrue($rightHeader->parentNode->isSameNode($rows->item(0)));
        self::assertTrue($brand->parentNode->isSameNode($rows->item(1)));
        self::assertTrue($contacts->parentNode->isSameNode($rows->item(1)));
        self::assertSame('48%', $leftHeader->getAttribute('width'));
        self::assertSame('52%', $rightHeader->getAttribute('width'));
        self::assertSame(1, $xpath->query('.//div[contains(concat(" ",normalize-space(@class)," ")," rt-person-kopf ")]', $leftHeader)->length);
        self::assertSame(0, $xpath->query('.//div[contains(concat(" ",normalize-space(@class)," ")," rt-person-kopf ")]', $brand)->length);
        self::assertSame(1, $xpath->query('.//td[contains(concat(" ",normalize-space(@class)," ")," rt-ledger-direct ")]', $brand)->length);
        self::assertSame(0, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]', $brand)->length);
        self::assertSame(1, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]', $rightHeader)->length);
        self::assertSame(0, $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-logo ")]', $contacts)->length);
        self::assertSame(1, $xpath->query('.//td[contains(concat(" ",normalize-space(@class)," ")," rt-ledger-company ")]', $contacts)->length);
        self::assertSame(0, $xpath->query('.//div[contains(concat(" ",normalize-space(@class)," ")," rt-person-kopf ")]', $contacts)->length);
        foreach ([$brand, $contacts, $leftHeader, $rightHeader] as $cell) {
            self::assertSame('presentation', $cell->getAttribute('role'));
        }
    }

    private function assertBudgetAndMedia(array $document, bool $checkMedia = true): void
    {
        SignatureTableOverlapDelivery::assertRuntime(OutlookTrainBottomOverlay::restore($document['html']));
        self::assertLessThan(OutlookSignatureInlineStyle::MAX_CSS_BYTES, array_sum(array_map('strlen', $this->styles($document['html']))));
        self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($document['html'], 'UTF-16LE', 'UTF-8')), 2));
        $media = array_column($document['media'], null, 'contentId');
        preg_match_all('~<img\b[^>]*\bsrc="cid:([^"]+)"~', $document['html'], $images);
        if ($checkMedia) {
            self::assertNotEmpty($images[1]);
        } else {
            self::assertGreaterThan(0, preg_match_all('~<img\b~', $document['html']));
        }
        foreach ($checkMedia ? $images[1] : [] as $cid) {
            self::assertArrayHasKey($cid, $media);
            self::assertNotFalse(base64_decode($media[$cid]['base64'], true));
        }
        self::assertStringNotContainsString('background-image:', $document['html']);
        self::assertStringContainsString('border-left:6px solid #e90032;', $document['html']);
    }

    private function styles(string $html): array
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style>~s', $html, $styles);

        return $styles[1];
    }

    private function legacyProjection(string $canonical): string
    {
        return (new \ReflectionMethod(OutlookNativePersonalSignature::class, 'projectLegacy'))->invoke(null, $canonical);
    }

    private function mediaAndLinks(string $html): array
    {
        preg_match_all('~<img\b[^>]*>|<a\b[^>]*>~', $html, $tags);
        sort($tags[0]);

        return $tags[0];
    }

    private function source(bool $nested): string
    {
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        if ($nested) {
            $source = preg_replace_callback('~(<img class="rt-logo"[^>]*>)(<!--\[if mso\]>.*?<!\[endif\]-->)~s', static fn (array $match): string => '<table class="personal-logo-frame" role="presentation" width="180" height="31" border="0" cellspacing="0" cellpadding="0" style="width:180px;height:31px;table-layout:fixed;border-collapse:collapse;">'
                .'<tr><td width="180" height="31" style="width:180px;height:31px;padding:0;">'.$match[1].$match[2].'</td></tr></table>', $source, 1);
        }

        return $source;
    }

    private function employee(): User
    {
        $user = User::factory()->create(['name' => 'Alexandra Christine von RailTime Musterhausen', 'email' => 'alexandra.christine@operations.railtime-example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Alexandra Christine', 'last_name' => 'von RailTime Musterhausen', 'position' => 'Disposition und Betriebskoordination', 'phone' => '+49 4171 555123', 'mobile' => '+49 160 555123']);

        return $user;
    }

    private function publish(string $source, string $kind, bool $active = false): MailDocument
    {
        $builder = ['pages' => [['name' => 'Native personal fixture', 'component' => $source]], 'styles' => [], 'railtime' => ['document' => $kind, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::query()->create([
            'kind' => MailDocumentKind::from($kind), 'name' => 'Native personal fixture', 'status' => MailDocumentStatus::Published,
            'is_active' => $active ? true : null, 'html' => $source, 'css' => '', 'builder_data' => $builder,
            'published_html' => $source, 'published_css' => '', 'published_at' => now(),
            'content_hash' => MailDocument::contentHashFor($builder, $source, ''), 'version' => 1,
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    private function one(DOMXPath $xpath, string $class, string $tag): DOMElement
    {
        $elements = $xpath->query('//'.$tag.'[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        self::assertSame(1, $elements->length);

        return $elements->item(0);
    }
}
