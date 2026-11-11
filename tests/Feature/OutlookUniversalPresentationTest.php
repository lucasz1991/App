<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\OutlookAddin\OutlookUniversalPresentation;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Source/structure tests only; this suite does not claim native Outlook proof. */
final class OutlookUniversalPresentationTest extends TestCase
{
    private const SOURCE_HASH = 'c7efab2c64890dc57386448be880122e1e284c6c35a2392fcad5ed5094f94b6b';

    private const IMAGES = [
        ['b983af0049.png', 184, 32], ['9dfa25b253.png', 15, 15], ['d92bd192b6.png', 15, 15],
        ['1593d63cca.png', 15, 15], ['a38bc7d27c.png', 15, 15],
        ['19125261c2.png', 24, 24], ['f9d88b899d.png', 24, 24], ['1111670ac8.png', 24, 24],
        ['14d7943c88.png', 500, 83],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://mail.example.test']);
    }

    #[DataProvider('modes')]
    public function test_both_modes_keep_the_original_cell_layout_and_exact_image_material(string $mode): void
    {
        $result = $this->render(['mode' => $mode]);
        $xpath = $this->xpath($result['html']);
        $shells = $xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-shell ")]');
        self::assertSame(1, $shells->length);
        $shell = $shells->item(0);
        self::assertFalse($shell->hasAttribute('align'), 'An outer align attribute would enable legacy table wrapping.');
        self::assertSame('1040', $shell->getAttribute('width'));
        self::assertSame('0', $shell->getAttribute('cellpadding'));
        self::assertSame('0', $shell->getAttribute('cellspacing'));
        foreach (['width:1040px', 'max-width:100%', 'margin:0 auto 0 0', 'float:none', 'text-align:left'] as $rule) {
            self::assertStringContainsString($rule, $shell->getAttribute('style'));
        }
        $rows = $xpath->query('./tr|./tbody/tr', $shell);
        self::assertSame(4, $rows->length);
        self::assertSame([1, 4, 1, 1], array_map(fn (DOMNode $row): int => $xpath->query('./td', $row)->length, iterator_to_array($rows)));
        $columns = $xpath->query('./td', $rows->item(1));
        self::assertSame(['260', '280', '213', '287'], array_map(fn (DOMElement $cell): string => $cell->getAttribute('width'), iterator_to_array($columns)));
        foreach ($columns as $index => $column) {
            self::assertStringContainsString('width:'.[260, 280, 213, 287][$index].'px', $column->getAttribute('style'));
            self::assertStringContainsString('display:inline-block;max-width:100%;box-sizing:border-box;', $column->getAttribute('style'));
            self::assertSame('top', $column->getAttribute('valign'));
        }
        self::assertSame('2', $columns->item(3)->getAttribute('rowspan'));
        self::assertSame('4', $xpath->query('./td', $rows->item(0))->item(0)->getAttribute('colspan'));
        self::assertSame('3', $xpath->query('./td', $rows->item(2))->item(0)->getAttribute('colspan'));
        self::assertStringContainsString('40px', $xpath->query('./td', $rows->item(2))->item(0)->getAttribute('style'));
        $legal = $xpath->query('./td', $rows->item(3))->item(0);
        self::assertSame('4', $legal->getAttribute('colspan'));
        self::assertSame('#e4e8ec', $legal->getAttribute('bgcolor'));
        self::assertSame(1, $xpath->query('.//table[@width="247"]', $columns->item(3))->length);

        // Independent reference topology: 1 shell + 9 nested tables, no floating
        // columns or a second top-level author table are a valid substitute.
        self::assertSame(['1040', '100%', '100%', '100%', '100%', '100%', '100%', '247', '100%', '100%'],
            array_map(fn (DOMElement $table): string => $table->getAttribute('width'), iterator_to_array($xpath->query('//table'))));
        self::assertSame([4, 1, 1, 2, 1, 2, 1, 4, 1, 1],
            array_map(fn (DOMElement $table): int => $xpath->query('./tr|./tbody/tr', $table)->length, iterator_to_array($xpath->query('//table'))));
        $images = $xpath->query('//img');
        self::assertSame(9, $images->length);
        foreach (self::IMAGES as $index => [$file, $width, $height]) {
            $image = $images->item($index);
            self::assertSame('https://mail.example.test/m/'.$file, $image->getAttribute('src'));
            self::assertSame((string) $width, $image->getAttribute('width'));
            self::assertSame((string) $height, $image->getAttribute('height'));
            self::assertStringContainsString('width:'.$width.'px', $image->getAttribute('style'));
            self::assertStringContainsString('height:'.$height.'px', $image->getAttribute('style'));
        }
        self::assertSame(0, $xpath->query('//img[@width="8" and @height="8"]')->length);
        $caps = $xpath->query('//td[@class="rt-dispo-cap"]');
        self::assertSame(2, $caps->length);
        foreach ($caps as $index => $corner) {
            self::assertSame('#e4002b', $corner->getAttribute('bgcolor'));
            self::assertStringContainsString('background-color:#e4002b;', $corner->getAttribute('style'));
            self::assertStringContainsString('font-size:1px', $corner->getAttribute('style'));
            self::assertStringContainsString('line-height:8px', $corner->getAttribute('style'));
            self::assertStringContainsString('border-radius:'.['8px 8px 0 0', '0 0 8px 8px'][$index].';', $corner->getAttribute('style'));
            self::assertSame('', $corner->getAttribute('colspan'));
            self::assertSame(1, $xpath->query('./td', $corner->parentNode)->length);
            self::assertSame(0, $xpath->query('.//img', $corner)->length);
        }
        self::assertStringContainsString('border-collapse:separate;border-spacing:0;border-radius:8px;', $xpath->query('//table[@width="247"]')->item(0)->getAttribute('style'));
        self::assertSame(0, $xpath->query('//td[@class="rt-dispo-head" or @class="rt-dispo-actions"]/@colspan')->length);
        self::assertSame(self::SOURCE_HASH, $result['designSourceHash']);
        self::assertSame(hash('sha256', 'published synthetic source'), $result['sourceHash']);
        self::assertSame(hash('sha256', $result['headCss']), $result['headCssHash']);
        self::assertSame(intdiv(strlen(mb_convert_encoding($result['html'], 'UTF-16LE', 'UTF-8')), 2), $result['utf16Length']);
    }

    public function test_editor_is_the_default_and_has_only_four_image_links_without_head_or_body_style_rules(): void
    {
        $result = $this->render();
        self::assertSame($this->render(['mode' => 'editor'])['html'], $result['html']);
        self::assertSame('', $result['headCss']);
        self::assertLessThanOrEqual(27000, $result['utf16Length']);
        self::assertGreaterThanOrEqual(3000, 30000 - $result['utf16Length']);
        $withoutCk15 = $this->withoutCk15Additions($result['html'], false);
        self::assertDoesNotMatchRegularExpression('~<\s*(?:head|meta|style)\b|@media|table-layout|mso-|\[if|dir\s*=\s*["\x27]rtl~i', $withoutCk15);
        self::assertDoesNotMatchRegularExpression('~display\s*:\s*(?:none|inline-block)|RT-(?:FLUID|TEMPLATE|SIGNATURE-MANAGED)~i', $withoutCk15);
        $xpath = $this->xpath($result['html']);
        $anchors = $xpath->query('//a');
        self::assertSame(4, $anchors->length);
        self::assertSame(['https://example.invalid', 'mailto:dispo@example.invalid', 'https://wa.me/491601881848', 'tel:+491601881839'],
            array_map(fn (DOMElement $link): string => $link->getAttribute('href'), iterator_to_array($anchors)));
        foreach ($anchors as $link) {
            self::assertSame(1, $xpath->query('.//img', $link)->length);
            self::assertSame('', trim($link->textContent));
        }
    }

    public function test_send_mode_preserves_the_original_two_breakpoints_and_all_seven_metas(): void
    {
        $result = $this->render(['mode' => 'send']);
        $xpath = $this->xpath($result['html']);
        self::assertSame(1, $xpath->query('//head')->length);
        self::assertSame(1, $xpath->query('//head/style')->length);
        self::assertSame(7, $xpath->query('//head/meta')->length, 'The immutable U2e source has seven metas; no four-meta rewrite.');
        self::assertSame(0, $xpath->query('//body//style|//body//meta')->length);
        self::assertSame(3, substr_count($result['headCss'], '@media'));
        $referenceCss = str_replace("\r\n", "\n", file_get_contents(base_path('tests/Fixtures/mail/u2e-reference.css')));
        $originalCss = str_replace($this->desktopReset(), '', $this->withoutCk26MobileSpacing($this->withoutCk27Spacing($result['headCss'], false)));
        self::assertSame($referenceCss, $originalCss,
            'Reversing the approved C-K27 text spacing, C-K26 padding values and C-K15 reset recovers the immutable original media rules.');
        self::assertSame(1, substr_count($result['headCss'], $this->desktopReset()));
        self::assertStringContainsString('max-width:1039px', $result['headCss']);
        self::assertStringContainsString('max-width:600px', $result['headCss']);
        self::assertStringContainsString('width:52%', $result['headCss']);
        self::assertStringContainsString('width:48%', $result['headCss']);
        self::assertSame('telephone=no,date=no,address=no,email=no', $xpath->query('//head/meta[@name="format-detection"]')->item(0)->getAttribute('content'));
        self::assertSame(9, $xpath->query('//a')->length);
        foreach (['mailto:alex@example.invalid', 'tel:+491705550101', 'https://example.invalid', 'tel:+491601881839'] as $href) {
            self::assertGreaterThan(0, $xpath->query('//a[@href="'.$href.'"]')->length);
        }
        $maps = $xpath->query('//a[contains(@href,"maps.google.com")]');
        self::assertSame(1, $maps->length);
        self::assertStringContainsString('Musterweg', urldecode($maps->item(0)->getAttribute('href')));
        foreach ($xpath->query('//a[not(img)]') as $link) {
            self::assertStringContainsString('text-decoration:none', $link->getAttribute('style'));
            self::assertStringContainsString('color:', $link->getAttribute('style'));
            self::assertGreaterThan(0, $xpath->query('./span', $link)->length);
        }
        self::assertDoesNotMatchRegularExpression('~table-layout|mso-|\[if~i', $this->withoutCk15Additions($result['html'], true));
    }

    #[DataProvider('modes')]
    public function test_ck25_has_one_action_row_without_separator_or_cta_borders_and_preserves_padding(string $mode): void
    {
        $result = $this->render(['mode' => $mode]);
        $xpath = $this->xpath($result['html']);
        self::assertSame(0, $xpath->query('//tr[@class="rt-cta-line"]|//td[@colspan="5"]')->length);
        $actionRows = $xpath->query('//td[@class="rt-dispo-actions"]/table/tr|//td[@class="rt-dispo-actions"]/table/tbody/tr');
        self::assertSame(1, $actionRows->length);
        $actions = $xpath->query('./td', $actionRows->item(0));
        self::assertSame(5, $actions->length);
        foreach (['6px 0 4px', '6px 0 4px 14px', '6px 0 4px', '6px 8px 4px 0', '6px 0 4px'] as $index => $padding) {
            self::assertStringContainsString('padding:'.$padding.';', $actions->item($index)->getAttribute('style'));
            self::assertStringNotContainsString('border-top', $actions->item($index)->getAttribute('style'));
        }
        self::assertSame(4, $xpath->query('//td[contains(@style,"border-top:2px solid #e4002b")]')->length);
        self::assertSame(0, $xpath->query('//td[contains(@style,"border-top:1px solid #ffffff")]')->length);
        self::assertStringNotContainsString('rt-cta-line', $result['headCss']);
    }

    public function test_ck25_ck26_change_only_the_separator_and_approved_sizing_from_v23(): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        self::assertSame('0201650e3985cf95b7ef84d112fede5d8338017f6d725a764906da1a99e1c5dc', hash('sha256', $template));
        self::assertSame(15620, strlen($template));
        self::assertStringNotContainsString('rt-cta-line', $template);
        self::assertSame('e71922554f82861cab15ddf4cccd30717deb48a42034990ce675535367848bcd', hash('sha256', $this->withoutCk26Sizing($template)));
        $previous = $this->withCk25Separator($template);
        self::assertSame('5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204', hash('sha256', $previous));
        self::assertSame(248, strlen($previous) - strlen($this->withoutLeftAlignment($template)));
        self::assertSame($this->render()['version'], $this->render(['mode' => 'send'])['version']);
    }

    #[DataProvider('modes')]
    public function test_ck26_projects_the_compact_head_and_mobile_spacing_in_both_modes(string $mode): void
    {
        $result = $this->render(['mode' => $mode]);
        $xpath = $this->xpath($result['html']);
        foreach (['rt-cta-dispo' => 'font-size:19px;line-height:20px;',
            'rt-cta-erreichbar' => 'font-size:15px;line-height:18px;',
            'rt-cta-time' => 'font-size:34px;line-height:36px;'] as $class => $style) {
            $nodes = $xpath->query('//span[@class="'.$class.'"]');
            self::assertSame(1, $nodes->length);
            self::assertStringContainsString($style, $nodes->item(0)->getAttribute('style'));
        }
        $heads = $xpath->query('//td[@class="rt-dispo-head"]/table//tr/td');
        self::assertSame(2, $heads->length);
        foreach ($heads as $cell) {
            self::assertStringContainsString('padding:0 0 4px;', $cell->getAttribute('style'));
        }
        if ($mode === 'send') {
            foreach (['padding:8px 0 8px 20px !important;', 'padding:2px 20px 2px 0 !important;',
                'padding:4px 0 4px 12px !important;'] as $style) {
                self::assertSame(1, substr_count($result['headCss'], $style));
            }
        }
    }

    public function test_ck13_template_has_only_the_three_approved_changes_from_the_previous_pin(): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        $template = $this->withoutCk22NoWrap($this->withCk25Separator($template));
        $template = $this->withoutCk21Changes($template);
        $template = $this->withoutCk15Additions($template, true, 8);
        self::assertSame('9b8900e1c9640b445dcf309cbb823ca4195001d3cabdf61f927c59c5389b26c4', hash('sha256', $template));
        $line = '<tr class="rt-cta-line"><td colspan="5" height="1" bgcolor="#ffffff" style="height:1px;line-height:1px;font-size:1px;padding:0;background-color:#ffffff;">&nbsp;</td></tr>';
        $old = str_replace($line, '', $template, $lines);
        $old = str_replace("  .rt-cta-line { display:none !important; }\n", '', $old, $rules);
        self::assertSame(1, $lines);
        self::assertSame(1, $rules);
        $old = preg_replace_callback('~(<td class="rt-cta-cell\b[^>]*style=")([^"]*)(")~', static function (array $match): string {
            $style = $match[2];
            $border = 'border-top:1px solid #ffffff;';
            if (str_contains($style, 'font-family:')) {
                $style = str_replace('font-family:', $border.'font-family:', $style);
            } elseif (str_contains($style, 'font-size:')) {
                $style = str_replace('font-size:', $border.'font-size:', $style);
            } else {
                $style .= $border;
            }

            return $match[1].$style.$match[3];
        }, $old, -1, $cells);
        self::assertSame(5, $cells);
        self::assertSame('571786a0e872c1c92fa09578a2e5333e7af34e268d8664d56dad2e478bb13ff3', hash('sha256', $old),
            'Reversing exactly the approved separator, media rule and five border removals must recover the entire old template byte-for-byte.');
    }

    public function test_ck13_template_pin_automatically_invalidates_the_previous_same_profile_version(): void
    {
        // Recorded with this exact synthetic descriptor/default wording and the
        // previous 571786... pinned template. Author and host were not inputs.
        $legacyVersion = '97bfde8446893e251ac8b8bc';
        $editor = $this->render();
        $send = $this->render(['mode' => 'send']);
        self::assertNotSame($legacyVersion, $editor['version']);
        self::assertSame($editor['version'], $send['version']);
        self::assertSame('rt-sig-u2-'.$editor['version'], $editor['marker']);
        self::assertSame('u2e-'.$editor['version'], $editor['header']);
        self::assertSame($editor['version'], $this->render()['version']);
    }

    public function test_ck15_is_exactly_the_approved_555_bytes_and_changes_the_prior_same_profile_version(): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        $template = $this->withoutCk22NoWrap($this->withCk25Separator($template));
        $template = $this->withoutCk21Changes($template);
        $previous = $this->withoutCk15Additions($template, true, 8);
        self::assertSame('9b8900e1c9640b445dcf309cbb823ca4195001d3cabdf61f927c59c5389b26c4', hash('sha256', $previous));
        self::assertSame(555, strlen($template) - strlen($previous));
        self::assertSame('2ad0c5a96b3b03a0e4a633ec87fa4aa79eba30f4d3b4a979937a5f5c7871dd12', hash('sha256', $template));
        $editor = $this->render();
        self::assertNotSame('227841d4f4100bc828804a73', $editor['version'], 'C-K13 version for this exact synthetic descriptor/default wording.');
        self::assertSame($editor['version'], $this->render(['mode' => 'send'])['version']);
        self::assertSame('u2e-'.$editor['version'], $editor['header']);
    }

    public function test_ck21_changes_only_the_dispo_caps_and_no_longer_delivers_corner_images(): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        $beforeCk22 = $this->withoutCk22NoWrap($this->withCk25Separator($template));
        self::assertSame('f297c0bb222061eb3b78c85889e139274067d5744707d7807d5b6e8545e676b2', hash('sha256', $beforeCk22));
        self::assertSame('2ad0c5a96b3b03a0e4a633ec87fa4aa79eba30f4d3b4a979937a5f5c7871dd12', hash('sha256', $this->withoutCk21Changes($beforeCk22)),
            'Reversing only the cap rows, two colspans and dispatch table styles must recover the complete C-K15 template.');
        $result = $this->render(['mode' => 'send']);
        self::assertSame(hash('sha256', $template), $result['templateHash']);
        $corners = array_values(array_filter($result['media'], static fn (array $entry): bool => str_starts_with($entry['name'], 'corner')));
        self::assertSame([], $corners);
        self::assertCount(9, $result['media']);
        self::assertStringNotContainsString('ASSET_corner', $template);
        foreach (['c01c48d975', '8a5901e027', '033a29c0b7', '097c3bb121'] as $rejectedV21Asset) {
            self::assertStringNotContainsString('/m/'.$rejectedV21Asset.'.png', $result['html']);
        }
        foreach (['8a9ee1e161', '4b1a2aaba8', 'b4b1fbea07', 'f1b083c20b'] as $oldAsset) {
            self::assertStringNotContainsString('/m/'.$oldAsset.'.png', $result['html']);
            self::assertFileExists(public_path('m/'.$oldAsset.'.png'), 'Historical quoted mail assets must remain available.');
        }
    }

    #[DataProvider('modes')]
    public function test_ck22_prevents_number_and_time_wrapping_without_changing_the_previous_template_structure(string $mode): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        self::assertSame('0201650e3985cf95b7ef84d112fede5d8338017f6d725a764906da1a99e1c5dc', hash('sha256', $template));
        self::assertSame('f297c0bb222061eb3b78c85889e139274067d5744707d7807d5b6e8545e676b2', hash('sha256', $this->withoutCk22NoWrap($this->withCk25Separator($template))),
            'Removing exactly three nowrap declarations must recover the entire v2.2 template.');
        $rendered = $this->render(['mode' => $mode]);
        self::assertSame(hash('sha256', $template), $rendered['templateHash']);
        $xpath = $this->xpath($rendered['html']);
        self::assertSame(3, $xpath->query('//*[@style and contains(@style,"white-space:nowrap;")]')->length);
        foreach (['//td[@width="1" and contains(concat(" ",normalize-space(@class)," ")," rt-cta-cell ")]',
            '//span[@class="rt-cta-number"]', '//td[span[@class="rt-cta-time"]]'] as $selector) {
            $matches = $xpath->query($selector);
            self::assertSame(1, $matches->length);
            self::assertStringContainsString('white-space:nowrap;', $matches->item(0)->getAttribute('style'));
        }
    }

    #[DataProvider('modes')]
    public function test_exact_height_rule_remains_only_on_caps_and_gap(string $mode): void
    {
        $result = $this->render(['mode' => $mode]);
        $xpath = $this->xpath($result['html']);
        $cells = $xpath->query('//td[contains(@style,"mso-line-height-rule:exactly;")]');
        self::assertSame(3, $cells->length);
        self::assertSame(2, $xpath->query('//td[@class="rt-dispo-cap" and @height="8" and contains(@style,"mso-line-height-rule:exactly;")]')->length);
        self::assertSame(0, $xpath->query('//tr[@class="rt-cta-line"]')->length);
        self::assertSame(1, $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-cta-gap ") and contains(@style,"mso-line-height-rule:exactly;")]')->length);
        self::assertSame(4, $xpath->query('//td[contains(@style,"display:inline-block;max-width:100%;box-sizing:border-box;")]')->length);
        self::assertStringNotContainsString('mso-line-height-alt', $result['html']);
    }

    public function test_signature_text_keeps_explicit_font_spans_and_red_and_grey_cells_keep_both_color_forms(): void
    {
        $xpath = $this->xpath($this->render()['html']);
        foreach (['Alex Beispiel', 'IT-TECHNIK', 'alex@example.invalid', '+49 170 5550101', 'example.invalid', 'Musterweg 12', '10115 Berlin', 'Dispo', 'erreichbar', '24/'] as $label) {
            $matches = $xpath->query('//span[contains(.,"'.$label.'")]');
            self::assertGreaterThan(0, $matches->length, $label);
            $hasFont = false;
            foreach ($matches as $span) {
                if (str_contains($span->getAttribute('style'), 'font-family:') && str_contains($span->getAttribute('style'), 'font-size:')
                    && str_contains($span->getAttribute('style'), 'line-height:') && str_contains($span->getAttribute('style'), 'color:')) {
                    $hasFont = true;
                }
            }
            self::assertTrue($hasFont, $label.' requires a span-level font contract.');
        }
        foreach ($xpath->query('//td[@bgcolor="#e4002b" or @bgcolor="#e4e8ec"]') as $cell) {
            self::assertStringContainsString('background-color:'.$cell->getAttribute('bgcolor'), $cell->getAttribute('style'));
        }
    }

    public function test_marker_is_deterministic_and_shared_by_editor_send_and_both_required_marker_locations(): void
    {
        $one = $this->render();
        $two = $this->render(['mode' => 'send']);
        $three = (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString('<p>Different author, same identity.</p>'));
        self::assertSame($one['marker'], $this->render()['marker']);
        self::assertSame($one['marker'], $two['marker']);
        self::assertSame($one['marker'], $three['marker']);
        self::assertMatchesRegularExpression('/\Art-sig-u2-[0-9a-f]{24}\z/', $one['marker']);
        self::assertSame('rt-sig-u2-'.$one['version'], $one['marker']);
        self::assertSame('u2e-'.$one['version'], $one['header']);
        self::assertSame(['X-RailTime-Template' => $one['header']], $one['headers']);
        $xpath = $this->xpath($one['html']);
        $markers = $xpath->query('//*[@id="'.$one['marker'].'"]');
        self::assertSame(1, $markers->length);
        self::assertSame('table', $markers->item(0)->nodeName);
        $cells = $xpath->query('//*[@id="'.$one['marker'].'-cell"]');
        self::assertSame(1, $cells->length);
        self::assertSame('td', $cells->item(0)->nodeName);
        self::assertSame('260', $cells->item(0)->getAttribute('width'));
    }

    public function test_associative_input_order_does_not_change_the_content_marker(): void
    {
        $data = array_reverse($this->descriptor(), true);
        foreach (['identity', 'company', 'dispatch'] as $name) {
            $data[$name] = array_reverse($data[$name], true);
        }
        $reordered = (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>Synthetic author content.</p>'));
        self::assertSame($this->render()['marker'], $reordered['marker']);
        self::assertSame($this->render()['html'], $reordered['html']);
    }

    #[DataProvider('materialChanges')]
    public function test_changed_identity_dispatch_publication_wording_or_legal_content_changes_the_marker(string $case): void
    {
        $data = $this->descriptor();
        $options = [];
        match ($case) {
            'name' => $data['identity']['name'] = 'Robin Beispiel',
            'role' => $data['identity']['role'] = 'DISPOSITION',
            'direct' => $data['direct'][1] = ['type' => 'phone', 'value' => '+491705550102', 'label' => '+49 170 5550102'],
            'email' => $data['dispatch']['email'] = 'andere-dispo@example.invalid',
            'phone' => $data['dispatch'] = array_replace($data['dispatch'], ['phone' => '+49305550102', 'phoneLabel' => '+49 30 5550102']),
            'wa' => $data['dispatch']['whatsapp'] = 'https://wa.me/49305550102',
            'legal' => $data['company']['registration'] .= ' · Zweigstelle Berlin',
            'confidentiality' => $data['company']['confidentiality'] .= ' Interne Fassung.',
            'source' => $data['sourceHash'] = hash('sha256', 'different admitted publication'),
            'wording' => $options = ['showGreeting' => true, 'greeting' => 'Hallo Testteam'],
        };
        $changed = (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>Synthetic author content.</p>'), $options);
        self::assertNotSame($this->render()['marker'], $changed['marker']);
    }

    public function test_author_is_read_once_preserved_byte_exact_and_never_reinterpreted_as_template_tokens(): void
    {
        $author = "<p>Unchanged &amp; {{IDENTITY_NAME}}. 🚆</p>\r\n<pre>  two spaces\r\nnext</pre><!-- RT_NATIVE_MESSAGE_END -->";
        $content = new class($author) implements Htmlable
        {
            public int $reads = 0;

            public function __construct(private readonly string $value) {}

            public function toHtml(): string
            {
                $this->reads++;

                return $this->value;
            }
        };
        $result = (new OutlookUniversalPresentation)->render($this->descriptor(), $content, ['mode' => 'send']);
        self::assertSame(1, $content->reads);
        self::assertSame(hash('sha256', $author), $result['authorContentHash']);
        self::assertSame(1, substr_count($result['html'], $author));
        self::assertSame(1, substr_count($result['bodyHtml'], $author));
        self::assertSame(0, substr_count($result['signatureHtml'], $author));
        self::assertSame(intdiv(strlen(mb_convert_encoding($result['html'], 'UTF-16LE', 'UTF-8')), 2), $result['utf16Length']);
    }

    public function test_a_quote_with_the_same_deterministic_marker_is_preserved_as_opaque_author_content(): void
    {
        $previous = $this->render();
        $quote = '<blockquote class="history">'.$previous['bodyHtml'].'</blockquote>';
        $result = (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString($quote), ['mode' => 'send']);
        self::assertSame($previous['marker'], $result['marker']);
        self::assertSame(hash('sha256', $quote), $result['authorContentHash']);
        self::assertSame(1, substr_count($result['html'], $quote));
        self::assertSame(1, substr_count($result['bodyHtml'], $quote));
        self::assertSame(0, substr_count($result['signatureHtml'], $quote));
    }

    public function test_greeting_and_farewell_remain_explicit_but_legacy_name_option_never_appends_a_name(): void
    {
        $default = $this->render();
        foreach (['Guten Tag,', 'Mit freundlichen Grüßen,', 'Lucas Zacharias', 'it-technik@rail-time.de'] as $fixtureText) {
            self::assertStringNotContainsString($fixtureText, $default['html']);
        }
        $with = $this->render(['showGreeting' => true, 'greeting' => 'Hallo Testteam', 'showFarewell' => true, 'farewell' => 'Beste Testgrüße', 'farewellName' => true]);
        self::assertStringContainsString('Hallo Testteam', $with['html']);
        self::assertStringContainsString('Beste Testgrüße', $with['html']);
        self::assertSame(1, substr_count($with['html'], 'Alex Beispiel'));
        $withoutName = $this->render(['showFarewell' => true, 'farewell' => 'Beste Testgrüße', 'farewellName' => false]);
        self::assertSame(1, substr_count($withoutName['html'], 'Alex Beispiel'));
    }

    #[DataProvider('modes')]
    public function test_ck27_spacing_and_text_only_farewell_preserve_authored_names_and_canonicalize_the_legacy_option(string $mode): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        self::assertSame('ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf', hash('sha256', $this->withoutCk27Spacing($template)), 'Only the two approved padding values differ from complete v2.5.');
        $author = '<p data-authored="1">Alex Beispiel<br>Opaque author content.</p>';
        $options = ['mode' => $mode, 'showFarewell' => true, 'farewell' => 'Beste Testgrüße', 'farewellName' => true];
        $with = (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString($author), $options);
        $without = (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString($author), array_replace($options, ['farewellName' => false]));
        self::assertSame($with['version'], $without['version']);
        self::assertSame($with['html'], $without['html']);
        self::assertSame(1, substr_count($with['html'], $author));
        self::assertSame(2, substr_count($with['html'], 'Alex Beispiel'), 'One authored name and one signature identity; no farewell suffix.');
        $xpath = $this->xpath($with['html']);
        $cell = $xpath->query('//td[@class="rt-text"]')->item(0);
        self::assertStringContainsString('padding:32px 40px 48px;', $cell->getAttribute('style'));
        $farewell = $xpath->query('./p[last()]', $cell)->item(0);
        self::assertSame('Beste Testgrüße', $farewell->textContent);
        self::assertSame(0, $xpath->query('.//br', $farewell)->length);
        if ($mode === 'send') {
            self::assertStringContainsString('.rt-text { padding:24px 20px 32px !important; }', $with['headCss']);
        }
    }

    public function test_personal_and_company_text_is_escaped_without_mutating_trusted_author_bytes(): void
    {
        $data = $this->descriptor();
        $data['identity']['name'] = 'Alex <svg/onload=alert(1)> & "Team"';
        $data['company']['registration'] = '<img src=x onerror=alert(2)> & HRB';
        $result = (new OutlookUniversalPresentation)->render($data, new HtmlString('<p class="trusted">Author &amp; bytes.</p>'));
        self::assertStringContainsString('&lt;svg/onload=alert(1)&gt;', $result['html']);
        self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $result['html']);
        $xpath = $this->xpath($result['html']);
        self::assertSame(0, $xpath->query('//svg|//*[@onload]|//*[@onerror]')->length);
        self::assertSame(9, $xpath->query('//img')->length);
        self::assertStringContainsString('<p class="trusted">Author &amp; bytes.</p>', $result['html']);
    }

    public function test_media_catalog_points_to_exact_existing_bytes_and_no_outbound_transport_is_created(): void
    {
        $result = $this->render();
        self::assertCount(9, $result['media']);
        foreach ($result['media'] as $asset) {
            self::assertStringStartsWith('https://mail.example.test/m/', $asset['url']);
            $path = public_path(ltrim(parse_url($asset['url'], PHP_URL_PATH), '/'));
            self::assertFileExists($path);
            self::assertSame(hash_file('sha256', $path), $asset['sha256']);
            self::assertSame('image/png', $asset['mime']);
        }
        self::assertFalse(method_exists(OutlookUniversalPresentation::class, 'send'));
        self::assertFalse(method_exists(OutlookUniversalPresentation::class, 'publish'));
    }

    public function test_system_identity_and_one_or_three_direct_contacts_use_the_same_layout_without_inventing_fields(): void
    {
        $data = $this->descriptor();
        $data['kind'] = 'system';
        $data['identity'] = ['name' => $data['company']['name'], 'role' => 'SYSTEM'];
        $data['direct'] = [$data['direct'][0]];
        $system = (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>System content.</p>'));
        $xpath = $this->xpath($system['html']);
        self::assertSame(8, $xpath->query('//img')->length);
        self::assertStringContainsString('Muster Rail GmbH', $system['html']);
        self::assertStringNotContainsString('Alex Beispiel', $system['html']);

        $data = $this->descriptor();
        $data['direct'][] = ['type' => 'phone', 'value' => '+49305550102', 'label' => '+49 30 5550102'];
        $three = (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>Employee content.</p>'));
        self::assertSame(10, $this->xpath($three['html'])->query('//img')->length);
        self::assertStringContainsString('+49 30 5550102', $three['html']);
    }

    #[DataProvider('canonicalPhoneLabels')]
    public function test_existing_phone_normalization_preserves_the_display_label_and_international_link(string $value, string $label): void
    {
        $data = $this->descriptor();
        $data['direct'][1] = ['type' => 'phone', 'value' => $value, 'label' => $label];
        $before = $data;
        foreach (['editor', 'send'] as $mode) {
            $result = (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>Trusted.</p>'), ['mode' => $mode]);
            self::assertStringContainsString('>'.$label.'</span>', $result['html']);
            if ($mode === 'send') {
                self::assertStringContainsString('href="tel:'.$value.'"', $result['html']);
            }
            self::assertSame($before, $data);
        }
    }

    #[DataProvider('invalidCanonicalPhoneLabels')]
    public function test_phone_normalization_does_not_admit_different_numbers_or_invalid_syntax(mixed $value, mixed $label): void
    {
        $data = $this->descriptor();
        $data['direct'][1] = ['type' => 'phone', 'value' => $value, 'label' => $label];
        $this->expectException(RuntimeException::class);
        (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>Trusted.</p>'));
    }

    public function test_phone_validation_change_keeps_existing_international_profile_bytes_and_version(): void
    {
        $expected = ['editor' => '78b9cd363baa354d9d9e87feee43a4f59f8e9641ea5d76979ca00da86641e1b6',
            'send' => '52af138a70eaae550c138cd340c393cb4f4a06ae44b6bef832fcbf8890243a5c'];
        foreach ($expected as $mode => $hash) {
            $rendered = $this->render(['mode' => $mode]);
            self::assertSame('88c8f55828f714777696708b', $rendered['version']);
            // Reverse only this release's outer alignment and derived marker;
            // the earlier phone-normalization byte contract remains unchanged.
            $historical = $this->withoutLeftAlignment(str_replace($rendered['version'], 'f38e7dcd51fc2b61cd8fac97', $rendered['html']));
            self::assertSame($hash, hash('sha256', $historical));
        }
    }

    public static function canonicalPhoneLabels(): array
    {
        return [['+491705550101', '+49 170 5550101'], ['+491705550101', '0170 5550101'],
            ['+491705550101', '0049 170 5550101'], ['+491705550101', '+49 (0)170 5550101'],
            ['01705550101', '0170 5550101']];
    }

    public static function invalidCanonicalPhoneLabels(): array
    {
        return [['+491705550101', '0170 5550102'], ['+441705550101', '0170 5550101'],
            ['+491705550101', 'phone 0170 5550101'], ['+491705550101', '01705550101;ext=5'],
            ['+491705550101', "0170\n5550101"], ['+491705550101', str_repeat(' ', 33).'01705550101'],
            ['+49+1705550101', '+49+1705550101'], ['+491705550101', '++491705550101'],
            ['+491705550101', null], ['+4917055501010123', '+4917055501010123']];
    }

    #[DataProvider('invalidAuthorContent')]
    public function test_invalid_utf8_control_or_unbounded_author_bytes_are_rejected(string $author): void
    {
        $this->expectException(RuntimeException::class);
        (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString($author));
    }

    #[DataProvider('invalidDescriptors')]
    public function test_ambiguous_or_unsafe_input_fails_closed(string $case): void
    {
        $data = $this->descriptor();
        $options = [];
        switch ($case) {
            case 'source': $data['sourceHash'] = 'not-a-hash';
                break;
            case 'kind': $data['kind'] = 'reply';
                break;
            case 'unknown-field': $data['extraHtml'] = '<b>hidden</b>';
                break;
            case 'missing-field': unset($data['company']['registration']);
                break;
            case 'unknown-nested': $data['identity']['markup'] = '<b>x</b>';
                break;
            case 'control': $data['identity']['name'] .= "\0";
                break;
            case 'long-role': $data['identity']['role'] = str_repeat('x', 121);
                break;
            case 'email': $data['dispatch']['email'] = "x@example.invalid\r\nBcc:y@example.invalid";
                break;
            case 'email-label': $data['direct'][0]['label'] = 'Someone else';
                break;
            case 'empty-direct': $data['direct'] = [];
                break;
            case 'too-many-direct': $data['direct'] = [...$data['direct'], $data['direct'][1], $data['direct'][1]];
                break;
            case 'phone-first': $data['direct'] = array_reverse($data['direct']);
                break;
            case 'phone': $data['dispatch']['phoneLabel'] = '+49 160 0000000';
                break;
            case 'whatsapp': $data['dispatch']['whatsapp'] = 'https://evil.example/491601881848';
                break;
            case 'site': $data['company']['website'] = 'javascript:alert(1)';
                break;
            case 'site-credentials': $data['company']['website'] = 'https://user:pass@example.invalid';
                break;
            case 'system-person': $data['kind'] = 'system';
                break;
            case 'mode': $options['mode'] = 'transit';
                break;
            case 'mode-null': $options['mode'] = null;
                break;
            case 'flag': $options['showGreeting'] = 'true';
                break;
            case 'empty-wording': $options['showGreeting'] = true;
                break;
            case 'unknown-option': $options['bodyHtml'] = '<b>ignored?</b>';
                break;
            default: self::fail('Unknown test case.');
        }
        $this->expectException(RuntimeException::class);
        (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>Trusted.</p>'), $options);
    }

    public function test_unapproved_asset_origins_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new OutlookUniversalPresentation('https://different.example.test'))->render($this->descriptor(), new HtmlString('Trusted'));
    }

    public static function modes(): array
    {
        return [['editor'], ['send']];
    }

    public static function invalidAuthorContent(): array
    {
        return [["invalid\0bytes"], ["invalid\xC3\x28"], [str_repeat('x', 262145)]];
    }

    public static function materialChanges(): array
    {
        return array_map(static fn (string $case): array => [$case], ['name', 'role', 'direct', 'email', 'phone', 'wa', 'legal', 'confidentiality', 'source', 'wording']);
    }

    public static function invalidDescriptors(): array
    {
        return array_map(static fn (string $case): array => [$case], ['source', 'kind', 'unknown-field', 'missing-field', 'unknown-nested', 'control', 'long-role', 'email', 'email-label', 'empty-direct', 'too-many-direct', 'phone-first', 'phone', 'whatsapp', 'site', 'site-credentials', 'system-person', 'mode', 'mode-null', 'flag', 'empty-wording', 'unknown-option']);
    }

    private function render(array $options = []): array
    {
        return (new OutlookUniversalPresentation)->render($this->descriptor(), new HtmlString('<p>Synthetic author content.</p>'), $options);
    }

    /** Historical proof only: recover the complete prior v2.3 source, never runtime output. */
    private function withCk25Separator(string $html): string
    {
        $html = $this->withoutCk26Sizing($html);
        self::assertStringNotContainsString('rt-cta-line', $html);
        $html = str_replace('<tr><td class="rt-cta-cell"', '<tr class="rt-cta-line"><td colspan="5" height="1" bgcolor="#ffffff" style="height:1px;line-height:1px;font-size:1px;mso-line-height-rule:exactly;padding:0;background-color:#ffffff;">&nbsp;</td></tr><tr><td class="rt-cta-cell"', $html, $rows);
        $html = str_replace('  .rt-cta-icon { width:20px !important;', "  .rt-cta-line { display:none !important; }\n  .rt-cta-icon { width:20px !important;", $html, $rules);
        self::assertSame(1, $rows);
        self::assertSame(1, $rules);

        return $html;
    }

    /** Test-only reversal of precisely the approved v2.5 media spacing. */
    private function withoutCk26MobileSpacing(string $html): string
    {
        foreach ([
            'padding:8px 0 8px 20px !important;' => 'padding:9px 0 9px 20px !important;',
            'padding:2px 20px 2px 0 !important;' => 'padding:4px 20px 4px 0 !important;',
            'padding:4px 0 4px 12px !important;' => 'padding:5px 0 5px 12px !important;',
        ] as $current => $previous) {
            $html = str_replace($current, $previous, $html, $count);
            self::assertSame(1, $count, $current);
        }

        return $html;
    }

    /** Historical source proof; never transforms a delivered message. */
    private function withoutCk26Sizing(string $html): string
    {
        $html = $this->withoutCk27Spacing($html);
        $html = $this->withoutCk26MobileSpacing($html);
        foreach ([
            ['padding:0 0 4px;', 'padding:0 0 9px;', 2],
            ['font-size:19px;line-height:20px;', 'font-size:19px;line-height:21px;', 1],
            ['font-size:15px;line-height:18px;', 'font-size:15px;line-height:19px;', 1],
            ['font-size:34px;line-height:36px;', 'font-size:38px;line-height:40px;', 2],
            ['padding:6px 0 4px;', 'padding:11px 0 5px;', 3],
            ['padding:6px 0 4px 14px;', 'padding:11px 0 5px 14px;', 1],
            ['padding:6px 8px 4px 0;', 'padding:11px 8px 5px 0;', 1],
        ] as [$current, $previous, $expected]) {
            $html = str_replace($current, $previous, $html, $count);
            self::assertSame($expected, $count, $current);
        }

        return $html;
    }

    /** Test-only exact source reversal; runtime author data is never rewritten. */
    private function withoutCk27Spacing(string $html, bool $hasBody = true): string
    {
        $html = $this->withoutLeftAlignment($html);
        $html = str_replace('.rt-text { padding:24px 20px 32px !important; }', '.rt-text { padding:24px 20px 24px !important; }', $html, $mobile);
        self::assertSame(1, $mobile);
        if ($hasBody) {
            $html = str_replace('padding:32px 40px 48px;', 'padding:32px 40px 30px;', $html, $desktop);
            self::assertSame(1, $desktop);
        }

        return $html;
    }

    /** Historical source proof only: reverse the new outer alignment before older exact deltas. */
    private function withoutLeftAlignment(string $html): string
    {
        return str_replace(['class="rt-shell"', 'margin:0 auto 0 0;float:none;text-align:left;'],
            ['class="rt-shell" align="center"', 'margin:0 auto;'], $html);
    }

    private function desktopReset(): string
    {
        return "@media only screen and (min-width:1040px) {\n  .rt-col { display:table-cell !important; }\n}\n";
    }

    private function withoutCk15Additions(string $html, bool $hasHead, int $expectedHeightCells = 3): string
    {
        $html = str_replace('display:inline-block;max-width:100%;box-sizing:border-box;', '', $html, $columns);
        $html = str_replace('mso-line-height-rule:exactly;', '', $html, $cells);
        self::assertSame(4, $columns);
        self::assertSame($expectedHeightCells, $cells);
        if ($hasHead) {
            $html = str_replace($this->desktopReset(), '', $html, $rules);
            self::assertSame(1, $rules);
        }

        return $html;
    }

    private function withoutCk21Changes(string $html): string
    {
        $cell = static fn (string $vertical, string $asset): string => '<td width="8" height="8" valign="'.$vertical.'" bgcolor="#ffffff" style="width:8px;height:8px;padding:0;vertical-align:'.$vertical.';background-color:#ffffff;font-size:1px;line-height:8px;mso-line-height-rule:exactly;"><img src="{{ASSET_'.$asset.'}}" width="8" height="8" alt="" border="0" style="display:block;border:0;outline:none;text-decoration:none;width:8px;height:8px;max-width:100%;"></td>';
        $spacer = '<td width="231" height="8" bgcolor="#e4002b" style="background-color:#e4002b;width:231px;height:8px;padding:0;font-size:1px;line-height:8px;mso-line-height-rule:exactly;">&nbsp;</td>';
        foreach ([['8px 8px 0 0', 'top', 'cornerTopLeft', 'cornerTopRight'], ['0 0 8px 8px', 'bottom', 'cornerBottomLeft', 'cornerBottomRight']] as [$radius, $vertical, $left, $right]) {
            $cap = '<tr class="rt-dispo-corner"><td class="rt-dispo-cap" height="8" bgcolor="#e4002b" style="background-color:#e4002b;height:8px;padding:0;font-size:1px;line-height:8px;mso-line-height-rule:exactly;border-radius:'.$radius.';">&nbsp;</td></tr>';
            $old = '<tr class="rt-dispo-corner">'.$cell($vertical, $left).$spacer.$cell($vertical, $right).'</tr>';
            $html = str_replace($cap, $old, $html, $caps);
            self::assertSame(1, $caps);
        }
        foreach (['rt-dispo-head', 'rt-dispo-actions'] as $class) {
            $html = str_replace('<td class="'.$class.'" bgcolor=', '<td class="'.$class.'" colspan="3" bgcolor=', $html, $cells);
            self::assertSame(1, $cells);
        }
        $html = str_replace('width:247px;border-collapse:separate;border-spacing:0;border-radius:8px;background-color:#e4002b;', 'width:247px;border-collapse:collapse;background-color:#e4002b;', $html, $table);
        self::assertSame(1, $table);

        return $html;
    }

    private function withoutCk22NoWrap(string $html): string
    {
        $html = str_replace('white-space:nowrap;', '', $html, $declarations);
        self::assertSame(3, $declarations);

        return $html;
    }

    private function descriptor(): array
    {
        return [
            'sourceHash' => hash('sha256', 'published synthetic source'), 'kind' => 'employee',
            'identity' => ['name' => 'Alex Beispiel', 'role' => 'IT-TECHNIK'],
            'direct' => [['type' => 'email', 'value' => 'alex@example.invalid', 'label' => 'alex@example.invalid'], ['type' => 'phone', 'value' => '+491705550101', 'label' => '+49 170 5550101']],
            'company' => ['name' => 'Muster Rail GmbH', 'website' => 'https://example.invalid', 'websiteLabel' => 'example.invalid', 'street' => 'Musterweg 12', 'postalCity' => '10115 Berlin', 'registration' => 'Geschäftsführung: A. Beispiel · HRB 12345', 'confidentiality' => 'Vertrauliche Nachricht. Bei irrtümlichem Empfang bitte Absender informieren.'],
            'dispatch' => ['email' => 'dispo@example.invalid', 'whatsapp' => 'https://wa.me/491601881848', 'phone' => '+491601881839', 'phoneLabel' => '+49 160 1881839'],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }
}
