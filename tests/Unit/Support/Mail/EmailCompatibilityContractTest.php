<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mail;

use App\Support\Mail\EmailCompatibilityAuditor;
use App\Support\Mail\EmailCompatibilityCatalog;
use App\Support\Mail\EmailCompatibilityCatalogException;
use App\Support\Mail\EmailCompatibilityReport;
use PHPUnit\Framework\TestCase;

final class EmailCompatibilityContractTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'railtime-email-catalog-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
        EmailCompatibilityCatalog::clearCache();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->temporaryDirectory);
        EmailCompatibilityCatalog::clearCache();

        parent::tearDown();
    }

    public function test_catalog_validates_contract_and_exposes_only_safe_js_manifest_fields(): void
    {
        $row = $this->row('EMAIL-LAYOUT-001', 'layout_table_role', 'all');
        $row['validator_config_json'] = '{"required_role":"presentation"}';
        $row['valid_example'] = '<table role="presentation">';
        $row['source_url'] = 'https://www.w3.org/WAI/tutorials/tables/tips/';
        $path = $this->writeCatalog([$row]);

        $catalog = new EmailCompatibilityCatalog($path);

        self::assertSame('1.0.0', $catalog->schemaVersion());
        self::assertSame('1.0.0', $catalog->catalogVersion());
        self::assertCount(1, $catalog->rows());
        $manifest = $catalog->manifestForJs();
        self::assertSame('EMAIL-LAYOUT-001', $manifest['rules'][0]['rule_id']);
        self::assertArrayNotHasKey('validator_handler', $manifest['rules'][0]);
        self::assertArrayNotHasKey('validator_config_json', $manifest['rules'][0]);
        self::assertArrayNotHasKey('valid_example', $manifest['rules'][0]);
        self::assertArrayNotHasKey('source_url', $manifest['rules'][0]);
    }

    public function test_catalog_fails_closed_with_structured_errors(): void
    {
        $missingPath = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'missing.csv';

        try {
            (new EmailCompatibilityCatalog($missingPath))->rows();
            self::fail('Ein fehlender Katalog muss fehlschlagen.');
        } catch (EmailCompatibilityCatalogException $exception) {
            self::assertSame('catalog_missing', $exception->errorCode);
            self::assertSame($missingPath, $exception->catalogPath);
            self::assertSame('catalog_missing', $exception->toArray()['code']);
            $unavailable = EmailCompatibilityReport::unavailable($exception, 123, 45)->toArray();
            self::assertSame('block', $unavailable['status']);
            self::assertSame('unavailable', $unavailable['catalog_version']);
            self::assertSame('EMAIL_CATALOG_UNAVAILABLE', $unavailable['findings'][0]['diagnostic_code']);
        }

        $invalid = $this->row('EMAIL-CSS-001', 'css_forbidden_features', 'all');
        $invalid['severity'] = 'warning';
        $path = $this->writeCatalog([$invalid], 'invalid.csv');

        $this->expectException(EmailCompatibilityCatalogException::class);
        $this->expectExceptionMessage('severity');
        (new EmailCompatibilityCatalog($path))->rows();
    }

    public function test_catalog_rejects_inconsistent_revisions_and_incomplete_safe_coverage(): void
    {
        $first = $this->row('EMAIL-LAYOUT-002', 'layout_table_role', 'outlook-classic-windows');
        $second = $this->row('EMAIL-LAYOUT-002', 'layout_table_role', 'outlook-new-windows');
        $second['title_de'] = 'Abweichende Definition';
        $path = $this->writeCatalog([$first, $second], 'inconsistent.csv');

        try {
            (new EmailCompatibilityCatalog($path))->rows();
            self::fail('Abweichende Clientdefinitionen muessen fehlschlagen.');
        } catch (EmailCompatibilityCatalogException $exception) {
            self::assertSame('catalog_inconsistent_revision', $exception->errorCode);
            self::assertSame('title_de', $exception->column);
        }

        EmailCompatibilityCatalog::clearCache();
        $onlyOneProfile = $this->row('EMAIL-LAYOUT-003', 'layout_table_role', 'outlook-classic-windows');
        $path = $this->writeCatalog([$onlyOneProfile], 'coverage.csv');

        try {
            (new EmailCompatibilityCatalog($path))->rows();
            self::fail('Eine renderingspezifische SAFE-Regel braucht alle Pflichtprofile.');
        } catch (EmailCompatibilityCatalogException $exception) {
            self::assertSame('catalog_safe_profile_missing', $exception->errorCode);
        }
    }

    public function test_auditor_uses_only_allowlisted_handlers_and_returns_the_api_contract(): void
    {
        $rows = [];
        $rows[] = $this->row('EMAIL-ACTIVE-001', 'forbidden_elements', 'all', 'BLOCK', '{"elements":["script","form"]}');
        $rows[] = $this->row('EMAIL-LAYOUT-010', 'layout_table_role', 'all', 'WARN');
        $rows[] = $this->row('EMAIL-IMAGE-001', 'image_alt', 'all', 'WARN');
        $rows[] = $this->row('EMAIL-IMAGE-002', 'image_dimensions', 'all', 'WARN');
        $rows[] = $this->row('EMAIL-LINK-001', 'link_absolute', 'all', 'BLOCK');
        $rows[] = $this->row('EMAIL-CSS-010', 'css_forbidden_features', 'all', 'BLOCK', '{"features":["flex","grid"]}');
        $rows[] = $this->row('EMAIL-MIME-001', 'plain_text_required', 'all', 'WARN');
        $rows[] = $this->row('EMAIL-MANUAL-001', 'not_executable_php', 'all', 'BLOCK');
        $path = $this->writeCatalog($rows);
        $auditor = new EmailCompatibilityAuditor(new EmailCompatibilityCatalog($path));
        $html = '<!doctype html><html lang="de"><head><style>.x{display:flex}</style></head><body>'
            .'<script>alert(1)</script><table><tr><td>x</td></tr></table>'
            .'<a href="/relativ"><img src="https://example.test/x.png"></a></body></html>';

        $report = $auditor->audit($html, '', ['document_kind' => 'template', 'plain_text' => '']);
        $payload = $report->toArray();

        self::assertSame('block', $payload['status']);
        self::assertSame(3, $payload['counts']['block']);
        self::assertSame(4, $payload['counts']['warn']);
        self::assertSame(1, $payload['counts']['info']);
        self::assertSame(strlen($html), $payload['html_bytes']);
        self::assertSame(strlen('.x{display:flex}'), $payload['style_bytes']);
        self::assertTrue($report->blocksPublication());
        self::assertContains('EMAIL-MANUAL-001', array_column($payload['findings'], 'rule_id'));
        $manual = current(array_filter(
            $payload['findings'],
            static fn (array $finding): bool => $finding['rule_id'] === 'EMAIL-MANUAL-001',
        ));
        self::assertSame('INFO', $manual['enforcement']);
    }

    public function test_auditor_enforces_fixed_html_and_style_size_policy_without_catalog_handlers(): void
    {
        $path = $this->writeCatalog([$this->row('EMAIL-MANUAL-010', '', 'all', 'OFF')]);
        $auditor = new EmailCompatibilityAuditor(new EmailCompatibilityCatalog($path));

        $warning = $auditor->audit(str_repeat('x', EmailCompatibilityAuditor::HTML_WARN_BYTES));
        self::assertSame('warn', $warning->status());
        self::assertContains('EMAIL_SIZE_HTML_WARN', array_column($warning->findings, 'diagnostic_code'));

        $blocked = $auditor->audit(str_repeat('x', EmailCompatibilityAuditor::HTML_BLOCK_BYTES));
        self::assertSame('block', $blocked->status());
        self::assertContains('EMAIL_SIZE_HTML_BLOCK', array_column($blocked->findings, 'diagnostic_code'));

        $styleWarning = $auditor->audit('<p>x</p>', str_repeat('a', EmailCompatibilityAuditor::STYLE_WARN_BYTES));
        self::assertSame('warn', $styleWarning->status());
        self::assertContains('EMAIL_SIZE_STYLE_WARN', array_column($styleWarning->findings, 'diagnostic_code'));
    }

    /** @return array<string, string> */
    private function row(
        string $ruleId,
        string $handler,
        string $profile,
        string $enforcement = 'WARN',
        string $validatorConfig = '{}',
    ): array {
        $row = array_fill_keys(EmailCompatibilityCatalog::HEADER, '');

        return array_replace($row, [
            'schema_version' => '1.0.0',
            'catalog_version' => '1.0.0',
            'row_id' => strtolower($ruleId).'-'.$profile,
            'rule_id' => $ruleId,
            'rule_revision' => '1',
            'rule_status' => 'ACTIVE',
            'category' => 'test',
            'subcategory' => 'contract',
            'title_de' => 'Kompatibilitaetsregel',
            'rule_text_de' => 'Die Regel muss eingehalten werden.',
            'normative_level' => 'MUST',
            'compatibility_level' => 'SAFE',
            'severity' => 'MAJOR',
            'enforcement' => $enforcement,
            'rationale_de' => 'Reproduzierbare Darstellung.',
            'document_kinds_json' => '["template","signature","system_mail","html_download","eml","outlook_package","marketing"]',
            'applies_to' => 'html',
            'match_language' => 'html',
            'match_target' => 'document',
            'condition_json' => '{}',
            'allowed_patterns_json' => '[]',
            'forbidden_patterns_json' => '[]',
            'validator_handler' => $handler,
            'validator_config_json' => $validatorConfig,
            'diagnostic_code' => str_replace('-', '_', $ruleId),
            'diagnostic_message_de' => 'Die Regel wurde verletzt.',
            'fix_guidance_de' => 'Die Struktur mailkompatibel korrigieren.',
            'autofix_mode' => 'NONE',
            'autofix_action' => 'NONE',
            'autofix_config_json' => '{}',
            'project_mapping_json' => '[]',
            'valid_example' => '<p>ok</p>',
            'invalid_example' => '<script>x</script>',
            'exception_condition_de' => '',
            'exception_handling_de' => '',
            'client_profile_id' => $profile,
            'client_family' => $profile,
            'client_platform' => 'test',
            'rendering_engine' => 'test',
            'client_version_selector' => 'all',
            'support_status' => 'SUPPORTED',
            'support_note_de' => 'Im Vertrag belegt.',
            'test_method' => 'static_analysis',
            'last_tested_at' => '2026-08-24',
            'source_type' => 'project_policy',
            'source_title' => 'RailTime E-Mail-Vertrag',
            'source_url' => 'https://example.test/email-contract',
            'source_locator' => 'test',
            'source_published_at' => '2026-08-24',
            'source_accessed_at' => '2026-08-24',
            'evidence_summary_de' => 'Fokussierter Regressionstest.',
            'confidence' => 'HIGH',
            'confidence_reason_de' => 'Direkt geprueft.',
            'last_verified_in_project' => '2026-08-24',
            'review_due_at' => '2027-02-24',
            'secondary_sources_json' => '[]',
            'tags_json' => '["test"]',
            'notes_de' => '',
        ]);
    }

    /** @param list<array<string, string>> $rows */
    private function writeCatalog(array $rows, string $name = 'catalog.csv'): string
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.$name;
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        fputcsv($handle, EmailCompatibilityCatalog::HEADER, ',', '"', '', "\r\n");
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '', "\r\n");
        }
        fclose($handle);

        return $path;
    }
}
