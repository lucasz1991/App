<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookMobileBrandOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OutlookMobileBrandOrderTest extends TestCase
{
    public function test_it_moves_only_the_existing_complete_logo_bytes_before_the_name(): void
    {
        $source = $this->document();
        $output = OutlookMobileBrandOrder::project($source);
        $expected = str_replace($this->logo(), '', $source);
        $expected = str_replace('<div class="rt-personal-name">', $this->logo().'<div class="rt-personal-name">', $expected);
        self::assertSame($expected, $output);
        self::assertSame(strlen($source), strlen($output));
        self::assertSame(1, substr_count($output, $this->logo()));
        self::assertSame(2, substr_count($output, 'src="cid:logo.png"'));
        self::assertSame(1, substr_count($output, 'src="cid:train.gif"'));
        self::assertSame(1, substr_count($output, '<!--[if mso]>'));
        self::assertSame($output, OutlookMobileBrandOrder::project($output));
        self::assertSame($source, str_replace($this->logo().'<div class="rt-personal-name">', '<div class="rt-personal-name">', str_replace('<div class="rt-personal-company-contacts">', $this->logo().'<div class="rt-personal-company-contacts">', $output)));
    }

    public function test_comment_style_and_quoted_fake_tags_do_not_become_live_nodes(): void
    {
        $opaque = '<!-- <table class="rt-personal-logo-frame"><tr><td>fake</td></tr></table> -->'
            .'<style data-note="a > b">.fake:before{content:"<table class=rt-personal-logo-frame>"}</style>';
        $source = $opaque.$this->document($opaque);
        $output = OutlookMobileBrandOrder::project($source);
        self::assertSame(2, substr_count($output, $opaque));
        self::assertSame(strlen($source), strlen($output));
        self::assertSame($output, OutlookMobileBrandOrder::project($output));
    }

    public function test_marker_text_in_opaque_content_does_not_opt_an_unrelated_document_in(): void
    {
        $source = '<!-- rt-mobile-ledger data-rt-personal-columns="v1" -->'
            .'<style>.fake{content:"rt-mobile-ledger data-rt-personal-columns"}</style><div>Other mail</div>';
        self::assertSame($source, OutlookMobileBrandOrder::project($source));
        $source = str_replace('data-rt-personal-columns="v1"', 'data-note="ordinary"', $this->document());
        self::assertSame($source, OutlookMobileBrandOrder::project($source));
    }

    public function test_desktop_personal_and_mobile_system_signatures_are_byte_unchanged(): void
    {
        $desktop = str_replace(' rt-mobile-ledger rtm', '', $this->document());
        self::assertSame($desktop, OutlookMobileBrandOrder::project($desktop));
        $system = '<div class="rt-outlook-signature rt-mobile-ledger rtm"><table><tr><td>System logo</td></tr></table></div>';
        self::assertSame($system, OutlookMobileBrandOrder::project($system));
    }

    public function test_optional_tbody_and_single_quoted_attributes_are_supported_without_reserializing(): void
    {
        $source = str_replace(['<tbody>', '</tbody>'], '', $this->document());
        $source = str_replace('class="rt-personal-name"', "class='rt-personal-name'", $source);
        $output = OutlookMobileBrandOrder::project($source);
        self::assertSame(strlen($source), strlen($output));
        self::assertStringContainsString($this->logoWithoutTbody()."<div class='rt-personal-name'>", $output);
        self::assertSame($output, OutlookMobileBrandOrder::project($output));
    }

    #[DataProvider('invalidDocuments')]
    public function test_recognized_foreign_or_ambiguous_structure_fails_closed(string $from, string $to): void
    {
        $this->expectException(RuntimeException::class);
        OutlookMobileBrandOrder::project(str_replace($from, $to, $this->document()));
    }

    public static function invalidDocuments(): iterable
    {
        yield 'unknown marker' => ['data-rt-personal-columns="v1"', 'data-rt-personal-columns="v2"'];
        yield 'duplicate logo frame' => ['<div class="rt-personal-company-contacts">', '<table class="rt-personal-logo-frame"><tr><td>foreign</td></tr></table><div class="rt-personal-company-contacts">'];
        yield 'foreign left sibling' => ['<div class="rt-personal-direct">', '<p>foreign note</p><div class="rt-personal-direct">'];
        yield 'foreign text' => ['<div class="rt-personal-direct">', 'foreign note<div class="rt-personal-direct">'];
        yield 'foreign right wrapper' => ['<table class="rt-personal-company-block">', '<div><table class="rt-personal-company-block">'];
        yield 'wrong left tag' => ['<td class="rt-personal-cell-left">', '<td class="rt-personal-cell-other">'];
        yield 'duplicate attribute' => ['class="rt-personal-name"', 'class="rt-personal-name" class="second"'];
        yield 'unclosed name' => ['<p>Name</p></div>', '<p>Name</p>'];
        yield 'malformed opening' => ['<div class="rt-personal-name">', '<div class="rt-personal-name>'];
        yield 'duplicate mobile root' => ['<div class="rt-personal-name">', '<div class="rt-mobile-ledger"></div><div class="rt-personal-name">'];
        yield 'wrong flow parent' => ['class="rt-personal-columns"', 'class="rt-personal-columns-other"'];
        yield 'foreign company sibling' => ['<div class="rt-personal-company-contacts">', '<p>foreign</p><div class="rt-personal-company-contacts">'];
        yield 'foreign flow sibling' => ['<table class="rt-personal-right">', '<div>foreign</div><table class="rt-personal-right">'];
        yield 'invalid close attributes' => ['<p>Name</p>', '<p>Name</p data-x="bad">'];
        yield 'foreign logo contents' => ['style="width:200px"><tbody><tr><td>', 'style="width:200px"><tbody><tr><td><p>foreign</p>'];
        yield 'missing real logo class' => ['class="rt-logo" src="cid:logo.png" width="175"', 'class="foreign-logo" src="cid:logo.png" width="175"'];
        yield 'empty real logo source' => ['src="cid:logo.png" width="175"', 'src="" width="175"'];
        yield 'unknown artifact' => ['data-rt-artifact-version="v27"', 'data-rt-artifact-version="v28"'];
        yield 'missing artifact' => ['data-rt-artifact-version="v27"', 'data-note="not-an-artifact"'];
    }

    private function document(string $opaque = ''): string
    {
        return '<!-- before untouched -->'.'<style>.original{color:#142231}</style>'
            .'<div class="rt-outlook-signature rts0123456789 rt-mobile-ledger rtm m123"><table><tbody><tr data-rt-artifact-version="v27"><td>'
            .'<div class="rt-personal-columns" data-rt-personal-columns="v1">'
            .'<table class="rt-personal-left"><tbody><tr><td class="rt-personal-cell-left">'
            .'<div class="rt-personal-name"><p>Name</p></div>'.$opaque
            .'<div class="rt-personal-direct"><a href="mailto:person@example.test">person@example.test</a></div>'
            .'</td></tr></tbody></table>'
            .'<table class="rt-personal-right"><tbody><tr><td class="rt-personal-cell-right">'
            .'<table class="rt-personal-company-block"><tbody><tr><td>'.$this->logo()
            .'<div class="rt-personal-company-contacts"><a href="https://example.test">Company &amp; address</a></div>'
            .'</td></tr></tbody></table></td></tr></tbody></table></div>'
            .'<img src="cid:train.gif" width="600" data-note="a > b">'
            .'</td></tr></tbody></table></div><!-- after untouched -->';
    }

    private function logo(): string
    {
        return '<table class="rt-personal-logo-frame" style="width:200px"><tbody><tr><td>'
            .'<img class="rt-logo" src="cid:logo.png" width="175" height="30">'
            .'<!--[if mso]><img class="rt-logo" src="cid:logo.png" width="180" height="31"><![endif]-->'
            .'</td></tr></tbody></table>';
    }

    private function logoWithoutTbody(): string
    {
        return str_replace(['<tbody>', '</tbody>'], '', $this->logo());
    }
}
