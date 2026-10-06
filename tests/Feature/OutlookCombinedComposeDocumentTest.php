<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookCombinedComposeDocument;
use App\Support\OutlookAddin\OutlookTrainBottomOverlay;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class OutlookCombinedComposeDocumentTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
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

    public static function people(): array
    {
        return ['personal native48/52' => [true], 'generic native32/68' => [false]];
    }

    #[DataProvider('people')]
    public function test_actual_v32_native_fragments_share_one_continuous_body_carrier(bool $personal): void
    {
        [$template, $signature, $media, $user] = $this->fragments($personal);
        $before = MailDocument::query()->orderBy('id')->get()->toArray();
        $fingerprint = app(OutlookAddinPayloadService::class)->sourceFingerprint($user);
        $output = OutlookCombinedComposeDocument::build($template, $signature);
        $xpath = $this->xpath($output);
        $root = $xpath->query('//div[@data-rt-compose-document="combined-v1"]');
        self::assertSame(1, $root->length);
        $carrier = $xpath->query('./table[@class="rt-combined-compose-frame"]', $root->item(0));
        self::assertSame(1, $carrier->length);
        $cell = $xpath->query('./tbody/tr/td[@class="rt-combined-compose-cell"]', $carrier->item(0));
        self::assertSame(1, $cell->length);
        self::assertSame(2, $xpath->query('./div', $cell->item(0))->length);
        self::assertSame(0, $xpath->query('./br|./p', $cell->item(0))->length, 'No outside Office paragraphs can separate two body writes: there is only one carrier.');
        self::assertSame('rt-outlook-template', explode(' ', $xpath->query('./div[1]', $cell->item(0))->item(0)->getAttribute('class'))[0]);
        self::assertSame('rt-outlook-signature', explode(' ', $xpath->query('./div[2]', $cell->item(0))->item(0)->getAttribute('class'))[0]);
        self::assertStringContainsString('border-left:6px solid #e90032;', $carrier->item(0)->getAttribute('style'));
        foreach ($xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-native-compose-frame ") or contains(concat(" ",normalize-space(@class)," ")," rt-combined-signature-frame ")]') as $frame) {
            self::assertStringContainsString('border-left:0;', $frame->getAttribute('style'));
        }
        self::assertSame(1, $xpath->query('//*[@style and contains(@style,"border-left:6px solid #e90032;")]')->length);
        self::assertStringNotContainsString('RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE', $output);
        self::assertSame(2, substr_count($output, OutlookCombinedComposeDocument::MARKER));
        self::assertSame(1, substr_count($output, 'data-rt-template-signature-mode="combined-v1"'));
        self::assertSame(2, substr_count($output, 'RT-SIGNATURE-MANAGED-V1'));
        self::assertSame(2, preg_match_all('/RT-SIGNATURE-VERSION:[0-9a-f]{16}/', $output));
        self::assertSame($personal ? 4 : 2, $xpath->query('//th[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-wide-column ")]')->length);
        if ($personal) {
            foreach (['data-rt-personal-header', 'data-rt-personal-contacts'] as $rowAttribute) {
                self::assertSame(2, $xpath->query('//tr[@'.$rowAttribute.'="1"]/th')->length);
            }
        }
        self::assertSame($personal ? '48%' : '32%', $xpath->query('//th[contains(@class,"rt-ledger-brand")]')->item(0)->getAttribute('width'));
        self::assertSame($personal ? '52%' : '68%', $xpath->query('//th[contains(@class,"rt-ledger-contacts")]')->item(0)->getAttribute('width'));
        self::assertSame(1, $xpath->query('//table[contains(@class,"rt-hotline-banner")]')->length);
        self::assertStringContainsString('24/7', $output);
        self::assertStringContainsString('padding-left:40px;padding-right:40px;', $output);
        self::assertStringContainsString('padding-left:22px!important;padding-right:22px!important;', $output);
        self::assertSame(1, $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train ")]')->length);
        self::assertStringNotContainsString('background-image:', $output);
        OutlookTrainBottomOverlay::assertRuntime($signature);
        self::assertSame(1, $xpath->query('//div[@data-rt-train-bottom-overlay="intrinsic-img-v1"]/img[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train ")]')->length);
        self::assertSame($this->imagesAndLinks($template.$signature), $this->imagesAndLinks($output));
        self::assertSame($this->comments(str_replace('RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE', OutlookCombinedComposeDocument::MARKER, $template).$signature), $this->comments($output));
        $expectedTemplateStyle = str_replace('border-left:6px solid #e90032!important;', 'border-left:0!important;', implode('', $this->styleTags($template)), $templateBorders);
        $expectedSignatureStyle = str_replace('border-left:6px solid #e90032;', 'border-left:0;', implode('', $this->styleTags($signature)), $signatureBorders);
        self::assertSame(1, $templateBorders);
        self::assertSame(1, $signatureBorders);
        self::assertStringContainsString($expectedTemplateStyle, implode('', $this->styleTags($output)));
        self::assertStringContainsString($expectedSignatureStyle, implode('', $this->styleTags($output)), 'Only the generated outer-frame border mirror changes; every other CSS byte is retained.');
        preg_match_all('~\bsrc="cid:([^"]+)"~', $output, $cids);
        self::assertNotEmpty($cids[1]);
        foreach ($cids[1] as $cid) {
            self::assertArrayHasKey($cid, $media);
            self::assertNotFalse(base64_decode($media[$cid]['base64'], true));
        }
        self::assertLessThan(OutlookCombinedComposeDocument::MAX_CSS_BYTES, $this->cssBytes($output));
        self::assertLessThanOrEqual(OutlookCombinedComposeDocument::MAX_CHARACTERS, intdiv(strlen(mb_convert_encoding($output, 'UTF-16LE', 'UTF-8')), 2));
        self::assertSame($output, OutlookCombinedComposeDocument::build($template, $signature));
        self::assertSame($before, MailDocument::query()->orderBy('id')->get()->toArray());
        self::assertSame($fingerprint, app(OutlookAddinPayloadService::class)->sourceFingerprint($user));
    }

    public function test_opaque_css_literals_and_quoted_content_do_not_get_reserialized_or_rescoped(): void
    {
        [$template, $signature] = $this->fragments();
        $opaque = '<blockquote><table><tr><td style="padding-left:7px;content:\'$1 $0 \\rail;keep\';">Quoted text &amp; &lt;td&gt;</td></tr></table></blockquote>';
        $template = str_replace('Guten Tag,', 'Guten Tag,'.$opaque, $template);
        $style = '<style>.unrelated{content:"$1 \\rail & <td>";}/* opaque border-left:6px solid #e90032; */</style>';
        $template = $style.$template;
        $output = OutlookCombinedComposeDocument::build($template, $signature);
        self::assertStringContainsString($opaque, $output);
        self::assertStringContainsString($style, $output);
        self::assertStringNotContainsString('margin-bottom:-', $output);
        self::assertStringNotContainsString('#Signature', $output);
        self::assertStringNotContainsString('+div', $output);
    }

    public function test_removed_combined_override_style_cannot_restore_inner_border_mirrors(): void
    {
        [$template, $signature] = $this->fragments();
        $output = OutlookCombinedComposeDocument::build($template, $signature);
        $sanitized = preg_replace('~<style data-rt-combined-compose-css="1">.*?</style>~s', '', $output, -1, $removed);
        self::assertSame(1, $removed);
        self::assertStringNotContainsString('data-rt-combined-compose-css', $sanitized);
        self::assertSame(1, preg_match('~\.rtt[0-9a-f]{12} \.rt-native-compose-frame\{[^}]*border-left:0!important;~', $sanitized));
        self::assertSame(1, preg_match('~\.rts[0-9a-f]{10}\.(oi[0-9a-z]+),\.rts[0-9a-f]{10} \.\1\{[^}]*border-left:0;~', $sanitized));
        self::assertStringNotContainsString('border-left:6px solid #e90032!important;', $sanitized);
        self::assertSame(1, substr_count($sanitized, 'border-left:6px solid #e90032;'), 'Only the single common carrier keeps the red accent.');
        self::assertSame($this->imagesAndLinks($output), $this->imagesAndLinks($sanitized));
        self::assertSame($this->comments($output), $this->comments($sanitized));
        $xpath = $this->xpath($sanitized);
        foreach ($xpath->query('//table[contains(@class,"rt-native-compose-frame") or contains(@class,"rt-combined-signature-frame")]') as $frame) {
            self::assertStringContainsString('border-left:0;', $frame->getAttribute('style'));
        }
    }

    public function test_frame_alias_is_resolved_from_generated_classes_not_hardcoded_oi2(): void
    {
        [$template, $signature] = $this->fragments();
        $frame = $this->xpath($signature)->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-signature ")]/table')->item(0);
        self::assertSame(1, preg_match('/(?:^|\s)(oi[0-9a-z]+)(?:\s|$)/', $frame->getAttribute('class'), $alias));
        $signature = preg_replace('/\b'.preg_quote($alias[1], '/').'\b/', 'oiabc', $signature);
        $output = OutlookCombinedComposeDocument::build($template, $signature);
        self::assertSame(1, preg_match('~\.rts[0-9a-f]{10}\.oiabc,\.rts[0-9a-f]{10} \.oiabc\{[^}]*border-left:0;~', $output));
        self::assertSame($this->imagesAndLinks($template.$signature), $this->imagesAndLinks($output));
    }

    public static function malformedMirrors(): array
    {
        return ['missing template mirror' => ['template-missing'], 'duplicate template rule' => ['template-duplicate'], 'changed template rule' => ['template-changed'],
            'missing signature mirror' => ['signature-missing'], 'duplicate signature rule' => ['signature-duplicate'], 'changed signature rule' => ['signature-changed'],
            'missing generated alias' => ['alias-missing'], 'multiple generated aliases' => ['alias-multiple'], 'generated alias reused by a contact' => ['alias-reused']];
    }

    #[DataProvider('malformedMirrors')]
    public function test_missing_changed_or_ambiguous_generated_frame_mirrors_fail_closed(string $mutation): void
    {
        [$template, $signature] = $this->fragments();
        $frame = $this->xpath($signature)->query('//div[contains(concat(" ",normalize-space(@class)," ")," rt-outlook-signature ")]/table')->item(0);
        self::assertSame(1, preg_match('/(?:^|\s)(oi[0-9a-z]+)(?:\s|$)/', $frame->getAttribute('class'), $alias));
        if (str_starts_with($mutation, 'template-')) {
            $template = match ($mutation) {
                'template-missing' => preg_replace('~<style data-rt-outlook-template-css="1">.*?</style>~s', '', $template),
                'template-duplicate' => preg_replace('~(\.rtt[0-9a-f]{12} \.rt-native-compose-frame\{[^}]*\})~', '$1$1', $template),
                'template-changed' => str_replace('border-left:6px solid #e90032!important;', 'border-left:7px solid #e90032!important;', $template),
            };
        } else {
            $signature = match ($mutation) {
                'signature-missing' => preg_replace('~<style data-rt-outlook-signature-inline-css="1">.*?</style>~s', '', $signature),
                'signature-duplicate' => preg_replace('~(\.rts[0-9a-f]{10}\.'.$alias[1].',\.rts[0-9a-f]{10} \.'.$alias[1].'\{[^}]*\})~', '$1$1', $signature),
                'signature-changed' => preg_replace('~(\.rts[0-9a-f]{10}\.'.$alias[1].',\.rts[0-9a-f]{10} \.'.$alias[1].'\{[^}]*?)border-left:6px solid #e90032;~', '$1border-left:7px solid #e90032;', $signature),
                'alias-missing' => str_replace('class="'.$alias[1].'"', '', $signature),
                'alias-multiple' => str_replace('class="'.$alias[1].'"', 'class="'.$alias[1].' oiabc"', $signature),
                'alias-reused' => preg_replace('/class="rt-contact-text\b/', 'class="'.$alias[1].' rt-contact-text', $signature, 1),
            };
        }
        $this->expectException(RuntimeException::class);
        OutlookCombinedComposeDocument::build($template, $signature);
    }

    public static function malformed(): array
    {
        return array_combine(
            ['missing mode', 'one marker', 'duplicate root', 'foreign scope', 'duplicate style', 'duplicate frame', 'wrong border', 'double border', 'wrong width', 'unclosed template', 'unclosed signature', 'mixed scope', 'outside text', 'outside node', 'already combined', 'full document', 'html budget', 'css budget', 'visible metadata', 'foreign metadata cell'],
            array_map(static fn (string $mutation): array => [$mutation], ['mode', 'marker', 'root', 'scope', 'style', 'frame', 'border', 'borders', 'width', 'unclosed-template', 'unclosed-signature', 'mixed', 'text', 'node', 'combined', 'document', 'html-budget', 'css-budget', 'visible-marker', 'foreign-marker-cell']),
        );
    }

    #[DataProvider('malformed')]
    public function test_ambiguous_modified_or_oversized_fragments_fail_closed(string $mutation): void
    {
        [$template, $signature] = $this->fragments();
        $template = match ($mutation) {
            'mode' => str_replace('data-rt-template-signature-mode="native"', '', $template),
            'marker' => preg_replace('/RT-TEMPLATE-MANAGED-V1:NATIVE-SIGNATURE/', 'MISSING', $template, 1),
            'root' => $template.$template,
            'scope' => preg_replace('/\brtt[0-9a-f]{12}\b/', 'foreign', $template),
            'style' => preg_replace('/(<table\b[^>]*rt-native-compose-frame[^>]*)(>)/', '$1 style="width:100%;">', $template, 1),
            'frame' => str_replace('Guten Tag,', 'Guten Tag,<table class="rt-native-compose-frame"><tr><td></td></tr></table>', $template),
            'border' => str_replace('border-left:6px solid #e90032;', 'border-left:7px solid #e90032;', $template),
            'borders' => str_replace('border-left:6px solid #e90032;', 'border-left:6px solid #e90032;border-left:6px solid #e90032;', $template),
            'width' => preg_replace_callback('~<table\b[^>]*class="[^"]*rt-native-compose-frame[^"]*"[^>]*>~', static fn (array $match): string => str_replace('width="100%"', 'width="99%"', $match[0]), $template, 1),
            'unclosed-template' => substr($template, 0, -6),
            'mixed' => str_replace('Guten Tag,', '<div class="rt-outlook-signature">Foreign signature</div>', $template),
            'text' => 'Visible outside text'.$template,
            'node' => '<div>Outside node</div>'.$template,
            'combined' => OutlookCombinedComposeDocument::build($template, $signature),
            'document' => '<html><body>'.$template.'</body></html>',
            'html-budget' => str_replace('Guten Tag,', str_repeat('😀', 50000), $template),
            'css-budget' => '<style>'.str_repeat('/* opaque */', 24576).'</style>'.$template,
            'visible-marker' => str_replace('<span hidden aria-hidden="true" class="rt-office-metadata"', '<span class="rt-office-metadata"', $template),
            'foreign-marker-cell' => str_replace('rt-native-template-mark', 'foreign-template-mark', $template),
            default => $template,
        };
        if ($mutation === 'unclosed-signature') {
            $signature = substr($signature, 0, -6);
        }
        $this->expectException(RuntimeException::class);
        OutlookCombinedComposeDocument::build($template, $signature);
    }

    /** Real published V32-shaped template plus existing native media/compiler path. */
    private function fragments(bool $personal = true): array
    {
        $signatureSource = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $hotline = '<table class="rt-hotline-banner" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;background-color:#e90032;"><tr><td style="padding:14px 22px;color:#ffffff;">24/7 Hotline <a href="mailto:dispo@railtime-example.test"><img src="{{ICON_EMAIL_SRC}}" width="24" height="24" alt="E-Mail"></a><a href="tel:+49160555123"><img src="{{ICON_PHONE_SRC}}" width="24" height="24" alt="Telefon"></a></td></tr></table>';
        $signatureSource = str_replace('<div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;">', '<div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;">'.$hotline, $signatureSource);
        $signature = $this->publish($signatureSource, MailDocumentKind::Signature);
        $signature->update(['outlook_default' => true]);
        $master = file_get_contents(EmailTemplateBuilder::masterPath('email-master.html'));
        preg_match('~<!-- RT_TEMPLATE_MARK_START -->.*?<!-- RT_TEMPLATE_MARK_END -->~s', $master, $mark);
        $head = substr($master, 0, strpos($master, '<body'));
        $body = '<body data-rt-theme="{{THEME}}" bgcolor="{{PAGE_BG}}" style="margin:0;padding:0;background:{{PAGE_BG}};color:{{TEXT_PRIMARY}};font-family:Arial,Helvetica,sans-serif;">'
            .'<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;">Ihre Nachricht von {{FIRMENNAME}}.</div>'
            .'<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;"><tbody><tr><td class="design-page-pad" style="padding:34px 0 38px;">'
            .'<div style="width:100%;box-sizing:border-box;border-left:6px solid #e90032;overflow:hidden;"><table class="rt-shell design-v27" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"><tbody>'
            .'<tr><td class="rt-pad" style="padding:29px 42px 23px 22px;"><table dir="rtl" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"><tbody><tr><td align="right"></td>'.$mark[0].'</tr></tbody></table></td></tr>'
            .'<!-- RT_APPLICATION_CONTENT_START -->{{APPLICATION_CONTENT}}<tr><td class="rt-pad" style="padding:0 42px 26px 40px;font-size:16px;line-height:26px;">'
            .'<p class="design-salutation" style="margin:0 0 20px;font-size:26px;line-height:34px;font-weight:bold;">Guten Tag,</p>'
            .'<p class="design-spacer" style="margin:0 0 20px;"><br><br></p><p class="design-greeting" style="margin:0;">Mit freundlichen Grüßen,</p>'
            .'</td></tr><!-- RT_APPLICATION_CONTENT_END -->{{SIGNATURE_BLOCK}}</tbody></table></div></td></tr></tbody></table></body></html>';
        $this->publish($head.$body, MailDocumentKind::Template)->update(['outlook_released' => true, 'outlook_default' => true, 'published_signature_document_id' => $signature->id]);
        $user = User::factory()->create(['name' => $personal ? 'Alexandra RailTime' : '', 'email' => 'alexandra@railtime-example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => $personal ? 'Alexandra' : '', 'last_name' => $personal ? 'RailTime' : '', 'position' => 'Disposition', 'phone' => '+49 4171 555123', 'mobile' => '+49 160 555123']);
        $this->app->forgetScopedInstances();
        $payload = app(OutlookAddinPayloadService::class)->forUser($user);
        $template = $payload['templates'][0];
        $selectedSignature = $template['signature'] ?? $payload['signature'];
        $media = array_column(array_merge($template['composeMedia'], $selectedSignature['media']), null, 'contentId');

        return [$template['composeHtml'], $selectedSignature['html'], $media, $user];
    }

    private function publish(string $source, MailDocumentKind $kind): MailDocument
    {
        $builder = ['pages' => [['name' => 'Combined compose fixture', 'component' => $source]], 'styles' => [], 'railtime' => ['document' => $kind->value, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::query()->create([
            'kind' => $kind, 'name' => 'Combined compose fixture', 'status' => MailDocumentStatus::Published, 'is_active' => true,
            'html' => $source, 'css' => '', 'builder_data' => $builder, 'published_html' => $source, 'published_css' => '',
            'published_at' => now(), 'content_hash' => MailDocument::contentHashFor($builder, $source, ''), 'version' => 1,
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

    private function imagesAndLinks(string $html): array
    {
        preg_match_all('~<img\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>|<a\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $html, $matches);

        return $matches[0];
    }

    private function comments(string $html): array
    {
        preg_match_all('~<!--.*?-->~s', $html, $matches);

        return $matches[0];
    }

    private function styleTags(string $html): array
    {
        preg_match_all('~<style\b[^>]*>.*?</style\s*>~s', $html, $matches);

        return $matches[0];
    }

    private function cssBytes(string $html): int
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style\s*>~s', $html, $matches);

        return array_sum(array_map('strlen', $matches[1]));
    }
}
