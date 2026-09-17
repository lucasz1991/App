<?php

use App\Support\Mail\SignatureDocumentContract;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

/** Build a separate, portable v30 draft from the preserved v27 export. No DB writes. */
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['source:', 'output:', 'maker:']);
foreach (['source', 'output', 'maker'] as $key) {
    if (empty($options[$key])) {
        throw new RuntimeException('Required: --source --output --maker');
    }
}
$source = realpath($options['source']);
$out = realpath($options['output']);
if (! $source || ! $out || $source === $out) {
    throw new RuntimeException('Use an existing, separate v30 output folder.');
}
$signature = json_decode(file_get_contents($source.'/railtime-v27-signatur-24-7.json'), true, flags: JSON_THROW_ON_ERROR);
$template = json_decode(file_get_contents($source.'/railtime-v27-vorlage-weiss.json'), true, flags: JSON_THROW_ON_ERROR);
SignatureDocumentContract::assertValid($signature['html']);
$dom = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8"><table id="source-root"><tbody>'.$signature['html'].'</tbody></table>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
$xp = new DOMXPath($dom);
$ledger = $xp->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-sign-ledger ")]');
$legal = $xp->query('//*[@id="source-root"]/tbody/tr[2]');
if ($ledger->length !== 1 || $legal->length !== 1) {
    throw new RuntimeException('Expected exact v27 ledger and legal row.');
}
$serialize = static fn ($node) => str_ireplace(['%7B', '%7D'], ['{', '}'], $dom->saveHTML($node));
$mediaSources = [];
foreach (['v30-zug-hotline.gif', 'v30-zug-anrufen.gif', 'v30-zug-email.gif', 'v30-zug-lok.gif', 'v30-streckennetz.png'] as $file) {
    $mediaSources[$file] = '/storage/mail-imports/'.hash_file('sha256', $out.'/media/'.$file).'.'.pathinfo($file, PATHINFO_EXTENSION);
}
$images = [
    ['hotline', 180, 'mailto:dispo@rail-time.de', '24/7 Hotline – E-Mail an die Disposition'],
    ['anrufen', 110, 'tel:{{NOTFALLNUMMER_TEL}}', '24/7 Hotline anrufen'],
    ['email', 110, 'mailto:dispo@rail-time.de', 'E-Mail an die Disposition'],
    ['lok', 160, null, ''],
];
$cells = '';
foreach ($images as [$name, $width, $href, $alt]) {
    $img = '<img src="'.$mediaSources['v30-zug-'.$name.'.gif'].'" width="'.$width.'" height="112" alt="'.$alt.'" style="display:block;width:100%;max-width:'.$width.'px;height:auto;border:0;color:#ffffff;font-family:Arial,sans-serif;font-size:12px;">';
    if ($href !== null) {
        $img = '<a href="'.$href.'" style="display:block;color:#ffffff;text-decoration:none;">'.$img.'</a>';
    }
    $cells .= '<td width="'.($width / 560 * 100).'%" valign="bottom" style="padding:0;vertical-align:bottom;font-size:0;line-height:0;">'.$img.'</td>';
}
$banner = '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" bgcolor="#e60032" style="width:100%;background-color:#e60032;border-collapse:collapse;"><tr><td align="left" style="padding:0;"><table data-rt-hotline-train="1" role="presentation" width="560" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;table-layout:fixed;border-collapse:collapse;"><tr>'.$cells.'</tr></table></td></tr></table>';
$html = '<tr data-rt-artifact-version="v30"><td class="rt-sign-cell" width="100%" bgcolor="#ffffff" style="width:100%;padding:0;background-color:#ffffff;">'.$banner
    .'<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tr><td data-rt-v30-decoration="1" class="rt-sign-ledger-content" bgcolor="#ffffff" style="padding:26px 32px;background-color:#ffffff;background-image:url(/mail-import-source/v30/v30-streckennetz.png);background-position:right top;background-repeat:no-repeat;background-size:600px 210px;">'
    .$serialize($ledger->item(0)).'</td></tr></table></td></tr><!-- RT_SIGNATURE_MAIN_END -->'.$serialize($legal->item(0));
SignatureDocumentContract::assertValid($html);
$html = str_replace('/mail-import-source/v30/v30-streckennetz.png', $mediaSources['v30-streckennetz.png'], $html);

foreach (['signature' => [$html, '', 'railtime-v30-signatur'], 'template' => [$template['html'], $template['css'], 'railtime-v30-vorlage']] as $kind => [$html, $css, $name]) {
    file_put_contents($out.'/'.$name.'.html', $html);
    file_put_contents($out.'/'.$name.'.css', $css);
    $command = [PHP_BINARY, $options['maker'], 'make', '--project', dirname(__DIR__), '--kind', $kind, '--html', $out.'/'.$name.'.html', '--css', $out.'/'.$name.'.css', '--output', $out.'/'.$name.'.json', '--force'];
    if ($kind === 'signature') {
        foreach (['v30-zug-hotline.gif', 'v30-zug-anrufen.gif', 'v30-zug-email.gif', 'v30-zug-lok.gif', 'v30-streckennetz.png'] as $file) {
            $command[] = '--media';
            $command[] = $mediaSources[$file].'::'.$out.'/media/'.$file;
        }
    }
    foreach (['make' => $command, 'validate' => [PHP_BINARY, $options['maker'], 'validate', '--project', dirname(__DIR__), '--input', $out.'/'.$name.'.json']] as $operation => $args) {
        $process = new Process($args, dirname(__DIR__));
        $process->setTimeout(120);
        $process->run();
        file_put_contents($out.'/'.$name.'-'.$operation.'-report.json', $process->getOutput());
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getOutput().$process->getErrorOutput());
        }
        echo $name.' '.$operation.' OK'.PHP_EOL;
    }
}
