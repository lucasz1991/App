<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mail;

use App\Support\Mail\EmailCompatibilityAuditor;
use App\Support\Mail\EmailCompatibilityCatalog;
use App\Support\Mail\EmailCompatibilityReport;
use App\Support\Mail\TrustedEmailCss;
use PHPUnit\Framework\TestCase;

final class EmailEditingPolicyTest extends TestCase
{
    private function catalog(): EmailCompatibilityCatalog
    {
        return new EmailCompatibilityCatalog(dirname(__DIR__, 4).'/resources/data/email-html-compatibility-rules.csv');
    }

    private function audit(string $html, string $css = ''): EmailCompatibilityReport
    {
        return (new EmailCompatibilityAuditor($this->catalog()))->audit($html, $css, [
            'document_kind' => 'signature',
            'trusted_system_css' => true,
        ]);
    }

    public function test_static_pass_explicitly_keeps_manual_and_native_rendering_checks_open(): void
    {
        $report = $this->audit('<p>Lesbarer Inhalt</p>');
        self::assertSame('pass', $report->status());
        self::assertFalse($report->blocksPublication());
        self::assertGreaterThan(0, $report->checks['automated']);
        self::assertGreaterThan(42, $report->checks['manual']);
        self::assertContains('EMAIL-QA-004', $report->checks['manual_rule_ids']);
        self::assertContains('EMAIL-REPLY-001', $report->checks['manual_rule_ids']);
        self::assertContains('EMAIL-TYPO-003', $report->checks['manual_rule_ids']);
        self::assertFalse($report->toArray()['rendering_verified']);
        self::assertSame($report->checks, $report->toArray()['checks']);
    }

    public function test_inline_risks_are_warnings_even_for_trusted_mail_and_are_not_rewritten(): void
    {
        $html = '<div style="MARGIN-BOTTOM:-150px!important;position:relative;z-index:1">Kontakt</div>'
            .'<table role="presentation"><tr><td style="background-image:url(cid:picture)">Altbestand</td></tr></table>';
        $report = $this->audit($html);
        self::assertSame('warn', $report->status());
        self::assertSame(0, $report->counts['block']);
        self::assertGreaterThanOrEqual(3, $report->counts['warn']);
        self::assertFalse($report->blocksPublication());
        self::assertSame(['EMAIL-LAYOUT-007', 'EMAIL-LAYOUT-008', 'EMAIL-BACKGROUND-003'], array_values(array_filter(
            array_column($report->findings, 'rule_id'),
            static fn (string $id): bool => in_array($id, ['EMAIL-LAYOUT-007', 'EMAIL-LAYOUT-008', 'EMAIL-BACKGROUND-003'], true),
        )));
        self::assertStringContainsString('MARGIN-BOTTOM:-150px', $html);
    }

    public function test_background_attribute_and_shorthand_are_detected_without_cid_rewriting(): void
    {
        foreach (['background="cid:train"', 'style="background:url(&quot;cid:train&quot;) left bottom no-repeat"'] as $attributes) {
            $report = $this->audit('<table role="presentation"><tr><td '.$attributes.'>Text</td></tr></table>');
            self::assertContains('EMAIL-BACKGROUND-003', array_column($report->findings, 'rule_id'));
            self::assertFalse($report->blocksPublication());
        }
    }

    public function test_only_matching_flat_styles_are_checked_and_unknown_conditions_stay_manual(): void
    {
        $unused = $this->audit('<p class="used">Text</p>', '.missing { margin-bottom:-100px;z-index:2;background-image:url(cid:x); }');
        self::assertSame('pass', $unused->status());
        $used = $this->audit('<p class="used">Text</p>', '.used { margin-bottom:-100px; }');
        self::assertContains('EMAIL-LAYOUT-007', array_column($used->findings, 'rule_id'));
        $conditional = $this->audit('<p class="used">Text</p>', '@media(max-width:480px){.used {margin-bottom:-100px}}');
        self::assertSame('pass', $conditional->status());
        self::assertContains('EMAIL-LAYOUT-007', $conditional->checks['manual_rule_ids']);
        self::assertFalse($conditional->toArray()['rendering_verified']);
    }

    public function test_unused_trusted_runtime_branches_do_not_warn_but_actual_inline_overlap_does(): void
    {
        $runtime = '<style>/* '.TrustedEmailCss::RUNTIME_MARKER.' */'
            .'[data-rt-artifact-version="v20"] .rt-sign-train-layer{margin-bottom:-200px;z-index:0}'
            .'[data-rt-artifact-version="v23"] td{background-image:url(cid:legacy)}</style>';
        $flow = $runtime.'<div data-rt-artifact-version="v25"><div class="rt-sign-train-layer" style="margin:0">'
            .'<img class="rt-sign-train" width="720" height="61" src="cid:train" alt="" style="width:100%;height:auto"></div></div>';
        $flowReport = $this->audit($flow);
        self::assertSame('pass', $flowReport->status());
        self::assertContains('EMAIL-LAYOUT-007', $flowReport->checks['manual_rule_ids']);
        $overlap = str_replace(['version="v25"', 'style="margin:0"'], ['version="v20"', 'style="margin-bottom:-200px;z-index:0"'], $flow);
        $report = $this->audit($overlap);
        self::assertSame(0, $report->counts['block']);
        self::assertSame(2, $report->counts['warn']);
    }

    public function test_proportional_and_fixed_image_dimensions_pass_without_system_class_exception(): void
    {
        foreach ([
            'width="720" style="width:164%;height:auto"',
            'width="210" style="width:210px;max-width:100%;height:auto!important"',
            'width="22" height="22"',
            'width="200" style="aspect-ratio:16 / 9"',
        ] as $dimensions) {
            $report = $this->audit('<img src="cid:test" alt="" '.$dimensions.'>');
            self::assertNotContains('EMAIL-IMAGE-002', array_column($report->findings, 'rule_id'), $dimensions);
        }
        $fixed = $this->audit('<img src="cid:test" alt="" width="22" height="22">');
        self::assertContains('EMAIL-IMAGE-002', $fixed->checks['manual_rule_ids']);
    }

    public function test_fake_system_markers_and_invalid_image_dimensions_do_not_bypass_check(): void
    {
        foreach ([
            'class="any-rt-fake"',
            'class="rt-sign-train" data-rt-train',
            'width="0" height="22"',
            'width="720" height="-1" style="height:auto"',
            'width="broken" height="22"',
            'width="720" style="aspect-ratio:0 / 0"',
        ] as $dimensions) {
            $report = $this->audit('<img src="cid:test" alt="" '.$dimensions.'>');
            self::assertContains('EMAIL-IMAGE-002', array_column($report->findings, 'rule_id'), $dimensions);
        }
    }

    public function test_zero_margin_static_position_and_plain_background_are_not_risks(): void
    {
        self::assertSame('pass', $this->audit('<p style="margin:0 -0px 0;position:static;z-index:auto;background:#fff">Text</p>')->status());
        $report = $this->audit('<p style="margin:calc(10px - 50%);">Text</p>');
        self::assertContains('EMAIL-LAYOUT-007', $report->checks['manual_rule_ids']);
    }

    public function test_manifest_exposes_conservative_editing_policy_without_execution_config(): void
    {
        $manifest = $this->catalog()->builderManifestForJs();
        self::assertSame('1.0.1', $manifest['catalog_version']);
        $policy = $manifest['editing_policy'];
        self::assertSame('img-only', $policy['train_source']);
        self::assertSame('warn', $policy['negative_margin']);
        self::assertSame('warn', $policy['positioned_layout']);
        self::assertSame('warn', $policy['background_images']);
        self::assertSame('table-flow', $policy['critical_layout']);
        self::assertSame('critical-inline', $policy['mail_css']);
        self::assertSame('separate-client-test', $policy['addin_css']);
        self::assertArrayNotHasKey('html_css_same_for', $policy);
        foreach ($policy['evidence'] as $source) {
            self::assertSame('https', parse_url($source['url'], PHP_URL_SCHEME));
            self::assertSame('learn.microsoft.com', parse_url($source['url'], PHP_URL_HOST));
        }
        foreach ($manifest['controls'] as $control) {
            self::assertArrayNotHasKey('validator_config_json', $control);
        }
        $csv = file_get_contents($this->catalog()->path);
        self::assertIsString($csv);
        $hasCrLf = str_contains($csv, "\r\n");
        $hasBareLf = preg_match('/(?<!\r)\n/', $csv) === 1;
        self::assertSame(0, preg_match('/\r(?!\n)/', $csv));
        self::assertFalse($hasCrLf && $hasBareLf);
    }
}
