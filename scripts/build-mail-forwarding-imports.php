<?php

use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\Mail\TemplateForwardingStyle;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$options = getopt('', ['base:', 'maker:', 'v27-revision2', 'v27-revision3']);
$revision3 = array_key_exists('v27-revision3', $options);
$revision2 = $revision3 || array_key_exists('v27-revision2', $options);
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
    if ($revision2 && $version !== 'v27') {
        continue;
    }
    if ($revision3 && $kind !== 'template') {
        continue;
    }
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
    if ($revision2) {
        if ($kind === 'template') {
            $master = file_get_contents(resource_path('mail-templates/email-master.html'));
            preg_match('/<!-- RT_TEMPLATE_MARK_START -->.*?<!-- RT_TEMPLATE_MARK_END -->/s', $master, $mark);
            $header = $revision3
                ? TemplateForwardingStyle::markFragment($mark[0])
                : TemplateForwardingStyle::framedMarkFragment($mark[0]);
            $html = preg_replace('/<!-- RT_TEMPLATE_MARK_START -->.*?<!-- RT_TEMPLATE_MARK_END -->/s', $header, $html);
            preg_match('/<!-- RT_TEMPLATE_MARK_START -->.*?<!-- RT_TEMPLATE_MARK_END -->/s', $html, $protectedMark);
            $html = str_replace($protectedMark[0], '<!-- FORWARDING_MARK_PLACEHOLDER -->', $html);
            $colors = ['#ffffff'];
            $html = preg_replace_callback('/<\/?(?:table|td)\b[^>]*>/i', static function (array $m) use (&$colors): string {
                $tag = $m[0];
                if (str_starts_with($tag, '</')) {
                    array_pop($colors);

                    return $tag;
                }
                $color = end($colors) ?: '#ffffff';
                if (preg_match('/bgcolor="(#[a-f0-9]{6})"|(?:background|background-color):\s*(#[a-f0-9]{6})/i', $tag, $found)) {
                    $color = $found[1] ?: $found[2];
                }
                $colors[] = $color;
                if (! str_contains($tag, 'bgcolor=')) {
                    $tag = substr($tag, 0, -1).' bgcolor="'.$color.'">';
                }
                if (! preg_match('/background(?:-color)?:/i', $tag)) {
                    $tag = str_contains($tag, 'style="')
                        ? str_replace('style="', 'style="background-color:'.$color.'!important;', $tag)
                        : substr($tag, 0, -1).' style="background-color:'.$color.'!important;">';
                }

                return $tag;
            }, $html);
            $html = str_replace('<!-- FORWARDING_MARK_PLACEHOLDER -->', $protectedMark[0], $html);
        } else {
            $asset = EmailTemplateBuilder::signatureLogoAsset('light', 'v27');
            [$naturalWidth, $naturalHeight] = getimagesize(public_path('mail-assets/'.$asset));
            $height = (int) round(180 * $naturalHeight / $naturalWidth);
            $html = preg_replace_callback('/<img\b[^>]*src="\{\{LOGO(?:_STILL)?_SRC\}\}"[^>]*>/', static function (array $match) use ($height): string {
                $tag = preg_replace('/\bstyle="([^"]*)"/', 'style="display:block;width:180px!important;max-width:180px!important;height:'.$height.'px!important;max-height:'.$height.'px!important;border:0;margin:0;'.(str_contains($match[0], 'mso-hide:all') ? 'mso-hide:all;' : '').'"', $match[0]);

                return str_replace('width="180"', 'width="180" height="'.$height.'"', $tag);
            }, $html);
            $html = preg_replace('/(<img class="rt-logo".*?<!\[endif\]-->)/s', '<table role="presentation" width="180" border="0" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="width:180px;max-width:180px;table-layout:fixed;background-color:#ffffff!important;"><tr><td width="180" height="'.$height.'" style="width:180px;height:'.$height.'px;padding:0;font-size:0;line-height:0;">$1</td></tr></table>', $html, 1);
            // This table lies behind BOTH the train and content; painting the
            // overlaid contact cell instead would hide the train.
            $html = str_replace('class="rt-sign-content-frame"', 'class="rt-sign-content-frame" bgcolor="#ffffff"', $html);
            $html = preg_replace('/(<table class="rt-sign-content-frame"[^>]*style=")/', '$1background-color:#ffffff!important;', $html);
        }
    }
    $out = $base.'/'.$version.($revision3 ? '/forwarding-r3' : ($revision2 ? '/forwarding-r2' : '/forwarding'));
    if (! is_dir($out)) {
        mkdir($out, 0777, true);
    }
    $name = 'railtime-'.$version.'-'.($kind === 'template' ? 'vorlage' : 'signatur').'-weiterleitung'.($revision3 ? '-r3' : ($revision2 ? '-r2' : ''));
    file_put_contents($out.'/'.$name.'.html', $html);
    file_put_contents($out.'/'.$name.'.css', $bundle['css']);
    $sanitized = app(EmailHtmlSanitizer::class)->clean($html);
    if ($sanitized->hasViolations()) {
        throw new RuntimeException(json_encode($sanitized->violationMessages(), JSON_UNESCAPED_UNICODE));
    }
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
