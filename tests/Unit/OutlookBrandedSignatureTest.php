<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OutlookAddin\OutlookBrandedSignature;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// The shared checkout's autoloader must not resolve a different worktree copy.
require_once __DIR__.'/../../app/Support/OutlookAddin/OutlookBrandedSignature.php';

final class OutlookBrandedSignatureTest extends TestCase
{
    public function test_raster_banner_keeps_actions_and_surrounding_content_without_serializing_them(): void
    {
        $source = $this->document();
        $output = $this->project($source);
        self::assertStringContainsString('class="other design-hotline-row rt-brand-hotline-cell"', $output);
        self::assertSame(1, substr_count($output, 'design-hotline-row'));
        self::assertStringNotContainsString('design-hotline-spaced', $output);
        self::assertStringContainsString('data-rt-brand-hotline="v1"', $output);
        self::assertStringContainsString($this->mailOpen(), $output);
        self::assertStringContainsString($this->phoneOpen(), $output);
        self::assertStringContainsString($this->outside(), $output);
        self::assertSame(2, substr_count($output, '/mail-assets/outlook-hotline-fill-v1.png'));
        foreach (['label', 'mail', 'phone'] as $kind) {
            self::assertSame(1, substr_count($output, '/mail-assets/outlook-hotline-'.$kind.'-v1.png'));
        }
        self::assertSame(5, substr_count($output, 'height="34" align="top"'));
        self::assertStringContainsString('width="100%" height="34" align="top" alt=""', $output);
        self::assertStringContainsString('width="152" height="34" align="top" alt="24/7 erreichbar"', $output);
        self::assertStringContainsString('width="42" height="34"', $output);
        self::assertStringContainsString('bgcolor="#e90032"', $output);
        self::assertSame(5, substr_count($output, 'border="0" style="display:block;"'));
        self::assertSame(1, substr_count($output, 'style="width:100%;table-layout:fixed;"'));
        self::assertSame(6, substr_count($output, 'style="padding:0;font-size:0;line-height:0;"'));
        self::assertStringNotContainsString('max-width:none', $output);
        self::assertStringNotContainsString('vertical-align:middle', $output);
        self::assertStringNotContainsString('data:', $output);
        self::assertSame($output, $this->project($output));
    }

    public function test_comments_styles_and_quoted_fake_tags_stay_opaque_and_exact(): void
    {
        $opaque = '<!-- <td class="design-hotline-row" data-rt-brand-hotline="v1">fake</td> -->'
            .'<style data-note="a > b">.fake{content:"<tr data-rt-artifact-version=v27>"}</style>';
        $source = $opaque.str_replace($this->outside(), $opaque.$this->outside(), $this->document());
        $output = $this->project($source);
        self::assertSame(2, substr_count($output, $opaque));
        self::assertStringContainsString($this->outside(), $output);
        self::assertSame($output, $this->project($output));
    }

    public function test_unknown_designs_and_marker_text_are_unchanged(): void
    {
        foreach (['<p>normal</p>', '<tr data-rt-artifact-version="v28"><td>unknown unfinished source', str_replace('data-rt-artifact-version="v27"', 'data-rt-artifact-version="v28"', $this->document()), '<!-- <tr data-rt-artifact-version="v27"><td class="design-hotline-row">fake</td></tr> -->'] as $source) {
            self::assertSame($source, $this->project($source));
        }
    }

    public function test_older_unspaced_hotline_stays_unchanged_while_the_owned_role_cleanup_can_apply(): void
    {
        $source = str_replace(' design-hotline-spaced', '', $this->document());
        $output = $this->project($source);
        self::assertStringContainsString('class="other design-hotline-row"', $output);
        self::assertStringNotContainsString('outlook-hotline-', $output);
        self::assertStringNotContainsString('data-rt-brand-hotline=', $output);
        self::assertSame(str_replace($this->role(), '<p class="rt-personal-role"><span style="background-color:transparent!important;">Role</span></p>', $source), $output);
    }

    public function test_action_images_keep_accessible_names_when_original_anchors_have_no_aria_or_title(): void
    {
        $source = str_replace([$this->mailOpen(), $this->phoneOpen()], ['<a href="mailto:office@example.test">', '<a href="tel:+491234">'], $this->document());
        $output = $this->project($source);
        self::assertMatchesRegularExpression('~<a href="mailto:office@example.test"><img[^>]+alt="E-Mail an die Hotline"~', $output);
        self::assertMatchesRegularExpression('~<a href="tel:\+491234"><img[^>]+alt="Hotline anrufen"~', $output);
        self::assertSame($output, $this->project($output));
    }

    public function test_only_white_role_leaf_background_is_removed_before_mirror_generation(): void
    {
        $leaf = '<span data-note="keep > me" bgcolor="WHITE" style="font-size:10px;background-color:#fff!important;color:#e4002b;font-weight:bold;">IT-Technik</span>';
        $source = str_replace($this->role(), '<p class="rt-personal-role">'.$leaf.'</p>', $this->document());
        $output = $this->project($source);
        self::assertStringContainsString('<span data-note="keep > me" style="font-size:10px;color:#e4002b;font-weight:bold;background-color:transparent!important;">IT-Technik</span>', $output);
        self::assertStringContainsString('<span style="background-color:#fff">Name stays</span>', $output);
        self::assertStringContainsString('<span style="background:#eee;color:red">other background</span>', $output);
        self::assertSame($output, $this->project($output));
    }

    #[DataProvider('whiteBackgrounds')]
    public function test_exact_white_background_forms_are_removed_and_foreground_is_retained(string $background): void
    {
        $source = str_replace($this->role(), '<p class="rt-sign-role"><span style="'.$background.';color:red">Role</span></p>', $this->document());
        $output = $this->project($source);
        self::assertStringContainsString('<p class="rt-sign-role"><span style="color:red;background-color:transparent!important;">Role</span></p>', $output);
    }

    public static function whiteBackgrounds(): iterable
    {
        foreach (['background:#fff', 'background-color:#ffffff!important', 'BACKGROUND:White', 'background-color:rgb(255, 255, 255)', 'background:rgba(255,255,255,1.0)'] as $background) {
            yield $background => [$background];
        }
    }

    public function test_role_white_only_style_becomes_an_explicit_transparent_guard_without_removing_its_span(): void
    {
        $source = str_replace($this->role(), '<p class="rt-personal-role"><span style="background:#fff">Role</span></p>', $this->document());
        self::assertStringContainsString('<p class="rt-personal-role"><span style="background-color:transparent!important;">Role</span></p>', $this->project($source));
    }

    public function test_role_white_bgcolor_without_style_receives_the_same_unique_transparent_guard(): void
    {
        $source = str_replace($this->role(), '<p class="rt-personal-role"><span bgcolor="#fff">Role</span></p>', $this->document());
        $output = $this->project($source);
        self::assertStringContainsString('<p class="rt-personal-role"><span style="background-color:transparent!important;">Role</span></p>', $output);
        self::assertStringNotContainsString('bgcolor="#fff"', $output);
        self::assertSame($output, $this->project($output));
    }

    public function test_role_quoted_background_text_and_nonwhite_color_are_not_rewritten(): void
    {
        $role = '<p class="rt-personal-role"><span style="font-family:\'a;background:#fff\';background:transparent;color:#fff">Role</span></p>';
        $source = str_replace($this->role(), $role, $this->document());
        self::assertStringContainsString($role, $this->project($source));
    }

    #[DataProvider('ambiguousRoleBackgrounds')]
    public function test_authored_background_precedence_and_nonwhite_paint_leave_the_entire_leaf_unchanged(string $leaf): void
    {
        $source = str_replace($this->role(), '<p class="rt-personal-role">'.$leaf.'</p>', $this->document());
        $output = $this->project($source);
        self::assertStringContainsString('<p class="rt-personal-role">'.$leaf.'</p>', $output);
        self::assertSame($output, $this->project($output));
    }

    public static function ambiguousRoleBackgrounds(): iterable
    {
        yield 'white then red' => ['<span style="background:#fff;background:#e4002b">Role</span>'];
        yield 'red then white' => ['<span style="background:#e4002b;background:#fff">Role</span>'];
        yield 'important white then red' => ['<span style="background:#fff!important;background:#e4002b">Role</span>'];
        yield 'bgcolor white and style red' => ['<span bgcolor="white" style="background:#e4002b;color:#fff">Role</span>'];
        yield 'bgcolor red and style white' => ['<span bgcolor="#e4002b" style="background:#fff;color:red">Role</span>'];
        yield 'multiple white fallbacks' => ['<span style="background:#fff;background-color:white">Role</span>'];
        yield 'background image' => ['<span style="background:#fff;background-image:url(\'known.png\')">Role</span>'];
        yield 'background shorthand image' => ['<span style="background:#fff url(known.png)">Role</span>'];
        yield 'unknown background family rule' => ['<span style="background:#fff;background-position:top left">Role</span>'];
    }

    #[DataProvider('invalidStructures')]
    public function test_recognized_ambiguous_or_foreign_structure_fails_closed(string $from, string $to): void
    {
        $this->expectException(RuntimeException::class);
        $this->project(str_replace($from, $to, $this->document()));
    }

    public static function invalidStructures(): iterable
    {
        yield 'foreign label' => ['24/7</strong>', '24/8</strong>'];
        yield 'foreign caption' => [' erreichbar</td>', ' HOTLINE</td>'];
        yield 'foreign child in caption' => [' erreichbar</td>', '<span>erreichbar</span></td>'];
        yield 'foreign extra note' => ['<strong style="font-size:25px">', 'note<strong style="font-size:25px">'];
        yield 'mail link changed scheme' => ['mailto:office@example.test', 'https://example.test/mail'];
        yield 'phone link changed scheme' => ['tel:+491234', 'javascript:alert(1)'];
        yield 'foreign cell instead of spacer' => ['&nbsp;</td>', '<p>foreign</p></td>'];
        yield 'duplicate marker' => ['class="other design-hotline-row', 'data-rt-brand-hotline="v1" class="other design-hotline-row'];
        yield 'foreign outer parent' => ['class="rt-sign-cell"', 'class="rt-sign-other"'];
        yield 'malformed close' => ['</strong>', '</p>'];
        yield 'duplicate href' => ['href="tel:+491234"', 'href="tel:+491234" href="tel:+49999"'];
        yield 'duplicate role' => ['<p class="rt-personal-role">', '<p class="rt-sign-role">extra</p><p class="rt-personal-role">'];
        yield 'foreign role owner' => ['class="rt-person-kopf"', 'class="rt-person-other"'];
    }

    public function test_owned_raster_with_tampered_image_is_rejected(): void
    {
        $projected = $this->project($this->document());
        $this->expectException(RuntimeException::class);
        $this->project(str_replace('outlook-hotline-label-v1.png', 'foreign-label.png', $projected));
    }

    public function test_owned_raster_cannot_reintroduce_the_legacy_spaced_padding_contract(): void
    {
        $projected = $this->project($this->document());
        $this->expectException(RuntimeException::class);
        $this->project(str_replace('design-hotline-row rt-brand-hotline-cell', 'design-hotline-row design-hotline-spaced rt-brand-hotline-cell', $projected));
    }

    public function test_trusted_absolute_base_with_path_and_port_is_preserved(): void
    {
        $output = OutlookBrandedSignature::project($this->document(), 'http://localhost:8080/railtime/');
        self::assertStringContainsString('src="http://localhost:8080/railtime/mail-assets/outlook-hotline-label-v1.png"', $output);
        self::assertSame($output, OutlookBrandedSignature::project($output, 'http://localhost:8080/railtime/'));
    }

    #[DataProvider('invalidBaseUrls')]
    public function test_non_absolute_or_credentialed_asset_base_is_rejected(string $baseUrl): void
    {
        $this->expectException(RuntimeException::class);
        OutlookBrandedSignature::project($this->document(), $baseUrl);
    }

    public static function invalidBaseUrls(): iterable
    {
        foreach (['/relative', '//example.test', 'ftp://example.test', 'https://user:password@example.test', 'https://example.test?token=private', 'https://example.test#fragment', 'javascript:alert(1)', 'https://'] as $baseUrl) {
            yield $baseUrl => [$baseUrl];
        }
    }

    public function test_asset_hashes_use_exact_fixed_png_files_and_detect_changes(): void
    {
        $this->withAssets(function (string $directory): void {
            $before = OutlookBrandedSignature::assetHashes();
            self::assertCount(4, $before);
            foreach (['fill', 'label', 'mail', 'phone'] as $kind) {
                $url = '/mail-assets/outlook-hotline-'.$kind.'-v1.png';
                self::assertSame(hash_file('sha256', $directory.$url), $before[$url]);
            }
            file_put_contents($directory.'/mail-assets/outlook-hotline-label-v1.png', $this->png().'trailing approved-test-byte');
            $after = OutlookBrandedSignature::assetHashes();
            self::assertNotSame($before['/mail-assets/outlook-hotline-label-v1.png'], $after['/mail-assets/outlook-hotline-label-v1.png']);
            unset($before['/mail-assets/outlook-hotline-label-v1.png'], $after['/mail-assets/outlook-hotline-label-v1.png']);
            self::assertSame($before, $after);
        });
    }

    public function test_missing_asset_is_not_silently_accepted(): void
    {
        $this->withAssets(function (string $directory): void {
            unlink($directory.'/mail-assets/outlook-hotline-phone-v1.png');
            $this->expectException(RuntimeException::class);
            OutlookBrandedSignature::assetHashes();
        });
    }

    public function test_non_png_asset_is_not_silently_accepted(): void
    {
        $this->withAssets(function (string $directory): void {
            file_put_contents($directory.'/mail-assets/outlook-hotline-label-v1.png', 'not a png');
            $this->expectException(RuntimeException::class);
            OutlookBrandedSignature::assetHashes();
        });
    }

    private function withAssets(callable $test): void
    {
        $directory = sys_get_temp_dir().'/rt-branded-signature-'.bin2hex(random_bytes(6));
        mkdir($directory.'/mail-assets', 0777, true);
        foreach (['fill', 'label', 'mail', 'phone'] as $kind) {
            file_put_contents($directory.'/mail-assets/outlook-hotline-'.$kind.'-v1.png', $this->png());
        }
        $previous = Container::getInstance();
        (new Application(dirname(__DIR__, 2)))->usePublicPath($directory);
        try {
            $test($directory);
        } finally {
            Container::setInstance($previous);
            foreach (glob($directory.'/mail-assets/*') as $file) {
                unlink($file);
            }
            rmdir($directory.'/mail-assets');
            rmdir($directory);
        }
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==', true);
    }

    private function project(string $rows): string
    {
        return OutlookBrandedSignature::project($rows, 'https://example.test');
    }

    private function mailOpen(): string
    {
        return '<a title="Mail > now" aria-label="E-Mail senden" href="mailto:office@example.test?subject=A&amp;body=B" style="display:block;text-decoration:none">';
    }

    private function phoneOpen(): string
    {
        return "<a href=\"tel:+491234\" aria-label='Hotline anrufen' title=\"Telefon\">";
    }

    private function outside(): string
    {
        return '<!--[if mso]><img src="/mail-assets/train-still.png"><![endif]--><img src="/mail-assets/train.gif" width="600" alt="">';
    }

    private function role(): string
    {
        return '<p class="rt-personal-role"><span style="background-color:#fff">Role</span></p>';
    }

    private function document(): string
    {
        return '<tr data-rt-artifact-version="v27"><td class="rt-sign-cell">'
            .'<table class="rt-hotline-banner" width="100%" bgcolor="#e60032"><tbody><tr><td class="other design-hotline-row design-hotline-spaced" style="padding:3px 40px;color:#fff;background:#e60032"><table width="240"><tbody><tr>'
            .'<td><strong style="font-size:25px">24/7</strong></td><td> erreichbar</td>'
            .'<td>'.$this->mailOpen().'<img src="/mail-assets/old-mail.png" alt="E-Mail"></a></td><td width="16">&nbsp;</td>'
            .'<td>'.$this->phoneOpen().'<img src="/mail-assets/old-phone.png" alt="Telefon"></a></td>'
            .'</tr></tbody></table></td></tr></tbody></table>'
            .'<div class="rt-person-kopf"><p><span style="background-color:#fff">Name stays</span></p>'.$this->role().'</div>'
            .'<span style="background:#eee;color:red">other background</span>'.$this->outside().'</td></tr>';
    }
}
