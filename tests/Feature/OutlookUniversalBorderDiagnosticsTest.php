<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\OutlookAddin\OutlookUniversalDiagnosticDetails;
use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureValidator;
use Tests\TestCase;

final class OutlookUniversalBorderDiagnosticsTest extends TestCase
{
    public function test_the_received_black_border_pair_remains_a_valid_redacted_signature_fixture(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/mail/u2e-cta-border-0746/received-0746.redacted.json'));
        $fixture = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/mail/u2e-cta-border-0746/manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($manifest['fixtureFileSha256'], hash('sha256', str_replace("\r\n", "\n", $bytes)));
        self::assertTrue(OutlookUniversalDiagnosticFixtureValidator::valid($fixture));
        self::assertDoesNotMatchRegularExpression('~https?://|mailto:|tel:|@~', $fixture['html'].$fixture['expectedHtml']);
        self::assertTrue($manifest['authorAndQuoteExcluded']);
    }

    public function test_safe_border_longhands_word_alternatives_and_redacted_images_survive_the_php_contract(): void
    {
        $fixture = $this->fixture();
        foreach (['border-top-color:#000000', 'border-top-width:.75pt', 'border-top-style:solid',
            'mso-border-top-alt:solid #000000 1pt', 'border-image:rt-redacted',
            'border-image-source:rt-redacted', 'border-image-slice:rt-redacted'] as $declaration) {
            $copy = $fixture;
            $copy['html'] = preg_replace_callback('~style="([^"]*\bborder-top:[^"]*)"~',
                static fn (array $match): string => 'style="'.$match[1].';'.$declaration.'"', $copy['html'], -1, $count);
            self::assertGreaterThan(0, $count);
            $copy['sha256'] = hash('sha256', $copy['html']);
            self::assertTrue(OutlookUniversalDiagnosticFixtureValidator::valid($copy), $declaration);
        }
    }

    public function test_border_diagnostics_accept_only_finite_checkpoints_and_hashed_differences(): void
    {
        foreach (['borders.structure', 'borders.invalid', 'borders.image', 'evidence.borderColors'] as $checkpoint) {
            self::assertTrue(OutlookUniversalDiagnosticDetails::valid(['checkpoint' => $checkpoint, 'difference' => null]));
        }
        $difference = ['key' => 'evidence.borderColors.value', 'index' => 4,
            'expected' => 'sha256:'.hash('sha256', '#ffffff').':7', 'actual' => 'sha256:'.hash('sha256', '#000000').':7'];
        self::assertTrue(OutlookUniversalDiagnosticDetails::valid(['checkpoint' => 'evidence.borderColors', 'difference' => $difference]));
        self::assertTrue(OutlookUniversalDiagnosticDetails::valid(['checkpoint' => 'evidence.borderColors',
            'difference' => ['key' => 'evidence.borderColors.count', 'index' => null, 'expected' => 9, 'actual' => 8]]));
        foreach ([['expected' => 'border-top:1px solid private@example.invalid'], ['key' => 'private@example.invalid'], ['index' => 999999]] as $invalid) {
            self::assertFalse(OutlookUniversalDiagnosticDetails::valid(['checkpoint' => 'evidence.borderColors', 'difference' => array_replace($difference, $invalid)]));
        }
    }

    public function test_raw_private_border_urls_or_arbitrary_styles_never_enter_the_fixture_store_contract(): void
    {
        $fixture = $this->fixture();
        foreach (['border-image-source:url(https://private.invalid/token)', 'border-top-color:private@example.invalid'] as $declaration) {
            $copy = $fixture;
            $copy['html'] = preg_replace('~style="~', 'style="'.$declaration.';', $copy['html'], 1, $count);
            self::assertSame(1, $count);
            $copy['sha256'] = hash('sha256', $copy['html']);
            self::assertFalse(OutlookUniversalDiagnosticFixtureValidator::valid($copy));
        }
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/mail/u2e-cta-border-0746/received-0746.redacted.json')), true, 512, JSON_THROW_ON_ERROR);
    }
}
