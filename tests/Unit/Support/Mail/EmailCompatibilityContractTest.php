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
        self::assertSame([], $catalog->builderManifestForJs()['controls']);
    }

    public function test_real_catalog_uses_the_canonical_quoted_utf8_dialect(): void
    {
        $path = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'data'
            .DIRECTORY_SEPARATOR.'email-html-compatibility-rules.csv';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        self::assertFalse(str_starts_with($contents, "\xEF\xBB\xBF"));
        self::assertTrue(mb_check_encoding($contents, 'UTF-8'));
        $hasCrLf = str_contains($contents, "\r\n");
        $hasBareLf = preg_match('/(?<!\r)\n/', $contents) === 1;
        self::assertSame(0, preg_match('/\r(?!\n)/', $contents));
        self::assertFalse($hasCrLf && $hasBareLf);

        $normalized = str_replace("\r\n", "\n", $contents);
        self::assertStringNotContainsString("\r", $normalized);
        self::assertTrue(str_ends_with($normalized, "\n"));

        $lines = explode("\n", substr($normalized, 0, -1));
        $field = '"(?:[^"]|"")*"';
        $recordPattern = '/^'.$field.'(?:,'.$field.'){'.(count(EmailCompatibilityCatalog::HEADER) - 1).'}$/D';
        foreach ($lines as $lineNumber => $line) {
            self::assertSame(1, preg_match($recordPattern, $line), 'Nicht kanonisch gequotete CSV-Zeile '.($lineNumber + 1));
        }

        $attributes = file_get_contents(dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'.gitattributes');
        self::assertIsString($attributes);
        self::assertMatchesRegularExpression(
            '/^resources\/data\/email-html-compatibility-rules\.csv text eol=lf$/m',
            $attributes,
        );

        $catalog = new EmailCompatibilityCatalog($path);
        self::assertCount(1908, $catalog->rows());
        self::assertCount(78, $catalog->activeRuleGroups());
        self::assertSame(EmailCompatibilityCatalog::HEADER, array_keys($catalog->rows()[0]));
        self::assertContains('official_vendor', array_column($catalog->rows(), 'source_type'));
        self::assertContains('original_client_test', array_column($catalog->rows(), 'source_type'));
    }

    public function test_catalog_accepts_consistent_lf_and_crlf_but_rejects_mixed_endings(): void
    {
        $row = $this->row('EMAIL-EOL-001', 'layout_table_role', 'all');
        $crlfPath = $this->writeCatalog([$row], 'crlf.csv');
        $crlf = file_get_contents($crlfPath);
        self::assertIsString($crlf);
        self::assertSame('1.0.0', (new EmailCompatibilityCatalog($crlfPath))->catalogVersion());

        $lfPath = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'lf.csv';
        file_put_contents($lfPath, str_replace("\r\n", "\n", $crlf));
        self::assertSame('1.0.0', (new EmailCompatibilityCatalog($lfPath))->catalogVersion());

        $mixedPath = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'mixed.csv';
        file_put_contents($mixedPath, preg_replace('/\r\n/', "\n", $crlf, 1));

        try {
            (new EmailCompatibilityCatalog($mixedPath))->rows();
            self::fail('Gemischte LF-/CRLF-Zeilenenden muessen fehlschlagen.');
        } catch (EmailCompatibilityCatalogException $exception) {
            self::assertSame('catalog_line_endings', $exception->errorCode);
        }

        $bareCrPath = $this->temporaryDirectory.DIRECTORY_SEPARATOR.'bare-cr.csv';
        file_put_contents($bareCrPath, preg_replace('/\r\n/', "\r", $crlf, 1));

        try {
            (new EmailCompatibilityCatalog($bareCrPath))->rows();
            self::fail('Einzelne CR-Zeilenenden muessen fehlschlagen.');
        } catch (EmailCompatibilityCatalogException $exception) {
            self::assertSame('catalog_line_endings', $exception->errorCode);
        }
    }

    public function test_deployment_checks_the_real_catalog_before_maintenance_and_migrations(): void
    {
        $deploy = file_get_contents(
            dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'deploy.sh',
        );
        self::assertIsString($deploy);

        $composer = strpos($deploy, '"$COMPOSER_BIN" install --no-dev');
        $catalogCheck = strpos($deploy, 'artisan mail:compatibility-catalog:check --no-interaction');
        $maintenance = strpos($deploy, 'artisan down --render=');
        $migration = strpos($deploy, 'artisan migrate --force');

        self::assertNotFalse($composer);
        self::assertNotFalse($catalogCheck);
        self::assertNotFalse($maintenance);
        self::assertNotFalse($migration);
        self::assertTrue($composer < $catalogCheck);
        self::assertTrue($catalogCheck < $maintenance);
        self::assertTrue($catalogCheck < $migration);
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

    public function test_full_document_rules_do_not_treat_signature_fragments_as_broken_documents(): void
    {
        $row = $this->row('EMAIL-DOC-001', 'doctype_lang', 'all', 'BLOCK');
        $row['match_target'] = 'full_document';
        $row['validator_config_json'] = '{"require_doctype":true,"require_lang":true}';
        $catalog = new EmailCompatibilityCatalog($this->writeCatalog([$row]));
        $auditor = new EmailCompatibilityAuditor($catalog);

        $fragment = $auditor->audit(
            '<table role="presentation"><tr><td>Signatur</td></tr></table>',
            '',
            ['document_kind' => 'signature'],
        );
        self::assertSame('pass', $fragment->status());

        $document = $auditor->audit(
            '<!doctype html><html><head></head><body>Mail</body></html>',
            '',
            ['document_kind' => 'template'],
        );
        self::assertSame('block', $document->status());
        self::assertSame('EMAIL-DOC-001', $document->findings[0]['rule_id']);
    }

    public function test_real_catalog_handlers_accept_safe_markup_and_report_forbidden_builder_techniques(): void
    {
        $path = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'data'
            .DIRECTORY_SEPARATOR.'email-html-compatibility-rules.csv';
        $auditor = new EmailCompatibilityAuditor(new EmailCompatibilityCatalog($path));
        $safe = '<!doctype html><html lang="de"><head><meta charset="UTF-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"></head><body>'
            .'<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">'
            .'<tr><td><a href="https://rail-time.de/">RailTime</a></td></tr></table></body></html>';

        self::assertSame('pass', $auditor->audit($safe, '', ['document_kind' => 'template'])->status());

        $unsafe = str_replace(
            '</body>',
            '<form><input></form><table><tr><td><a href="/relativ">'
                .'<img src="https://rail-time.de/bild.png" alt=""></a></td></tr></table></body>',
            $safe,
        );
        $report = $auditor->audit(
            $unsafe,
            '.layout{display:flex}',
            ['document_kind' => 'template', 'trusted_system_css' => false],
        );
        $ruleIds = array_column($report->findings, 'rule_id');

        self::assertSame('block', $report->status());
        self::assertContains('EMAIL-SECURITY-001', $ruleIds);
        self::assertContains('EMAIL-LAYOUT-001', $ruleIds);
        self::assertContains('EMAIL-LAYOUT-004', $ruleIds);
        self::assertContains('EMAIL-LINK-001', $ruleIds);
        self::assertContains('EMAIL-IMAGE-001', $ruleIds);
        self::assertContains('EMAIL-IMAGE-002', $ruleIds);
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
