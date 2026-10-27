<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookMobileHybridDelivery;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutlookMobileHybridDeliveryTest extends TestCase
{
    #[DataProvider('formats')]
    public function test_late_mobile_view_has_one_copy_of_all_four_groups_and_retains_source_content(bool $compact): void
    {
        $source = $this->document($compact);
        $output = OutlookMobileHybridDelivery::project($source);
        $format = fn (string $html): string => $compact ? $this->compact($html) : $html;
        self::assertSame($output, OutlookMobileHybridDelivery::project($output));
        self::assertStringContainsString('data-rt-mobile-hybrid="v1"', $output);
        self::assertStringContainsString('max-width:680px;', $output);
        self::assertSame(4, substr_count($output, 'max-width:340px;'));
        self::assertSame(4, substr_count($output, 'border-width:0 18px;'));
        self::assertStringContainsString('display:inline-table;vertical-align:bottom;', $output);
        self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $output);
        preg_match('/class="rt-mhy-image rt-i([0-9a-f]{6})"/', $output, $scope);
        self::assertNotEmpty($scope);
        self::assertStringContainsString('<div class=rt-w'.$scope[1].'>', $output);
        self::assertStringContainsString('.rt-w'.$scope[1].'{overflow:hidden;padding-top:9.2%}.rt-i'.$scope[1].'{transform:scale(1.733333);transform-origin:100% 100%}', $output);
        self::assertSame(1, substr_count($output, $format($this->logo())));
        self::assertSame(1, substr_count($output, $format($this->person())));
        $expectedCompany = preg_replace('/class="rt-personal-company-contacts mh" style="padding-top:14px;?"/', 'class="rt-personal-company-contacts" style="padding-top:4px!important;"', $format($this->company()));
        self::assertSame(1, substr_count($output, $expectedCompany));
        self::assertStringContainsString($format($this->mso()), $output);
        self::assertSame(1, substr_count($output, 'src="cid:train.gif"'));
        self::assertSame(1, preg_match_all('/height=(?:"38"|38)(?=\s|>)/', $output));
        self::assertStringContainsString('data-note="a > b"', $output);
        self::assertStringContainsString('Company &amp; address', $output);
        self::assertStringContainsString('<!-- Outside unchanged --><p>Editable message &amp; quote.</p>', $output);
        self::assertStringContainsString('Legal &amp; disclaimer.', $output);
        self::assertStringNotContainsString('class="rt-personal-column rt-personal-left', $output);
        self::assertStringNotContainsString('class="rt-personal-column rt-personal-right', $output);
        self::assertStringNotContainsString(' .m14{', $output);
        self::assertStringNotContainsString(' .mb{', $output);
        self::assertStringContainsString(' .mh{padding-top:14px!important}', $output);
        self::assertStringContainsString('overflow:hidden;direction:ltr;margin:0 auto 0 0;', $output);
        self::assertSame(1, substr_count($output, 'float:left;'));
        self::assertStringNotContainsString('clear:left;', $output);
        self::assertStringNotContainsString('direction:rtl;', $output);
        self::assertStringNotContainsString('position:sticky', $output);
        self::assertStringContainsString($this->canonicalStyle(), $output);
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($output, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $groups = (new DOMXPath($dom))->query('//table[@data-rt-mobile-hybrid="v1"]/tbody/tr/td/table');
        self::assertCount(4, $groups);
        self::assertCount(2, (new DOMXPath($dom))->query('//table[@data-rt-mobile-hybrid="v1"]/tbody/tr'));
        self::assertCount(3, (new DOMXPath($dom))->query('//table[@data-rt-mobile-hybrid="v1"]/tbody/tr[1]/td/table'));
        self::assertSame('rt-mhy-train', (new DOMXPath($dom))->query('//table[@data-rt-mobile-hybrid="v1"]/tbody/tr[2]/td/table')->item(0)->getAttribute('class'));
        foreach (['logo', 'person', 'company', 'train'] as $index => $kind) {
            self::assertSame('rt-mhy-'.$kind, $groups->item($index)->getAttribute('class'));
            self::assertSame('ltr', $groups->item($index)->getAttribute('dir'));
            $cell = (new DOMXPath($dom))->query('./tbody/tr/td', $groups->item($index))->item(0);
            self::assertSame('ltr', $cell->getAttribute('dir'));
            self::assertSame('left', $cell->getAttribute('align'));
            if ($kind === 'person') {
                self::assertSame('left', $groups->item($index)->getAttribute('align'));
                self::assertSame('50', $groups->item($index)->getAttribute('height'));
                self::assertStringStartsWith('display:inline-table;', $groups->item($index)->getAttribute('style'));
            }
            if ($kind !== 'train') {
                self::assertStringNotContainsString('table-layout:fixed;', $groups->item($index)->getAttribute('style'));
            }
        }
    }

    public static function formats(): iterable
    {
        yield 'standalone aliases' => [false];
        yield 'compact combined aliases' => [true];
    }

    public function test_a_conditional_owner_keeps_a_generated_alias_rule_that_would_otherwise_be_unused(): void
    {
        $source = str_replace('class="rt-logo-mso"', 'class="rt-logo-mso ma"', $this->document());
        $output = OutlookMobileHybridDelivery::project($source);
        self::assertStringContainsString('.m123.rtm.rtm .ma{max-width:920px!important}', $output);
        self::assertStringContainsString('class="rt-logo-mso ma"', $output);
        self::assertSame($output, OutlookMobileHybridDelivery::project($output));
    }

    public function test_optional_tbody_and_non_geometry_quoted_values_survive_without_a_dom_round_trip(): void
    {
        $source = str_replace(['<tbody>', '</tbody>'], '', $this->document());
        $source = str_replace('class="rt-personal-name"', "class='rt-personal-name'", $source);
        $output = OutlookMobileHybridDelivery::project($source);
        self::assertStringContainsString("<div class='rt-personal-name'>", $output);
        self::assertStringContainsString($this->mso(), $output);
        self::assertStringContainsString('href="mailto:person@example.test" data-note="a > b"', $output);
        self::assertSame($output, OutlookMobileHybridDelivery::project($output));
    }

    public function test_empty_published_style_and_opaque_fake_markers_are_preserved(): void
    {
        $opaque = '<!-- <table data-rt-mobile-hybrid="v9">Not live markup</table> -->';
        $source = '<style data-rt-mail-document-css="signature"> </style>'.$opaque.$this->document();
        $output = OutlookMobileHybridDelivery::project($source);
        self::assertStringStartsWith('<style data-rt-mail-document-css="signature"> </style>'.$opaque, $output);
        self::assertSame($output, OutlookMobileHybridDelivery::project($output));
        $unrelated = $opaque.'<!-- rt-mobile-ledger data-rt-personal-columns="v1" --><p>Other message</p>';
        self::assertSame($unrelated, OutlookMobileHybridDelivery::project($unrelated));
    }

    public function test_desktop_and_system_output_are_byte_identical_bypasses(): void
    {
        $desktop = str_replace(' rt-mobile-ledger rtm', '', $this->document());
        self::assertSame($desktop, OutlookMobileHybridDelivery::project($desktop));
        $system = '<div class="rt-outlook-signature rt-mobile-ledger rtm"><table><tr><td>System</td></tr></table></div>';
        self::assertSame($system, OutlookMobileHybridDelivery::project($system));
    }

    #[DataProvider('invalidDocuments')]
    public function test_recognized_ambiguous_or_foreign_structure_fails_closed(string $before, string $after): void
    {
        $this->expectException(RuntimeException::class);
        OutlookMobileHybridDelivery::project(str_replace($before, $after, $this->document()));
    }

    public static function invalidDocuments(): iterable
    {
        yield 'unknown artifact' => ['data-rt-artifact-version="v27"', 'data-rt-artifact-version="v28"'];
        yield 'unknown personal marker' => ['data-rt-personal-columns="v1"', 'data-rt-personal-columns="v2"'];
        yield 'foreign flow style' => ['max-width:920px;', 'max-width:900px;'];
        yield 'foreign column style' => ['max-width:none;', 'max-width:300px;'];
        yield 'foreign cell attribute' => ['class="rt-personal-cell-left mc"', 'class="rt-personal-cell-left mc" data-note="foreign"'];
        yield 'foreign personal sibling' => ['<div class="rt-personal-direct mh"', '<p>Foreign note</p><div class="rt-personal-direct mh"'];
        yield 'foreign personal text' => ['<div class="rt-personal-direct mh"', 'Foreign note<div class="rt-personal-direct mh"'];
        yield 'foreign company sibling' => ['<div class="rt-personal-company-contacts mh"', '<p>Foreign</p><div class="rt-personal-company-contacts mh"'];
        yield 'foreign company spacing' => ['class="rt-personal-company-contacts mh" style="padding-top:14px;"', 'class="rt-personal-company-contacts mh" style="padding-top:20px;"'];
        yield 'foreign company class' => ['class="rt-personal-company-contacts mh"', 'class="rt-personal-company-contacts mh custom"'];
        yield 'duplicate logo' => ['<div class="rt-personal-name">', '<table class="rt-personal-logo-frame"><tr><td>Other</td></tr></table><div class="rt-personal-name">'];
        yield 'not a real logo' => ['class="rt-logo"', 'class="foreign-logo"'];
        yield 'foreign train width' => ['width="300"', 'width="500"'];
        yield 'foreign train geometry' => ['width:100%;max-width:100%;height:auto;', 'width:500px;max-width:100%;height:auto;'];
        yield 'train gap' => ['</td></tr></tbody></table></td></tr><tr><td>Legal', '</td><td>Extra</td></tr></tbody></table></td></tr><tr><td>Legal'];
        yield 'authored CSS' => ['<!-- Outside unchanged -->', '<style data-rt-mail-document-css="signature">table{width:2px}</style><!-- Outside unchanged -->'];
        yield 'unknown CSS owner' => ['<!-- Outside unchanged -->', '<style>.custom{display:block}</style><!-- Outside unchanged -->'];
        yield 'duplicate attribute' => ['class="rt-personal-name"', 'class="rt-personal-name" class="duplicate"'];
        yield 'malformed closing tag' => ['Name &amp; role</p>', 'Name &amp; role</p data-note="bad">'];
        yield 'foreign projected marker' => ['<!-- Outside unchanged -->', '<table data-rt-mobile-hybrid="v1"></table><!-- Outside unchanged -->'];
    }

    public function test_projected_geometry_or_extra_siblings_are_rejected_on_replay(): void
    {
        $output = OutlookMobileHybridDelivery::project($this->document());
        preg_match('/<div class=(rt-w[0-9a-f]{6})>/', $output, $window);
        foreach ([['max-width:680px;', 'max-width:681px;'], ['data-rt-mobile-hybrid="v1"', 'data-rt-mobile-hybrid="v2"'],
            ['<div class="rt-personal-name">', '<p>Foreign replay</p><div class="rt-personal-name">'],
            ['width="300"', 'width="520"'], ['height="38"', 'height="66"'], ['height=50', 'height=49'],
            ['transform:scale(1.733333)', 'transform:scale(5)'], ['padding-top:9.2%', 'padding-top:20%'],
            ['<div class='.$window[1].'>', '<div class='.$window[1].' style="overflow:visible">'],
            ['Name &amp; role', 'Changed replay name'], [$window[1], 'rt-wabcdef'],
            ['</td></tr><tr><td dir=ltr align=left style="padding:0;">', '']] as [$before, $after]) {
            try {
                OutlookMobileHybridDelivery::project(str_replace($before, $after, $output));
                self::fail('A modified projected structure must not count as current.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_lost_stylesheets_leave_a_complete_proportional_train_in_an_independent_table_row(): void
    {
        $output = OutlookMobileHybridDelivery::project($this->document());
        $fallback = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $output);
        $fallback = str_replace('float:left;', '', $fallback);
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($fallback, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $train = $xpath->query('//table[@class="rt-mhy-train"]')->item(0);
        $image = $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," rt-mhy-image ")]', $train)->item(0);
        self::assertSame('cid:train.gif', $image->getAttribute('src'));
        self::assertSame('300', $image->getAttribute('width'));
        self::assertSame('38', $image->getAttribute('height'));
        self::assertStringContainsString('width:100%;max-width:100%;height:auto;', $image->getAttribute('style'));
        self::assertStringNotContainsString('transform', $fallback);
        self::assertStringNotContainsString('padding-top:9.2%', $fallback);
        self::assertStringNotContainsString('520px', $fallback);
        self::assertFalse($image->parentNode->hasAttribute('style'));
        self::assertMatchesRegularExpression('/\Art-w[0-9a-f]{6}\z/', $image->parentNode->getAttribute('class'));
        self::assertSame('rt-mhy-train', $xpath->query('//table[@data-rt-mobile-hybrid="v1"]/tbody/tr[2]/td/table')->item(0)->getAttribute('class'));
        self::assertCount(3, $xpath->query('//table[@data-rt-mobile-hybrid="v1"]/tbody/tr[1]/td/table'));
        self::assertStringContainsString('border-left:0;', $train->getAttribute('style'));
        self::assertStringContainsString($this->mso(), $fallback);
    }

    public function test_enhancement_requires_one_existing_owned_mobile_stylesheet_and_rejects_foreign_rules(): void
    {
        $source = $this->document();
        foreach ([
            preg_replace('~<style data-rt-outlook-mobile-css="1">.*?</style>~s', '', $source),
            str_replace('<!-- Outside unchanged -->', '<style data-rt-outlook-mobile-css="1">.m123.rtm .m1{width:2px}</style><!-- Outside unchanged -->', $source),
            str_replace('.m123.rtm .m14{', '.rt-mhy-image{transform:scale(9)}.m123.rtm .m14{', $source),
            str_replace('.m123.rtm .m14{', '.rt-w{overflow:visible}.m123.rtm .m14{', $source),
        ] as $invalid) {
            try {
                OutlookMobileHybridDelivery::project($invalid);
                self::fail('Enhancement must not claim an absent, duplicate or foreign stylesheet.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_owned_wording_selectors_do_not_collide_with_the_exact_window_class(): void
    {
        $source = str_replace('.m123.rtm .m14{', '.rt-wording-greeting{margin:0}.m123.rtm .m14{', $this->document());
        $output = OutlookMobileHybridDelivery::project($source);
        self::assertStringContainsString('.rt-wording-greeting{margin:0}', $output);
        self::assertSame($output, OutlookMobileHybridDelivery::project($output));
    }

    public function test_enhancement_cannot_target_an_older_quoted_mobile_image_or_a_generic_window(): void
    {
        $output = OutlookMobileHybridDelivery::project($this->document());
        preg_match('/class="rt-mhy-image rt-i([0-9a-f]{6})"/', $output, $scope);
        $quoted = '<div class="rt-w"><img class="rt-mhy-image" src="cid:older.gif" width="520" style="width:520px;"></div>';
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($output.$quoted, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        self::assertCount(2, $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-mhy-image ")]'));
        self::assertCount(1, $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," rt-i'.$scope[1].' ")]'));
        self::assertCount(1, $xpath->query('//div[@class="rt-w'.$scope[1].'"]'));
        self::assertStringNotContainsString('.rt-mhy-image{', $output);
        self::assertStringNotContainsString('.rt-w{', $output);
        $changed = OutlookMobileHybridDelivery::project(str_replace('person@example.test', 'other@example.test', $this->document()));
        self::assertStringNotContainsString('rt-i'.$scope[1], $changed);
        self::assertStringNotContainsString('rt-w'.$scope[1], $changed);
        self::assertSame($changed, OutlookMobileHybridDelivery::project($changed));
    }

    public function test_unformatted_personal_and_company_text_inherits_a_visible_baseline_instead_of_zero(): void
    {
        $source = str_replace([' style="font-size:22px;"', ' style="font-size:13px;"'], '', $this->document());
        $output = OutlookMobileHybridDelivery::project($source);
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($output, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        foreach (['person' => 'Name & role', 'company' => 'Company & address'] as $kind => $text) {
            $cell = $xpath->query('//table[@class="rt-mhy-'.$kind.'"]/tbody/tr/td')->item(0);
            self::assertNotNull($cell);
            self::assertStringContainsString('font-size:13px;line-height:18px;', $cell->getAttribute('style'));
            self::assertStringContainsString($text, $cell->textContent);
        }
        self::assertSame($output, OutlookMobileHybridDelivery::project($output));
    }

    private function document(bool $compact = false): string
    {
        $html = $this->canonicalStyle().'<style data-rt-outlook-mobile-css="1">'
            .'.m123.rtm.rtm .ma{max-width:920px!important}.m123.rtm.rtm .mb{display:table!important;max-width:none!important}'
            .'.m123.rtm.rtm .mc{padding:0 18px!important}.m123.rtm.rtm .mq{padding:24px 18px 0!important}'
            .'.m123.rtm .m14{width:100%!important;max-width:100%!important}.m123.rtm.rtm .mh{padding-top:14px!important}'
            .'</style><!-- Outside unchanged --><p>Editable message &amp; quote.</p>'
            .'<div class="rt-outlook-signature rts0123456789 rt-mobile-ledger rtm m123"><table><tbody><tr data-rt-artifact-version="v27"><td>'
            .'<table><tbody><tr><td><div class="rt-sign-ledger design-train-logo design-personal-columns rt-personal-columns ma" data-rt-personal-columns="v1" style="font-size:0;line-height:0;text-align:left;width:100%;max-width:920px;">'
            .'<table class="rt-personal-column rt-personal-left mb" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" align="left" style="display:table;vertical-align:top;width:100%;min-width:0;max-width:none;table-layout:fixed;border-collapse:collapse;margin:0;"><tbody><tr>'
            .'<td class="rt-personal-cell-left mc" style="padding:0 18px;font-size:13px;line-height:18px;vertical-align:top;text-align:left;">'.$this->logo().$this->person().'</td></tr></tbody></table>'
            .'<table class="rt-personal-column rt-personal-right mb" role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" align="left" style="display:table;vertical-align:top;width:100%;min-width:0;max-width:none;table-layout:fixed;border-collapse:collapse;margin:0;"><tbody><tr>'
            .'<td class="rt-personal-cell-right mq" style="padding:24px 18px 0;font-size:13px;line-height:18px;vertical-align:top;text-align:left;">'.$this->company().'</td></tr></tbody></table>'
            .'</div></td></tr><tr class="rt-delivery-train-row"><td class="rt-delivery-train-cell m13" width="100%" align="left" valign="bottom" style="width:100%;padding:0 18px 0 0;box-sizing:border-box;border:0;font-size:0;line-height:0;vertical-align:bottom;">'
            .'<!--[if !mso]><!--><img class="rt-delivery-train m14" src="cid:train.gif" width="300" height="38" alt="" style="display:block;width:100%;max-width:100%;height:auto;margin:0;border:0;vertical-align:bottom;"><!--<![endif]-->'.$this->mso()
            .'</td></tr></tbody></table></td></tr><tr><td>Legal &amp; disclaimer.</td></tr></tbody></table></div>';
        if ($compact) {
            $html = $this->compact($html);
        }

        return $html;
    }

    private function compact(string $html): string
    {
        $html = strtr($html, ['class="rt-outlook-signature ' => 'class="xz ', 'rt-mobile-ledger' => 'x10', ' rtm ' => ' xy ', '.rtm' => '.xy',
            'class="rt-delivery-train-cell ' => 'class="xq ', 'class="rt-delivery-train ' => 'class="xr ']);
        $html = preg_replace('/\b(role|width|height|align|valign|border|cellpadding|cellspacing)="([^"\s]+)"/', '$1=$2', $html);

        return str_replace(';"', '"', $html);
    }

    private function canonicalStyle(): string
    {
        return '<style data-rt-outlook-signature-css="1">.rts0123456789 .rt-personal-company-block{float:none!important}</style>';
    }

    private function logo(): string
    {
        return '<table class="rt-personal-logo-frame" width="200" style="width:200px;max-width:100%;"><tbody><tr><td style="padding-bottom:10px;">'
            .'<img class="rt-logo" src="cid:logo.png" width="175" height="30" alt="Company" style="display:block;width:175px;max-width:100%;height:auto;">'
            .'<!--[if mso]><img class="rt-logo-mso" src="cid:logo.png" width="175" height="30"><![endif]--></td></tr></tbody></table>';
    }

    private function person(): string
    {
        return '<div class="rt-personal-name"><p style="font-size:22px;">Name &amp; role</p></div>'
            .'<div class="rt-personal-direct mh" style="padding-top:14px;"><p style="font-size:13px;"><a href="mailto:person@example.test" data-note="a > b">person@example.test</a></p></div>';
    }

    private function company(): string
    {
        return '<table class="rt-personal-company-block" width="220" style="width:220px;max-width:100%;"><tbody><tr><td>'
            .'<div class="rt-personal-company-contacts mh" style="padding-top:14px;"><p style="font-size:13px;">Company &amp; address<br><a href="https://example.test">example.test</a></p></div></td></tr></tbody></table>';
    }

    private function mso(): string
    {
        return '<!--[if mso]><img class="rt-delivery-train-mso" src="cid:train-still.png" width="220" height="28" alt="" style="display:block;width:220px;height:28px;"><![endif]-->';
    }
}
