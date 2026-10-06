<?php

namespace Tests\Feature;

use App\Enums\MailDocumentKind;
use App\Enums\MailDocumentStatus;
use App\Models\MailDocument;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTableOverlapDelivery;
use App\Support\Mail\TrustedEmailCss;
use App\Support\Mail\TrustedOutlookSignatureCss;
use App\Support\MailSignature;
use App\Support\OutlookAddin\OutlookAddinPayloadService;
use App\Support\OutlookAddin\OutlookMobileSignature;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

final class SignaturePaneWidthDeliveryTest extends TestCase
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

    public static function renderContexts(): array
    {
        return [
            'personal, direct logo' => ['personal', false],
            'personal, fixed nested logo' => ['personal', true],
            'company, direct logo' => ['company', false],
            'company, fixed nested logo' => ['company', true],
        ];
    }

    #[DataProvider('renderContexts')]
    public function test_logo_resolves_against_its_column_not_the_outer_window_without_editing_the_source(string $context, bool $nested): void
    {
        $source = $this->source($nested);
        SignatureDocumentContract::assertValid($source);
        $this->publish($source, 'signature', active: true)->update(['outlook_default' => true]);
        $user = $this->employee();
        $before = $this->publishedState();
        $this->app->forgetScopedInstances();
        $html = $context === 'personal'
            ? (new EmailTemplateBuilder($user))->buildOutlookAddinSignatureHtml()
            : MailSignature::forCompany(remoteAssets: true)->render();

        $this->assertFluidLogo($html, $nested);
        $this->assertCanonicalLogoCss($html);
        SignatureTableOverlapDelivery::assertRuntime($html);
        self::assertSame($before, $this->publishedState());
        self::assertSame($source, $this->source($nested));
        $sourceXpath = $this->xpath($source);
        self::assertSame('180', $this->one($sourceXpath, 'rt-logo', 'img')->getAttribute('width'));
        self::assertStringContainsString('width:180px;', $this->one($sourceXpath, 'rt-logo', 'img')->getAttribute('style'));
        if ($nested) {
            self::assertSame('180', $this->one($sourceXpath, 'pane-logo-table', 'table')->getAttribute('width'));
            self::assertSame('180', $this->one($sourceXpath, 'pane-logo-cell', 'td')->getAttribute('width'));
        }
        if ($context === 'personal') {
            self::assertSame(1, substr_count($html, 'Alexandra Christine von RailTime Musterhausen'));
            self::assertStringContainsString('alexandra.christine@operations.railtime-example.test', $html);
            $this->assertInlineMirror($html, $this->one($this->xpath($html), 'rt-logo', 'img'));
            if ($nested) {
                foreach (['pane-logo-table' => 'table', 'pane-logo-cell' => 'td'] as $class => $tag) {
                    $this->assertInlineMirror($html, $this->one($this->xpath($html), $class, $tag), $tag === 'table');
                }
            }
        }
    }

    public function test_adjacent_fixed_dimension_declarations_are_all_replaced_and_mso_logo_comment_stays_numeric(): void
    {
        $source = $this->source(true);
        $rendered = str_replace(['{{TRAIN_SRC}}', '{{LOGO_SRC}}', '{{LOGO_STILL_SRC}}'], ['cid:train.gif', 'cid:logo.gif', 'cid:logo.png'], $source);
        self::assertSame(1, preg_match('~<!--\[if mso\]><img class="rt-logo"[^>]*><!\[endif\]-->~', $rendered, $beforeComment));
        $html = SignatureTableOverlapDelivery::project($rendered, 'cid:train.png');
        $this->assertFluidLogo($html, true);
        self::assertStringContainsString($beforeComment[0], $html, 'The existing Word logo fallback is not rewritten by modern container normalization.');
        self::assertSame(1, preg_match('~<!--\[if mso\]>(<img class="rt-logo"[^>]*>)<!\[endif\]-->~', $html, $fallback));
        $fallbackXpath = $this->xpath($fallback[1]);
        $logo = $this->one($fallbackXpath, 'rt-logo', 'img');
        self::assertSame('180', $logo->getAttribute('width'));
        self::assertSame('31', $logo->getAttribute('height'));
        self::assertSame('180px', $this->properties($logo->getAttribute('style'))['width']);
        self::assertSame($html, SignatureTableOverlapDelivery::project($html, 'cid:train.png'));
        SignatureDocumentContract::assertValid($source);
    }

    public function test_actual_global_and_paired_payloads_keep_cids_budgets_defaults_and_mobile_physical_stacks(): void
    {
        $source = $this->source(true);
        $this->publish($source, 'signature', active: true)->update(['outlook_default' => true]);
        $paired = $this->publish($source."\n", 'signature');
        $this->publish(file_get_contents(EmailTemplateBuilder::masterPath('email-master.html')), 'template', active: true)->update([
            'published_signature_document_id' => $paired->id,
            'outlook_released' => true,
            'outlook_default' => true,
        ]);
        $user = $this->employee();
        $before = $this->publishedState();
        $this->app->forgetScopedInstances();
        $desktop = app(OutlookAddinPayloadService::class)->forUser($user);
        $desktopBefore = $desktop;
        $mobile = OutlookMobileSignature::payload($desktop);
        self::assertSame($desktopBefore, $desktop, 'The cached desktop payload is immutable.');
        self::assertSame($paired->public_id, $desktop['templates'][0]['signatureDocumentId']);

        foreach ([['signature'], ['templates', 0, 'signature']] as $path) {
            $desktopSignature = $desktop;
            $mobileSignature = $mobile;
            foreach ($path as $key) {
                $desktopSignature = $desktopSignature[$key];
                $mobileSignature = $mobileSignature[$key];
            }
            $this->assertFluidLogo($desktopSignature['html'], true);
            $this->assertInlineMirror($desktopSignature['html'], $this->one($this->xpath($desktopSignature['html']), 'rt-logo', 'img'));
            foreach ([$desktopSignature, $mobileSignature] as $signature) {
                $this->assertBudget($signature['html']);
                $this->assertEmbeddedMedia($signature);
            }
            self::assertSame($desktopSignature['media'], $mobileSignature['media']);
            $mobileXpath = $this->xpath($mobileSignature['html']);
            $ledger = $this->one($mobileXpath, 'rt-sign-ledger', 'table');
            self::assertSame(2, $mobileXpath->query('./tr|./tbody/tr', $ledger)->length);
            self::assertSame(0, $mobileXpath->query('//th')->length);
            foreach (['rt-ledger-brand', 'rt-ledger-contacts'] as $class) {
                $cell = $this->one($mobileXpath, $class, 'td');
                self::assertSame('100%', $cell->getAttribute('width'));
                self::assertSame(1, $mobileXpath->query('./td', $cell->parentNode)->length);
            }
        }
        self::assertSame($before, $this->publishedState(), 'Delivery cannot change any source, publication, default or pairing.');
    }

    private function assertFluidLogo(string $html, bool $nested): void
    {
        $xpath = $this->xpath($html);
        $brand = $this->one($xpath, 'rt-ledger-brand', '*');
        $logo = $this->one($xpath, 'rt-logo', 'img');
        self::assertSame('th', $brand->tagName);
        $ledger = $this->one($xpath, 'rt-sign-ledger', 'table');
        $personal = $ledger->getAttribute('data-rt-personal-layout') === 'identity-left-v1';
        self::assertSame($personal ? '48%' : '32%', $brand->getAttribute('width'), 'Personal identity/direct contacts need balanced columns; canonical company columns stay intact.');
        self::assertSame('180', $logo->getAttribute('width'), 'The numeric image attribute remains the documented CSS-free fallback.');
        self::assertSame('31', $logo->getAttribute('height'));
        $style = $this->properties($logo->getAttribute('style'));
        self::assertSame('100%', $style['width']);
        self::assertSame('180px', $style['max-width']);
        self::assertSame('auto', $style['height']);
        foreach (['min-width', 'min-height', 'max-height'] as $property) {
            self::assertArrayNotHasKey($property, $style);
        }
        foreach (['width', 'max-width', 'height'] as $property) {
            self::assertSame(1, preg_match_all('~(?:^|;)\s*'.preg_quote($property, '~').'\s*:~', $logo->getAttribute('style')), 'No superseded adjacent '.$property.' declaration can remain.');
        }
        if ($nested) {
            foreach (['pane-logo-table' => 'table', 'pane-logo-cell' => 'td'] as $class => $tag) {
                $wrapper = $this->one($xpath, $class, $tag);
                self::assertSame('100%', $wrapper->getAttribute('width'));
                self::assertFalse($wrapper->hasAttribute('height'));
                $wrapperStyle = $this->properties($wrapper->getAttribute('style'));
                self::assertSame('100%', $wrapperStyle['width']);
                if ($tag === 'table') {
                    self::assertSame('180px', $wrapperStyle['max-width']);
                } else {
                    self::assertArrayNotHasKey('max-width', $wrapperStyle);
                    self::assertSame('0', $wrapperStyle['padding']);
                }
                self::assertSame('auto', $wrapperStyle['height']);
                self::assertDoesNotMatchRegularExpression('~(?:^|;)\s*width\s*:\s*180px\s*(?:;|$)~', $wrapper->getAttribute('style'));
                foreach (['min-width', 'min-height', 'max-height'] as $property) {
                    self::assertArrayNotHasKey($property, $wrapperStyle);
                }
            }
            self::assertSame('fixed', $this->properties($this->one($xpath, 'pane-logo-table', 'table')->getAttribute('style'))['table-layout']);
        }
    }

    private function assertCanonicalLogoCss(string $html): void
    {
        foreach ([TrustedEmailCss::forDocument($html), TrustedOutlookSignatureCss::responsive($html)] as $css) {
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
            $found = false;
            foreach ($rules as $rule) {
                if (! str_contains($rule[1], '.rt-sign-ledger img.rt-logo')) {
                    continue;
                }
                $found = true;
                $properties = $this->properties($rule[2]);
                if (isset($properties['width'])) {
                    self::assertSame('100%', $properties['width'], 'A wide outer viewport must not reintroduce a fixed logo inside a narrow pane.');
                }
                if (isset($properties['max-width'])) {
                    self::assertLessThanOrEqual(180, (int) $properties['max-width']);
                }
            }
            self::assertTrue($found, 'The canonical logo rule must remain in both delivery stylesheets.');
            self::assertStringContainsString('max-width:180px!important', $css);
        }
    }

    private function assertInlineMirror(string $html, DOMElement $element, bool $bounded = true): void
    {
        self::assertSame(1, preg_match('~\brts[0-9a-f]{10}\b~', $html, $scope));
        preg_match_all('~\boi[0-9a-z]+\b~', $element->getAttribute('class'), $aliases);
        self::assertNotEmpty($aliases[0]);
        self::assertSame(1, preg_match('~<style\b[^>]*data-rt-outlook-signature-inline-css="1"[^>]*>(.*?)</style>~s', $html, $mirror));
        $matched = false;
        foreach ($aliases[0] as $alias) {
            if (preg_match('~\.'.$scope[0].' \.'.$alias.'\{([^}]+)\}~', $mirror[1], $rule) !== 1) {
                continue;
            }
            $style = $this->properties($rule[1]);
            $matched = ($style['width'] ?? null) === '100%' && ($style['height'] ?? null) === 'auto'
                && ($bounded ? ($style['max-width'] ?? null) === '180px' : ! isset($style['max-width']));
            if ($matched) {
                break;
            }
        }
        self::assertTrue($matched, 'Fluid inline geometry must also survive Office removing the style attribute.');
    }

    private function assertEmbeddedMedia(array $signature): void
    {
        SignatureTableOverlapDelivery::assertRuntime($signature['html']);
        $media = array_column($signature['media'], null, 'contentId');
        preg_match_all('~<img\b[^>]*\bsrc="([^\"]+)"~i', $signature['html'], $images);
        self::assertNotEmpty($images[1]);
        foreach ($images[1] as $source) {
            self::assertStringStartsWith('cid:', $source);
            $cid = substr($source, 4);
            self::assertArrayHasKey($cid, $media);
            self::assertNotFalse(getimagesizefromstring(base64_decode($media[$cid]['base64'], true)));
        }
        self::assertSame(1, substr_count($signature['html'], 'class="rt-delivery-train-mso"'));
        self::assertStringContainsString('<!--<![endif]-->', $signature['html']);
        self::assertStringNotContainsString('background-image:', $signature['html']);
    }

    private function assertBudget(string $html): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $styles);
        self::assertLessThanOrEqual(12288, array_sum(array_map('strlen', $styles[1])));
        self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
    }

    private function source(bool $nested): string
    {
        $source = trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
        $source = preg_replace('~(<!--\[if mso\]><img class="rt-logo"[^>]*\bwidth="180")~', '$1 height="31"', $source);
        if (! $nested) {
            return $source;
        }

        return preg_replace_callback('~(<img class="rt-logo"[^>]*>)(<!--\[if mso\]>.*?<!\[endif\]-->)~s', static fn (array $match): string => '<table class="pane-logo-table" role="presentation" width="180" height="31" border="0" cellspacing="0" cellpadding="0" style="width:180px;max-width:180px;min-width:180px;height:31px;max-height:31px;min-height:31px;table-layout:fixed;border-collapse:collapse;">'
            .'<tr><td class="pane-logo-cell" width="180" height="31" style="width:180px;max-width:180px;min-width:180px;height:31px;max-height:31px;min-height:31px;padding:0;">'
            .str_replace('width:180px;max-width:100%;height:auto;', 'width:180px;max-width:180px;min-width:180px;height:31px;max-height:31px;min-height:31px;', $match[1])
            .$match[2].'</td></tr></table>', $source, 1);
    }

    private function employee(): User
    {
        $user = User::factory()->create(['name' => 'Alexandra Christine von RailTime Musterhausen', 'email' => 'alexandra.christine@operations.railtime-example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Alexandra Christine', 'last_name' => 'von RailTime Musterhausen', 'position' => 'Disposition und Betriebskoordination', 'phone' => '+49 4171 555123']);

        return $user;
    }

    private function publish(string $source, string $kind, bool $active = false): MailDocument
    {
        $builder = ['pages' => [['name' => 'Pane width fixture', 'component' => $source]], 'styles' => [], 'railtime' => ['document' => $kind, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::query()->create([
            'kind' => MailDocumentKind::from($kind), 'name' => 'Pane width fixture',
            'status' => MailDocumentStatus::Published, 'is_active' => $active ? true : null,
            'html' => $source, 'css' => '', 'builder_data' => $builder,
            'published_html' => $source, 'published_css' => '', 'published_at' => now(),
            'content_hash' => MailDocument::contentHashFor($builder, $source, ''), 'version' => 1,
        ]);
    }

    private function publishedState(): array
    {
        return MailDocument::query()->orderBy('id')->get()->toArray();
    }

    private function properties(string $style): array
    {
        $properties = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) === 2) {
                $properties[strtolower(trim($parts[0]))] = trim(str_replace('!important', '', $parts[1]));
            }
        }

        return $properties;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($dom->loadHTML('<?xml encoding="UTF-8"><table>'.$html.'</table>', LIBXML_NONET));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    private function one(DOMXPath $xpath, string $class, string $tag): DOMElement
    {
        $elements = $xpath->query('//'.$tag.'[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        self::assertSame(1, $elements->length, 'Expected exactly one '.$class.'.');
        self::assertInstanceOf(DOMElement::class, $elements->item(0));

        return $elements->item(0);
    }
}
