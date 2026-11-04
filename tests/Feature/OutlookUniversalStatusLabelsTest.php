<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\OutlookAddin\OutlookUniversalDiagnosticDetails;
use App\Support\OutlookAddin\OutlookUniversalOperationDetails;
use App\Support\OutlookAddin\OutlookUniversalStatusLabels;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class OutlookUniversalStatusLabelsTest extends TestCase
{
    public function test_labels_cover_every_admitted_checkpoint_and_execution_step(): void
    {
        $checkpoints = array_keys(OutlookUniversalStatusLabels::CHECKPOINT_LABELS);
        $expected = OutlookUniversalDiagnosticDetails::CHECKPOINTS;
        sort($checkpoints);
        sort($expected);
        self::assertSame($expected, $checkpoints);
        $steps = array_keys(OutlookUniversalStatusLabels::STEP_LABELS);
        $expected = (new ReflectionClass(OutlookUniversalOperationDetails::class))->getConstant('STEPS');
        sort($steps);
        sort($expected);
        self::assertSame($expected, $steps);
        self::assertSame('Rahmenfarben der Signatur', OutlookUniversalStatusLabels::checkpointLabel('evidence.borderColors'));
        self::assertSame('Weiße Trennlinie über den Dispo-Symbolen', OutlookUniversalStatusLabels::checkpointLabel('colors.line'));
        self::assertSame('Unerwarteter Rahmen an der Dispo-Symbolzeile', OutlookUniversalStatusLabels::checkpointLabel('borders.cta'));
    }

    public function test_javascript_and_php_labels_and_fallback_behavior_are_exactly_equal(): void
    {
        $script = <<<'JS'
import {U2E_CHECKPOINT_LABELS,U2E_STEP_LABELS,U2E_STATUS_FALLBACK_LABEL,u2eCheckpointLabel} from './resources/js/outlook-addin/u2e-status-labels.js';
import {U2E_REPORT_STEPS} from './resources/js/outlook-addin/u2e-session-report.js';
const inputs=[['colors.line','session.read'],[null,'snapshot.region'],['PRIVATE','session.read'],['private@example.test','PRIVATE'],[42,{}]];
process.stdout.write(JSON.stringify({checkpoints:U2E_CHECKPOINT_LABELS,steps:U2E_STEP_LABELS,allowedSteps:U2E_REPORT_STEPS,fallback:U2E_STATUS_FALLBACK_LABEL,outputs:inputs.map(args=>u2eCheckpointLabel(...args))}));
JS;
        $process = new Process(['node', '--input-type=module', '-e', $script], base_path());
        $process->mustRun();
        $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(OutlookUniversalStatusLabels::CHECKPOINT_LABELS, $output['checkpoints']);
        self::assertSame(OutlookUniversalStatusLabels::STEP_LABELS, $output['steps']);
        self::assertSame((new ReflectionClass(OutlookUniversalOperationDetails::class))->getConstant('STEPS'), $output['allowedSteps']);
        self::assertSame(OutlookUniversalStatusLabels::FALLBACK_LABEL, $output['fallback']);
        $inputs = [['colors.line', 'session.read'], [null, 'snapshot.region'], ['PRIVATE', 'session.read'], ['private@example.test', 'PRIVATE'], [42, new \stdClass]];
        self::assertSame(array_map(static fn (array $args): string => OutlookUniversalStatusLabels::checkpointLabel(...$args), $inputs), $output['outputs']);
    }

    public function test_fallback_never_echoes_unknown_values_or_invokes_user_string_conversion(): void
    {
        $hostile = new class
        {
            public function __toString(): string
            {
                throw new \LogicException('Input must never be converted to text.');
            }
        };
        foreach ([null, false, 42, [], $hostile, 'PRIVATE-BODY', '<script>PRIVATE</script>', 'private@example.test',
            'https://private.example', '__proto__', 'constructor', ' colors.line'] as $input) {
            self::assertSame(OutlookUniversalStatusLabels::FALLBACK_LABEL, OutlookUniversalStatusLabels::checkpointLabel($input, $input));
        }
    }

    public function test_admin_status_shows_plain_checkpoints_preserves_known_codes_and_omits_unknown_input(): void
    {
        DB::listen(static fn () => throw new \LogicException('Rendering labels must not query business data.'));
        $directory = sys_get_temp_dir().'/u2e-status-labels-layout-'.bin2hex(random_bytes(8));
        mkdir($directory.'/layouts', 0777, true);
        file_put_contents($directory.'/layouts/master.blade.php', '@yield("content")');
        $this->app['view']->getFinder()->prependLocation($directory);
        $this->app['view']->getFinder()->flush();
        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            unlink($directory.'/layouts/master.blade.php');
            rmdir($directory.'/layouts');
            rmdir($directory);
        });
        $base = ['at' => '2026-11-04T09:00:00+01:00', 'host' => 'OutlookWebApp', 'code' => 'SIGNATURE_LAYOUT_UNCONFIRMED',
            'phase' => 'readback', 'sourceLength' => 13822, 'bodyLength' => 16000];
        $history = ['readbackErrors' => [
            $base + ['checkpoint' => 'colors.line', 'step' => 'readback.material'],
            $base + ['step' => 'snapshot.region'],
            $base + ['checkpoint' => 'PRIVATE-CHECKPOINT', 'step' => 'PRIVATE-STEP'],
            $base + ['checkpoint' => 'borders.cta'],
            $base + ['checkpoint' => 'evidence.borderColors'],
        ]];
        $subject = (object) ['id' => 5, 'email' => 'synthetic@example.test', 'name' => 'Synthetisches Profil'];
        $admission = ['status' => 'admitted', 'reason' => 'admitted'];
        $html = view('mail-documents.u2e-status', ['subject' => $subject, 'users' => collect([$subject]),
            'admission' => $admission, 'reviewAdmission' => $admission, 'history' => $history,
            'mode' => 'editor', 'templateId' => null, 'previewAvailable' => false])->render();
        self::assertSame(5, substr_count($html, 'data-u2e-checkpoint-label'));
        foreach (['Weiße Trennlinie über den Dispo-Symbolen', 'Eigenen Nachrichtenteil und Zitatgrenze prüfen',
            'Unerwarteter Rahmen an der Dispo-Symbolzeile', 'Rahmenfarben der Signatur', 'Prüfschritt nicht verfügbar',
            'Prüfpunkt: colors.line', 'Schritt: readback.material'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringNotContainsString('PRIVATE-CHECKPOINT', $html);
        self::assertStringNotContainsString('PRIVATE-STEP', $html);
    }
}
