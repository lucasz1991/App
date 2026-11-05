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
        ['c01c48d975.png', 8, 8], ['8a5901e027.png', 8, 8],
        ['19125261c2.png', 24, 24], ['f9d88b899d.png', 24, 24], ['1111670ac8.png', 24, 24],
        ['033a29c0b7.png', 8, 8], ['097c3bb121.png', 8, 8], ['14d7943c88.png', 500, 83],
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
        self::assertSame('center', $shell->getAttribute('align'));
        self::assertSame('1040', $shell->getAttribute('width'));
        self::assertSame('0', $shell->getAttribute('cellpadding'));
        self::assertSame('0', $shell->getAttribute('cellspacing'));
        foreach (['width:1040px', 'max-width:100%', 'margin:0 auto'] as $rule) {
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
        self::assertSame([4, 1, 1, 2, 1, 2, 1, 4, 1, 2],
            array_map(fn (DOMElement $table): int => $xpath->query('./tr|./tbody/tr', $table)->length, iterator_to_array($xpath->query('//table'))));
        $images = $xpath->query('//img');
        self::assertSame(13, $images->length);
        foreach (self::IMAGES as $index => [$file, $width, $height]) {
            $image = $images->item($index);
            self::assertSame('https://mail.example.test/m/'.$file, $image->getAttribute('src'));
            self::assertSame((string) $width, $image->getAttribute('width'));
            self::assertSame((string) $height, $image->getAttribute('height'));
            self::assertStringContainsString('width:'.$width.'px', $image->getAttribute('style'));
            self::assertStringContainsString('height:'.$height.'px', $image->getAttribute('style'));
        }
        self::assertSame(4, $xpath->query('//td[img[@width="8" and @height="8"]]')->length);
        foreach ($xpath->query('//td[img[@width="8" and @height="8"]]') as $corner) {
            self::assertSame('#e4002b', $corner->getAttribute('bgcolor'));
            self::assertStringContainsString('background-color:#e4002b;', $corner->getAttribute('style'));
            self::assertStringContainsString('font-size:1px', $corner->getAttribute('style'));
            self::assertStringContainsString('line-height:8px', $corner->getAttribute('style'));
        }
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
        $originalCss = str_replace(["  .rt-cta-line { display:none !important; }\n", $this->desktopReset()], '', $result['headCss']);
        self::assertSame($referenceCss, $originalCss,
            'Only C-K13 separator hiding and the exact C-K15 desktop reset augment the immutable original media rules.');
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
    public function test_ck13_separator_replaces_only_the_five_cta_borders_and_preserves_their_padding(string $mode): void
    {
        $result = $this->render(['mode' => $mode]);
        $xpath = $this->xpath($result['html']);
        $lines = $xpath->query('//tr[@class="rt-cta-line"]');
        self::assertSame(1, $lines->length);
        $lineCells = $xpath->query('./td', $lines->item(0));
        self::assertSame(1, $lineCells->length);
        $line = $lineCells->item(0);
        self::assertSame('5', $line->getAttribute('colspan'));
        self::assertSame('1', $line->getAttribute('height'));
        self::assertSame('#ffffff', $line->getAttribute('bgcolor'));
        self::assertSame('height:1px;line-height:1px;font-size:1px;mso-line-height-rule:exactly;padding:0;background-color:#ffffff;', $line->getAttribute('style'));
        self::assertSame("\u{a0}", $line->textContent);
        $actionRows = $xpath->query('./tr|./tbody/tr', $lines->item(0)->parentNode);
        self::assertSame(2, $actionRows->length);
        self::assertSame($lines->item(0), $actionRows->item(0));
        $actions = $xpath->query('./td', $actionRows->item(1));
        self::assertSame(5, $actions->length);
        foreach (['11px 0 5px', '11px 0 5px 14px', '11px 0 5px', '11px 8px 5px 0', '11px 0 5px'] as $index => $padding) {
            self::assertStringContainsString('padding:'.$padding.';', $actions->item($index)->getAttribute('style'));
            self::assertStringNotContainsString('border-top', $actions->item($index)->getAttribute('style'));
        }
        self::assertSame(4, $xpath->query('//td[contains(@style,"border-top:2px solid #e4002b")]')->length);
        self::assertSame(0, $xpath->query('//td[contains(@style,"border-top:1px solid #ffffff")]')->length);
        if ($mode === 'send') {
            self::assertSame(1, substr_count($result['headCss'], '.rt-cta-line { display:none !important; }'));
            self::assertGreaterThan(strpos($result['headCss'], 'max-width:600px'), strpos($result['headCss'], '.rt-cta-line'));
        }
    }

    public function test_ck13_template_has_only_the_three_approved_changes_from_the_previous_pin(): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        $template = $this->withoutCk21CornerColors($template);
        $template = $this->withoutCk15Additions($template, true);
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
        $template = $this->withoutCk21CornerColors($template);
        $previous = $this->withoutCk15Additions($template, true);
        self::assertSame('9b8900e1c9640b445dcf309cbb823ca4195001d3cabdf61f927c59c5389b26c4', hash('sha256', $previous));
        self::assertSame(555, strlen($template) - strlen($previous));
        self::assertSame('2ad0c5a96b3b03a0e4a633ec87fa4aa79eba30f4d3b4a979937a5f5c7871dd12', hash('sha256', $template));
        $editor = $this->render();
        self::assertNotSame('227841d4f4100bc828804a73', $editor['version'], 'C-K13 version for this exact synthetic descriptor/default wording.');
        self::assertSame($editor['version'], $this->render(['mode' => 'send'])['version']);
        self::assertSame('u2e-'.$editor['version'], $editor['header']);
    }

    public function test_ck21_changes_only_four_corner_cell_colors_and_delivers_opaque_24px_assets_at_8px(): void
    {
        $template = str_replace("\r\n", "\n", file_get_contents(resource_path('mail-templates/u2e-universal-v1.html')));
        self::assertSame('942f8bf2e36712fb616d5acd3096d642baf3731c7952f72cadd1a474796600ba', hash('sha256', $template));
        self::assertSame('2ad0c5a96b3b03a0e4a633ec87fa4aa79eba30f4d3b4a979937a5f5c7871dd12', hash('sha256', $this->withoutCk21CornerColors($template)),
            'Reversing only the four corner backgrounds must recover the complete C-K15 template.');
        $result = $this->render(['mode' => 'send']);
        self::assertSame(hash('sha256', $template), $result['templateHash']);
        $corners = array_values(array_filter($result['media'], static fn (array $entry): bool => str_starts_with($entry['name'], 'corner')));
        self::assertSame(['cornerTopLeft', 'cornerTopRight', 'cornerBottomLeft', 'cornerBottomRight'], array_column($corners, 'name'));
        foreach ($corners as $entry) {
            $bytes = file_get_contents(public_path(parse_url($entry['url'], PHP_URL_PATH)));
            self::assertSame([24, 24], [$entry['width'], $entry['height']]);
            self::assertSame($entry['sha256'], hash('sha256', $bytes));
            self::assertSame(2, ord($bytes[25]), 'PNG IHDR truecolour RGB has no alpha channel.');
            self::assertSame([24, 24], array_slice(getimagesizefromstring($bytes), 0, 2));
        }
        foreach (['8a9ee1e161', '4b1a2aaba8', 'b4b1fbea07', 'f1b083c20b'] as $oldAsset) {
            self::assertStringNotContainsString('/m/'.$oldAsset.'.png', $result['html']);
            self::assertFileExists(public_path('m/'.$oldAsset.'.png'), 'Historical quoted mail assets must remain available.');
        }
    }

    #[DataProvider('modes')]
    public function test_ck15_exact_height_rule_is_only_on_the_eight_approved_material_cells(string $mode): void
    {
        $result = $this->render(['mode' => $mode]);
        $xpath = $this->xpath($result['html']);
        $cells = $xpath->query('//td[contains(@style,"mso-line-height-rule:exactly;")]');
        self::assertSame(8, $cells->length);
        self::assertSame(4, $xpath->query('//td[@width="8" and @height="8" and img[@width="8"] and contains(@style,"mso-line-height-rule:exactly;")]')->length);
        self::assertSame(2, $xpath->query('//td[@width="231" and @height="8" and contains(@style,"mso-line-height-rule:exactly;")]')->length);
        self::assertSame(1, $xpath->query('//tr[@class="rt-cta-line"]/td[@colspan="5" and contains(@style,"mso-line-height-rule:exactly;")]')->length);
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

    public function test_greeting_farewell_and_personal_name_are_explicit_options_without_reference_fixture_leaks(): void
    {
        $default = $this->render();
        foreach (['Guten Tag,', 'Mit freundlichen Grüßen,', 'Lucas Zacharias', 'it-technik@rail-time.de'] as $fixtureText) {
            self::assertStringNotContainsString($fixtureText, $default['html']);
        }
        $with = $this->render(['showGreeting' => true, 'greeting' => 'Hallo Testteam', 'showFarewell' => true, 'farewell' => 'Beste Testgrüße', 'farewellName' => true]);
        self::assertStringContainsString('Hallo Testteam', $with['html']);
        self::assertStringContainsString('Beste Testgrüße', $with['html']);
        self::assertSame(2, substr_count($with['html'], 'Alex Beispiel'));
        $withoutName = $this->render(['showFarewell' => true, 'farewell' => 'Beste Testgrüße', 'farewellName' => false]);
        self::assertSame(1, substr_count($withoutName['html'], 'Alex Beispiel'));
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
        self::assertSame(13, $xpath->query('//img')->length);
        self::assertStringContainsString('<p class="trusted">Author &amp; bytes.</p>', $result['html']);
    }

    public function test_media_catalog_points_to_exact_existing_bytes_and_no_outbound_transport_is_created(): void
    {
        $result = $this->render();
        self::assertCount(13, $result['media']);
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
        self::assertSame(12, $xpath->query('//img')->length);
        self::assertStringContainsString('Muster Rail GmbH', $system['html']);
        self::assertStringNotContainsString('Alex Beispiel', $system['html']);

        $data = $this->descriptor();
        $data['direct'][] = ['type' => 'phone', 'value' => '+49305550102', 'label' => '+49 30 5550102'];
        $three = (new OutlookUniversalPresentation)->render($data, new HtmlString('<p>Employee content.</p>'));
        self::assertSame(14, $this->xpath($three['html'])->query('//img')->length);
        self::assertStringContainsString('+49 30 5550102', $three['html']);
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

    private function desktopReset(): string
    {
        return "@media only screen and (min-width:1040px) {\n  .rt-col { display:table-cell !important; }\n}\n";
    }

    private function withoutCk15Additions(string $html, bool $hasHead): string
    {
        $html = str_replace('display:inline-block;max-width:100%;box-sizing:border-box;', '', $html, $columns);
        $html = str_replace('mso-line-height-rule:exactly;', '', $html, $cells);
        self::assertSame(4, $columns);
        self::assertSame(8, $cells);
        if ($hasHead) {
            $html = str_replace($this->desktopReset(), '', $html, $rules);
            self::assertSame(1, $rules);
        }

        return $html;
    }

    private function withoutCk21CornerColors(string $html): string
    {
        $html = preg_replace_callback('~<td\b(?=[^>]*\bwidth="8")[^>]*>~', static function (array $match): string {
            self::assertSame(1, substr_count($match[0], 'bgcolor="#e4002b"'));
            self::assertSame(1, substr_count($match[0], 'background-color:#e4002b;'));

            return str_replace(['bgcolor="#e4002b"', 'background-color:#e4002b;'], ['bgcolor="#ffffff"', 'background-color:#ffffff;'], $match[0]);
        }, $html, -1, $corners);
        self::assertSame(4, $corners);

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
