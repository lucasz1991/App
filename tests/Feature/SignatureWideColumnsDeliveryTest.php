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

final class SignatureWideColumnsDeliveryTest extends TestCase
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

    public static function profiles(): array
    {
        return ['personal Outlook signature' => ['personal'], 'company system signature' => ['company']];
    }

    #[DataProvider('profiles')]
    public function test_wide_render_uses_exactly_two_real_outer_cells_and_preserves_published_state(string $profile): void
    {
        $source = $this->source();
        SignatureDocumentContract::assertValid($source);
        $this->publish($source, 'signature', active: true)->update(['outlook_default' => true]);
        $user = $this->employee();
        $before = $this->publishedState();
        $this->app->forgetScopedInstances();

        $html = $profile === 'personal'
            ? (new EmailTemplateBuilder($user))->buildOutlookAddinSignatureHtml()
            : MailSignature::forCompany()->render();

        $this->assertWideColumns($html);
        SignatureTableOverlapDelivery::assertRuntime($html);
        self::assertSame($before, $this->publishedState(), 'Rendering must not alter source, publication, default or pairing records.');
        self::assertSame($source, $this->source(), 'The portable source fixture remains unchanged.');
        $sourceXpath = $this->xpath($source);
        self::assertSame(0, $sourceXpath->query('//th')->length, 'Presentation TH cells belong only to delivery output, never to the portable source.');
        $this->one($sourceXpath, 'rt-ledger-brand', 'td');
        $this->one($sourceXpath, 'rt-ledger-contacts', 'td');
        self::assertStringNotContainsString('6031.746032%', $html);
        self::assertStringNotContainsString('background-image:', $html);
        if ($profile === 'personal') {
            self::assertStringContainsString('Alexandra Christine von RailTime Musterhausen', $html);
            self::assertStringContainsString('alexandra.christine@operations.railtime-example.test', $html);
        }
    }

    public function test_wide_columns_have_an_explicit_narrow_cell_reset_in_both_canonical_stylesheets(): void
    {
        $html = SignatureTableOverlapDelivery::project(str_replace('{{TRAIN_SRC}}', 'cid:train.gif', $this->source()), 'cid:train.png');
        $this->assertWideColumns($html);

        foreach ([TrustedEmailCss::forDocument($html), TrustedOutlookSignatureCss::responsive($html)] as $css) {
            $narrow = $this->mediaBody($css, 'max-width:860px');
            self::assertNotSame('', $narrow);
            self::assertMatchesRegularExpression('~\.rt-delivery-wide-column\s*\{[^}]*display:block!important;~', $narrow);
            self::assertMatchesRegularExpression('~\.rt-delivery-wide-column\s*\{[^}]*width:100%!important;~', $narrow);
            self::assertMatchesRegularExpression('~\.rt-delivery-wide-column\s*\{[^}]*box-sizing:border-box!important;~', $narrow);
            self::assertMatchesRegularExpression('~\.rt-ledger-contacts\.rt-delivery-wide-column\s*\{[^}]*padding-top:14px!important;~', $narrow);
            self::assertStringNotContainsString('>tbody{display:table-row', $css);
            self::assertStringNotContainsString('.rt-delivery-ledger-group{display:table-cell', $css);
            self::assertStringContainsString('max-width:600px', $css);
            self::assertMatchesRegularExpression('~\.rt-delivery-wide-column\s*\{[^}]*font-weight:normal!important;~', $css);
            self::assertLessThanOrEqual(12288, strlen($css));
        }
    }

    public function test_final_global_and_paired_payloads_keep_wide_cells_cids_and_proportional_closed_train_branches(): void
    {
        [$payload, $before, $paired] = $this->desktopPayload();
        self::assertCount(1, $payload['templates']);
        self::assertSame($paired->public_id, $payload['templates'][0]['signatureDocumentId']);
        foreach ([$payload['signature'], $payload['templates'][0]['signature']] as $signature) {
            $this->assertWideColumns($signature['html']);
            $this->assertTrainMedia($signature);
            $this->assertBudget($signature['html']);
            self::assertStringContainsString('alexandra.christine@operations.railtime-example.test', $signature['html']);
        }
        self::assertSame($before, $this->publishedState());
    }

    public function test_mobile_adapter_remains_a_physical_stack_without_css_and_preserves_desktop_payload_media(): void
    {
        [$desktop, $before] = $this->desktopPayload();
        $original = $desktop;
        $mobile = OutlookMobileSignature::payload($desktop);
        self::assertSame($original, $desktop, 'Mobile projection must not change the cached desktop artifact.');
        foreach ([['signature'], ['templates', 0, 'signature']] as $path) {
            $desktopSignature = $desktop;
            $signature = $mobile;
            foreach ($path as $key) {
                $desktopSignature = $desktopSignature[$key];
                $signature = $signature[$key];
            }
            self::assertSame($desktopSignature['media'], $signature['media']);
            $withoutCss = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $signature['html']);
            $withoutCss = preg_replace('~\sstyle="[^"]*"~i', '', $withoutCss);
            $xpath = $this->xpath($withoutCss);
            self::assertSame(0, $xpath->query('//th')->length, 'Mobile converts only the trusted outer TH layout cells back into physical TD stacks.');
            $ledger = $this->one($xpath, 'rt-sign-ledger', 'table');
            self::assertSame(2, $xpath->query('./tr|./tbody/tr', $ledger)->length);
            foreach (['rt-ledger-brand', 'rt-ledger-contacts', 'rt-ledger-direct', 'rt-ledger-company'] as $class) {
                $cell = $this->one($xpath, $class, 'td');
                self::assertSame('100%', $cell->getAttribute('width'));
                self::assertSame(1, $xpath->query('./td', $cell->parentNode)->length);
            }
            $this->assertTrainMedia($signature);
            $this->assertBudget($signature['html']);
            self::assertSame(1, substr_count($signature['html'], 'Alexandra Christine von RailTime Musterhausen'));
        }
        self::assertSame($before, $this->publishedState());
    }

    public static function untrustedHeaderMutations(): array
    {
        return ['foreign header semantics' => ['role'], 'missing delivery opt-in' => ['class']];
    }

    #[DataProvider('untrustedHeaderMutations')]
    public function test_mobile_adapter_rejects_untrusted_header_cells_instead_of_converting_them(string $mutation): void
    {
        [$payload] = $this->desktopPayload();
        $payload['signature']['html'] = preg_replace_callback(
            '~<th\b(?=[^>]*\bclass="[^\"]*\brt-delivery-wide-column\b)[^>]*>~i',
            static fn (array $match): string => $mutation === 'role'
                ? str_replace('role="presentation"', 'role="columnheader"', $match[0])
                : str_replace(' rt-delivery-wide-column', '', $match[0]),
            $payload['signature']['html'],
            1,
            $count,
        );
        self::assertSame(1, $count);
        $before = $payload;
        try {
            OutlookMobileSignature::payload($payload);
            self::fail('An untrusted TH must not acquire the generated presentation-cell compatibility contract.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('fremde Kopfzellattribute', $exception->getMessage());
        }
        self::assertSame($before, $payload, 'A rejected mobile transformation must not mutate the cached desktop payload.');
    }

    private function assertWideColumns(string $html): void
    {
        $xpath = $this->xpath($html);
        $ledger = $this->one($xpath, 'rt-sign-ledger', 'table');
        $rows = $xpath->query('./tr|./tbody/tr', $ledger);
        self::assertSame(1, $rows->length, 'The wide layout is a real table row, not two rows recomposed with CSS roles.');
        $cells = $xpath->query('./td|./th', $rows->item(0));
        self::assertSame(2, $cells->length);
        $personal = $ledger->getAttribute('data-rt-personal-layout') === 'identity-left-v1';
        foreach (['rt-ledger-brand' => $personal ? '48%' : '32%', 'rt-ledger-contacts' => $personal ? '52%' : '68%'] as $class => $width) {
            $cell = $this->one($xpath, $class, '*');
            self::assertSame('th', $cell->tagName);
            self::assertSame('presentation', $cell->getAttribute('role'));
            self::assertStringContainsString('font-weight:normal;', $cell->getAttribute('style'));
            self::assertTrue($cell->parentNode->isSameNode($rows->item(0)));
            self::assertSame($width, $cell->getAttribute('width'));
            self::assertStringContainsString('rt-delivery-wide-column', $cell->getAttribute('class'));
            self::assertStringNotContainsString('min-width:', $cell->getAttribute('style'));
        }
        self::assertSame(2, $xpath->query('//th')->length, 'Only the two outer layout cells are projected to presentation TH.');
        self::assertSame(2, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-wide-column ")]')->length);
        $direct = $this->one($xpath, 'rt-ledger-direct', 'td');
        $company = $this->one($xpath, 'rt-ledger-company', 'td');
        self::assertFalse($direct->parentNode->isSameNode($company->parentNode), 'The right column keeps two physical stacked contact groups, not a third visual column.');
        foreach ([$direct, $company] as $cell) {
            self::assertSame('100%', $cell->getAttribute('width'));
            self::assertSame(1, $xpath->query('./td', $cell->parentNode)->length);
            self::assertStringNotContainsString('rt-delivery-wide-column', $cell->getAttribute('class'));
        }
    }

    private function assertTrainMedia(array $signature): void
    {
        $html = $signature['html'];
        SignatureTableOverlapDelivery::assertRuntime($html);
        $xpath = $this->xpath($html);
        $gif = $this->one($xpath, 'rt-delivery-train', 'img');
        $trainCell = $this->one($xpath, 'rt-delivery-train-cell', 'td');
        self::assertTrue($gif->parentNode->isSameNode($trainCell));
        foreach (['width' => '300', 'height' => '38'] as $attribute => $value) {
            self::assertSame($value, $gif->getAttribute($attribute));
        }
        self::assertStringContainsString('width:100%', $gif->getAttribute('style'));
        self::assertStringContainsString('max-width:600px', $gif->getAttribute('style'));
        self::assertStringContainsString('height:auto', $gif->getAttribute('style'));
        self::assertSame(1, preg_match('~<!--\[if !mso\]><!-->\s*(<img\b[^>]*class="rt-delivery-train(?:\s[^\"]*)?"[^>]*>)\s*<!--<!\[endif\]-->\s*<!--\[if mso\]>(<img\b[^>]*class="rt-delivery-train-mso"[^>]*>)<!\[endif\]-->~is', $html, $branches));
        $media = array_column($signature['media'], null, 'contentId');
        foreach ([1 => IMAGETYPE_GIF, 2 => IMAGETYPE_PNG] as $index => $type) {
            $imageXpath = $this->xpath($branches[$index]);
            $image = $imageXpath->query('//img')->item(0);
            self::assertStringStartsWith('cid:', $image->getAttribute('src'));
            $cid = substr($image->getAttribute('src'), 4);
            self::assertArrayHasKey($cid, $media);
            $imageSize = getimagesizefromstring(base64_decode($media[$cid]['base64'], true));
            self::assertNotFalse($imageSize);
            self::assertSame([1205, 151], array_slice($imageSize, 0, 2));
            self::assertSame($type, $imageSize[2]);
            if ($index === 2) {
                self::assertSame('300', $image->getAttribute('width'));
                self::assertSame('38', $image->getAttribute('height'));
                self::assertStringNotContainsString('min-width:', $image->getAttribute('style'));
                self::assertLessThanOrEqual(320, (int) $image->getAttribute('width'), 'The Word fallback must fit a 320 px reading pane without depending on CSS max-width.');
            }
        }
        self::assertStringNotContainsString('6031.746032%', $html);
        self::assertStringNotContainsString('background-image:', $html);
        self::assertSame(0, $xpath->query('//img[starts-with(@src,"http")]')->length, 'The final signature uses actual embedded CID media, not remote substitutions.');
    }

    private function assertBudget(string $html): void
    {
        preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $styles);
        self::assertLessThanOrEqual(12288, array_sum(array_map('strlen', $styles[1])));
        self::assertLessThanOrEqual(30000, intdiv(strlen(mb_convert_encoding($html, 'UTF-16LE', 'UTF-8')), 2));
    }

    private function desktopPayload(): array
    {
        $this->publish($this->source(), 'signature', active: true)->update(['outlook_default' => true]);
        $paired = $this->publish($this->source()."\n", 'signature');
        $this->publish(file_get_contents(EmailTemplateBuilder::masterPath('email-master.html')), 'template', active: true)->update([
            'published_signature_document_id' => $paired->id,
            'outlook_released' => true,
            'outlook_default' => true,
        ]);
        $user = $this->employee();
        $before = $this->publishedState();
        $this->app->forgetScopedInstances();

        return [app(OutlookAddinPayloadService::class)->forUser($user), $before, $paired];
    }

    private function employee(): User
    {
        $user = User::factory()->create(['name' => 'Alexandra Christine von RailTime Musterhausen', 'email' => 'alexandra.christine@operations.railtime-example.test']);
        UserProfile::create(['user_id' => $user->id, 'first_name' => 'Alexandra Christine', 'last_name' => 'von RailTime Musterhausen', 'position' => 'Disposition und Betriebskoordination', 'phone' => '+49 4171 555123']);

        return $user;
    }

    private function source(): string
    {
        return trim(file_get_contents(base_path('tests/Fixtures/mail/signature-v27-ledger.html')));
    }

    private function publish(string $source, string $kind, bool $active = false): MailDocument
    {
        $builder = ['pages' => [['name' => 'Wide columns fixture', 'component' => $source]], 'styles' => [], 'railtime' => ['document' => $kind, 'schema' => SignatureDocumentContract::SCHEMA]];

        return MailDocument::query()->create([
            'kind' => MailDocumentKind::from($kind), 'name' => 'Wide columns fixture',
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

    private function mediaBody(string $css, string $condition): string
    {
        $position = strpos($css, '('.$condition.')');
        self::assertNotFalse($position);
        $start = strpos($css, '{', $position);
        self::assertNotFalse($start);
        $depth = 1;
        for ($end = $start + 1, $length = strlen($css); $end < $length; $end++) {
            $depth += ($css[$end] === '{' ? 1 : 0) - ($css[$end] === '}' ? 1 : 0);
            if ($depth === 0) {
                return substr($css, $start + 1, $end - $start - 1);
            }
        }
        self::fail('Unclosed narrow media query.');
    }
}
