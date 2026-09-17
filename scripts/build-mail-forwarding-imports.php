<?php

use App\Support\Mail\TemplateForwardingStyle;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$options = getopt('', ['base:', 'maker:']);
$base = realpath($options['base'] ?? '');
$maker = realpath($options['maker'] ?? '');
if (! $base || ! $maker) {
    throw new RuntimeException('Required: --base=<Mail-Templates folder> --maker=<maker PHP>');
}
$inputs = [
    ['v27', 'signature', 'v27/compact-hotline/railtime-v27-signatur-24-7-kompakt.json'],
    ['v27', 'template', 'v27/white-hotline-signature/railtime-v27-vorlage-weiss.json'],
    ['v30', 'signature', 'v30/railtime-v30-signatur.json'],
    ['v30', 'template', 'v30/railtime-v30-vorlage.json'],
];
foreach ($inputs as [$version, $kind, $input]) {
    $bundle = json_decode(file_get_contents($base.'/'.$input), true, flags: JSON_THROW_ON_ERROR);
    $html = $bundle['html'];
    // Only reinforce existing white surfaces. Do not paint overlay contact
    // cells: that would hide V27's train. Red banner and grey legal row remain.
    $html = preg_replace_callback('/<(?:table|td|body|div)\b[^>]*>/i', static function (array $match): string {
        $tag = $match[0];
        $whiteSurface = preg_match('/\bbgcolor=["\']#ffffff["\']/i', $tag)
            || preg_match('/(?:background|background-color)\s*:\s*#ffffff\b/i', $tag)
            || str_contains($tag, 'class="rt-sign-stage"')
            || str_contains($tag, 'box-sizing:border-box;border-left:6px solid #e90032;');
        if (! $whiteSurface) {
            return $tag;
        }
        $tag = preg_replace_callback('/\bstyle="([^"]*)"/i', static function (array $style): string {
            $css = preg_replace('/(?:^|;)\s*(?:background|background-color)\s*:\s*#ffffff\s*(?:!important)?\s*;?/i', ';', $style[1]);

            return 'style="background-color:#ffffff!important;'.ltrim($css, ';').'"';
        }, $tag);
        if (! str_contains(strtolower($tag), 'style=')) {
            $tag = substr($tag, 0, -1).' style="background-color:#ffffff!important;">';
        }
        if (preg_match('/^<(?:table|td|body)\b/i', $tag) && ! str_contains(strtolower($tag), 'bgcolor=')) {
            $tag = substr($tag, 0, -1).' bgcolor="#ffffff">';
        }

        return $tag;
    }, $html);
    if ($kind === 'template') {
        $html = preg_replace_callback('/<!-- RT_TEMPLATE_MARK_START -->.*?<!-- RT_TEMPLATE_MARK_END -->/s', static fn (array $m): string => TemplateForwardingStyle::markFragment($m[0]), $html);
    }
    $out = $base.'/'.$version.'/forwarding';
    if (! is_dir($out)) {
        mkdir($out, 0777, true);
    }
    $name = 'railtime-'.$version.'-'.($kind === 'template' ? 'vorlage' : 'signatur').'-weiterleitung';
    file_put_contents($out.'/'.$name.'.html', $html);
    file_put_contents($out.'/'.$name.'.css', $bundle['css']);
    $args = [PHP_BINARY, $maker, 'make', '--project', dirname(__DIR__), '--kind', $kind, '--html', $out.'/'.$name.'.html', '--css', $out.'/'.$name.'.css', '--output', $out.'/'.$name.'.json', '--force'];
    foreach ($bundle['media'] as $media) {
        if (! str_starts_with($media['id'], 'mail-imports/')) {
            continue;
        }
        $file = $out.'/'.basename($media['id']);
        file_put_contents($file, base64_decode($media['data'], true));
        $args[] = '--media';
        $args[] = $media['source'].'::'.$file;
    }
    foreach (['make' => $args, 'validate' => [PHP_BINARY, $maker, 'validate', '--project', dirname(__DIR__), '--input', $out.'/'.$name.'.json']] as $operation => $command) {
        $process = new Process($command, dirname(__DIR__));
        $process->setTimeout(120)->run();
        file_put_contents($out.'/'.$name.'-'.$operation.'-report.json', $process->getOutput());
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getOutput().$process->getErrorOutput());
        }
        echo $name.' '.$operation.' OK'.PHP_EOL;
    }
}
